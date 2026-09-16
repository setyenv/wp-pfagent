<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

/**
 * Bridge between wp-pfagent and the wp-pfmanagement agent-ready service.
 *
 * wp-pfmanagement publishes its agent-ready service via the
 * `projectflash_management_agent_api` PHP filter — same pattern wp-pfworkflow
 * uses for `projectflash_workflow_agent_api`. The service exposes typed,
 * round-trippable methods that the LLM consumes via wrapped envelopes
 * `{ content, contextForYou }`.
 *
 * Tools this bridge serves:
 *   - pfm_get_contract — bootstrap contract (types, EQL ops, kinds vocab)
 *   - pfm_list({ kind, filters? }) — list a resource kind (entity/record/action/…)
 *   - pfm_get({ kind, ref }) — fetch one resource by slug or id
 *   - pfm_apply({ kind, payload }) — create or update one resource
 *   - pfm_delete({ kind, ref }) — delete one resource by reference
 */
final class ManagementApiBridge
{
    private const FILTER = 'projectflash_management_agent_api';

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @return array<string, mixed>|WP_Error
     */
    public function execute(string $tool_name, array $arguments, array $tool)
    {
        $service = $this->resolve_service();
        if ($service instanceof WP_Error) {
            return $service;
        }

        return match ($tool_name) {
            'pfm_get_contract' => $this->call_service($service, 'agent_contract', [], $tool_name),
            'pfm_list'         => $this->pfm_list($service, $arguments, $tool_name),
            'pfm_get'          => $this->pfm_get($service, $arguments, $tool_name),
            'pfm_apply'        => $this->pfm_apply($service, $arguments, $tool_name),
            'pfm_delete'       => $this->pfm_delete($service, $arguments, $tool_name),
            default            => new WP_Error('pfa_agent_tool_not_allowed', __('Tool is not executable by Management API bridge.', 'wp-pfagent'), ['status' => 400]),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_list(object $service, array $arguments, string $tool_name)
    {
        $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
        if ($kind === '') {
            return new WP_Error('pfa_agent_kind_required', __('kind is required (entity, record, action, application, module, group, role, page).', 'wp-pfagent'), ['status' => 400]);
        }
        $filters = is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
        $response = $this->call_service($service, 'agent_list', [$kind, $filters], $tool_name);
        if ($kind === 'entity' && !($response instanceof WP_Error)) {
            $response['data'] = self::entity_map($response['data'] ?? null);
        }

        return $response;
    }

    /**
     * Reduce the entity catalogue to the map the agent actually needs to
     * pick one: what exists, what it is called, how big it is.
     *
     * The full catalogue is every entity's complete definition — every
     * field, every per-locale label — and on a normal install that is a
     * third of a megabyte. Answering "how many open incidents are there"
     * cost a quarter of a million prompt tokens for exactly that reason.
     * The structure of ONE entity is a `pfm_get` away, complete, whenever
     * the agent needs it; the map is what it needs to know which one.
     *
     * @param mixed $data
     * @return array<string, mixed>
     */
    public static function entity_map(mixed $data): array
    {
        $content = is_array($data) ? ($data['content'] ?? null) : null;
        if (!is_array($content)) {
            return is_array($data) ? $data : [];
        }

        $entities = [];
        foreach ($content as $item) {
            $entity = is_array($item) ? ($item['content']['entity'] ?? $item['entity'] ?? $item) : null;
            if (!is_array($entity) || ($entity['slug'] ?? '') === '') {
                continue;
            }
            $fields = is_array($entity['fields'] ?? null) ? $entity['fields'] : [];
            $entities[] = [
                'slug' => (string) $entity['slug'],
                'name' => (string) ($entity['label'] ?? ''),
                'name_plural' => (string) ($entity['label_plural'] ?? ''),
                'is_system' => (bool) ($entity['is_system'] ?? false),
                'field_count' => count($fields),
            ];
        }

        return [
            'content' => [
                'schema' => 'projectflash.management.agent_entity_map',
                'schemaVersion' => 1,
                'count' => count($entities),
                'entities' => $entities,
            ],
            'contextForYou' => 'This is the map of what exists, not the model itself: slug, display name '
                . 'and field count per entity. To work with one — its fields, their types, its form layout, '
                . 'its number sequence — read that single entity with pfm_get(kind: "entity", ref: "<slug>"), '
                . 'which returns its complete structure. Never ask for the map to learn about fields.',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_get(object $service, array $arguments, string $tool_name)
    {
        $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
        $ref  = trim((string) ($arguments['ref'] ?? ''));
        if ($kind === '' || $ref === '') {
            return new WP_Error('pfa_agent_kind_ref_required', __('kind and ref are required.', 'wp-pfagent'), ['status' => 400]);
        }
        $response = $this->call_service($service, 'agent_get', [$kind, $ref], $tool_name);
        if (!($response instanceof WP_Error)) {
            $response['data'] = self::without_translations($response['data'] ?? null);
        }

        return $response;
    }

    /**
     * Drop the per-locale label maps from anything the agent reads.
     *
     * Every entity, field and application carries its labels translated into
     * every platform locale — fourteen copies of text the agent has no use
     * for, because it renders display values into the customer's language
     * itself. Sending them is paying tokens to tell a translator how to
     * translate. The stored translations are untouched: this only shapes what
     * travels to the model, and pfm_apply puts them back on the way in.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function without_translations(mixed $payload): mixed
    {
        if (!is_array($payload)) {
            return $payload;
        }
        $clean = [];
        foreach ($payload as $key => $value) {
            if ($key === 'labels' || $key === 'labels_json') {
                continue;
            }
            $clean[$key] = is_array($value) ? self::without_translations($value) : $value;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_apply(object $service, array $arguments, string $tool_name)
    {
        // Canonical shape: { kind, payload }. We also accept lenient shapes
        // that smaller LLMs emit:
        //   - { kind, <kind>: {...} }     → payload is { <kind>: {...} }
        //   - { entity: {...}, fields: [...] } etc. → infer kind from the
        //     unique top-level resource key (entity, record, action,
        //     application, module, group, role, page) and wrap payload.
        // The downstream agent_apply re-validates the unwrapped payload,
        // so being forgiving here never bypasses real schema checks.
        $canonical = self::canonicalise_apply_arguments($arguments);
        $kind = (string) $canonical['kind'];
        $payload = (array) $canonical['payload'];

        if ($kind === '' || $payload === []) {
            return new WP_Error('pfa_agent_kind_payload_required', __('Send { kind: "<entity|record|action|application|module|group|role|page>", payload: { <kind>: {...} } }.', 'wp-pfagent'), ['status' => 400]);
        }
        $options = (array) $canonical['options'];
        if ($kind === 'entity') {
            $payload = self::normalize_entity_payload($payload);
            $payload = self::carry_translations_forward($service, $payload);
        }
        return $this->call_service($service, 'agent_apply', [$kind, $payload, $options], $tool_name);
    }

    /**
     * Put an apply call into the one shape the service accepts, whichever of
     * the documented shapes the model actually sent.
     *
     * The canonical form is `{ kind, payload: { <kind>: {...} } }`, and the
     * service's own error tells the model that a flat `{ <kind>: {...} }` is
     * fine too because "the bridge will infer kind" — which was only ever
     * true of this class, and the tools stopped coming through it. The model
     * followed the message it was given, the write refused it, and the
     * customer watched a change fail for a reason nobody could see. So the
     * leniency lives here, where both paths can use it, and everything the
     * bridge does to a payload before a write applies to every shape.
     *
     * @param array<string, mixed> $arguments
     * @return array{kind: string, payload: array<string, mixed>, options: array<string, mixed>}
     */
    public static function canonicalise_apply_arguments(array $arguments): array
    {
        $known_kinds = ['entity', 'record', 'action', 'application', 'module', 'group', 'role', 'page'];

        $kind = function_exists('sanitize_key')
            ? sanitize_key((string) ($arguments['kind'] ?? ''))
            : strtolower(trim((string) ($arguments['kind'] ?? '')));
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [];

        if ($payload === []) {
            // Assemble a payload from top-level kind-named keys.
            foreach ($known_kinds as $k) {
                if (isset($arguments[$k])) {
                    $payload[$k] = $arguments[$k];
                }
            }
            // Some kinds (record, module) need a sibling like `entity` or
            // `application` at the payload root — carry those over too.
            foreach (['entity', 'application', 'values', 'sys_id', 'numberSequence', 'formLayout', 'fields'] as $sibling) {
                if (isset($arguments[$sibling]) && !isset($payload[$sibling])) {
                    $payload[$sibling] = $arguments[$sibling];
                }
            }
        }

        if ($kind === '' && $payload !== []) {
            // Infer the kind from the unique known top-level key inside the payload.
            $matches = [];
            foreach ($known_kinds as $k) {
                if (isset($payload[$k])) {
                    $matches[] = $k;
                }
            }
            if (count($matches) === 1) {
                $kind = $matches[0];
            }
        }

        // `options` (notably options.force, which is how a schema change on a
        // populated entity gets through) is accepted at the tool root and
        // inside the payload, where smaller models tend to put it.
        $options = is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        if ($options === [] && is_array($payload['options'] ?? null)) {
            $options = $payload['options'];
        }
        unset($payload['options']);

        return ['kind' => $kind, 'payload' => $payload, 'options' => $options];
    }

    /**
     * Lift the parts of an entity payload that the model tucked inside the
     * entity block back up to where the write reads them.
     *
     * `pfm_get` returns an entity as `{ entity: {...}, numberSequence: {...},
     * formLayout: {...} }` and the write takes that same shape back — but
     * auto-numbering and the form layout READ like properties of the entity,
     * so a model that has never seen a round-trip nests them inside it. The
     * write then ignores them without a word (they are optional, so absent is
     * legal) and reports success. Live, a customer was told their laptops
     * would be numbered LAP-00001 and they were not: the entity had been
     * created, the numbering silently dropped.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalize_entity_payload(array $payload): array
    {
        $entity = is_array($payload['entity'] ?? null) ? $payload['entity'] : null;
        if ($entity === null) {
            return $payload;
        }
        foreach (['numberSequence', 'formLayout'] as $sibling) {
            if (!array_key_exists($sibling, $entity)) {
                continue;
            }
            if (!array_key_exists($sibling, $payload)) {
                $payload[$sibling] = $entity[$sibling];
            }
            unset($entity[$sibling]);
        }
        $payload['entity'] = $entity;

        return $payload;
    }

    /**
     * Put the stored translations back into an entity payload the agent
     * never saw them in.
     *
     * An entity write persists its label maps from whatever the payload
     * carries, so a payload that omits them — which is now every payload the
     * agent produces, since reads no longer include them — would silently
     * erase the entity's and its fields' translations on the first edit. The
     * agent stays out of it: we read the current maps and merge them under
     * anything it did send, matching fields by name.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function carry_translations_forward(object $service, array $payload): array
    {
        $entity = is_array($payload['entity'] ?? null) ? $payload['entity'] : null;
        $slug = is_string($entity['slug'] ?? null) ? $entity['slug'] : '';
        if ($entity === null || $slug === '' || !is_callable([$service, 'agent_get'])) {
            return $payload;
        }

        try {
            $current = $service->agent_get('entity', $slug);
        } catch (\Throwable $e) {
            return $payload;
        }
        $stored = is_array($current) ? ($current['content']['entity'] ?? null) : null;
        if (!is_array($stored)) {
            // No stored entity: this is a create, so there is nothing to lose.
            return $payload;
        }

        if (!isset($entity['labels']) && is_array($stored['labels'] ?? null)) {
            $entity['labels'] = $stored['labels'];
        }

        $storedFieldLabels = [];
        foreach (is_array($stored['fields'] ?? null) ? $stored['fields'] : [] as $storedField) {
            $name = is_array($storedField) ? (string) ($storedField['name'] ?? '') : '';
            if ($name !== '' && is_array($storedField['labels'] ?? null)) {
                $storedFieldLabels[$name] = $storedField['labels'];
            }
        }
        if ($storedFieldLabels !== [] && is_array($entity['fields'] ?? null)) {
            foreach ($entity['fields'] as $index => $field) {
                $name = is_array($field) ? (string) ($field['name'] ?? '') : '';
                if ($name !== '' && !isset($field['labels']) && isset($storedFieldLabels[$name])) {
                    $entity['fields'][$index]['labels'] = $storedFieldLabels[$name];
                }
            }
        }

        $payload['entity'] = $entity;

        return $payload;
    }

    /**
     * Delete ONE resource by { kind, ref }. Same shape as pfm_get; the ref is
     * the single explicit target (there is no bulk delete). Downstream
     * agent_delete re-applies the capability gate, the audit trail and the
     * cascade rules (business_rule delete refused, entity delete drops records,
     * record delete clears inbound relations), so this bridge stays a thin
     * argument adapter.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_delete(object $service, array $arguments, string $tool_name)
    {
        $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
        $ref  = trim((string) ($arguments['ref'] ?? ''));
        if ($kind === '' || $ref === '') {
            return new WP_Error('pfa_agent_kind_ref_required', __('kind and ref are required.', 'wp-pfagent'), ['status' => 400]);
        }
        $options = is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        return $this->call_service($service, 'agent_delete', [$kind, $ref, $options], $tool_name);
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function call_service(object $service, string $method, array $arguments, string $tool_name)
    {
        if (!is_callable([$service, $method])) {
            return new WP_Error(
                'pfa_pfm_service_method_missing',
                'wp-pfmanagement agent service does not expose the requested method: ' . $method,
                ['status' => 502, 'method' => $method]
            );
        }

        try {
            $result = $service->$method(...$arguments);
        } catch (\Throwable $e) {
            return new WP_Error(
                'pfa_pfm_service_failed',
                'wp-pfmanagement agent service threw while executing ' . $method . ': ' . $e->getMessage(),
                ['status' => 502, 'method' => $method]
            );
        }

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (!is_array($result) || !array_key_exists('content', $result) || !array_key_exists('contextForYou', $result)) {
            return new WP_Error(
                'pfa_pfm_service_invalid_envelope',
                'wp-pfmanagement agent service did not return a { content, contextForYou } envelope from ' . $method . '.',
                ['status' => 502, 'method' => $method]
            );
        }

        return [
            'method' => 'INTERNAL',
            'route' => 'internal:pfm:' . $method,
            'status' => 200,
            'data' => $result,
            'tool' => $tool_name,
        ];
    }

    /**
     * Read a resource snapshot for diff capture. Returns null when the
     * service is unavailable or the call fails — callers omit the diff
     * rather than fabricate one.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(string $kind, string $ref): ?array
    {
        if ($kind === '' || $ref === '') {
            return null;
        }
        $service = $this->resolve_service();
        if ($service instanceof WP_Error || !is_callable([$service, 'agent_get'])) {
            return null;
        }
        try {
            $result = $service->agent_get($kind, $ref);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($result) || !is_array($result['content'] ?? null)) {
            return null;
        }
        return [
            'kind' => $kind,
            'ref' => $ref,
            'content' => $result['content'],
        ];
    }

    /**
     * Resolve the wp-pfmanagement agent-ready service via the documented filter.
     *
     * @return object|WP_Error
     */
    private function resolve_service()
    {
        $service = apply_filters(self::FILTER, null);
        if (!is_object($service)) {
            return new WP_Error('pfa_pfm_service_unavailable', __('wp-pfmanagement agent-ready service is not available.', 'wp-pfagent'),
                ['status' => 502, 'filter' => self::FILTER]
            );
        }
        return $service;
    }
}
