<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

use ProjectFlash\Agent\WorkflowDependency;

/**
 * Keeps each workflow's decompiled source up to date so the LLM's
 * virtual filesystem reads are O(1) (no decompile on read).
 *
 * Storage (W2): wp-pfworkflow's per-workflow state store
 * (`wp_pfw_workflow_state`), keyed by the workflow's opaque char(36)
 * id — the legitimate slot the store offers other plugins for their
 * per-workflow state. The old `_pfa_source*` postmeta on the
 * `pfw_workflow` post died with the CPT in the cutover; state rows are
 * purged automatically when the workflow is deleted through the
 * repository, so this cache can never outlive its workflow.
 *
 * Hooked on `projectflash_workflow_changed` — wp-pfworkflow fires this
 * action whenever a workflow is saved (via the designer or via
 * pfagent's apply tool). We re-decompile and persist under the
 * `pfa_source*` state keys.
 *
 * Plugin activation triggers a one-shot backfill of every existing
 * workflow.
 */
final class DecompileCache
{
    public const KEY_SOURCE = 'pfa_source';
    public const KEY_SOURCE_AT = 'pfa_source_at';
    public const KEY_SOURCE_VERSION = 'pfa_source_version';
    public const KEY_ERROR = 'pfa_source_error';
    public const SOURCE_FORMAT_VERSION = 1;

    public static function init(): void
    {
        add_action('projectflash_workflow_changed', [self::class, 'on_workflow_changed'], 20, 1);
    }

    public static function activate(): void
    {
        self::backfill();
    }

    /**
     * Whether the state store is reachable (wp-pfworkflow active).
     */
    private static function storeAvailable(): bool
    {
        return class_exists('\\ProjectFlash\\Workflow\\Helpers\\WorkflowStateStore');
    }

    /**
     * Re-decompile and persist for the given workflow id (opaque string).
     * Called from the workflow-changed hook; also exposed for the manual
     * REST refresh endpoint and the backfill loop.
     *
     * @param mixed $workflow_id
     */
    public static function refresh($workflow_id): bool
    {
        $id = WorkflowDependency::normalize_workflow_id($workflow_id);
        if ($id === '' || !self::storeAvailable()) {
            return false;
        }
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_workflow_full')) {
            return false;
        }
        $envelope = $service->agent_workflow_full(WorkflowDependency::workflow_id_payload($id));
        if (!is_array($envelope) || !is_array($envelope['content'] ?? null)) {
            return false;
        }
        $content = $envelope['content'];
        $workflow = [
            'id' => $id,
            'name' => (string) ($content['workflow']['name'] ?? ''),
            'status' => (string) ($content['workflow']['status'] ?? 'draft'),
            'graph' => is_array($content['graph'] ?? null) ? $content['graph'] : [],
        ];

        $store = '\\ProjectFlash\\Workflow\\Helpers\\WorkflowStateStore';
        try {
            $source = Decompiler::decompile($workflow);
        } catch (CompileError $e) {
            $store::set($id, self::KEY_ERROR, $e->getMessage());
            return false;
        }

        $store::delete($id, self::KEY_ERROR);
        $store::set($id, self::KEY_SOURCE, $source);
        $store::set($id, self::KEY_SOURCE_AT, gmdate(DATE_ATOM));
        $store::set($id, self::KEY_SOURCE_VERSION, self::SOURCE_FORMAT_VERSION);
        return true;
    }

    /**
     * Re-decompile every existing workflow. Called on activation and from
     * a manual REST endpoint.
     *
     * @return array{processed:int, failed:int}
     */
    public static function backfill(): array
    {
        $processed = 0;
        $failed = 0;

        if (!class_exists('\\ProjectFlash\\Workflow\\WorkflowRepository')) {
            return ['processed' => 0, 'failed' => 0];
        }
        foreach ((new \ProjectFlash\Workflow\WorkflowRepository())->all() as $workflow) {
            $ok = self::refresh((string) ($workflow['id'] ?? ''));
            if ($ok) {
                $processed++;
            } else {
                $failed++;
            }
        }
        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * @param mixed $workflow_id
     */
    public static function on_workflow_changed($workflow_id): void
    {
        $raw = is_array($workflow_id) ? ($workflow_id['id'] ?? '') : $workflow_id;
        $id = WorkflowDependency::normalize_workflow_id($raw);
        if ($id !== '') {
            self::refresh($id);
        }
    }

    /**
     * @param mixed $workflow_id
     */
    public static function read($workflow_id): string
    {
        $id = WorkflowDependency::normalize_workflow_id($workflow_id);
        if ($id === '' || !self::storeAvailable()) {
            return '';
        }
        $store = '\\ProjectFlash\\Workflow\\Helpers\\WorkflowStateStore';
        $cached = (string) $store::get($id, self::KEY_SOURCE, '');
        if ($cached !== '') {
            return $cached;
        }
        // Lazy populate if missing (e.g. workflow created before the cache
        // existed, or backfill missed it).
        self::refresh($id);
        return (string) $store::get($id, self::KEY_SOURCE, '');
    }

    /**
     * @param mixed $workflow_id
     */
    public static function readError($workflow_id): ?string
    {
        $id = WorkflowDependency::normalize_workflow_id($workflow_id);
        if ($id === '' || !self::storeAvailable()) {
            return null;
        }
        $store = '\\ProjectFlash\\Workflow\\Helpers\\WorkflowStateStore';
        $err = (string) $store::get($id, self::KEY_ERROR, '');
        return $err === '' ? null : $err;
    }
}
