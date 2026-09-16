<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestApi
{
    private const NAMESPACE = 'wp-pfagent/v1';

    private ProviderPresets $presets;
    private CredentialStore $credentials;
    private ProviderModelDiscovery $models;
    private ProviderHealth $health;
    private ProviderRuntime $runtime;
    private AgentToolRegistry $agent_tools;
    private FrameworkRuntime $framework_runtime;
    private AgentContract $agent_contract;
    private AgentFixAdvisor $fix_advisor;
    private AgentInternalDocs $internal_docs;
    private RateLimiter $rate_limiter;
    private TraceLogger $trace_logger;
    private BetaReadiness $beta_readiness;

    public function __construct()
    {
        $this->presets = new ProviderPresets();
        $this->credentials = new CredentialStore($this->presets);
        $this->models = new ProviderModelDiscovery($this->credentials, $this->presets);
        $this->health = new ProviderHealth($this->models, $this->credentials, $this->presets);
        $this->runtime = new ProviderRuntime($this->credentials, $this->models);
        $this->agent_tools = new AgentToolRegistry();
        $this->trace_logger = new TraceLogger();
        $this->framework_runtime = new FrameworkRuntime($this->credentials, $this->presets, $this->agent_tools);
        $this->agent_contract = new AgentContract($this->presets, $this->agent_tools);
        $this->fix_advisor = new AgentFixAdvisor();
        $this->internal_docs = new AgentInternalDocs($this->agent_contract);
        $this->rate_limiter = new RateLimiter();
        $this->beta_readiness = new BetaReadiness($this->agent_contract, $this->health, $this->credentials);
    }

    private function enforce_rate(string $bucket): ?WP_Error
    {
        $result = $this->rate_limiter->consume($bucket);
        if ($result instanceof WP_Error) {
            $this->trace_logger->log(
                $this->trace_logger->new_trace_id(),
                TraceLogger::KIND_RATE_LIMIT,
                TraceLogger::STATUS_RATE_LIMITED,
                [
                    'bucket' => $bucket,
                    'errorCode' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'httpStatus' => 429,
                ]
            );

            return $result;
        }

        return null;
    }

    private function enforce_body_size(WP_REST_Request $request, int $max_bytes = 524288): ?WP_Error
    {
        $size = strlen((string) $request->get_body());
        if ($size > $max_bytes) {
            $error = new WP_Error(
                'pfa_payload_too_large',
                sprintf(
                    /* translators: 1: maximum bytes allowed, 2: actual bytes received */
                    __('Request body exceeds %1$d bytes (got %2$d).', 'wp-pfagent'),
                    $max_bytes,
                    $size
                ),
                ['status' => 413, 'maxBytes' => $max_bytes, 'received' => $size]
            );
            $this->trace_logger->log(
                $this->trace_logger->new_trace_id(),
                TraceLogger::KIND_BODY_SIZE,
                TraceLogger::STATUS_FAILED,
                [
                    'errorCode' => 'pfa_payload_too_large',
                    'message' => __('oversized request body', 'wp-pfagent'),
                    'httpStatus' => 413,
                    'received' => $size,
                ]
            );

            return $error;
        }

        return null;
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/contract', [
            'methods' => 'GET',
            'callback' => [$this, 'contract'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/contract/openapi', [
            'methods' => 'GET',
            'callback' => [$this, 'openapi_contract'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-presets', [
            'methods' => 'GET',
            'callback' => [$this, 'provider_presets'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials', [
            'methods' => 'GET',
            'callback' => [$this, 'credential_statuses'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials/(?P<provider>[a-z0-9-]+)', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_credential'],
                'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
                'args' => [
                    'provider' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_credential'],
                'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
                'args' => [
                    'provider' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials/(?P<provider>[a-z0-9-]+)/rotate', [
            'methods' => 'POST',
            'callback' => [$this, 'save_credential'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials/(?P<provider>[a-z0-9-]+)/test', [
            'methods' => 'POST',
            'callback' => [$this, 'test_credential'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-models/(?P<provider>[a-z0-9-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'provider_models'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'force' => [
                    'required' => false,
                    'type' => 'boolean',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-models/(?P<provider>[a-z0-9-]+)/manual', [
            'methods' => 'POST',
            'callback' => [$this, 'save_manual_provider_models'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-models/(?P<provider>[a-z0-9-]+)/save', [
            'methods' => 'POST',
            'callback' => [$this, 'save_provider_models'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-health/(?P<provider>[a-z0-9-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'provider_health'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-runtime/(?P<provider>[a-z0-9-]+)/smoke', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_smoke'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/tools', [
            'methods' => 'GET',
            'callback' => [$this, 'agent_tools'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/internal-docs', [
            'methods' => 'GET',
            'callback' => [$this, 'agent_internal_docs'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/fix-suggestions', [
            'methods' => 'POST',
            'callback' => [$this, 'agent_fix_suggestions'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        // Framework Loop-backed turn route. The /agent-runtime/turn (v1)
        // route, plus the /llm/{provider}/{complete,stream,tool-call} routes
        // that fed it, were removed during the Sprint C cutover.
        register_rest_route(self::NAMESPACE, '/agent-runtime/turn-v2', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'agent_turn_v2'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/resume-v2', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'agent_resume_v2'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        // H5: continue a turn that paused on its wall-clock time budget
        // (response `continuation: true`, status `paused`). No verdict, no
        // token — just the conversationId + the same provider/model. The host
        // calls this transparently until the response is no longer a pause, so
        // long multi-round work spans several short requests instead of one
        // that fatals on max_execution_time.
        register_rest_route(self::NAMESPACE, '/agent-runtime/continue-v2', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'agent_continue_v2'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        // Progress polling: the frontend hits this while waiting on
        // /turn-v2 (synchronous request that may take 1-2 minutes for
        // multi-round tool work). Returns the latest tool calls and
        // assistant rounds since the supplied cursor so the chat can
        // surface a localised, customer-facing "working on it" trace
        // ("Analyzing the data model…", "Implementing the logic…") that
        // updates as the agent makes progress. Read-only, no rate-limit
        // concern beyond the standard auth gate.
        register_rest_route(self::NAMESPACE, '/agent-runtime/progress', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'agent_progress'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        // Permission ruleset CRUD. Operators paste / edit the JSON; the
        // server validates against PermissionRuleset's schema before
        // storing in wp_pfagent_permission_rules. FrameworkRuntime
        // re-reads on every turn so changes apply without reload.
        register_rest_route(self::NAMESPACE, '/agent-runtime/permission-rules', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_permission_rules'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'save_permission_rules'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/support-export', [
            'methods' => 'GET',
            'callback' => [$this, 'support_export'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/metrics', [
            'methods' => 'GET',
            'callback' => [$this, 'agent_metrics'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/beta-readiness', [
            'methods' => 'GET',
            'callback' => [$this, 'beta_readiness'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        // /vfs/active-preview, /vfs/apply, /vfs/discard removed: the VFS
        // no longer carries a separate draft/preview layer — write_file
        // persists straight into wp-pfworkflow as a draft, and the
        // activate_workflow agent tool (sideEffect=true) promotes it
        // to active. Nothing left to preview, apply, or discard.

        register_rest_route(self::NAMESPACE, '/vfs/library-refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'vfs_library_refresh'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        // Active LLM selection. Single source of truth for which provider +
        // model the operator picked in the wizard. Persisted server-side so
        // any plugin (e.g. wp-pfmanagement) can read it without depending
        // on the browser. The frontend reads on mount and writes on change.
        register_rest_route(self::NAMESPACE, '/active-llm', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_active_llm'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'save_active_llm'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);
    }

    public function vfs_library_refresh(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('writes')) {
            return $error;
        }
        // The VFS library is the compiler's authoring surface. It only exists
        // when the Setyenv-suite half is loaded; a standalone distribution omits
        // those files, so this endpoint degrades to an honest no-op instead of
        // fatally calling absent classes.
        if (!class_exists('\\ProjectFlash\\Agent\\Sourcecode\\LibraryBuilder')) {
            return rest_ensure_response([
                'schema' => 'projectflash.agent.vfs.library_refresh',
                'schemaVersion' => 1,
                'available' => false,
                'nodesBytes' => 0,
                'manageBytes' => 0,
                'workflowsBackfilled' => 0,
                'templatesBackfilled' => 0,
            ]);
        }
        // Each plugin owns its TS — ask them to rebuild, then pull.
        if (class_exists('\\ProjectFlash\\Workflow\\Agent\\TypingsBuilder')) {
            \ProjectFlash\Workflow\Agent\TypingsBuilder::rebuild();
        }
        if (class_exists('\\ProjectFlash\\Management\\Agent\\TypingsBuilder')) {
            \ProjectFlash\Management\Agent\TypingsBuilder::rebuild();
        }
        $nodes = Sourcecode\LibraryBuilder::nodesLibrary();
        $manage = Sourcecode\LibraryBuilder::manageLibrary();
        $backfill = Sourcecode\DecompileCache::backfill();
        $templates = Sourcecode\TemplateDecompileCache::backfill();
        return rest_ensure_response([
            'schema' => 'projectflash.agent.vfs.library_refresh',
            'schemaVersion' => 1,
            'nodesBytes' => strlen($nodes),
            'manageBytes' => strlen($manage),
            'workflowsBackfilled' => $backfill,
            'templatesBackfilled' => $templates,
        ]);
    }

    public function beta_readiness(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        return rest_ensure_response($this->beta_readiness->evaluate());
    }

    public function provider_presets(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $catalog = $this->presets->catalog();
        if ($catalog instanceof WP_Error) {
            return $catalog;
        }

        return rest_ensure_response($catalog);
    }

    public function contract(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $contract = $this->agent_contract->build();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        return rest_ensure_response($contract);
    }

    public function openapi_contract(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $contract = $this->agent_contract->openapi();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        return rest_ensure_response($contract);
    }

    public function credential_statuses(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        return rest_ensure_response(['credentials' => $this->credentials->statuses()]);
    }

    public function save_credential(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $provider_id = sanitize_key((string) $request['provider']);
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $api_key = (string) ($params['apiKey'] ?? $params['api_key'] ?? '');
        $settings = is_array($params['settings'] ?? null) ? $params['settings'] : [];
        $status = $this->credentials->save($provider_id, $api_key, $settings);
        if ($status instanceof WP_Error) {
            return $status;
        }

        return new WP_REST_Response($status, 200);
    }

    public function delete_credential(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $status = $this->credentials->delete(sanitize_key((string) $request['provider']));
        if ($status instanceof WP_Error) {
            return $status;
        }

        return rest_ensure_response($status);
    }

    public function test_credential(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $this->health->check(sanitize_key((string) $request['provider']));
        $status = $this->credentials->status(sanitize_key((string) $request['provider']));
        if ($status instanceof WP_Error) {
            return $status;
        }

        return rest_ensure_response($status);
    }

    public function provider_models(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $result = $this->models->discover(sanitize_key((string) $request['provider']), (bool) $request->get_param('force'));
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function save_manual_provider_models(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $params = $request->get_json_params();
        $models = is_array($params) && is_array($params['models'] ?? null) ? $params['models'] : [];
        $result = $this->models->save_manual_models(sanitize_key((string) $request['provider']), $models);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Persist the wizard's confirmed per-model configuration into the
     * credential's settings.models[]. This is what runtime gateways read
     * to discover caps + pricing + features — i.e. the user-owned source
     * of truth, parallel to the user's API key. See CredentialStore::save_models.
     */
    public function save_provider_models(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $params = $request->get_json_params();
        $models = is_array($params) && is_array($params['models'] ?? null) ? $params['models'] : [];
        $result = $this->credentials->save_models(sanitize_key((string) $request['provider']), $models);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function provider_health(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $provider_id = sanitize_key((string) $request['provider']);
        // Every sibling route (test, delete, save, smoke, models) answers 404
        // for a provider that does not exist. This one reported 200 with
        // status "failed", which reads as "your provider is broken" when the
        // truth is "there is no such provider".
        if ($this->presets->preset($provider_id) === null) {
            return new WP_Error(
                'pfa_provider_unknown',
                __('Provider preset was not found.', 'wp-pfagent'),
                ['status' => 404, 'providerId' => $provider_id]
            );
        }

        return rest_ensure_response($this->health->check($provider_id));
    }

    public function generate_smoke(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('llm')) {
            return $error;
        }
        $result = $this->runtime->generate_smoke(sanitize_key((string) $request['provider']));
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function agent_tools(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        return rest_ensure_response(['tools' => $this->agent_tools->tools()]);
    }

    public function agent_internal_docs(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $docs = $this->internal_docs->build();
        if ($docs instanceof WP_Error) {
            return $docs;
        }

        return rest_ensure_response($docs);
    }

    public function agent_fix_suggestions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }

        return rest_ensure_response($this->fix_advisor->suggest($this->json_params($request)));
    }

    public function support_export(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        $contract = $this->agent_contract->build();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        global $wp_version;
        // The traces are a PERSON's, so ask for the person.
        $current = \ProjectFlash\Agent\PersonColumns::current();
        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $recent = $this->trace_logger->query(['user_id' => $current, 'limit' => 50]);
        $failures = $this->trace_logger->query(['user_id' => $current, 'status' => TraceLogger::STATUS_FAILED, 'limit' => 20]);
        $aggregate = $this->trace_logger->aggregate(['user_id' => $current, 'since' => $since]);

        $providers = [];
        foreach ($this->credentials->statuses() as $status) {
            $providers[] = [
                'providerId' => $status['providerId'] ?? '',
                'family' => $status['family'] ?? '',
                'status' => $status['status'] ?? '',
                'configured' => (bool) ($status['configured'] ?? false),
                'maskedKey' => $status['maskedKey'] ?? null,
                'configuredAt' => $status['configuredAt'] ?? null,
                'updatedAt' => $status['updatedAt'] ?? null,
                'validationMessage' => $status['validationMessage'] ?? '',
            ];
        }

        return rest_ensure_response([
            'schema' => 'projectflash.agent.support_export',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'agent' => [
                'schema' => $contract['schema'] ?? '',
                'version' => $contract['plugin']['version'] ?? '',
                'namespace' => $contract['plugin']['namespace'] ?? '',
                'capabilityCount' => is_array($contract['capabilities'] ?? null) ? count($contract['capabilities']) : 0,
                'agentToolCount' => is_array($contract['agentTools'] ?? null) ? count($contract['agentTools']) : 0,
                'routeCount' => is_array($contract['routes'] ?? null) ? count($contract['routes']) : 0,
            ],
            'workflow' => $contract['workflowDependency'] ?? ['active' => false],
            'environment' => [
                'wpVersion' => (string) ($wp_version ?? ''),
                'phpVersion' => PHP_VERSION,
                'locale' => function_exists('get_locale') ? get_locale() : '',
                'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : '',
                'multisite' => is_multisite(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
            ],
            'providers' => $providers,
            'metrics' => [
                'window' => '24h',
                'since' => $since,
                'totals' => $aggregate,
            ],
            'recentTraces' => $recent,
            'recentFailures' => $failures,
            'security' => [
                'secretsInExport' => false,
                'credentialValuesExposed' => false,
            ],
        ]);
    }

    public function agent_metrics(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        // The traces are a PERSON's, so ask for the person.
        $current = \ProjectFlash\Agent\PersonColumns::current();
        $window_hours = max(1, min(168, (int) ($request->get_param('windowHours') ?? 24)));
        $since = gmdate('Y-m-d H:i:s', time() - $window_hours * HOUR_IN_SECONDS);

        return rest_ensure_response([
            'schema' => 'projectflash.agent.metrics',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'windowHours' => $window_hours,
            'since' => $since,
            'totals' => $this->trace_logger->aggregate(['user_id' => $current, 'since' => $since]),
        ]);
    }

    public function agent_turn_v2(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('agent_turn')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $started_at = microtime(true);
        $result = $this->framework_runtime->turn($params);
        if ($result instanceof WP_Error) {
            return $result;
        }
        $this->log_turn_metric($params, $result, $started_at);

        return rest_ensure_response($result);
    }

    /**
     * Emit one summary row into pfa_trace_log per completed turn so the
     * /agent-runtime/metrics aggregation (which reads pfa_trace_log) reflects
     * real agent activity. The Sprint C cutover moved per-round telemetry to
     * the Framework store (pfaf_traces) but never re-pointed the metrics
     * aggregation nor emitted a per-turn summary, so metrics read 0 rows for
     * every real turn. This restores the agent_turn count + token/cost totals
     * without touching the per-round trace surface.
     *
     * @param array<string, mixed> $params The turn input (providerId, model).
     * @param array<string, mixed> $result The serialised turn result.
     */
    private function log_turn_metric(array $params, array $result, float $started_at): void
    {
        $subtype = (string) ($result['subtype'] ?? '');
        $status = match (true) {
            $subtype === 'success' => TraceLogger::STATUS_SUCCESS,
            $subtype === 'needs_confirmation' => TraceLogger::STATUS_NEEDS_CONFIRMATION,
            default => TraceLogger::STATUS_FAILED,
        };
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $this->trace_logger->log(
            $this->trace_logger->new_trace_id(),
            TraceLogger::KIND_AGENT_TURN,
            $status,
            [
                'providerId' => sanitize_key((string) ($params['providerId'] ?? '')),
                'tokensTotal' => (int) ($usage['totalTokens'] ?? 0),
                'costMicros' => (int) ($result['costMicros'] ?? 0),
                'durationMs' => (int) round((microtime(true) - $started_at) * 1000),
                'context' => [
                    'conversationId' => (string) ($result['conversationId'] ?? ''),
                    'rounds' => (int) ($result['rounds'] ?? 0),
                    'model' => (string) ($params['model'] ?? ''),
                ],
            ]
        );
    }

    public function get_permission_rules(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $stored = get_option('wp_pfagent_permission_rules', []);
        return rest_ensure_response([
            'rules' => is_array($stored) ? $stored : [],
            'updatedAt' => (string) get_option('wp_pfagent_permission_rules_updated_at', ''),
        ]);
    }

    public function save_permission_rules(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $rules = is_array($params['rules'] ?? null) ? $params['rules'] : null;
        if ($rules === null) {
            return new WP_Error('pfa_permission_rules_invalid', __('rules must be a JSON object.', 'wp-pfagent'), ['status' => 400]);
        }
        // Sanity-check: PermissionRuleset tolerates unknown shapes (verdicts
        // outside allow/deny/ask are simply ignored at evaluate time), so
        // we accept whatever the operator pastes. The validation is
        // primarily about JSON shape, not content.
        $now = gmdate('c');
        update_option('wp_pfagent_permission_rules', $rules, false);
        update_option('wp_pfagent_permission_rules_updated_at', $now, false);
        return rest_ensure_response([
            'rules' => $rules,
            'updatedAt' => $now,
        ]);
    }

    public function get_active_llm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $stored = get_option('wp_pfagent_active_llm', []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return rest_ensure_response([
            'providerId' => (string) ($stored['providerId'] ?? ''),
            'model' => (string) ($stored['model'] ?? ''),
            'sessionId' => isset($stored['sessionId']) ? (string) $stored['sessionId'] : null,
            'updatedAt' => (string) ($stored['updatedAt'] ?? ''),
        ]);
    }

    public function save_active_llm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $providerId = sanitize_key((string) ($params['providerId'] ?? ''));
        $model = sanitize_text_field((string) ($params['model'] ?? ''));

        // This option IS the operator's choice of engine, and the whole product
        // reads it. A body without both fields — or a body that is not even an
        // object, which json_params() flattens to [] — used to answer 200 and
        // overwrite the real selection with an empty one: after that the chat
        // came up with no engine and nothing said why. Refuse instead.
        if ($providerId === '' || $model === '') {
            return new WP_Error(
                'pfa_active_llm_invalid',
                __('providerId and model are required.', 'wp-pfagent'),
                ['status' => 400]
            );
        }
        if ($this->presets->preset($providerId) === null) {
            return new WP_Error(
                'pfa_provider_unknown',
                __('Provider preset was not found.', 'wp-pfagent'),
                ['status' => 404, 'providerId' => $providerId]
            );
        }

        $sessionId = isset($params['sessionId']) && $params['sessionId'] !== null
            ? (string) $params['sessionId']
            : null;
        $now = gmdate('c');
        $payload = [
            'providerId' => $providerId,
            'model' => $model,
            'sessionId' => $sessionId,
            'updatedAt' => $now,
        ];
        update_option('wp_pfagent_active_llm', $payload, false);
        return rest_ensure_response($payload);
    }

    public function agent_resume_v2(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('agent_turn')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $conversationId = trim((string) ($params['conversationId'] ?? ''));
        $token = (string) ($params['confirmationToken'] ?? '');
        $approved = (bool) ($params['approved'] ?? false);
        $providerId = (string) ($params['providerId'] ?? '');
        $model = (string) ($params['model'] ?? '');

        if ($conversationId === '' || $token === '' || $providerId === '' || $model === '') {
            return new WP_Error('pfa_resume_invalid', __('conversationId, confirmationToken, providerId and model are required.', 'wp-pfagent'), ['status' => 400]);
        }

        $started_at = microtime(true);
        $result = $this->framework_runtime->resume($conversationId, $token, $approved, $providerId, $model);
        if ($result instanceof WP_Error) {
            return $result;
        }
        $this->log_turn_metric($params, $result, $started_at);

        return rest_ensure_response($result);
    }

    /**
     * H5: continue a turn that paused on its time budget. Same auth + rate gate
     * as a turn; no confirmation token — just conversationId + provider/model.
     */
    public function agent_continue_v2(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('agent_turn')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $conversationId = trim((string) ($params['conversationId'] ?? ''));
        $providerId = (string) ($params['providerId'] ?? '');
        $model = (string) ($params['model'] ?? '');

        if ($conversationId === '' || $providerId === '' || $model === '') {
            return new WP_Error('pfa_continue_invalid', __('conversationId, providerId and model are required.', 'wp-pfagent'), ['status' => 400]);
        }

        $started_at = microtime(true);
        $result = $this->framework_runtime->continueTurn($conversationId, $providerId, $model);
        if ($result instanceof WP_Error) {
            return $result;
        }
        $this->log_turn_metric($params, $result, $started_at);

        return rest_ensure_response($result);
    }

    /**
     * Live-progress poll: returns whatever tool calls and assistant rounds
     * for the conversation have landed since the cursors the frontend
     * sends. Used by the chat to surface a customer-facing "working on
     * it" trace while /turn-v2 is still executing synchronously. The
     * shape is intentionally minimal — names and timestamps only, no
     * internal arguments or payloads, because the customer must not see
     * implementation details (per the system prompt's hard rule).
     */
    public function agent_progress(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $conversationId = trim((string) ($request->get_param('conversationId') ?? ''));
        if ($conversationId === '') {
            return new WP_Error('pfa_progress_invalid', __('conversationId is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $sinceToolCallSeq = max(0, (int) ($request->get_param('sinceToolCallSeq') ?? 0));
        $sinceTraceSeq = max(0, (int) ($request->get_param('sinceTraceSeq') ?? 0));
        // sinceMessageOrdinal lets the frontend stream new assistant
        // narrations bubble-by-bubble during a long turn instead of
        // waiting for /turn-v2 to return. -1 means "I haven't seen any
        // yet"; the value the poll returns in lastMessageOrdinal is
        // the cursor for the next request.
        $sinceMessageOrdinal = max(-1, (int) ($request->get_param('sinceMessageOrdinal') ?? -1));

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return new WP_Error('pfa_progress_db', __('Database unavailable.', 'wp-pfagent'), ['status' => 500]);
        }
        $tc = $wpdb->prefix . 'pfaf_tool_calls';
        $tr = $wpdb->prefix . 'pfaf_traces';
        $msgs = $wpdb->prefix . 'pfaf_messages';

        $tool_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT seq, tool_name, status, started_at, arguments_json, result_json FROM {$tc}
             WHERE conversation_id = %s AND seq > %d
             ORDER BY seq ASC LIMIT 50",
            $conversationId,
            $sinceToolCallSeq
        ), ARRAY_A);

        $trace_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT seq, kind, created_at FROM {$tr}
             WHERE conversation_id = %s AND seq > %d
               AND kind IN ('llm_round', 'compaction_applied')
             ORDER BY seq ASC LIMIT 50",
            $conversationId,
            $sinceTraceSeq
        ), ARRAY_A);

        // New assistant narrations since the cursor. Mid-loop rows the
        // Loop persists between tool rounds — "Voy a hacer X", "Ahora
        // creo Y" — must surface live so the operator sees the agent's
        // thinking as it happens, not as a burst at end-of-turn. We
        // mirror serialize_full's filter: role=assistant + non-empty
        // content (rows with only tool_calls are implementation
        // detail).
        $msg_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ordinal, content_json, created_at FROM {$msgs}
             WHERE conversation_id = %s AND role = 'assistant' AND ordinal > %d
             ORDER BY ordinal ASC LIMIT 50",
            $conversationId,
            $sinceMessageOrdinal
        ), ARRAY_A);

        $tools = array_map(static function (array $r): array {
            $args = json_decode((string) ($r['arguments_json'] ?? ''), true);
            $res = json_decode((string) ($r['result_json'] ?? ''), true);
            $argsArr = is_array($args) ? $args : [];
            $resArr = is_array($res) ? $res : [];
            // Compact focus payload: kind/ref for pfm tools,
            // workflowId/path for VFS tools. Lets the frontend rebuild
            // the iframe URL without a second fetch.
            $focus = [];
            foreach (['kind', 'ref', 'path'] as $k) {
                if (isset($argsArr[$k]) && is_scalar($argsArr[$k])) {
                    $focus[$k] = (string) $argsArr[$k];
                }
            }
            // pfm_apply doesn't carry `ref` at the top level — the slug
            // / sys_id lives in payload, with a shape that varies per
            // kind. Without this extraction the iframe URL had
            // kind=entity but no ref, and the pfm SPA stayed on its
            // landing page ("Bienvenido") instead of focusing on the
            // entity / record the agent just touched. Mirror the same
            // unwrap the frontend's buildPreviewTargetFromExecution
            // does so the pfm tab dances with the agent's activity.
            if (
                ($r['tool_name'] ?? '') === 'pfm_apply'
                && (!isset($focus['ref']) || $focus['ref'] === '')
            ) {
                $payload = isset($argsArr['payload']) && is_array($argsArr['payload']) ? $argsArr['payload'] : null;
                $kind = $focus['kind'] ?? '';
                if (is_array($payload) && $kind !== '') {
                    $ref = '';
                    if ($kind === 'record') {
                        // record: { entity: 'slug', values: {...}, sys_id? }
                        $eslug = isset($payload['entity']) && is_string($payload['entity']) ? $payload['entity'] : '';
                        $sysId = '';
                        foreach (['sys_id', 'id'] as $k) {
                            if (isset($payload[$k]) && is_scalar($payload[$k]) && (string) $payload[$k] !== '') {
                                $sysId = (string) $payload[$k];
                                break;
                            }
                        }
                        if ($eslug !== '' && $sysId !== '') {
                            $ref = $eslug . ':' . $sysId;
                        } elseif ($eslug !== '') {
                            // No sys_id yet (create record): point the
                            // tab at the entity's record list. Better
                            // than leaving the SPA on landing.
                            $ref = $eslug;
                        }
                    } else {
                        // entity / action / application / module /
                        // group / role / page: payload[kind].slug, with
                        // a legacy bare-payload.slug fallback.
                        $inner = isset($payload[$kind]) && is_array($payload[$kind]) ? $payload[$kind] : $payload;
                        if (isset($inner['slug']) && is_string($inner['slug'])) {
                            $ref = $inner['slug'];
                        }
                    }
                    if ($ref !== '') {
                        $focus['ref'] = $ref;
                    }
                }
            }
            // Opaque ids (P4.2): numeric today, uuid after the PFW cutover.
            // is_numeric() here used to DROP a uuid focus silently - the same
            // int-truncation class the BR migration exposed.
            $focus_wid = WorkflowDependency::normalize_workflow_id($resArr['workflowId'] ?? '');
            $focus_wid_content = WorkflowDependency::normalize_workflow_id($resArr['content']['workflowId'] ?? '');
            $focus_wid_br = WorkflowDependency::normalize_workflow_id($resArr['content']['business_rule']['workflow_id'] ?? '');
            if ($focus_wid !== '') {
                $focus['workflowId'] = WorkflowDependency::workflow_id_payload($focus_wid);
            } elseif ($focus_wid_content !== '') {
                // The VFS tools (write_file / edit_file / read) wrap their return
                // under `content`, so the id of the workflow the agent just wrote
                // lands at content.workflowId — NOT the top level. Surface it so the
                // Workflow tab focuses the graph as the agent authors it (create and
                // every subsequent edit), both live and at end-of-turn. Without this
                // the tab stayed on "Waiting for the agent" through a whole build.
                $focus['workflowId'] = WorkflowDependency::workflow_id_payload($focus_wid_content);
            } elseif ($focus_wid_br !== '') {
                // A workflow born from a Business Rule carries its id nested under
                // the BR content; surface it so the Workflow tab focuses the live
                // graph as it evolves.
                $focus['workflowId'] = WorkflowDependency::workflow_id_payload($focus_wid_br);
            }
            // Cross-cutting WordPress layer: derive the live wp-admin focus
            // target for wp_*/wc_*/seo_*/forms_* tools so Susan's "WordPress"
            // tab points at the touched screen live (progress poll), the same
            // way workflowId drives the Workflow tab. Additive channel — it
            // never touches the workflowId/kind/ref reflection above.
            $wpTarget = self::wp_focus_target((string) ($r['tool_name'] ?? ''), $argsArr, $resArr);
            if ($wpTarget !== null) {
                $focus['wpTarget'] = $wpTarget;
            }
            return [
                'seq' => (int) $r['seq'],
                'tool' => (string) $r['tool_name'],
                'status' => (string) $r['status'],
                'at' => (string) $r['started_at'],
                'focus' => $focus,
            ];
        }, $tool_rows);

        $traces = array_map(static fn(array $r): array => [
            'seq' => (int) $r['seq'],
            'kind' => (string) $r['kind'],
            'at' => (string) $r['created_at'],
        ], $trace_rows);

        // Drop empty-content rows here (the LIMIT above already trims
        // them on the next poll because their ordinal advances the
        // cursor regardless). The bumped cursor MUST cover every row
        // we saw, including empties, so the next poll doesn't refetch
        // them.
        $assistantTexts = [];
        foreach ($msg_rows as $row) {
            $ord = (int) ($row['ordinal'] ?? 0);
            $raw = $row['content_json'] ?? '';
            $content = is_string($raw) ? (string) (json_decode($raw, true) ?? '') : '';
            if ($content === '') {
                continue;
            }
            $assistantTexts[] = [
                'ordinal' => $ord,
                'content' => $content,
                'at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        // lastMessageOrdinal must be the TRUE max(ordinal) for this
        // conversation regardless of the LIMIT 50 cap on $msg_rows.
        // Fallback is -1 (no rows yet) — NOT $sinceMessageOrdinal:
        // if the frontend's warm-up call sends a high probe value
        // (e.g. 2147483647 to bypass the LIMIT and just learn the
        // cursor) and the conversation is empty, echoing the probe
        // back as the cursor pins lastSeenNarrationOrdinalRef sky-
        // high in the chat and every subsequent narration on that
        // conversation is filtered out as "already seen". Empty
        // conv MUST return -1 so the chat starts from the real
        // beginning. MAX is monotonic on insert (no row deletion
        // mid-turn), so no "don't go backwards" guard is needed.
        $lastMessageOrdinalSeen = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(ordinal), -1) FROM {$msgs} WHERE conversation_id = %s",
            $conversationId
        ));

        $lastToolCallSeq = $tools === [] ? $sinceToolCallSeq : (int) end($tools)['seq'];
        $lastTraceSeq = $traces === [] ? $sinceTraceSeq : (int) end($traces)['seq'];

        return rest_ensure_response([
            'conversationId' => $conversationId,
            'tools' => $tools,
            'traces' => $traces,
            'assistantTexts' => $assistantTexts,
            'lastToolCallSeq' => $lastToolCallSeq,
            'lastTraceSeq' => $lastTraceSeq,
            'lastMessageOrdinal' => $lastMessageOrdinalSeen,
        ]);
    }

    /**
     * Derive the live "WordPress" tab focus target for a cross-cutting tool
     * call (wp_* / wc_* / seo_* / forms_*), mirroring the frontend's
     * describeWpExecution() screen mapping in wpTarget.ts so the tab points at
     * the same wp-admin screen LIVE (progress poll) as it resolves at
     * end-of-turn from executions[]. Additive + self-contained: it inspects
     * only the transversal tool surface and never references any suite
     * (pfm/pfw) tool, mirroring the isolation of the WP-core module itself.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $res
     * @return array{screen:string, postType?:string, id?:int}|null
     */
    private static function wp_focus_target(string $tool, array $args, array $res): ?array
    {
        $isWp = false;
        foreach (['wp_', 'wc_', 'seo_', 'forms_', 'ld_', 'mp_'] as $p) {
            if (str_starts_with($tool, $p)) { $isWp = true; break; }
        }
        if (!$isWp) {
            return null;
        }

        // Object id: result top-level, then nested result.post/user.id, then args.
        $id = null;
        foreach (['id', 'post', 'user'] as $k) {
            if (isset($res[$k]) && is_numeric($res[$k])) { $id = (int) $res[$k]; break; }
            if (isset($res[$k]) && is_array($res[$k]) && isset($res[$k]['id']) && is_numeric($res[$k]['id'])) {
                $id = (int) $res[$k]['id']; break;
            }
        }
        if ($id === null) {
            foreach (['id', 'post_id', 'postId', 'user_id', 'order_id', 'comment_id', 'product_id', 'course_id', 'membership_id', 'menu_id'] as $k) {
                if (isset($args[$k]) && is_numeric($args[$k])) { $id = (int) $args[$k]; break; }
            }
        }

        $postType = '';
        foreach ([$args['post_type'] ?? null, $res['type'] ?? null] as $c) {
            if (is_string($c) && $c !== '') { $postType = $c; break; }
        }
        if ($postType === '') { $postType = 'post'; }

        $mk = static function (string $screen, ?string $pt = null, ?int $oid = null): array {
            $out = ['screen' => $screen];
            if ($pt !== null && $pt !== '') { $out['postType'] = $pt; }
            if ($oid !== null) { $out['id'] = $oid; }
            return $out;
        };

        switch ($tool) {
            case 'wp_post_create':
            case 'wp_post_update':
            case 'wp_post_get':
            case 'wp_post_meta_set':
            case 'wp_term_assign':
                return $mk('edit', $postType, $id);
            case 'wp_post_trash':
            case 'wp_post_list':
                return $mk('edit', $postType);
            case 'wp_taxonomy_list':
            case 'wp_term_create': {
                // Terms live on edit-tags.php, NOT the posts list. Carry the
                // taxonomy (and its owning post type, so wp-admin scopes the
                // screen) for the frontend's `terms` mapping.
                $tax = '';
                foreach ([$res['taxonomy'] ?? null, $args['taxonomy'] ?? null] as $c) {
                    if (is_string($c) && $c !== '') { $tax = $c; break; }
                }
                $target = ['screen' => 'terms'];
                if ($tax !== '') {
                    $target['taxonomy'] = $tax;
                    $taxObj = get_taxonomy($tax);
                    if ($taxObj && !empty($taxObj->object_type) && is_string($taxObj->object_type[0] ?? null)) {
                        $target['postType'] = (string) $taxObj->object_type[0];
                    }
                }
                return $target;
            }
            case 'wp_media_list':
                return $mk('upload');
            case 'wp_media_get':
            case 'wp_media_sideload':
                return $mk('upload', null, $id);
            case 'wp_menu_manage':
                return $mk('menus');
            case 'wp_user_create':
            case 'wp_user_update':
            case 'wp_user_get':
                return $mk('users', null, $id);
            case 'wp_user_list':
                return $mk('users');
            case 'wp_comment_list':
            case 'wp_comment_moderate':
                return $mk('comments');
            case 'wp_menu_list':
                return $mk('menus');
            case 'wp_option_get':
            case 'wp_option_set':
                return $mk('options');
            case 'wp_site_info':
                return $mk('site');
            case 'wp_plugins_list':
                return $mk('plugins');
            // third-party adapters
            case 'wc_read':
            case 'wc_product_upsert':
            case 'wc_stock_set':
                return $mk('edit', 'product', $id ?: null);
            case 'wc_order_note':
            case 'wc_order_update':
            case 'wc_order_cancel':
            case 'wc_order_create':
            case 'wc_order_line':
            case 'wc_apply_coupon':
            case 'wc_refund_request':
                return $mk('edit', 'shop_order', $id);
            case 'seo_get':
            case 'seo_set':
                return $mk('edit', $postType, $id);
            case 'forms_list':
            case 'forms_entries':
            case 'forms_entry_manage':
                return self::forms_focus($args, $res);
            case 'ld_read':
            case 'ld_enroll':
                return $mk('edit', 'sfwd-courses', $id);
            case 'mp_read':
            case 'mp_access':
                return $mk('edit', 'memberpressproduct', $id);
            default:
                return null;
        }
    }

    /**
     * WordPress-tab target for a forms tool: the active engine's native screen,
     * NOT the generic plugins list. With a specific form (form_id present) it
     * deep-links that engine's entries view; otherwise the engine's forms page.
     * The engine is taken from the tool's plugin field, else detected from the
     * active forms plugin. Emits an `admin_page` target with the engine's page
     * slug + a `query` map (route/view + the engine's form-id param) so the
     * frontend builds `admin.php?page=<page>&<query…>`.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $res
     * @return array<string, mixed>
     */
    private static function forms_focus(array $args, array $res): array
    {
        $plugin = '';
        foreach ([$res['plugin'] ?? null, $args['plugin'] ?? null] as $c) {
            if (is_string($c) && $c !== '') { $plugin = $c; break; }
        }
        if ($plugin === '') {
            if (defined('FLUENTFORM')) { $plugin = 'fluentforms'; }
            elseif (class_exists('GFForms')) { $plugin = 'gravityforms'; }
            elseif (defined('WPFORMS_VERSION')) { $plugin = 'wpforms'; }
            elseif (defined('WPCF7_VERSION')) { $plugin = 'contactform7'; }
        }
        $formId = null;
        foreach (['formId', 'form_id'] as $k) {
            if (isset($res[$k]) && is_numeric($res[$k])) { $formId = (int) $res[$k]; break; }
            if (isset($args[$k]) && is_numeric($args[$k])) { $formId = (int) $args[$k]; break; }
        }
        // Per engine: list page · entries page · entries query (form-id param
        // name + any fixed route/view). CF7 stores no entries → forms page only.
        $engines = [
            'fluentforms'  => ['list' => 'fluent_forms',    'entries' => 'fluent_forms',    'idParam' => 'form_id', 'fixed' => ['route' => 'entries']],
            'gravityforms' => ['list' => 'gf_edit_forms',   'entries' => 'gf_entries',      'idParam' => 'id',      'fixed' => []],
            'wpforms'      => ['list' => 'wpforms-overview', 'entries' => 'wpforms-entries', 'idParam' => 'form_id', 'fixed' => ['view' => 'list']],
            'contactform7' => ['list' => 'wpcf7',           'entries' => 'wpcf7',           'idParam' => null,      'fixed' => []],
        ];
        $e = $engines[$plugin] ?? null;
        if ($e === null) {
            return ['screen' => 'plugins']; // unknown engine — plugins list is the safe fallback
        }
        if ($formId !== null && $e['idParam'] !== null) {
            $query = $e['fixed'];
            $query[$e['idParam']] = (string) $formId;
            return ['screen' => 'admin_page', 'page' => $e['entries'], 'query' => $query];
        }
        return ['screen' => 'admin_page', 'page' => $e['list']];
    }

    /**
     * @return array<string, mixed>
     */
    private function json_params(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();

        return is_array($params) ? $params : [];
    }
}
