<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\WordPress\Tools;

use ProjectFlash\Agent\Framework\Tools\Tool;
use ProjectFlash\Agent\Framework\ToolDefinition;
use ProjectFlash\Agent\Framework\ToolResult;

/**
 * Generic adapter that exposes a WordPress filter-backed service as a
 * framework Tool. Replaces the bespoke one-class-per-bridge pattern.
 *
 * Wiring an existing service:
 *
 *   $registry->register(new FilterBridgeTool(
 *       name: 'pfm_apply',
 *       description: 'Persist a wp-pfmanagement resource (entity/field/...).',
 *       parameters: [...JSON Schema...],
 *       filter: 'projectflash_management_agent_api',
 *       method: 'agent_apply',
 *       sideEffect: true,
 *       idempotent: false,
 *       stateExtractor: function ($result) use ($service): mixed {
 *           // Return whatever fresh state the agent needs to verify "done".
 *           // e.g. the updated entity slug list.
 *           return $service->agent_list(['kind' => 'entity']);
 *       },
 *   ));
 *
 * The service object is resolved via apply_filters($filter, null) on every
 * call — exactly how pfagent itself reaches the bridges today. If the host
 * returns null (filter has no handler), the tool fails with code
 * `bridge_unavailable` so the LLM sees a recoverable error.
 *
 * stateExtractor is the antidote to false-done: every call returns a fresh
 * snapshot of the relevant external state, embedded into the tool_result
 * as state_after. The LLM verifies its own claims against that snapshot.
 */
final class FilterBridgeTool implements Tool
{
    private ToolDefinition $definition;

    /** @var \Closure(mixed): mixed)|null */
    private ?\Closure $stateExtractor;

    /** @var list<string>|null */
    private ?array $argMapping;

    /**
     * @param array<string, mixed> $parameters JSON Schema
     * @param list<string>|null $argMapping Optional. When null, the whole
     *     $arguments array is passed as the FIRST positional argument to
     *     the bridge method. When a list of keys, the loop pulls each
     *     $arguments[$key] in order and passes them positionally. Lets us
     *     adapt to bridge methods like agent_list(string $kind, array $filters)
     *     without changing the bridge code.
     * @param \Closure(mixed $result): mixed $stateExtractor optional
     * @param \Closure(array<string, mixed> $arguments): array<string, mixed> $argumentShaper
     *     optional. Last chance to complete or correct what the LLM sent
     *     before the service sees it — e.g. carrying data forward that the
     *     agent was never shown and must not drop.
     * @param \Closure(mixed $result, array<string, mixed> $arguments): mixed $resultShaper
     *     optional. Shapes what the LLM gets back — the place to leave out
     *     payload the model has no use for, without the source service or
     *     the tool contract changing at all.
     */
    public function __construct(
        string $name,
        string $description,
        array $parameters,
        private readonly string $filter,
        private readonly string $method,
        bool $sideEffect = false,
        bool $idempotent = false,
        ?\Closure $stateExtractor = null,
        ?array $argMapping = null,
        bool $strict = false,
        private readonly ?\Closure $argumentShaper = null,
        private readonly ?\Closure $resultShaper = null,
    ) {
        $this->definition = new ToolDefinition($name, $description, $parameters, $sideEffect, $idempotent, $strict);
        $this->stateExtractor = $stateExtractor;
        $this->argMapping = $argMapping;
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function execute(array $arguments): ToolResult
    {
        if (!function_exists('apply_filters')) {
            return ToolResult::failure('wp_not_loaded', 'WordPress is not loaded; this tool can only run inside WordPress.', false);
        }
        if ($this->argumentShaper !== null) {
            $arguments = ($this->argumentShaper)($arguments);
        }
        $service = apply_filters($this->filter, null);
        if (!is_object($service) || !method_exists($service, $this->method)) {
            return ToolResult::failure(
                'bridge_unavailable',
                sprintf('Service "%s::%s" is not available (filter "%s" returned no compatible handler).', get_debug_type($service), $this->method, $this->filter),
                false,
            );
        }

        try {
            // Calling convention: either pass the whole $arguments array as
            // a single positional argument (default), OR decompose to
            // positional args following argMapping order. argMapping=['kind',
            // 'filters'] means call($args['kind'], $args['filters']).
            if ($this->argMapping === null) {
                $result = call_user_func([$service, $this->method], $arguments);
            } else {
                // Decompose to positional args following argMapping. KEY
                // detail: if the LLM omitted an optional trailing arg, we
                // DROP it (don't pass null) so the method's declared
                // default kicks in. Passing null to a typed `array` arg
                // throws a TypeError, which is the bug the live test
                // surfaced. We only pass nulls when the LLM EXPLICITLY
                // included a null in the arguments object.
                $positional = [];
                $sawValue = false;
                $rev = array_reverse($this->argMapping);
                $tail = [];
                foreach ($rev as $key) {
                    if (!$sawValue && !array_key_exists($key, $arguments)) {
                        continue; // skip trailing optional
                    }
                    $sawValue = true;
                    $tail[] = $arguments[$key] ?? null;
                }
                $positional = array_reverse($tail);
                // Lenient fallback: when the LLM emits a payload that
                // doesn't carry ANY of the mapped keys (e.g. pfm_apply
                // with `{entity:{...}}` and no top-level `kind` /
                // `payload`), the decomposition above produces an
                // empty positional list and the underlying method
                // throws "Too few arguments". Pass the whole
                // arguments bag as ONE positional instead — the
                // target method can apply its own lenient parser to
                // extract what it needs. Methods that DON'T accept a
                // bag form fail the same way they would with empty
                // positional, so nothing regresses.
                if ($positional === []) {
                    $result = call_user_func([$service, $this->method], $arguments);
                } else {
                    $result = call_user_func_array([$service, $this->method], $positional);
                }
            }
        } catch (\TypeError $e) {
            // H9: a positional decomposition that hands null (or a wrong-typed
            // value) to a typed parameter throws a TypeError. It is already
            // caught here (never a fatal), but surface it as a CLEAN, labelled
            // argument-validation failure the agent can act on — not the raw
            // engine message — so "payload: null" reads as an invalid-argument
            // 400 rather than an opaque internal throw.
            return ToolResult::failure(
                'bridge_invalid_args',
                sprintf('Invalid arguments for %s: %s', $this->method, $e->getMessage()),
                false,
            );
        } catch (\Throwable $e) {
            return ToolResult::failure('bridge_threw', $e->getMessage(), false);
        }

        if (is_object($result) && $result instanceof \WP_Error) {
            return ToolResult::failure(
                (string) $result->get_error_code(),
                (string) $result->get_error_message(),
                false,
            );
        }

        if ($this->resultShaper !== null) {
            $result = ($this->resultShaper)($result, $arguments);
        }

        $stateAfter = null;
        if ($this->stateExtractor !== null) {
            try {
                $stateAfter = ($this->stateExtractor)($result);
                if (is_object($stateAfter) && $stateAfter instanceof \WP_Error) {
                    $stateAfter = null;
                }
            } catch (\Throwable $e) {
                $stateAfter = ['state_extractor_failed' => $e->getMessage()];
            }
        }

        return ToolResult::ok($result, $stateAfter);
    }
}
