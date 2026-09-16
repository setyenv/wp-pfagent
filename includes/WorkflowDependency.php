<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

final class WorkflowDependency
{
    private const FALLBACK_REST_NAMESPACE = 'wp-pfworkflow/v1';

    public static function is_active(): bool
    {
        return defined('PFW_REST_NAMESPACE')
            || class_exists('\\ProjectFlash\\Workflow\\Plugin');
    }

    /**
     * Canonical string form of a PFW workflow id, or '' when shapeless.
     * Mirrors PFW's WorkflowIdHelper::normalize (the P4.2 opaque-id
     * contract): int / all-digits -> positive decimal string; uuid-shaped
     * -> lowercased; anything else -> ''. Local on purpose - PFA must not
     * fatal when PFW is inactive.
     *
     * @param mixed $id
     */
    public static function normalize_workflow_id($id): string
    {
        if (is_int($id) || (is_string($id) && $id !== '' && ctype_digit($id))) {
            $int = (int) $id;

            return $int > 0 ? (string) $int : '';
        }
        if (is_string($id)) {
            $id = strtolower(trim($id));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1) {
                return $id;
            }
        }

        return '';
    }

    /**
     * Payload form of a normalized id: numeric ids keep travelling as int
     * (byte-identical to the historic contract), uuid ids travel as the
     * string they are, '' -> 0 (the historic "absent" sentinel).
     *
     * @return int|string
     */
    public static function workflow_id_payload(string $normalized)
    {
        if ($normalized === '') {
            return 0;
        }

        return ctype_digit($normalized) ? (int) $normalized : $normalized;
    }

    public static function rest_namespace(): string
    {
        if (defined('PFW_REST_NAMESPACE')) {
            return (string) PFW_REST_NAMESPACE;
        }

        return self::FALLBACK_REST_NAMESPACE;
    }

    /**
     * @return array<string, bool>
     */
    public static function capabilities(): array
    {
        $workflow_capabilities = '\\ProjectFlash\\Workflow\\Capabilities';

        if (class_exists($workflow_capabilities)) {
            return [
                'viewWorkflows' => (bool) call_user_func([$workflow_capabilities, 'can_view_workflows']),
                'manageWorkflows' => (bool) call_user_func([$workflow_capabilities, 'can_manage_workflows']),
                'runWorkflows' => (bool) call_user_func([$workflow_capabilities, 'can_run_workflows']),
                'viewLogs' => (bool) call_user_func([$workflow_capabilities, 'can_view_logs']),
            ];
        }

        return [
            'viewWorkflows' => current_user_can('manage_options'),
            'manageWorkflows' => current_user_can('manage_options'),
            'runWorkflows' => current_user_can('manage_options'),
            'viewLogs' => current_user_can('manage_options'),
        ];
    }

    public static function admin_url(): string
    {
        return esc_url_raw(admin_url('admin.php?page=wp-pfworkflow'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function status_payload(): array
    {
        $namespace = self::rest_namespace();

        return [
            'active' => self::is_active(),
            'namespace' => $namespace,
            'restUrl' => esc_url_raw(rest_url($namespace . '/')),
            'adminUrl' => self::admin_url(),
            'capabilities' => self::capabilities(),
            'source' => defined('PFW_REST_NAMESPACE') ? 'workflow_constant' : 'fallback',
        ];
    }
}
