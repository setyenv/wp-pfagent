<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Thin pull-and-mount layer between the agent's virtual filesystem
 * and the plugins that own their typed-surface declarations.
 *
 * - `/lib/nodes.d.ts`  is owned by wp-pfworkflow's `TypingsBuilder` and
 *                      exposed through the filter
 *                      `projectflash_workflow_typings_dts`.
 * - `/lib/manage.d.ts` is owned by wp-pfmanagement's `TypingsBuilder`
 *                      and exposed through the filter
 *                      `projectflash_management_typings_dts`.
 * - `/lib/variables.d.ts` is owned by wp-pfworkflow's
 *                      `VariablesTypingsBuilder` and exposed through the
 *                      filter `projectflash_workflow_variables_dts`
 *                      (the filter takes a workflow id as its second
 *                      argument — each workflow has its own variable set).
 *
 * Reads go straight to the filter — no compute, no hashing, no
 * decompile. Each filter implementation reads its own pre-built
 * `wp_options` row and returns the string verbatim. The string is
 * rebuilt only when the owning plugin's contract changes (entity,
 * field, event, action, or node-contract registration).
 */
final class LibraryBuilder
{
    private const FALLBACK_NODES = <<<TS
// pfflow v1 — /lib/nodes.d.ts unavailable.
// The wp-pfworkflow plugin is either not active or has not produced
// its typed contract yet. Activate the workflow plugin or re-run its
// activation hook to seed the typings.

TS;

    private const FALLBACK_MANAGE = <<<TS
// pfflow-manage v1 — /lib/manage.d.ts unavailable.
// The wp-pfmanagement plugin is either not active or has not produced
// its model contract yet. Activate the management plugin or re-run
// its activation hook to seed the typings. Without this file the
// agent still authors workflows, but it has no machine-readable view
// of pfmanagement entities, events, or actions.

TS;

    private const FALLBACK_VARIABLES_NO_WORKFLOW = <<<TS
// pfflow-variables v1 — no workflow in context.
// Pass `workflow_id` to read_file (or write a draft for a /workflows/<id>__*.pfflow
// path first) so this surface can resolve which workflow's variable
// declarations to expose. Without context this file is empty by design.

TS;

    /**
     * Returns the workflow verb catalogue as TypeScript declarations.
     * Pure read of the option wp-pfworkflow maintains.
     */
    public static function nodesLibrary(): string
    {
        $dts = apply_filters('projectflash_workflow_typings_dts', null);
        if (!is_string($dts) || $dts === '') {
            return self::FALLBACK_NODES;
        }
        return $dts;
    }

    /**
     * Returns the pfmanagement model catalogue (entities, events,
     * actions) as TypeScript declarations. Pure read of the option
     * wp-pfmanagement maintains.
     *
     * Returns an empty fallback (not null) so the LLM always sees the
     * file with a self-explaining stub instead of a missing path.
     */
    public static function manageLibrary(): string
    {
        $dts = apply_filters('projectflash_management_typings_dts', null);
        if (!is_string($dts) || $dts === '') {
            return self::FALLBACK_MANAGE;
        }
        return $dts;
    }

    /**
     * Returns the per-workflow variables surface as TypeScript
     * declarations. A null/empty workflow id triggers a self-explaining
     * stub so the agent sees the file but understands no workflow is in
     * context yet. wp-pfworkflow's VariablesTypingsBuilder handles the
     * "workflow exists but has no variables" case with its own stub.
     *
     * @param mixed $workflow_id  opaque workflow id (uuid string or legacy int)
     */
    public static function variablesLibrary($workflow_id): string
    {
        $id = \ProjectFlash\Agent\WorkflowDependency::normalize_workflow_id($workflow_id ?? '');
        if ($id === '') {
            return self::FALLBACK_VARIABLES_NO_WORKFLOW;
        }
        $dts = apply_filters('projectflash_workflow_variables_dts', null, $id);
        if (!is_string($dts) || $dts === '') {
            return self::FALLBACK_VARIABLES_NO_WORKFLOW;
        }
        return $dts;
    }

    /**
     * @deprecated Retained for the period in which downstream callers
     *             still ask for "the library". New code should use
     *             {@see self::nodesLibrary()}.
     */
    public static function library(): string
    {
        return self::nodesLibrary();
    }
}
