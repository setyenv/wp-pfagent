<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use ProjectFlash\Agent\Framework\LlmCompactor;
use ProjectFlash\Agent\Framework\Llm\GatewayFactory;
use ProjectFlash\Agent\Framework\Llm\ModelCatalog;
use ProjectFlash\Agent\Framework\Loop;
use ProjectFlash\Agent\Framework\LoopOptions;
use ProjectFlash\Agent\Framework\LoopResult;
use ProjectFlash\Agent\Framework\OutputFilter;
use ProjectFlash\Agent\Framework\PermissionRuleset;
use ProjectFlash\Agent\Framework\Tools\Registry;
use ProjectFlash\Agent\Framework\WordPress\HumanModalApprovalGate;
use ProjectFlash\Agent\Framework\WordPress\Storage\WpDbStore;
use ProjectFlash\Agent\Framework\WordPress\Tools\FilterBridgeTool;
use ProjectFlash\Agent\Framework\WordPress\TransientApprovalStore;
use WP_Error;

/**
 * Bridge between wp-pfagent's REST surface and the multi-provider Framework
 * Loop. Builds, on each `turn()` call, everything the Loop needs:
 *
 *  - WpDbStore over wp_pfaf_* tables (created by the activation hook).
 *  - Registry of FilterBridgeTool instances mirroring agent-tools.json — so
 *    the LLM sees the same tool surface as the legacy AgentRuntime, but
 *    routed through the Framework's loop discipline (fingerprinting,
 *    idempotency, oscillation detection, side-effect approval).
 *  - Gateway selected by the active credential's preset family
 *    (OpenAiCompatibleGateway / AnthropicGateway / GeminiGateway), with the
 *    ModelCatalog wired in for cost + caps fallback.
 *
 * No state is held between calls; the WpDbStore IS the state.
 */
final class FrameworkRuntime
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ProviderPresets $presets,
        private readonly AgentToolRegistry $toolRegistry
    ) {
    }

    /**
     * Run one user message through the Loop. Returns a normalised result
     * envelope suitable for the REST response.
     *
     * @param array{providerId: string, model?: string, message: string, conversationId?: ?string, label?: string} $input
     * @return array<string, mixed>|WP_Error
     */
    public function turn(array $input): array|WP_Error
    {
        $providerId = sanitize_key((string) ($input['providerId'] ?? ''));
        if ($providerId === '') {
            return new WP_Error('pfa_runtime_provider_required', __('providerId is required.', 'wp-pfagent'), ['status' => 400]);
        }

        $userMessage = trim((string) ($input['message'] ?? ''));
        if ($userMessage === '') {
            return new WP_Error('pfa_runtime_message_required', __('message is required.', 'wp-pfagent'), ['status' => 400]);
        }

        $context = $this->credentials->runtime_context($providerId);
        if ($context instanceof WP_Error) {
            return $context;
        }

        $model = trim((string) ($input['model'] ?? ''));
        if ($model === '') {
            return new WP_Error('pfa_runtime_model_required', __('model is required for the framework runtime.', 'wp-pfagent'), ['status' => 400]);
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return new WP_Error('pfa_runtime_wpdb_missing', __('WordPress database is not available.', 'wp-pfagent'), ['status' => 500]);
        }

        try {
            $loop = $this->buildLoop($wpdb, $context, $providerId, $model);
        } catch (\Throwable $e) {
            return new WP_Error('pfa_runtime_build_failed', $e->getMessage(), ['status' => 500]);
        }

        $conversationId = isset($input['conversationId']) && $input['conversationId'] !== ''
            ? (string) $input['conversationId']
            : null;
        $label = (string) ($input['label'] ?? '');

        $turnStartIso = gmdate('c');
        try {
            $result = $loop->run($conversationId, $userMessage, $label !== '' ? $label : null);
        } catch (\Throwable $e) {
            return new WP_Error('pfa_runtime_turn_failed', $e->getMessage(), ['status' => 500]);
        }

        return $this->serialise($result, $wpdb, $turnStartIso);
    }

    /**
     * Resume after a side-effect approval verdict.
     */
    public function resume(string $conversationId, string $confirmationToken, bool $approved, string $providerId, string $model): array|WP_Error
    {
        $context = $this->credentials->runtime_context($providerId);
        if ($context instanceof WP_Error) {
            return $context;
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return new WP_Error('pfa_runtime_wpdb_missing', __('WordPress database is not available.', 'wp-pfagent'), ['status' => 500]);
        }

        $turnStartIso = gmdate('c');
        try {
            $loop = $this->buildLoop($wpdb, $context, $providerId, $model);
            $result = $loop->resume($conversationId, $confirmationToken, $approved);
        } catch (\Throwable $e) {
            return new WP_Error('pfa_runtime_resume_failed', $e->getMessage(), ['status' => 500]);
        }

        return $this->serialise($result, $wpdb, $turnStartIso);
    }

    /**
     * H5: continue a turn that paused on its wall-clock budget. Same wiring as
     * resume(), but there is no confirmation token / verdict — the conversation
     * state is already persisted; we just drive more rounds in this fresh
     * request. The host calls this repeatedly while the response carries
     * `continuation: true`.
     */
    public function continueTurn(string $conversationId, string $providerId, string $model): array|WP_Error
    {
        $context = $this->credentials->runtime_context($providerId);
        if ($context instanceof WP_Error) {
            return $context;
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return new WP_Error('pfa_runtime_wpdb_missing', __('WordPress database is not available.', 'wp-pfagent'), ['status' => 500]);
        }

        $turnStartIso = gmdate('c');
        try {
            $loop = $this->buildLoop($wpdb, $context, $providerId, $model);
            $result = $loop->continueAfterBudget($conversationId);
        } catch (\Throwable $e) {
            return new WP_Error('pfa_runtime_continue_failed', $e->getMessage(), ['status' => 500]);
        }

        return $this->serialise($result, $wpdb, $turnStartIso);
    }

    /**
     * Wire the Loop from per-request context. Pure factory — no caching
     * across calls so a credential change is picked up immediately.
     *
     * @param array<string, mixed> $context  CredentialStore::runtime_context shape
     */
    private function buildLoop(\wpdb $wpdb, array $context, string $providerId, string $model): Loop
    {
        $store = new WpDbStore($wpdb);
        $registry = $this->buildRegistry();
        $approvalStore = new TransientApprovalStore();

        // Per-turn ModelCatalog assembled from the credential's confirmed
        // models[] (populated by the wizard from API discovery + user-entered
        // pricing/caps). A credential change is picked up immediately — no
        // cross-turn caching.
        $catalog = $this->buildCatalog($context);
        $factory = new GatewayFactory($catalog);

        $gateway = $factory->build([
            'family' => (string) ($context['preset']['family'] ?? ''),
            'apiKey' => (string) ($context['apiKey'] ?? ''),
            'baseUrl' => $this->resolveBaseUrl($context),
            'settings' => is_array($context['settings'] ?? null) ? $context['settings'] : [],
            'timeout' => 120,
        ]);

        $systemPrompt = $this->systemPrompt($context);

        // Per-model `defaultReasoningEffort` saved on the credential via the
        // wizard wins over any host default (Kilo Tier 2.4). null means
        // "let the model decide" / "no reasoning extension".
        $modelRecord = $this->credentials->model($providerId, $model);
        $reasoningEffort = is_array($modelRecord) && isset($modelRecord['defaultReasoningEffort']) && is_string($modelRecord['defaultReasoningEffort'])
            ? (string) $modelRecord['defaultReasoningEffort']
            : null;

        // Auto-compactor: when the running prompt estimate reaches the
        // model's context window, the Loop folds older history into an
        // anchored summary instead of overflowing. Prefer the credential's
        // small_model_id when the operator set one (Kilo Tier 2.5) so the
        // compaction LLM call is cheap; fall back to the active model.
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $smallModel = is_string($settings['small_model_id'] ?? null) && (string) $settings['small_model_id'] !== ''
            ? (string) $settings['small_model_id']
            : $model;
        $compactor = new LlmCompactor($gateway, $smallModel);

        return new Loop(
            store: $store,
            registry: $registry,
            gateway: $gateway,
            systemPrompt: $systemPrompt,
            outputFilter: new OutputFilter(),
            options: new LoopOptions(
                approvalStore: $approvalStore,
                reasoningEffort: $reasoningEffort,
            ),
            approval: new HumanModalApprovalGate($this->permissionRuleset()),
            compactor: $compactor,
            activeProviderId: $providerId,
            activeModel: $model,
        );
    }

    /**
     * Build the per-turn PermissionRuleset (Kilo Tier 2.2). Merges, in
     * precedence order:
     *   1. wp_pfagent_permission_rules option (operator-configured)
     *   2. pfa_permission_rules filter (host-supplied at runtime)
     * The latter wins on key collisions so hosts can pin specific tool
     * verdicts above whatever the operator stored.
     *
     * When neither produces a non-empty map the ruleset is left empty
     * (effectively defaulting every side-effect tool to PENDING, the
     * pre-Sprint-D behaviour).
     */
    private function permissionRuleset(): PermissionRuleset
    {
        $stored = get_option('wp_pfagent_permission_rules', []);
        $rules = is_array($stored) ? $stored : [];

        if (function_exists('apply_filters')) {
            /**
             * Filter the permission ruleset before it's handed to the gate.
             * Hosts can append / override specific tool verdicts. The
             * filter receives the option-loaded array; return the
             * (possibly modified) array.
             *
             * @param array<string, mixed> $rules
             */
            $filtered = apply_filters('pfa_permission_rules', $rules);
            if (is_array($filtered)) {
                $rules = $filtered;
            }
        }

        return new PermissionRuleset($rules);
    }

    /**
     * Build a ModelCatalog for this turn from the credential's user-confirmed
     * per-model records (the wizard's output). Falls back to the legacy JSON
     * file only when the credential has no models[] saved yet, so existing
     * deployments keep working until their owner runs the wizard.
     *
     * @param array<string, mixed> $context  CredentialStore::runtime_context shape
     */
    private function buildCatalog(array $context): ?ModelCatalog
    {
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $models = is_array($settings['models'] ?? null) ? $settings['models'] : [];
        if ($models !== []) {
            return ModelCatalog::fromArray($models);
        }

        $path = WP_PFAGENT_DIR . 'config/model-catalog.json';
        if (!file_exists($path)) {
            return null;
        }
        return ModelCatalog::fromFile($path);
    }

    /**
     * Build a Registry by iterating agent-tools.json — exactly the same
     * surface AgentToolRegistry exposes today, but adapted to the Framework
     * Tool contract via FilterBridgeTool.
     */
    private function buildRegistry(): Registry
    {
        $registry = new Registry();
        $tools = $this->toolRegistry->tools();

        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $phpService = is_array($tool['phpService'] ?? null) ? $tool['phpService'] : [];
            $filter = (string) ($phpService['filter'] ?? '');
            $method = (string) ($phpService['method'] ?? $name);
            if ($filter === '') {
                continue;
            }

            // sideEffect comes from the agent-tools manifest. When the LLM
            // calls a side-effect tool, the Loop pauses for approval before
            // execution (host UI surfaces the confirmation dialog).
            $sideEffect = (bool) ($tool['sideEffect'] ?? $phpService['sideEffect'] ?? false);
            $idempotent = (bool) ($tool['idempotent'] ?? $phpService['idempotent'] ?? false);
            $argMapping = is_array($phpService['argMapping'] ?? null)
                ? array_values(array_map('strval', $phpService['argMapping']))
                : null;

            // Normalize the JSON Schema so empty `{}` objects (decoded as
            // PHP arrays) become stdClass — otherwise json_encode emits []
            // and OpenAI-compat providers (DeepSeek) reject the tool with
            // "Invalid schema for function ...: [] is not of type object".
            // The legacy AgentRuntime path consumed llm_tool_definitions()
            // which already normalised; the Framework path needs the same
            // treatment since it reads tools() raw.
            $rawParameters = is_array($tool['parameters'] ?? null) ? $tool['parameters'] : ['type' => 'object', 'properties' => new \stdClass()];
            $parameters = $this->toolRegistry->normalize_json_schema($rawParameters);
            if (!is_array($parameters)) {
                $parameters = ['type' => 'object', 'properties' => new \stdClass()];
            }

            $registry->register(new FilterBridgeTool(
                name: $name,
                description: (string) ($tool['description'] ?? ''),
                parameters: $parameters,
                filter: $filter,
                method: $method,
                sideEffect: $sideEffect,
                idempotent: $idempotent,
                stateExtractor: null,
                argMapping: $argMapping,
                strict: false,
                argumentShaper: self::argumentShaperFor($name, $filter),
                resultShaper: self::resultShaperFor($name),
            ));
        }

        return $registry;
    }

    /**
     * What the data-model tools send back to the agent.
     *
     * The tools reach wp-pfmanagement's service directly, so this is where
     * PFA decides how much of that service's answer is worth spending the
     * model's context on:
     *
     *  - the entity listing arrives as every entity's full definition (a
     *    third of a megabyte on a normal install) and leaves as the map of
     *    what exists — one line per entity;
     *  - single reads keep everything about that one entity EXCEPT the
     *    per-locale label maps, because the agent renders display values
     *    into the customer's language itself and has no use for fourteen
     *    pre-translated copies.
     *
     * Nothing changes in wp-pfmanagement: the same service answers the same
     * way, and its stored translations stay exactly where they are.
     */
    private static function resultShaperFor(string $toolName): ?\Closure
    {
        if ($toolName === 'pfm_list') {
            return static function (mixed $result, array $arguments): mixed {
                if (!is_array($result)) {
                    return $result;
                }
                $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
                if ($kind === 'entity') {
                    return ManagementApiBridge::entity_map($result);
                }
                if ($kind !== 'record') {
                    return $result;
                }
                return self::countableRecordList($result, $arguments);
            };
        }

        if ($toolName === 'pfm_get' || $toolName === 'pfm_get_contract') {
            return static fn(mixed $result, array $arguments): mixed => ManagementApiBridge::without_translations($result);
        }

        return null;
    }

    /**
     * Make a record list answer "how many" honestly, or say that it cannot.
     *
     * A record list is a PAGE: twenty-five rows unless someone asked for a
     * different number, and the envelope reports `count` (what came back) with
     * `truncated: false` — which reads as "that is all of them". Asked how many
     * tasks there were, the agent listed them, read 25, and told the customer
     * 25. There were 35. The number was never wrong in the model's arithmetic;
     * it was wrong in the answer it was given.
     *
     * So when a page fills up and nobody chose that page size, ask again for
     * the largest page the platform serves — that turns almost every real
     * counting question into an exact figure at the cost of one extra call,
     * paid only when the first page was full. When even that fills up, the
     * envelope says so in words, so "at least N" is the most the agent can
     * claim and it knows it.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function countableRecordList(array $result, array $arguments): array
    {
        // Mirrors wp-pfmanagement's record listing: 25 rows by default, 100 the
        // most it will serve for one entity, 200 across a whole sweep.
        $defaultPage = 25;
        $maxPage = 100;

        $filters = is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
        $askedFor = (int) ($filters['limit'] ?? 0);
        $count = (int) ($result['content']['count'] ?? 0);
        $truncated = (bool) ($result['content']['truncated'] ?? false);

        if ($askedFor <= 0 && !$truncated && $count >= $defaultPage) {
            $service = apply_filters('projectflash_management_agent_api', null);
            if (is_object($service) && is_callable([$service, 'agent_list'])) {
                try {
                    $wider = $service->agent_list('record', array_merge($filters, ['limit' => $maxPage]));
                } catch (\Throwable $e) {
                    $wider = null;
                }
                if (is_array($wider) && isset($wider['content']['count'])) {
                    $result = $wider;
                    $count = (int) $wider['content']['count'];
                    $truncated = (bool) ($wider['content']['truncated'] ?? false);
                    $askedFor = 0;
                }
            }
        }

        $cap = $askedFor > 0 ? min($maxPage, $askedFor) : $maxPage;
        $isCapped = $truncated || $count >= $cap;

        // The envelope carries the answer, not a number the reply has to
        // interpret: `total` when the query returned everything it matched,
        // and no total at all when it did not. Told "count: 100, truncated:
        // false" and warned in prose that it was only a page, the agent still
        // reported "exactly 100 entries" for a list of 239 — so the figure it
        // cannot support is no longer in front of it to quote.
        if (!$isCapped) {
            $result['content']['returned'] = $count;
            $result['content']['total'] = $count;
            $result['contextForYou'] = trim((string) ($result['contextForYou'] ?? '')) . ' '
                . sprintf('`total` is the whole answer to "how many": %d records match this query and nothing was cut.', $count);

            return $result;
        }

        unset($result['content']['count']);
        $result['content']['returned'] = $count;
        $result['content']['total'] = null;
        $result['content']['at_least'] = $count;
        $result['contextForYou'] = trim((string) ($result['contextForYou'] ?? '')) . ' '
            . sprintf(
                'There is no total here: this page filled up at %d and the rest was never fetched, so `total` is '
                . 'null on purpose. Never present %d as how many there are — either narrow the query until '
                . '`total` comes back, or tell the customer "more than %d" and say the list was cut.',
                $count,
                $count,
                $count
            );

        return $result;
    }

    /**
     * What the data-model tools receive before the service does.
     *
     * An entity write persists whatever label maps its payload carries, and
     * the agent's payloads no longer carry any — reads stopped including
     * them. Left alone, the first edit of an entity would wipe its
     * translations and its fields'. The stored maps are merged back in here,
     * under anything the agent did send.
     */
    private static function argumentShaperFor(string $toolName, string $filter): ?\Closure
    {
        if ($toolName !== 'pfm_apply') {
            return null;
        }

        return static function (array $arguments) use ($filter): array {
            // Every shape the tool documents ends up canonical here, so what
            // follows — and the service behind it — only ever sees one.
            $canonical = ManagementApiBridge::canonicalise_apply_arguments($arguments);
            $kind = (string) $canonical['kind'];
            $payload = (array) $canonical['payload'];
            if ($kind === '' || $payload === []) {
                return $arguments;
            }
            if ($kind === 'entity') {
                $payload = ManagementApiBridge::normalize_entity_payload($payload);
                $service = apply_filters($filter, null);
                if (is_object($service)) {
                    $payload = ManagementApiBridge::carry_translations_forward($service, $payload);
                }
            }

            return ['kind' => $kind, 'payload' => $payload, 'options' => (array) $canonical['options']];
        };
    }

    /**
     * Resolve the base URL for the active credential — handles preset
     * baseUrl templates with {{base_url}} (custom-* presets) by substituting
     * from the credential's settings.
     *
     * @param array<string, mixed> $context
     */
    private function resolveBaseUrl(array $context): string
    {
        $preset = is_array($context['preset'] ?? null) ? $context['preset'] : [];
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $template = (string) ($preset['baseUrl'] ?? '');
        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function (array $m) use ($settings): string {
            $key = sanitize_key((string) $m[1]);
            return array_key_exists($key, $settings) ? (string) $settings[$key] : (string) $m[0];
        }, $template);
    }

    /**
     * The operator-facing system prompt now lives in its own SystemPrompt
     * class (post-Sprint-C cleanup). Family-aware: hosts can register
     * different prompts per provider family via the
     * pfa_system_prompt_for_family filter (Kilo Tier 2.3).
     *
     * @param array<string, mixed> $context CredentialStore::runtime_context shape
     */
    private function systemPrompt(array $context = []): string
    {
        $family = (string) ($context['preset']['family'] ?? '');
        $prompt = SystemPrompt::forFamily($family);
        $map = self::entityMapSection();

        return $map === '' ? $prompt : $prompt . "\n\n" . $map;
    }

    /**
     * The install's entities, preloaded — one line each.
     *
     * Knowing WHAT exists is the answer to half the questions a customer
     * asks, and it used to cost a tool round that dumped every entity's full
     * definition into the context. It is small enough to just carry: name,
     * plural and slug, sorted by slug so the section is byte-identical from
     * one request to the next and the provider's prefix cache keeps hitting.
     * It changes only when the data model does. Nothing about fields lives
     * here — that is what reading one entity is for.
     */
    private static function entityMapSection(): string
    {
        if (!ManagementDependency::is_active()) {
            return '';
        }
        $service = apply_filters('projectflash_management_agent_api', null);
        if (!is_object($service) || !is_callable([$service, 'agent_entity_list'])) {
            return '';
        }
        try {
            $catalog = $service->agent_entity_list();
        } catch (\Throwable $e) {
            return '';
        }
        $content = is_array($catalog) ? ($catalog['content'] ?? null) : null;
        if (!is_array($content)) {
            return '';
        }

        $lines = [];
        foreach ($content as $item) {
            $entity = is_array($item) ? ($item['content']['entity'] ?? $item['entity'] ?? $item) : null;
            if (!is_array($entity)) {
                continue;
            }
            $slug = (string) ($entity['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $label = (string) ($entity['label'] ?? $slug);
            $plural = (string) ($entity['label_plural'] ?? '');
            $lines[$slug] = $plural !== '' && $plural !== $label
                ? sprintf('- %s — %s / %s', $slug, $label, $plural)
                : sprintf('- %s — %s', $slug, $label);
        }
        if ($lines === []) {
            return '';
        }
        ksort($lines);

        return "## Entities on this install\n\n"
            . "The data model of this site, by slug and display name. This is the whole list: an entity "
            . "that is not here does not exist, and you never need a listing call to find out what exists. "
            . "The fields, types, layout and number sequence of one of them come from reading that single "
            . "entity, on demand.\n\n"
            . implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(LoopResult $result, ?\wpdb $wpdb = null, string $turnStartIso = ''): array
    {
        $status = $this->mapSubtype($result->subtype);
        $confirmation = $result->confirmationToken === ''
            ? null
            : [
                'token' => $result->confirmationToken,
                'pendingCall' => $result->pendingToolCall,
            ];
        // Surface the pending tool's {name, arguments} at the top level for
        // the v1-shaped frontend (App.tsx renders the confirm modal from
        // result.tool). Empty when there's no pending call.
        $tool = null;
        if (is_array($result->pendingToolCall) && isset($result->pendingToolCall['name'])) {
            $tool = [
                'name' => (string) $result->pendingToolCall['name'],
                'arguments' => is_array($result->pendingToolCall['arguments'] ?? null)
                    ? $result->pendingToolCall['arguments']
                    : [],
            ];
        }

        $executions = $this->collectExecutions($wpdb, $result->conversationId, $turnStartIso);
        $assistantTexts = $this->collectAssistantTexts($wpdb, $result->conversationId, $turnStartIso);

        return [
            'status' => $status,
            'subtype' => $result->subtype,
            'conversationId' => $result->conversationId,
            'finalText' => $result->finalText,
            // v1-compat: the legacy /turn shape used `message` for the
            // assistant text, `confirmationId` + `tool` for the approval
            // payload, and `executions`/`timeline` for the tool trail. We
            // mirror them so the existing frontend renders v2 results
            // without needing a structural refactor of App.tsx.
            'message' => $result->finalText,
            // Every non-empty assistant bubble the Loop persisted in
            // this turn, in order. Frontend pushes each one as its own
            // chat bubble so the live chat matches what rehydration
            // shows when the operator reopens the session — no
            // hidden mid-loop narration ("Voy a hacer X", "Ahora creo
            // Y") that only surfaces on reload.
            'assistantTexts' => $assistantTexts,
            'confirmationId' => $result->confirmationToken,
            'tool' => $tool,
            'tools' => $tool !== null ? [$tool] : [],
            'evidence' => new \stdClass(),
            'executions' => $executions,
            'timeline' => [],
            // Named and explained, not dumped: `code` is the same vocabulary
            // provider health uses, `message` is a sentence the user can act
            // on, and the provider's raw text stays in errorMessage below for
            // support. It used to carry only the raw string, which reached the
            // chat as "LLM error: unknown — LLM HTTP 401: {json…}".
            'llmError' => $status === 'completed_with_response_error' && $result->errorMessage !== ''
                ? ProviderFailure::describe($result->errorMessage)
                : null,
            'rounds' => $result->rounds,
            'usage' => $result->usage,
            'costMicros' => $result->costMicros,
            'errorMessage' => $result->errorMessage,
            'confirmation' => $confirmation,
            // H5: true when the turn paused on its time budget with work still
            // pending. The host should immediately POST /agent-runtime/continue-v2
            // with this conversationId to resume — no user action, transparent.
            'continuation' => $result->subtype === LoopResult::SUBTYPE_PAUSED_TIME_BUDGET,
        ];
    }

    /**
     * Read every tool_call row this turn produced and shape it as the
     * frontend's AgentRuntimeExecution list. The Loop writes one row per
     * call into wp_pfaf_tool_calls (success or error) via WpDbStore::
     * logToolCall — we just read them back filtered to the turn window so
     * the chat surfaces a real "N execution(s)" disclosure block instead of
     * the empty array that lived here before.
     *
     * @return list<array<string, mixed>>
     */
    private function collectExecutions(?\wpdb $wpdb, string $conversationId, string $turnStartIso): array
    {
        if ($wpdb === null || $conversationId === '' || $turnStartIso === '') {
            return [];
        }
        $table = $wpdb->prefix . 'pfaf_tool_calls';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT tool_name, arguments_json, status, result_json, state_after_json,
                    error_code, error_message, duration_ms, started_at, ended_at
             FROM {$table}
             WHERE conversation_id = %s AND started_at >= %s
             ORDER BY seq ASC",
            $conversationId,
            $turnStartIso,
        ), ARRAY_A);
        if ($rows === []) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $args = json_decode((string) ($row['arguments_json'] ?? ''), true);
            $stateAfter = json_decode((string) ($row['state_after_json'] ?? ''), true);
            $resultPayload = json_decode((string) ($row['result_json'] ?? ''), true);
            $entry = [
                'tool' => [
                    'name' => (string) ($row['tool_name'] ?? ''),
                    'arguments' => is_array($args) ? $args : [],
                ],
                'evidence' => is_array($stateAfter) ? $stateAfter : new \stdClass(),
                'result' => $resultPayload,
                'diff' => null,
                'startedAt' => (string) ($row['started_at'] ?? ''),
                'endedAt' => (string) ($row['ended_at'] ?? ''),
                'durationMs' => (int) ($row['duration_ms'] ?? 0),
                'status' => ((string) ($row['status'] ?? '') === 'ok') ? 'success' : 'error',
            ];
            $errorCode = (string) ($row['error_code'] ?? '');
            $errorMessage = (string) ($row['error_message'] ?? '');
            if ($errorCode !== '') {
                $entry['errorCode'] = $errorCode;
            }
            if ($errorMessage !== '') {
                $entry['errorMessage'] = $errorMessage;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Read every non-empty assistant message the Loop persisted during
     * this turn so the frontend can render them in real time, in the
     * same order rehydration would show them. This is the missing
     * piece that produced the operator's confusion: during a live
     * chat the UI only saw the final assistant reply, but reopening
     * the session re-rendered every mid-loop narration ("Voy a hacer
     * X", "Ahora creo Y") and they looked like fresh content. Emit
     * them as part of the turn response so live == rehydrated.
     *
     * Each entry carries its `ordinal` so the frontend can
     * deduplicate against narrations that already arrived via the
     * /agent-runtime/progress polling stream — without ordinals, a
     * narration that the polling already surfaced would re-appear
     * at end-of-turn as a duplicate bubble.
     *
     * Empty content rows are skipped (those are the narration-less
     * tool-call rounds that have no surface text).
     *
     * @return list<array{ordinal: int, content: string, at: string}>
     */
    private function collectAssistantTexts(?\wpdb $wpdb, string $conversationId, string $turnStartIso): array
    {
        if ($wpdb === null || $conversationId === '' || $turnStartIso === '') {
            return [];
        }
        $table = $wpdb->prefix . 'pfaf_messages';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ordinal, content_json, created_at FROM {$table}
             WHERE conversation_id = %s AND role = 'assistant' AND created_at >= %s
             ORDER BY ordinal ASC",
            $conversationId,
            $turnStartIso,
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $contentRaw = $row['content_json'] ?? '';
            $content = is_string($contentRaw) ? (string) (json_decode($contentRaw, true) ?? '') : '';
            if ($content === '') {
                continue;
            }
            $out[] = [
                'ordinal' => (int) ($row['ordinal'] ?? 0),
                'content' => $content,
                'at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    private function mapSubtype(string $subtype): string
    {
        return match ($subtype) {
            LoopResult::SUBTYPE_SUCCESS => 'completed',
            LoopResult::SUBTYPE_NEEDS_CONFIRMATION => 'needs_confirmation',
            // H5: clean resumable pause — the host must continue the same
            // conversation (see the `continuation` flag in serialise()).
            LoopResult::SUBTYPE_PAUSED_TIME_BUDGET => 'paused',
            LoopResult::SUBTYPE_REFUSAL => 'refused',
            LoopResult::SUBTYPE_ERROR_MAX_TURNS => 'max_turns',
            LoopResult::SUBTYPE_ERROR_MAX_BUDGET => 'max_budget',
            LoopResult::SUBTYPE_ERROR_FINGERPRINT_LOOP => 'fingerprint_loop',
            LoopResult::SUBTYPE_ERROR_LLM => 'completed_with_response_error',
            default => 'completed',
        };
    }
}
