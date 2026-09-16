<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

/**
 * Bridge between wp-pfagent and the wp-pfworkflow agent-ready service.
 *
 * The frontier is no longer REST. wp-pfworkflow exposes a PHP-internal
 * service via apply_filters('projectflash_workflow_agent_api', null) with
 * four methods: agent_contract, agent_workflow_list, agent_workflow_full,
 * agent_workflow_apply. Every response is a { content, contextForYou }
 * envelope and is round-tripped to the LLM verbatim — contextForYou
 * carries behavioral rules the model must read on every turn.
 */
final class WorkflowApiBridge
{
    private const FILTER = 'projectflash_workflow_agent_api';

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @return array<string, mixed>|WP_Error
     */
    public function execute(string $tool_name, array $arguments, array $tool)
    {
        if (!WorkflowDependency::is_active()) {
            return new WP_Error('pfa_workflow_inactive', __('WP PFWorkflow plugin is not active.', 'wp-pfagent'), ['status' => 409]);
        }

        $service = $this->resolve_service();
        if ($service instanceof WP_Error) {
            return $service;
        }

        return match ($tool_name) {
            'workflow_get_contract' => $this->call_service($service, 'agent_contract', [], $tool_name),
            'workflow_list' => $this->call_service($service, 'agent_workflow_list', [is_array($arguments['filters'] ?? null) ? $arguments['filters'] : []], $tool_name),
            'workflow_get' => $this->workflow_get($service, $arguments, $tool_name),
            'workflow_apply' => $this->workflow_apply($service, $arguments, $tool_name),
            default => new WP_Error('pfa_agent_tool_not_allowed', __('Tool is not executable by Workflow API bridge.', 'wp-pfagent'), ['status' => 400]),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function workflow_get(object $service, array $arguments, string $tool_name)
    {
        $workflow_id = WorkflowDependency::normalize_workflow_id($arguments['workflowId'] ?? '');
        if ($workflow_id === '') {
            return new WP_Error('pfa_agent_workflow_id_required', __('workflowId is required.', 'wp-pfagent'), ['status' => 400]);
        }

        return $this->call_service($service, 'agent_workflow_full', [$workflow_id], $tool_name);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function workflow_apply(object $service, array $arguments, string $tool_name)
    {
        // Accept both the canonical shape `{ payload: { workflow, graph } }`
        // and the flat shape `{ workflow, graph }` that some LLMs emit when
        // they skip the wrapper. The narrator and tool description specify
        // the canonical shape, but rejecting the flat shape outright wastes
        // a whole tool-call round; auto-detecting is forgiving without
        // hiding any real validation downstream (agent_workflow_apply
        // re-validates the unwrapped payload).
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [];
        if ($payload === [] && (isset($arguments['workflow']) || isset($arguments['graph']))) {
            $payload = $arguments;
        }
        if ($payload === []) {
            return new WP_Error('pfa_agent_payload_required', __('payload is required: send { payload: { workflow: { id, name, status }, graph: { schemaVersion: 1, nodes: [], connections: [] } } }.', 'wp-pfagent'), ['status' => 400]);
        }

        return $this->call_service($service, 'agent_workflow_apply', [$payload], $tool_name);
    }

    /**
     * Invoke a method on the workflow agent service and validate the
     * envelope shape. The wrapper { content, contextForYou } is always
     * preserved verbatim so the LLM can read contextForYou and round-trip
     * the wrapper back to apply().
     *
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function call_service(object $service, string $method, array $arguments, string $tool_name)
    {
        if (!is_callable([$service, $method])) {
            return new WP_Error(
                'pfa_workflow_service_method_missing',
                'Workflow agent service does not expose the requested method: ' . $method,
                ['status' => 502, 'method' => $method]
            );
        }

        try {
            $result = $service->$method(...$arguments);
        } catch (\Throwable $e) {
            return new WP_Error(
                'pfa_workflow_service_failed',
                'Workflow agent service threw while executing ' . $method . ': ' . $e->getMessage(),
                ['status' => 502, 'method' => $method]
            );
        }

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (!is_array($result) || !array_key_exists('content', $result) || !array_key_exists('contextForYou', $result)) {
            return new WP_Error(
                'pfa_workflow_service_invalid_envelope',
                'Workflow agent service did not return a { content, contextForYou } envelope from ' . $method . '.',
                ['status' => 502, 'method' => $method]
            );
        }

        return [
            'method' => 'INTERNAL',
            'route' => 'internal:' . $method,
            'status' => 200,
            'data' => $result,
            'tool' => $tool_name,
        ];
    }

    /**
     * Resolve the wp-pfworkflow agent-ready service via the documented filter.
     *
     * @return object|WP_Error
     */
    private function resolve_service()
    {
        $service = apply_filters(self::FILTER, null);
        if (!is_object($service)) {
            return new WP_Error('pfa_workflow_service_unavailable', __('wp-pfworkflow agent-ready service is not available.', 'wp-pfagent'),
                ['status' => 502, 'filter' => self::FILTER]
            );
        }

        return $service;
    }

    /**
     * Read a workflow snapshot for diff capture. Returns null when the
     * service is unavailable or the call fails so callers can omit the
     * diff rather than fabricate one. The wrapper inner shape may carry
     * the workflow either at content.workflow.* or at content.* itself
     * depending on the upstream version; both are accepted.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot_workflow($workflow_id): ?array
    {
        // A workflow id is opaque: PFW mints uuids. Declared `int` this could
        // only ever be called with the shape that no longer exists — under
        // strict_types a uuid reaching it is a TypeError, and a cast would be 0.
        $workflow_id = WorkflowDependency::normalize_workflow_id($workflow_id);
        if ($workflow_id === '' || !WorkflowDependency::is_active()) {
            return null;
        }

        $service = $this->resolve_service();
        if ($service instanceof WP_Error || !is_callable([$service, 'agent_workflow_full'])) {
            return null;
        }

        try {
            $result = $service->agent_workflow_full(WorkflowDependency::workflow_id_payload($workflow_id));
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($result) || !is_array($result['content'] ?? null)) {
            return null;
        }

        $content = $result['content'];
        $workflow = is_array($content['workflow'] ?? null) ? $content['workflow'] : $content;

        return [
            'id' => WorkflowDependency::workflow_id_payload(
                WorkflowDependency::normalize_workflow_id($workflow['id'] ?? $workflow_id)
            ),
            'name' => (string) ($workflow['name'] ?? ''),
            'status' => (string) ($workflow['status'] ?? ''),
            'graph' => is_array($workflow['graph'] ?? null) ? $workflow['graph'] : null,
            'updatedAt' => (string) ($workflow['updatedAt'] ?? ''),
        ];
    }
}
