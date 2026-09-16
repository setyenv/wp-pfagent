<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Workflow graph → source language.
 *
 * Deterministic so a compile + decompile + compile round-trip yields
 * the same structural graph (ids may differ; node count, connection
 * count, kinds and wiring keys do not).
 *
 * Supports:
 *   - one trigger node + name + status preamble
 *   - studio.variables emitted as `let` declarations at the top of the
 *     flow body
 *   - linear exec chains of actions / transforms via `await verb(...)`
 *   - atomic comparison nodes (data.equals, data.greater_than, ...)
 *     recognised as if/else blocks
 *   - workflow.set_variable rendered as `<var> = value;`
 *   - workflow.stop rendered as `stop();`
 *   - delay / retry / approval / try-catch / loop-items
 *
 * Unrecognised constructs are emitted inside `/* region: not-yet-expressible
 * — leave untouched *\/` blocks describing the raw node. The compiler
 * rejects edits inside those regions so the LLM does not accidentally
 * corrupt them.
 */
final class Decompiler
{
    /** @var array<int, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<string, array<string, mixed>> */
    private array $byId = [];

    /** @var array<int, array<string, mixed>> */
    private array $exec = [];

    /** @var array<int, array<string, mixed>> */
    private array $data = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $execBySource = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $dataByTarget = [];

    /** @var array<string, bool> */
    private array $visited = [];

    /** @var array<string, string> */
    private array $aliasFor = [];

    /** @var array<int, array<string, mixed>> */
    private array $variables = [];

    private string $eventAlias = 'event';
    private string $workflowName = 'Untitled workflow';
    private string $workflowStatus = 'draft';
    private ?string $triggerKey = null;
    private ?string $triggerNodeId = null;
    private int $aliasCounter = 0;

    /**
     * HANDLE-GRAPH model: nodeId → the `const` handle name used in the
     * emitted source (the single trigger is always `event`).
     *
     * @var array<string, string>
     */
    private array $handleName = [];

    /**
     * Reverse of the virtual resolver: a lookup key derived from a node's
     * (verb, entity/entityFilter) → the typed identifier the LLM writes
     * (`Email$send_email`, `Incidentes$Created$Trigger`, ...).
     *
     * @var array<string, string>
     */
    private array $reverseResolver = [];

    /**
     * Decompile a workflow envelope into `.pfflow` source.
     *
     * F12 disambiguation: the input is the NORMALIZED workflow envelope
     * produced by {@see DecompileCache::refresh} or
     * {@see \ProjectFlash\Management\Agent\AgentWorkflowService::agent_workflow_full},
     * NOT the raw `wp_pfw_workflows.graph` JSON column. The expected shape is:
     *
     *   array{
     *       id?: string,
     *       name?: string,
     *       status?: string,
     *       graph: array{
     *           schemaVersion?: int,
     *           nodes: list<array<string,mixed>>,
     *           connections: list<array<string,mixed>>,
     *           studio?: array<string,mixed>,
     *       }
     *   }
     *
     * Passing the raw row (which has `nodes` / `edges` at the top level
     * instead of nested under `graph`) yields the degenerate
     * "// (workflow has no trigger; nothing to decompile)" output.
     *
     * @param array<string, mixed> $workflow Normalized envelope; see shape above.
     */
    public static function decompile(array $workflow): string
    {
        $self = new self();
        return $self->run($workflow);
    }

    /**
     * F11 Path B: look up the typed identifier (`Entity$Verb$Trigger`)
     * for a verb in dotted form (`entity.verb`). Inverts the
     * Compiler::virtualResolver map which is keyed on the typed form
     * and stores the dotted form under `verb`.
     *
     * Returns null when no typed form exists for the verb — happens for
     * verbs registered outside the three typings resolver filters. The
     * caller then falls back to the legacy quoted form, which Parser
     * Path A still accepts.
     */
    private static function resolveTypedTriggerKey(string $dottedKey): ?string
    {
        if ($dottedKey === '' || !str_contains($dottedKey, '.')) {
            return null;
        }
        $resolver = Compiler::virtualResolver(0);
        foreach ($resolver as $typed => $entry) {
            if (!is_string($typed) || !is_array($entry)) {
                continue;
            }
            if ((string) ($entry['verb'] ?? '') === $dottedKey && str_ends_with($typed, '$Trigger')) {
                return $typed;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private function run(array $workflow): string
    {
        $this->workflowName = (string) ($workflow['name'] ?? $workflow['workflow']['name'] ?? 'Untitled workflow');
        $this->workflowStatus = (string) ($workflow['status'] ?? $workflow['workflow']['status'] ?? 'draft');
        $graph = is_array($workflow['graph'] ?? null) ? $workflow['graph'] : [];

        $this->nodes = array_values(array_filter((array) ($graph['nodes'] ?? []), 'is_array'));
        foreach ($this->nodes as $node) {
            $this->byId[(string) $node['id']] = $node;
            if (($node['type'] ?? '') === 'trigger') {
                $this->triggerNodeId = (string) $node['id'];
                $this->triggerKey = (string) $node['key'];
            }
        }
        $studio = is_array($graph['studio'] ?? null) ? $graph['studio'] : [];
        $this->variables = array_values(array_filter((array) ($studio['variables'] ?? []), 'is_array'));

        foreach ((array) ($graph['connections'] ?? []) as $conn) {
            if (!is_array($conn)) {
                continue;
            }
            $kind = (string) ($conn['kind'] ?? 'data');
            if ($kind === 'exec') {
                $this->exec[] = $conn;
                $this->execBySource[(string) $conn['source']][] = $conn;
            } else {
                $this->data[] = $conn;
                $this->dataByTarget[(string) $conn['target']][] = $conn;
            }
        }

        $out = "// pfflow v1\n";
        if ($this->triggerKey === null) {
            return $out . "// (workflow has no trigger; nothing to decompile)\n";
        }

        // HANDLE-GRAPH emission. Every node becomes `const <handle> =
        // nodes.<Method>({ ... });` and every exec edge becomes a wiring
        // statement. INTEGRAL: no node is ever dropped — pure/data nodes off
        // the exec chain (a query feeding a later input) appear like any
        // other, which is exactly what the old exec-walk decompiler lost.
        $this->buildReverseResolver();
        $this->assignHandles();

        $out .= sprintf("name %s;\n", $this->quote($this->workflowName !== '' ? $this->workflowName : 'Untitled workflow'));
        $out .= sprintf("status %s;\n\n", $this->quote((string) ($this->workflowStatus !== '' ? $this->workflowStatus : 'draft')));

        // Variable inventory as a comment so the LLM sees which names exist
        // (they are operator-owned; the get/set nodes reference them).
        $out .= $this->emitVariableComments();

        // One declaration per node, in the graph's node order (stable).
        foreach ($this->nodes as $node) {
            $out .= $this->emitHandleDecl($node);
        }

        // Exec wiring, grouped per source output.
        $wiring = $this->emitWiring();
        if ($wiring !== '') {
            $out .= "\n" . $wiring;
        }

        return $out;
    }

    /**
     * Assign a stable, readable handle name to every node. The single
     * trigger is always `event`; the rest derive from their verb, deduped
     * with a numeric suffix.
     */
    private function assignHandles(): void
    {
        $used = [];
        if ($this->triggerNodeId !== null) {
            $this->handleName[$this->triggerNodeId] = 'event';
            $used['event'] = true;
        }
        foreach ($this->nodes as $node) {
            $id = (string) $node['id'];
            if (isset($this->handleName[$id])) {
                continue;
            }
            $base = $this->handleBaseFor($node);
            $name = $base;
            $i = 1;
            while (isset($used[$name]) || $name === 'nodes' || $name === 'event') {
                $i++;
                $name = $base . $i;
            }
            $used[$name] = true;
            $this->handleName[$id] = $name;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function handleBaseFor(array $node): string
    {
        $key = (string) ($node['key'] ?? 'node');
        $parts = explode('.', $key);
        $verb = end($parts) ?: 'node';
        $camel = lcfirst(str_replace(['_', '-'], '', ucwords($verb, '_-')));
        return $camel !== '' ? $camel : 'node';
    }

    /**
     * Build the reverse resolver: for every typed identifier the compiler
     * knows, index it by the (verb, entity/entityFilter) it produces so a
     * node can be turned back into the exact identifier the LLM writes.
     */
    private function buildReverseResolver(): void
    {
        $resolver = Compiler::virtualResolver(0);
        foreach ($resolver as $ident => $entry) {
            if (!is_string($ident) || !is_array($entry)) {
                continue;
            }
            // Variable getters/setters are reconstructed directly from the
            // node's variableName, not via this table.
            if (isset($entry['variableName'])) {
                continue;
            }
            $verb = (string) ($entry['verb'] ?? '');
            if ($verb === '') {
                continue;
            }
            $scope = (string) ($entry['entity'] ?? $entry['entityFilter'] ?? '');
            $lookup = $verb . '|' . $scope;
            // First identifier wins (deterministic — resolver order is stable).
            if (!isset($this->reverseResolver[$lookup])) {
                $this->reverseResolver[$lookup] = $ident;
            }
        }
    }

    /**
     * Turn a node back into the typed `nodes.<Method>` identifier.
     *
     * @param array<string, mixed> $node
     */
    private function reverseIdentifier(array $node): string
    {
        $key = (string) ($node['key'] ?? '');
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];

        // Variable get/set → <Name>$Variable$Get / $Set from variableName.
        if ($key === 'workflow.get_variable' || $key === 'workflow.set_variable') {
            $varName = trim((string) ($data['variableName'] ?? $data['variableId'] ?? ''));
            $ident = $this->variableIdent($varName !== '' ? $varName : $this->variableName((string) ($data['variableId'] ?? '')));
            $op = $key === 'workflow.get_variable' ? 'Get' : 'Set';
            return $ident . '$Variable$' . $op;
        }

        // Entity/plain node via the reverse resolver, keyed on (verb, scope).
        $scope = (string) ($data['entity'] ?? $data['entityFilter'] ?? '');
        $lookup = $key . '|' . $scope;
        if (isset($this->reverseResolver[$lookup])) {
            return $this->reverseResolver[$lookup];
        }
        // Same verb, any scope (covers plain nodes whose resolver entry has
        // no scope while the node carries none either).
        if (isset($this->reverseResolver[$key . '|'])) {
            return $this->reverseResolver[$key . '|'];
        }

        // Fallback: reconstruct the PFW scheme from the dotted key + kind.
        $dot = strpos($key, '.');
        if ($dot === false) {
            return $key;
        }
        $cat = substr($key, 0, $dot);
        $verb = substr($key, $dot + 1);
        $ident = ucfirst($cat) . '$' . $verb;
        if ((string) ($node['type'] ?? '') === 'trigger') {
            $ident .= '$Trigger';
        }
        return $ident;
    }

    /**
     * Slugify a variable name to its TS identifier, matching
     * VariablesTypingsBuilder::tsIdent (clean → ucfirst).
     */
    private function variableIdent(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '_', trim($name)) ?? '';
        if ($clean === '') {
            return 'Variable';
        }
        if (preg_match('/^[0-9]/', $clean)) {
            $clean = '_' . $clean;
        }
        return ucfirst($clean);
    }

    private function emitVariableComments(): string
    {
        if ($this->variables === []) {
            return '';
        }
        $out = '';
        foreach ($this->variables as $var) {
            $name = (string) ($var['name'] ?? $var['id'] ?? '');
            if ($name === '') {
                continue;
            }
            $default = $var['default'] ?? ($var['defaultValue'] ?? null);
            $out .= sprintf("// variable %s = %s\n", $this->variableIdent($name), $this->literal($default));
        }
        return $out . "\n";
    }

    /**
     * `const <handle> = nodes.<Method>({ <args> });`
     *
     * @param array<string, mixed> $node
     */
    private function emitHandleDecl(array $node): string
    {
        $id = (string) $node['id'];
        $handle = $this->handleName[$id] ?? $id;
        $method = $this->reverseIdentifier($node);
        $args = $this->collectArgs($node);
        if ($args === '') {
            return sprintf("const %s = nodes.%s();\n", $handle, $method);
        }
        return sprintf("const %s = nodes.%s({ %s });\n", $handle, $method, $args);
    }

    /**
     * Build the `{ key: value, ... }` argument list for a node: data inputs
     * become `pin: <srcHandle>.out.<srcOut>`; config literals become
     * `key: <literal>`. Compiler-injected structural keys (entity /
     * entityFilter / variableName / variableId) are omitted — they are
     * encoded in the method identifier, not authored.
     *
     * @param array<string, mixed> $node
     */
    private function collectArgs(array $node): string
    {
        $id = (string) $node['id'];
        $key = (string) ($node['key'] ?? '');
        $verb = VerbCatalog::find($key);
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $structural = ['entity' => true, 'entityFilter' => true, 'variableName' => true, 'variableId' => true];
        $parts = [];

        $inputs = $verb !== null && is_array($verb['inputs'] ?? null) ? $verb['inputs'] : [];
        foreach ($inputs as $pin) {
            $k = (string) ($pin['key'] ?? '');
            if ($k === '') {
                continue;
            }
            $conn = null;
            foreach ($this->dataByTarget[$id] ?? [] as $c) {
                if ((string) ($c['targetInput'] ?? '') === $k) {
                    $conn = $c;
                    break;
                }
            }
            if ($conn !== null) {
                $parts[] = sprintf('%s: %s', $this->safeKey($k), $this->handleRef((string) $conn['source'], (string) ($conn['sourceOutput'] ?? '')));
                continue;
            }
            if (array_key_exists($k, $data) && !isset($structural[$k])) {
                $parts[] = sprintf('%s: %s', $this->safeKey($k), $this->literal($data[$k]));
            }
        }

        $config = $verb !== null && is_array($verb['config'] ?? null) ? $verb['config'] : [];
        foreach ($config as $field) {
            $k = (string) ($field['key'] ?? '');
            if ($k === '' || isset($structural[$k]) || !array_key_exists($k, $data)) {
                continue;
            }
            $parts[] = sprintf('%s: %s', $this->safeKey($k), $this->literal($data[$k]));
        }

        return implode(', ', $parts);
    }

    /**
     * `<handle>.out.<pin>` reference to another node's output.
     */
    private function handleRef(string $sourceNodeId, string $output): string
    {
        $handle = $this->handleName[$sourceNodeId] ?? null;
        if ($handle === null) {
            return '/* missing source ' . $sourceNodeId . ' */ null';
        }
        if ($output === '') {
            return $handle . '.out';
        }
        return $handle . '.out.' . $output;
    }

    /**
     * Emit the exec wiring: for each node, one statement per exec output.
     */
    private function emitWiring(): string
    {
        // Group exec edges by source → output → [targets], preserving order.
        $grouped = [];
        foreach ($this->exec as $c) {
            $src = (string) ($c['source'] ?? '');
            $outp = (string) ($c['sourceOutput'] ?? 'next');
            $grouped[$src][$outp][] = (string) ($c['target'] ?? '');
        }
        $out = '';
        foreach ($this->nodes as $node) {
            $id = (string) $node['id'];
            if (!isset($grouped[$id])) {
                continue;
            }
            $srcHandle = $this->handleName[$id] ?? $id;
            foreach ($grouped[$id] as $outp => $targets) {
                $method = $this->execMethodFor((string) $outp);
                $handles = [];
                foreach ($targets as $t) {
                    $handles[] = $this->handleName[$t] ?? $t;
                }
                $out .= sprintf("%s.%s([%s]);\n", $srcHandle, $method, implode(', ', $handles));
            }
        }
        return $out;
    }

    /**
     * Map a graph exec output socket to its handle-model method name.
     * next → exeOut, yes → exeOutYes, no → exeOutNo. Any other (legacy /
     * exotic) socket becomes exeOut<Camel> so it stays visible even though
     * the current compiler only wires the three canonical ones.
     */
    private function execMethodFor(string $output): string
    {
        switch ($output) {
            case 'next':
            case '':
                return 'exeOut';
            case 'yes':
                return 'exeOutYes';
            case 'no':
                return 'exeOutNo';
            default:
                return 'exeOut' . ucfirst(str_replace(['_', '-'], '', ucwords($output, '_-')));
        }
    }

    private function emitVariableDeclarations(int $indent): string
    {
        if ($this->variables === []) {
            return '';
        }
        $out = '';
        foreach ($this->variables as $var) {
            // Variables are operator-owned metadata (declared via the
            // variable editor, never by source). Emit them as inline
            // comments so the LLM can see which names exist and their
            // defaults, but the Compiler does NOT auto-create them (any
            // `let X = ...` reaching the parser is rejected with
            // `let_declares_variable`). Round-trip safe.
            $id = (string) ($var['id'] ?? '');
            if ($id === '') {
                $name = (string) ($var['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $id = preg_replace('/[^a-zA-Z0-9_]+/', '_', $name) ?: '';
                $id = trim($id, '_');
                if ($id === '') {
                    continue;
                }
            }
            $default = $var['default'] ?? ($var['defaultValue'] ?? null);
            $out .= str_repeat('  ', $indent) . sprintf("// variable %s = %s\n", $id, $this->literal($default));
        }
        return $out . "\n";
    }

    /**
     * Emit statements following exec edges from $startNodeId. The trigger
     * itself is implied by the preamble, so for the trigger we step
     * through its `next` exec output and emit its successors.
     */
    private function emitFromExec(string $startNodeId, int $indent): string
    {
        $out = '';
        $cursor = $startNodeId;
        $cursorOutput = 'next';

        while (true) {
            $next = $this->followExec($cursor, $cursorOutput);
            if ($next === null) {
                break;
            }
            $targetId = (string) $next['target'];
            if (isset($this->visited[$targetId])) {
                break;
            }
            $this->visited[$targetId] = true;
            $node = $this->byId[$targetId] ?? null;
            if (!is_array($node)) {
                break;
            }
            if (($node['type'] ?? '') === 'trigger') {
                break;
            }
            $result = $this->emitNodeStatement($node, $indent);
            $out .= $result['text'];
            if ($result['continueFrom'] === null) {
                break;
            }
            $cursor = $result['continueFrom'];
            $cursorOutput = $result['continueOutput'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{text: string, continueFrom: ?string, continueOutput: string}
     */
    private function emitNodeStatement(array $node, int $indent): array
    {
        $key = (string) $node['key'];

        if (self::isComparisonNodeKey($key)) {
            return $this->emitConditionStatement($node, $indent);
        }
        if ($key === 'workflow.try_catch') {
            return $this->emitTryStatement($node, $indent);
        }
        if ($key === 'workflow.loop_items') {
            return $this->emitForOfStatement($node, $indent);
        }
        if ($key === 'workflow.set_variable') {
            return ['text' => $this->emitSetVariable($node, $indent), 'continueFrom' => (string) $node['id'], 'continueOutput' => 'next'];
        }
        if ($key === 'workflow.stop') {
            return ['text' => str_repeat('  ', $indent) . "stop();\n", 'continueFrom' => null, 'continueOutput' => 'next'];
        }
        if ($key === 'workflow.delay') {
            // The delay duration lives in the node's config slots (amount +
            // unit) — design-time literals, NOT a data pin. The previous
            // code read a `seconds` slot that the current delay schema does
            // not have, so EVERY delay decompiled to `delay(null)`; a
            // read → edit → write round-trip then recompiled that null and
            // WIPED the configured duration (the setting fell back to the
            // 5-minute default). Emit the canonical call with the literal
            // settings so the round-trip preserves what the operator set.
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $args = [];
            foreach (['amount', 'unit'] as $slot) {
                if (array_key_exists($slot, $data) && $data[$slot] !== null && $data[$slot] !== '') {
                    $args[$slot] = $data[$slot];
                }
            }
            // Legacy graphs stored the whole duration as a single numeric
            // `seconds` slot; surface it as amount+unit so it round-trips
            // into the current schema instead of being dropped.
            if ($args === [] && isset($data['seconds']) && is_numeric($data['seconds'])) {
                $args = ['amount' => (int) $data['seconds'], 'unit' => 'seconds'];
            }
            $argExpr = $args === [] ? 'null' : $this->literal($args);
            return ['text' => str_repeat('  ', $indent) . "await Workflow\$delay($argExpr);\n", 'continueFrom' => (string) $node['id'], 'continueOutput' => 'next'];
        }
        if ($key === 'workflow.retry_block') {
            return $this->emitRetryStatement($node, $indent);
        }
        if ($key === 'workflow.approval_gate') {
            return $this->emitApprovalStatement($node, $indent);
        }
        if ($key === 'workflow.merge') {
            // Pass-through, continue from the merge's next.
            return ['text' => '', 'continueFrom' => (string) $node['id'], 'continueOutput' => 'next'];
        }
        return ['text' => $this->emitVerbCallStatement($node, $indent), 'continueFrom' => (string) $node['id'], 'continueOutput' => 'next'];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{text: string, continueFrom: ?string, continueOutput: string}
     */
    private function emitConditionStatement(array $node, int $indent): array
    {
        // Comparison nodes carry the operator IN THE KEY (data.equals,
        // data.greater_than, data.is_empty, …). Unary tests read `value`,
        // binary tests read `left` + `right`. Map the key back to the
        // surface operator so the body of the `if` reads like JS.
        $key = (string) ($node['key'] ?? '');
        [$operator, $is_unary] = self::operatorForComparisonNodeKey($key);
        if ($is_unary) {
            $valueDesc = $this->slotValueDescriptor($node, 'value');
            $cond_expr = $this->unaryExprText($operator, $valueDesc);
        } else {
            $leftDesc = $this->slotValueDescriptor($node, 'left');
            $rightDesc = $this->slotValueDescriptor($node, 'right');
            $cond_expr = $this->binaryExprText($operator, $leftDesc, $rightDesc);
        }

        $pad = str_repeat('  ', $indent);
        $out = $pad . sprintf("if (%s) {\n", $cond_expr);
        $out .= $this->emitBranchBody((string) $node['id'], 'yes', $indent + 1);
        $out .= $pad . "}";
        // Emit `else { ... }` only when the no-branch carries actual
        // statements. The compiler always wires cond.no → merge for
        // every if (so the merge sees both branches), which means a
        // hasExecBranch('no') check ALWAYS returns true and used to
        // produce a bare `else {}` block for if-without-else.
        // Distinguish the two by looking at whether the body is non-
        // empty — when it's nothing but the auto-merge wire, skip
        // the else entirely.
        if ($this->hasExecBranch((string) $node['id'], 'no')) {
            $no_body = $this->emitBranchBody((string) $node['id'], 'no', $indent + 1);
            if (trim($no_body) !== '') {
                $out .= " else {\n" . $no_body . $pad . "}";
            }
        }
        $out .= "\n";

        // Find the merge node both branches converge into (if any) and
        // continue from its `next` output.
        $merge_id = $this->findMergeForCondition((string) $node['id']);
        if ($merge_id !== null) {
            $this->visited[$merge_id] = true;
            return ['text' => $out, 'continueFrom' => $merge_id, 'continueOutput' => 'next'];
        }
        return ['text' => $out, 'continueFrom' => null, 'continueOutput' => 'next'];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{text: string, continueFrom: ?string, continueOutput: string}
     */
    private function emitTryStatement(array $node, int $indent): array
    {
        $pad = str_repeat('  ', $indent);
        $out = $pad . "try {\n";
        $out .= $this->emitBranchBody((string) $node['id'], 'try', $indent + 1);
        $out .= $pad . "} catch (e) {\n";
        $out .= $this->emitBranchBody((string) $node['id'], 'catch', $indent + 1);
        $out .= $pad . "}\n";
        $merge_id = $this->findMergeForBranches((string) $node['id'], ['try', 'catch']);
        if ($merge_id !== null) {
            $this->visited[$merge_id] = true;
            return ['text' => $out, 'continueFrom' => $merge_id, 'continueOutput' => 'next'];
        }
        return ['text' => $out, 'continueFrom' => null, 'continueOutput' => 'next'];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{text: string, continueFrom: ?string, continueOutput: string}
     */
    private function emitForOfStatement(array $node, int $indent): array
    {
        $pad = str_repeat('  ', $indent);
        $items_expr = $this->renderValueExpression($this->slotValueDescriptor($node, 'items'));
        $item_name = 'item';   // we lose the original name in the graph; reuse 'item' as a stable default
        $out = $pad . sprintf("for (const %s of %s) {\n", $item_name, $items_expr);
        $out .= $this->emitBranchBody((string) $node['id'], 'item', $indent + 1);
        $out .= $pad . "}\n";
        return ['text' => $out, 'continueFrom' => (string) $node['id'], 'continueOutput' => 'complete'];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{text: string, continueFrom: ?string, continueOutput: string}
     */
    private function emitRetryStatement(array $node, int $indent): array
    {
        $pad = str_repeat('  ', $indent);
        $policy = is_array($node['data'] ?? null) ? $node['data'] : [];
        $out = $pad . "await retry(" . $this->literal($policy) . ", async () => {\n";
        $out .= $this->emitBranchBody((string) $node['id'], 'attempt', $indent + 1);
        $out .= $pad . "});\n";
        return ['text' => $out, 'continueFrom' => (string) $node['id'], 'continueOutput' => 'next'];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{text: string, continueFrom: ?string, continueOutput: string}
     */
    private function emitApprovalStatement(array $node, int $indent): array
    {
        $pad = str_repeat('  ', $indent);
        $config = is_array($node['data'] ?? null) ? $node['data'] : [];
        $out = $pad . "await approval(" . $this->literal($config) . ");\n";
        return ['text' => $out, 'continueFrom' => (string) $node['id'], 'continueOutput' => 'approve'];
    }

    /**
     * Generalised merge-finder: walk each branch from a multi-output
     * decision node and return the first node id that appears on more
     * than one branch — that is the merge.
     *
     * @param array<int, string> $outputs
     */
    private function findMergeForBranches(string $decisionId, array $outputs): ?string
    {
        $branch_visited = [];
        foreach ($outputs as $branch) {
            $branch_visited[$branch] = [];
            $cursor = $decisionId;
            $cur_out = $branch;
            $steps = 0;
            while ($steps++ < 200) {
                $next = $this->followExec($cursor, $cur_out);
                if ($next === null) {
                    break;
                }
                $targetId = (string) $next['target'];
                $node = $this->byId[$targetId] ?? null;
                if (!is_array($node)) {
                    break;
                }
                if (($node['key'] ?? '') === 'workflow.merge') {
                    return $targetId;
                }
                $branch_visited[$branch][$targetId] = true;
                $cursor = $targetId;
                $cur_out = 'next';
            }
        }
        return null;
    }

    /**
     * Look at both yes/no branches of a condition and return the merge
     * node id they converge into, if any. The compiler always inserts a
     * workflow.merge after if/else so this is reliable in compiled output.
     */
    private function findMergeForCondition(string $condNodeId): ?string
    {
        foreach (['yes', 'no'] as $branch) {
            $cursor = $condNodeId;
            $cursorOutput = $branch;
            $steps = 0;
            while ($steps++ < 200) {
                $next = $this->followExec($cursor, $cursorOutput);
                if ($next === null) {
                    break;
                }
                $targetId = (string) $next['target'];
                $node = $this->byId[$targetId] ?? null;
                if (!is_array($node)) {
                    break;
                }
                if (($node['key'] ?? '') === 'workflow.merge') {
                    return $targetId;
                }
                $cursor = $targetId;
                $cursorOutput = 'next';
            }
        }
        return null;
    }

    private function emitBranchBody(string $condNodeId, string $branch, int $indent): string
    {
        $out = '';
        $cursor = $condNodeId;
        $cursorOutput = $branch;
        while (true) {
            $next = $this->followExec($cursor, $cursorOutput);
            if ($next === null) {
                break;
            }
            $targetId = (string) $next['target'];
            $node = $this->byId[$targetId] ?? null;
            if (!is_array($node)) {
                break;
            }
            // Stop at merge — the merge is consumed by the parent loop.
            if (($node['key'] ?? '') === 'workflow.merge') {
                break;
            }
            if (isset($this->visited[$targetId])) {
                break;
            }
            $this->visited[$targetId] = true;
            $result = $this->emitNodeStatement($node, $indent);
            $out .= $result['text'];
            if ($result['continueFrom'] === null) {
                break;
            }
            $cursor = $result['continueFrom'];
            $cursorOutput = $result['continueOutput'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function emitSetVariable(array $node, int $indent): string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $var_id = (string) ($data['variableId'] ?? '');
        $name = $this->variableName($var_id);
        // Input pin is `valueToSet` (SetVariableNode::manifest).
        // The legacy `value` name pre-2026-05-24 still appears in
        // older persisted graphs — fall back to it so the
        // decompiler keeps round-tripping workflows compiled
        // before the rename.
        $valueDesc = $this->slotValueDescriptor($node, 'valueToSet');
        if (($valueDesc['kind'] ?? '') === 'literal' && ($valueDesc['value'] ?? null) === null) {
            $legacy = $this->slotValueDescriptor($node, 'value');
            if (($legacy['kind'] ?? '') !== 'literal' || ($legacy['value'] ?? null) !== null) {
                $valueDesc = $legacy;
            }
        }
        return str_repeat('  ', $indent) . sprintf("%s = %s;\n", $name !== '' ? $name : $var_id, $this->renderValueExpression($valueDesc));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function emitVerbCallStatement(array $node, int $indent): string
    {
        $key = (string) $node['key'];
        $verb = VerbCatalog::find($key);
        if ($verb === null) {
            return $this->emitNotExpressible($node, $indent, sprintf('Node "%s" is not in the catalogue.', $key));
        }
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $args = [];
        // Inputs first.
        foreach ((array) $verb['inputs'] as $pin) {
            $k = (string) $pin['key'];
            if (!$this->slotHasValue($node, $k, $data)) {
                continue;
            }
            $args[$k] = $this->slotValueDescriptor($node, $k);
        }
        // Then config.
        foreach ((array) $verb['config'] as $field) {
            $k = (string) $field['key'];
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $args[$k] = $this->literalDescriptor($data[$k]);
        }

        $captureName = $this->maybeAlias((string) $node['id'], $verb);
        $callExpr = $this->renderVerbCall($verb['category'], $verb['verb'], $args);
        $pad = str_repeat('  ', $indent);
        if ($captureName !== null) {
            return $pad . sprintf("const %s = await %s;\n", $captureName, $callExpr);
        }
        return $pad . sprintf("await %s;\n", $callExpr);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function emitNotExpressible(array $node, int $indent, string $reason): string
    {
        $pad = str_repeat('  ', $indent);
        $json = wp_json_encode($node) ?: '{}';
        $out = $pad . "/* region: not-yet-expressible — leave untouched\n";
        $out .= $pad . sprintf(" * Reason: %s\n", $reason);
        foreach (explode("\n", $json) as $line) {
            $out .= $pad . " * " . $line . "\n";
        }
        $out .= $pad . " */\n";
        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function slotHasValue(array $node, string $slot, array $data): bool
    {
        if (array_key_exists($slot, $data)) {
            return true;
        }
        foreach ($this->dataByTarget[(string) $node['id']] ?? [] as $conn) {
            if ((string) ($conn['targetInput'] ?? '') === $slot) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a descriptor for the value flowing into a given slot:
     *   either a data connection's source (becomes a const alias or token)
     *   or the literal in node.data[slot].
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function slotValueDescriptor(array $node, string $slot): array
    {
        foreach ($this->dataByTarget[(string) $node['id']] ?? [] as $conn) {
            if ((string) ($conn['targetInput'] ?? '') === $slot) {
                $src_id = (string) ($conn['source'] ?? '');
                $src_out = (string) ($conn['sourceOutput'] ?? '');
                return ['kind' => 'dataPin', 'sourceNodeId' => $src_id, 'sourceOutput' => $src_out];
            }
        }
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        if (array_key_exists($slot, $data)) {
            return $this->literalDescriptor($data[$slot]);
        }
        return ['kind' => 'literal', 'value' => null, 'type' => 'null'];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function literalDescriptor($value): array
    {
        if (is_string($value)) {
            return ['kind' => 'string', 'value' => $value];
        }
        if (is_int($value)) {
            return ['kind' => 'literal', 'value' => $value, 'type' => 'integer'];
        }
        if (is_float($value)) {
            return ['kind' => 'literal', 'value' => $value, 'type' => 'number'];
        }
        if (is_bool($value)) {
            return ['kind' => 'literal', 'value' => $value, 'type' => 'boolean'];
        }
        if (is_array($value)) {
            return ['kind' => 'literal', 'value' => $value, 'type' => array_is_list($value) ? 'array' : 'object'];
        }
        return ['kind' => 'literal', 'value' => null, 'type' => 'null'];
    }

    /**
     * @param array<string, array<string, mixed>> $args
     */
    private function renderVerbCall(string $category, string $verb, array $args): string
    {
        if ($args === []) {
            return sprintf('%s.%s({})', $category, $verb);
        }
        $parts = [];
        foreach ($args as $key => $desc) {
            $parts[] = sprintf('%s: %s', $key, $this->renderValueExpression($desc));
        }
        return sprintf('%s.%s({ %s })', $category, $verb, implode(', ', $parts));
    }

    /**
     * @param array<string, mixed> $desc
     */
    private function renderValueExpression(array $desc): string
    {
        $kind = (string) ($desc['kind'] ?? '');
        if ($kind === 'dataPin') {
            $src_id = (string) $desc['sourceNodeId'];
            $out = (string) $desc['sourceOutput'];
            $src_node = $this->byId[$src_id] ?? null;
            // Trigger pins: every wire from the trigger renders as
            // `event.<out>`. Triggers have no "primary output" — every
            // declared pin (sys_id, estado, tt1, the entire entity
            // record's field set) is equally addressable, so the
            // const-alias primary-output shortcut applied below must
            // NOT fire here. Falling through to that branch produced
            // bare `event` references that dropped the pin name
            // entirely (a wire from trigger.sys_id rendered as `event`
            // instead of `event.sys_id`).
            if (is_array($src_node) && (string) ($src_node['type'] ?? '') === 'trigger') {
                return 'event.' . $out;
            }
            // workflow.get_variable extractor → render as the
            // variable's bare identifier (the same way the source
            // language refers to a let-bound name). The compiler
            // synthesises one of these per consumer reference to a
            // flow variable. Output pin is `valueToGet`
            // (GetVariableNode::manifest, since 2026-05-24). Legacy
            // graphs persisted before the rename still carry `value`
            // on the wire's sourceOutput, so accept both — round-trip
            // remains stable either way and the LLM-generated source
            // always uses the variable's bare name regardless.
            if (is_array($src_node) && (string) ($src_node['key'] ?? '') === 'workflow.get_variable') {
                $name = trim((string) ($src_node['data']['variableName'] ?? ''));
                if ($name !== '') {
                    if ($out === 'valueToGet' || $out === 'value') return $name;
                    if (strncmp($out, 'valueToGet.', 11) === 0) return $name . '.' . substr($out, 11);
                    if (strncmp($out, 'value.', 6) === 0) return $name . '.' . substr($out, 6);
                }
            }
            // data.text_render extractor → reconstruct the original
            // template literal from the format string + wired inputs.
            if (is_array($src_node) && (string) ($src_node['key'] ?? '') === 'data.text_render' && $out === 'result') {
                $template = $this->collapseTextRender($src_node);
                if ($template !== null) {
                    return $template;
                }
            }
            // data.literal_value source → render the literal back inline
            // (the compiler hoists inline action literals into these
            // value-source nodes; the source language keeps them as
            // inline expressions for readability).
            if (is_array($src_node) && (string) ($src_node['key'] ?? '') === 'data.literal_value' && $out === 'value') {
                $rendered = $this->collapseLiteralValue($src_node);
                if ($rendered !== null) {
                    return $rendered;
                }
            }
            $name = $this->aliasForNode($src_id);
            $verb = $this->verbForNode($src_id);
            $primary = $verb !== null ? VerbCatalog::primaryOutputKey($verb) : null;
            if ($primary !== null && $primary === $out) {
                return $name;
            }
            return $name . '.' . $out;
        }
        if ($kind === 'string') {
            return $this->renderStringValue((string) $desc['value']);
        }
        if ($kind === 'literal') {
            return $this->literal($desc['value']);
        }
        return 'null';
    }

    /**
     * Reconstruct a `\`...${expr}...\`` template literal from a
     * data.text_render node. The node's `format` config holds the
     * Python-style positional template (`Hola {0}, son {1}`); each `{N}`
     * placeholder corresponds to a wired input `value_N` whose upstream
     * source renders as the embedded expression. Literal `{` / `}` are
     * encoded as `{{` / `}}` per the runtime's escape rule.
     *
     * @param array<string, mixed> $node
     */
    private function collapseTextRender(array $node): ?string
    {
        // format is now an input pin (compiler hoists the template into a
        // data.literal_value source). Fall back to legacy data['format']
        // for graphs compiled before the change.
        $format = (string) ($this->resolveLiteralStringOnPin((string) ($node['id'] ?? ''), 'format')
            ?? (string) ($node['data']['format'] ?? ''));
        if ($format === '') {
            return null;
        }
        $incoming = $this->dataByTarget[(string) $node['id']] ?? [];
        $byInput = [];
        foreach ($incoming as $c) {
            $byInput[(string) ($c['targetInput'] ?? '')] = $c;
        }

        // Walk the format string. `{{` → literal `{`, `}}` → literal `}`,
        // `{N}` → resolve value_N upstream and inline as `${expr}`.
        $out = '`';
        $i = 0;
        $len = strlen($format);
        while ($i < $len) {
            $ch = $format[$i];
            if ($ch === '{' && $i + 1 < $len && $format[$i + 1] === '{') {
                $out .= $this->escapeTemplateSegment('{');
                $i += 2;
                continue;
            }
            if ($ch === '}' && $i + 1 < $len && $format[$i + 1] === '}') {
                $out .= $this->escapeTemplateSegment('}');
                $i += 2;
                continue;
            }
            if ($ch === '{') {
                $close = strpos($format, '}', $i + 1);
                if ($close !== false && ctype_digit(substr($format, $i + 1, $close - $i - 1))) {
                    $index = (int) substr($format, $i + 1, $close - $i - 1);
                    $conn = $byInput['value_' . $index] ?? null;
                    if ($conn !== null) {
                        $desc = [
                            'kind' => 'dataPin',
                            'sourceNodeId' => (string) $conn['source'],
                            'sourceOutput' => (string) ($conn['sourceOutput'] ?? 'value'),
                        ];
                        $out .= '${' . $this->renderValueExpression($desc) . '}';
                    } else {
                        $out .= '${null}';
                    }
                    $i = $close + 1;
                    continue;
                }
            }
            $out .= $this->escapeTemplateSegment($ch);
            $i++;
        }
        $out .= '`';
        return $out;
    }

    /**
     * Build a dataPin descriptor for a connection and render it via
     * renderValueExpression so nested extractor chains collapse back to
     * their inline source expression.
     *
     * @param array<string, mixed> $conn
     */
    private function renderConnectionValue(array $conn): string
    {
        $desc = [
            'kind' => 'dataPin',
            'sourceNodeId' => (string) $conn['source'],
            'sourceOutput' => (string) ($conn['sourceOutput'] ?? 'value'),
        ];
        return $this->renderValueExpression($desc);
    }

    /**
     * When an input pin is wired to a data.literal_value source whose
     * valueType is string, return the raw string value. Used by the
     * collapsers for operator args that the refactor moved from `config`
     * to `inputs` (object_get.path, object_set.path, text_render.format).
     * Returns null when the pin is unwired, the upstream is not a
     * literal_value, or the literal's type is not string.
     */
    private function resolveLiteralStringOnPin(string $node_id, string $pin_key): ?string
    {
        if ($node_id === '') {
            return null;
        }
        $incoming = $this->dataByTarget[$node_id] ?? [];
        foreach ($incoming as $c) {
            if ((string) ($c['targetInput'] ?? '') !== $pin_key) {
                continue;
            }
            $upstream_id = (string) ($c['source'] ?? '');
            $upstream_out = (string) ($c['sourceOutput'] ?? '');
            if ($upstream_out !== 'value') {
                return null;
            }
            $upstream_node = $this->byId[$upstream_id] ?? null;
            if (!is_array($upstream_node) || (string) ($upstream_node['key'] ?? '') !== 'data.literal_value') {
                return null;
            }
            $data = is_array($upstream_node['data'] ?? null) ? $upstream_node['data'] : [];
            $type = (string) ($data['valueType'] ?? 'string');
            if ($type !== '' && $type !== 'string') {
                return null;
            }
            return (string) ($data['value'] ?? '');
        }
        return null;
    }

    /**
     * Render a data.literal_value source node back as an inline literal
     * expression (the inverse of Compiler::emitLiteralValueExtractor).
     * Honours the node's `valueType` config so booleans render as
     * `true`/`false`, numbers as bare numerics, objects as `{...}`, etc.
     *
     * @param array<string, mixed> $node
     */
    private function collapseLiteralValue(array $node): ?string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $raw = (string) ($data['value'] ?? '');
        $type = (string) ($data['valueType'] ?? 'string');
        return match ($type) {
            'integer' => (string) (int) $raw,
            'number', 'float' => (string) (float) $raw,
            'boolean', 'bool' => ($raw === 'true' || $raw === '1') ? 'true' : 'false',
            'null' => 'null',
            'object', 'array', 'json' => $this->renderJsonLiteral($raw),
            default => $this->quote($raw),
        };
    }

    private function renderJsonLiteral(string $raw): string
    {
        if ($raw === '' || $raw === 'null') {
            return 'null';
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return 'null';
        }
        return $this->literal($decoded);
    }

    private function renderStringValue(string $value): string
    {
        // If the value contains any {{token}}, render as a template literal.
        if (!preg_match('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', $value)) {
            return $this->quote($value);
        }
        // If the value is EXACTLY one {{token}} with no surrounding text,
        // emit the bare expression (template-literal wrapping is needless).
        if (preg_match('/^{{\s*([a-zA-Z0-9_.]+)\s*}}$/', $value, $m)) {
            return $this->tokenToExpression((string) $m[1]);
        }
        $out = '`';
        $pos = 0;
        while (preg_match('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', $value, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            if ($start > $pos) {
                $out .= $this->escapeTemplateSegment(substr($value, $pos, $start - $pos));
            }
            $out .= '${' . $this->tokenToExpression((string) $m[1][0]) . '}';
            $pos = $start + strlen($m[0][0]);
        }
        if ($pos < strlen($value)) {
            $out .= $this->escapeTemplateSegment(substr($value, $pos));
        }
        $out .= '`';
        return $out;
    }

    private function escapeTemplateSegment(string $value): string
    {
        return str_replace(['\\', '`', '${'], ['\\\\', '\\`', '\\${'], $value);
    }

    private function tokenToExpression(string $token): string
    {
        // event.X → event.X
        if (strncmp($token, 'event.', 6) === 0 || $token === 'event') {
            return $token;
        }
        // variable.X → resolve to declared name
        if (strncmp($token, 'variable.', 9) === 0) {
            $id = substr($token, 9);
            $name = $this->variableName($id);
            return $name !== '' ? $name : $id;
        }
        // node.<id>.<out> → alias[.out]
        if (strncmp($token, 'node.', 5) === 0) {
            $rest = substr($token, 5);
            $dot = strpos($rest, '.');
            if ($dot !== false) {
                $id = substr($rest, 0, $dot);
                $out = substr($rest, $dot + 1);
                $alias = $this->aliasForNode($id);
                $verb = $this->verbForNode($id);
                $primary = $verb !== null ? VerbCatalog::primaryOutputKey($verb) : null;
                if ($primary !== null && $out === $primary) {
                    return $alias;
                }
                return $alias . '.' . $out;
            }
        }
        if (strncmp($token, 'site.', 5) === 0 || $token === 'site' ||
            strncmp($token, 'execution.', 10) === 0 || $token === 'execution' ||
            strncmp($token, 'workflow.', 9) === 0) {
            return $token;
        }
        return $token;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function binaryExprText(string $operator, array $left, array $right): string
    {
        $map = [
            'equals' => '===',
            'not_equals' => '!==',
            'greater_than' => '>',
            'less_than' => '<',
            'greater_or_equal' => '>=',
            'less_or_equal' => '<=',
        ];
        if (!isset($map[$operator])) {
            // Operators that do NOT have a JS infix form (contains,
            // starts_with, between, in, regex, ...) render as a verb call
            // so the source remains roundtrippable through the new
            // comparison nodes.
            return sprintf('await data.%s({ left: %s, right: %s })',
                $operator,
                $this->renderValueExpression($left),
                $this->renderValueExpression($right)
            );
        }
        return sprintf('%s %s %s', $this->renderValueExpression($left), $map[$operator], $this->renderValueExpression($right));
    }

    /**
     * Render a unary comparison (data.is_empty / data.is_not_empty /
     * data.exists / data.not_exists) back as a JS boolean expression.
     */
    private function unaryExprText(string $operator, array $value): string
    {
        $expr = $this->renderValueExpression($value);
        switch ($operator) {
            case 'is_empty':
                return '!' . $expr;
            case 'is_not_empty':
                return $expr;
            case 'exists':
                return $expr . ' !== null';
            case 'not_exists':
                return $expr . ' === null';
            default:
                return sprintf('await data.%s({ value: %s })', $operator, $expr);
        }
    }

    /**
     * True when the node key is one of the comparison nodes that should
     * decompile back to an `if (...)` block. Mirrors the compiler's
     * comparisonNodeForOperator map.
     */
    private static function isComparisonNodeKey(string $key): bool
    {
        $known = [
            'data.equals' => true, 'data.not_equals' => true,
            'data.contains' => true, 'data.not_contains' => true,
            'data.starts_with' => true, 'data.ends_with' => true,
            'data.greater_than' => true, 'data.less_than' => true,
            'data.greater_or_equal' => true, 'data.less_or_equal' => true,
            'data.is_in' => true, 'data.is_not_in' => true,
            'data.contains_all' => true, 'data.length_equals' => true,
            'data.between' => true, 'data.matches_regex' => true,
            'data.is_before' => true, 'data.is_after' => true,
            'data.is_empty' => true, 'data.is_not_empty' => true,
            'data.exists' => true, 'data.not_exists' => true,
        ];
        return isset($known[$key]);
    }

    /**
     * Reverse map: comparison node key → [operator, isUnary]. Mirrors
     * Compiler::comparisonNodeForOperator.
     *
     * @return array{0: string, 1: bool}
     */
    private static function operatorForComparisonNodeKey(string $key): array
    {
        $unary = [
            'data.is_empty' => 'is_empty',
            'data.is_not_empty' => 'is_not_empty',
            'data.exists' => 'exists',
            'data.not_exists' => 'not_exists',
        ];
        if (isset($unary[$key])) {
            return [$unary[$key], true];
        }
        $binary = [
            'data.equals' => 'equals',
            'data.not_equals' => 'not_equals',
            'data.contains' => 'contains',
            'data.not_contains' => 'not_contains',
            'data.starts_with' => 'starts_with',
            'data.ends_with' => 'ends_with',
            'data.greater_than' => 'greater_than',
            'data.less_than' => 'less_than',
            'data.greater_or_equal' => 'greater_or_equal',
            'data.less_or_equal' => 'less_or_equal',
            'data.is_in' => 'in',
            'data.is_not_in' => 'not_in',
            'data.contains_all' => 'in_all',
            'data.length_equals' => 'length_equal',
            'data.between' => 'between',
            'data.matches_regex' => 'regex',
            'data.is_before' => 'before',
            'data.is_after' => 'after',
        ];
        return [$binary[$key] ?? 'equals', false];
    }

    private function variableName(string $id): string
    {
        foreach ($this->variables as $var) {
            if ((string) ($var['id'] ?? '') === $id) {
                return (string) ($var['name'] ?? $id);
            }
        }
        return $id;
    }

    private function maybeAlias(string $nodeId, array $verb): ?string
    {
        if (!$this->isReferencedByData($nodeId)) {
            return null;
        }
        $primary = VerbCatalog::primaryOutputKey($verb);
        if ($primary === null) {
            return null;
        }
        return $this->aliasForNode($nodeId);
    }

    private function aliasForNode(string $nodeId): string
    {
        if (isset($this->aliasFor[$nodeId])) {
            return $this->aliasFor[$nodeId];
        }
        // The trigger node's outputs are exposed in the source language as
        // `event.<X>` (where `event` is the workflow function parameter).
        // Use that literally instead of inventing a wpHook1-style alias —
        // otherwise the decompiled body has `wpHook1.entity` which the
        // compiler does not recognise on re-parse.
        if ($nodeId !== '' && $nodeId === $this->triggerNodeId) {
            $this->aliasFor[$nodeId] = 'event';
            return 'event';
        }
        $verb = $this->verbForNode($nodeId);
        $hint = $verb !== null ? (string) $verb['verb'] : 'val';
        // Base from verb name, kebab → camelCase + suffix counter to avoid clashes.
        $base = lcfirst(str_replace(['_', '-'], '', ucwords($hint, '_-')));
        $this->aliasCounter++;
        $alias = $base . $this->aliasCounter;
        $this->aliasFor[$nodeId] = $alias;
        return $alias;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verbForNode(string $nodeId): ?array
    {
        $node = $this->byId[$nodeId] ?? null;
        if (!is_array($node)) {
            return null;
        }
        return VerbCatalog::find((string) $node['key']);
    }

    private function isReferencedByData(string $nodeId): bool
    {
        foreach ($this->data as $conn) {
            if ((string) ($conn['source'] ?? '') === $nodeId) {
                return true;
            }
        }
        return false;
    }

    private function hasExecBranch(string $sourceNodeId, string $output): bool
    {
        foreach ($this->execBySource[$sourceNodeId] ?? [] as $conn) {
            if ((string) ($conn['sourceOutput'] ?? '') === $output) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function followExec(string $sourceNodeId, string $output): ?array
    {
        foreach ($this->execBySource[$sourceNodeId] ?? [] as $conn) {
            if ((string) ($conn['sourceOutput'] ?? '') === $output) {
                return $conn;
            }
        }
        return null;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private function literal($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $this->renderConfigValue($value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $parts = array_map(fn($v) => $this->literal($v), $value);
                return '[' . implode(', ', $parts) . ']';
            }
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = sprintf('%s: %s', $this->safeKey((string) $k), $this->literal($v));
            }
            return '{ ' . implode(', ', $parts) . ' }';
        }
        return 'null';
    }

    private function safeKey(string $key): string
    {
        if (preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $key)) {
            return $key;
        }
        return $this->quote($key);
    }

    /**
     * Render a string value stored in node.data (a config slot, or an input
     * that a legacy graph baked as a `{{token}}` string instead of a data
     * edge). Two shapes:
     *   - EXACTLY one `{{node.<id>.<out>}}` token → a wire reference
     *     `<handle>.out.<out>`, so the recompile relinks it to the node by
     *     handle (the raw id would be stale after ids regenerate).
     *   - anything else → a PLAIN quoted string. Any `{{event.x}}` /
     *     `{{variable.x}}` / `{{site.x}}` markers are the RUNTIME's own
     *     interpolation, stored verbatim in config — NOT TS template
     *     interpolation — so they must NOT become a backtick template
     *     (which the compiler rejects). They round-trip as literal text.
     */
    private function renderConfigValue(string $value): string
    {
        if (preg_match('/^\{\{\s*node\.([^.}\s]+)\.([^}\s]+)\s*\}\}$/', $value, $m)) {
            return $this->handleRef((string) $m[1], (string) $m[2]);
        }
        return $this->quote($value);
    }
}
