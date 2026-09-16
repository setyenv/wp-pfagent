<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * AST → workflow graph (the same shape wp-pfworkflow's WorkflowRepository
 * persists in post_content).
 *
 * Scope:
 *   - trigger / name / status / flow function declarations
 *   - const-bound await calls (data pins between nodes)
 *   - let-bound flow variables with assignment
 *   - if/else single-comparison branching (and && / || via desugar)
 *   - verb resolution against the live VerbCatalog
 *   - template literals → wp-pfworkflow {{...}} token strings
 *   - stop() and bare return
 *   - delay / retry / approval / try-catch
 *
 * User-defined workflow functions are not part of the language. There is
 * exactly one entry point: `flow async function workflow(event) { ... }`.
 */
final class Compiler
{
    /** @var array<string, array{verb: string, entity?: string, entityFilter?: string}>|null */
    private static ?array $resolverCache = null;

    /**
     * Combined virtual-identifier → real-catalog-verb table.
     *
     * Globals (always loaded):
     *   - wp-pfworkflow: every non-pfm catalog node
     *     (`Email$send_email`, `Content$post_published$Trigger`, …)
     *   - wp-pfmanagement: every per-entity virtual node
     *     (`Incidentes$Create`, `Incidentes$Updated$Trigger`, …)
     *
     * Per-workflow (loaded only when a workflow id is given):
     *   - wp-pfworkflow VariablesTypingsBuilder: every operator-
     *     declared variable surfaces as `<Name>$Variable$Get/Set`.
     *
     * @return array<string, array{verb: string, entity?: string, entityFilter?: string, variableName?: string}>
     */
    public static function virtualResolver($workflow_id = 0): array
    {
        $workflow_id = \ProjectFlash\Agent\WorkflowDependency::normalize_workflow_id($workflow_id);
        if ($workflow_id === '' && self::$resolverCache !== null) {
            return self::$resolverCache;
        }
        $combined = [];
        if (function_exists('apply_filters')) {
            $sources = [
                apply_filters('projectflash_workflow_typings_resolver', []),
                apply_filters('projectflash_management_typings_resolver', []),
            ];
            if ($workflow_id !== '') {
                $sources[] = apply_filters('projectflash_workflow_variables_resolver', [], $workflow_id);
            }
            foreach ($sources as $map) {
                if (!is_array($map)) {
                    continue;
                }
                foreach ($map as $k => $v) {
                    if (is_string($k) && is_array($v) && isset($v['verb'])) {
                        $combined[$k] = $v;
                    }
                }
            }
        }
        if ($workflow_id === '') {
            self::$resolverCache = $combined;
        }
        return $combined;
    }

    public static function flushResolverCache(): void
    {
        self::$resolverCache = null;
    }

    /** @var array<int, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<int, array<string, mixed>> */
    private array $connections = [];

    /** @var array<int, array<string, mixed>> */
    private array $variables = [];

    /** @var array<string, array{nodeId: string, output: string}> */
    private array $constAliases = [];

    /**
     * Handle-graph model: maps each `const <handle>` name to the id of the
     * node it created, and stores the info needed to wire its data inputs in
     * a second pass (so declaration order is irrelevant).
     *
     * @var array<string, string>
     */
    private array $handleNodeId = [];

    /** @var array<string, array<string, mixed>> */
    private array $handleInfo = [];

    /** @var array<string, string> */
    private array $flowVarIds = [];   // name → variable id

    /** @var array<string, string> */
    private array $flowVarTypes = []; // name → inferred type

    /** @var array<string, array{nodeId: string, output: string}> */
    private array $materialiseCache = []; // CSE: descriptor signature → reusable source

    private int $idCounter = 0;
    private string $eventAlias = 'event';
    private ?string $triggerKey = null;
    private ?string $triggerNodeId = null;
    private string $workflowName = 'Untitled workflow';
    private string $workflowStatus = 'draft';
    private ?string $execTail = null;   // node id whose exec output we wire next
    private string $execTailOutput = 'next';

    public static function compile(string $source, $workflow_id = 0): array
    {
        $lexer = new Lexer($source);
        $tokens = $lexer->tokenize();
        $parser = new Parser($tokens);
        $program = $parser->parseProgram();

        $compiler = new self();
        $compiler->workflowId = \ProjectFlash\Agent\WorkflowDependency::normalize_workflow_id($workflow_id);
        return $compiler->compileProgram($program);
    }

    /** Canonical string form ('' = none) - opaque id, never arithmetic. */
    private string $workflowId = '';

    /** @var array<string, array<string, string>>|null */
    private ?array $instanceResolver = null;

    /**
     * Lazily-built virtual resolver for THIS compilation, including
     * the per-workflow variables map when workflowId is set.
     *
     * @return array<string, array<string, string>>
     */
    private function resolver(): array
    {
        if ($this->instanceResolver === null) {
            $this->instanceResolver = self::virtualResolver($this->workflowId);
        }
        return $this->instanceResolver;
    }

    /**
     * @param array<string, mixed> $program
     * @return array<string, mixed>
     */
    private function compileProgram(array $program): array
    {
        $decls = (array) ($program['declarations'] ?? []);

        // HANDLE-GRAPH model. The program is a flat list of top-level items:
        // `name` / `status` directives, node-handle declarations
        // (`const h = nodes.<node>({...});`) and wiring statements
        // (`h.exeOut([...]);`). Order is IRRELEVANT — the graph is defined by
        // the wiring, not the sequence — so we make THREE passes:
        //   (1) create every node, so all handles are known;
        //   (2) wire data inputs (they reference other handles' `.out` pins);
        //   (3) wire exec edges from the wiring statements.
        $handleDecls = [];
        $wiringStmts = [];
        foreach ($decls as $decl) {
            switch ((string) ($decl['type'] ?? '')) {
                case 'NameDecl':
                    $this->workflowName = (string) $decl['value'];
                    break;
                case 'StatusDecl':
                    $this->workflowStatus = (string) $decl['value'];
                    break;
                case 'VarDecl':
                    $handleDecls[] = $decl;
                    break;
                case 'ExprStmt':
                    $wiringStmts[] = $decl;
                    break;
                default:
                    throw CompileError::compile('unexpected_top_level', (int) ($decl['line'] ?? 0), (int) ($decl['column'] ?? 0), sprintf('Unexpected top-level item "%s". Only `name` / `status` directives, `const <handle> = nodes.<node>({...});` declarations and exec wiring statements are allowed.', (string) ($decl['type'] ?? '')));
            }
        }

        // Pass 1 — create a node shell per handle (id/key/type/label/data).
        foreach ($handleDecls as $decl) {
            $this->createHandleNode($decl);
        }
        // Pass 2 — wire data inputs now that every handle is known.
        foreach ($this->handleInfo as $info) {
            $this->wireHandleInputs($info);
        }
        // Pass 3 — wire exec edges.
        foreach ($wiringStmts as $stmt) {
            $this->compileWiringStmt($stmt);
        }

        // Exactly one trigger node is a GRAPH invariant.
        $triggerIds = [];
        foreach ($this->nodes as $n) {
            if (($n['type'] ?? '') === 'trigger') {
                $triggerIds[] = (string) $n['id'];
            }
        }
        if (count($triggerIds) === 0) {
            throw CompileError::compile('missing_trigger', 0, 0, 'A workflow must contain exactly one trigger node.', 'Declare it as a handle: const event = nodes.<X>$Trigger();');
        }
        if (count($triggerIds) > 1) {
            throw CompileError::compile('multiple_triggers', 0, 0, sprintf('A workflow must contain exactly one trigger node; found %d.', count($triggerIds)), 'A workflow is driven by a single event channel. Split into separate workflows or keep one trigger.');
        }

        // Auto-layout any nodes without explicit positions (BFS from the
        // trigger along exec edges).
        $this->autoLayoutPositions();

        return [
            'workflow' => [
                'name' => $this->workflowName,
                'status' => $this->workflowStatus,
            ],
            'graph' => [
                'schemaVersion' => 1,
                'studio' => [
                    'variables' => array_values($this->variables),
                ],
                'nodes' => $this->nodes,
                'connections' => $this->connections,
            ],
        ];
    }

    /**
     * Pass 1: create the node shell for `const <handle> = nodes.<node>({...});`.
     * No data inputs are wired yet — they may reference handles declared later.
     *
     * @param array<string, mixed> $decl
     */
    private function createHandleNode(array $decl): void
    {
        $name = (string) $decl['name'];
        $line = (int) ($decl['line'] ?? 0);
        $col = (int) ($decl['column'] ?? 0);
        if (isset($this->handleNodeId[$name])) {
            throw CompileError::compile('duplicate_handle', $line, $col, sprintf('Handle "%s" is declared twice; each `const` name must be unique.', $name));
        }
        $init = $decl['init'] ?? null;
        // Tolerate (and unwrap) a stray `await` in front of the factory call.
        if (is_array($init) && ($init['type'] ?? '') === 'AwaitExpr') {
            $init = $init['argument'] ?? null;
        }
        if (!is_array($init) || ($init['type'] ?? '') !== 'CallExpr') {
            throw CompileError::compile('handle_not_node_call', $line, $col, sprintf('`const %s` must be a node factory call: const %s = nodes.<node>({ ... });', $name, $name));
        }
        $callExpr = $init;
        $verbKey = $this->resolveVerbKey($callExpr['callee'], $line, $col);
        $verb = VerbCatalog::find($verbKey);
        if ($verb === null) {
            throw CompileError::compile('unknown_verb', $line, $col, sprintf('Node "%s" is not in the catalog.', $verbKey), 'See /lib/nodes.d.ts and /lib/manage.d.ts for the available nodes.');
        }
        $args = (array) ($callExpr['arguments'] ?? []);
        if (count($args) > 1) {
            throw CompileError::compile('too_many_args', $line, $col, sprintf('Node "%s" takes a single named-object argument.', $verbKey));
        }
        $arg_obj = $args === [] ? ['type' => 'ObjectExpr', 'properties' => []] : $args[0];
        if (($arg_obj['type'] ?? '') !== 'ObjectExpr') {
            throw CompileError::compile('verb_arg_not_object', $line, $col, sprintf('Node "%s" expects a named-object argument like { key: value, ... }.', $verbKey));
        }

        $node_id = $this->makeId($this->shortFor($verbKey));
        $data = [];
        // Structural identifiers the compiler injects from a typed virtual
        // (entity slug / variableName) — never authored by the LLM.
        if ($this->pendingEntityInjection !== null) {
            $data['entity'] = $this->pendingEntityInjection;
            $this->pendingEntityInjection = null;
        }
        if ($this->pendingEntityFilterInjection !== null) {
            // Entity-scoped trigger (Entity$Created$Trigger etc.): the runtime
            // dispatcher skips the workflow when the firing event's entity
            // does not match this filter.
            $data['entityFilter'] = $this->pendingEntityFilterInjection;
            $this->pendingEntityFilterInjection = null;
        }
        if ($this->pendingVariableInjection !== null) {
            $data['variableName'] = $this->pendingVariableInjection;
            $data['variableId'] = $this->pendingVariableInjection;
            $this->pendingVariableInjection = null;
        }
        $node = [
            'id' => $node_id,
            'key' => $verbKey,
            'type' => (string) $verb['kind'],
            'label' => (string) ($verb['label'] ?? $verbKey),
            'data' => $data,
        ];
        $this->nodes[] = $node;
        $nodeIndex = count($this->nodes) - 1;

        if ((string) $verb['kind'] === 'trigger') {
            $this->triggerKey = $verbKey;
            $this->triggerNodeId = $node_id;
        }

        $this->handleNodeId[$name] = $node_id;
        $this->handleInfo[$name] = [
            'name' => $name,
            'nodeId' => $node_id,
            'nodeIndex' => $nodeIndex,
            'verb' => $verb,
            'argObj' => $arg_obj,
            'line' => $line,
            'column' => $col,
        ];
    }

    /**
     * Pass 2: wire the data inputs of one handle. Every handle exists now, so
     * `otherHandle.out.<pin>` references resolve to real node outputs.
     *
     * @param array<string, mixed> $info
     */
    private function wireHandleInputs(array $info): void
    {
        $verb = (array) $info['verb'];
        $node_id = (string) $info['nodeId'];
        $arg_obj = (array) $info['argObj'];
        $line = (int) $info['line'];
        $col = (int) $info['column'];

        // Reference into the live node so config writes persist.
        $node = &$this->nodes[$info['nodeIndex']];

        $wired = [];
        foreach ((array) ($arg_obj['properties'] ?? []) as $prop) {
            $key = (string) ($prop['key'] ?? '');
            $argKind = VerbCatalog::argKind($verb, $key);
            if ($argKind === null) {
                $allowed = $this->describeAllowedArgs($verb);
                throw CompileError::compile('unknown_arg', (int) ($prop['line'] ?? $line), (int) ($prop['column'] ?? $col), sprintf('Node "%s" has no arg named "%s". %s', (string) $verb['key'], $key, $allowed));
            }
            $desc = $this->compileExpression($prop['value']);
            $this->wireValueIntoNode($desc, $node, $node_id, $key);
            $wired[$key] = true;
        }
        unset($node);

        // Auto-seed operator-tunable pins the LLM omitted (EQL / cron /
        // templates / thresholds) → a workflow variable + get_variable wire.
        $this->seedAutoVariablesForVerb($verb, $node_id, $wired);
    }

    /**
     * Pass 3: turn one wiring statement (`<handle>.exeOut([...]);` and the
     * exeOutYes / exeOutNo / exeIn variants) into exec edges.
     *
     * @param array<string, mixed> $stmt
     */
    private function compileWiringStmt(array $stmt): void
    {
        $expr = $stmt['expression'] ?? null;
        $line = (int) ($stmt['line'] ?? 0);
        $col = (int) ($stmt['column'] ?? 0);
        if (!is_array($expr) || ($expr['type'] ?? '') !== 'CallExpr') {
            throw CompileError::compile('invalid_wiring', $line, $col, 'A bare top-level statement must be an exec wiring call like `handle.exeOut([target, ...]);`.');
        }
        $callee = $expr['callee'] ?? null;
        if (!is_array($callee) || ($callee['type'] ?? '') !== 'MemberExpr' || !empty($callee['computed'])
            || !is_array($callee['object'] ?? null) || ($callee['object']['type'] ?? '') !== 'Identifier') {
            throw CompileError::compile('invalid_wiring', $line, $col, 'Exec wiring must be `<handle>.exeOut([...])` / `.exeOutYes([...])` / `.exeOutNo([...])` / `.exeIn([...])`.');
        }
        $srcName = (string) $callee['object']['name'];
        $method = (string) $callee['property'];
        if (!isset($this->handleNodeId[$srcName])) {
            throw CompileError::compile('unknown_handle', $line, $col, sprintf('Unknown handle "%s". Declare it first: const %s = nodes.<node>({ ... });', $srcName, $srcName));
        }
        $srcNodeId = $this->handleNodeId[$srcName];

        $args = (array) ($expr['arguments'] ?? []);
        if (count($args) !== 1 || ($args[0]['type'] ?? '') !== 'ArrayExpr') {
            throw CompileError::compile('wiring_arg', $line, $col, sprintf('%s(...) takes a single array of handles, e.g. %s.%s([targetA, targetB]).', $method, $srcName, $method));
        }
        $targetIds = [];
        foreach ((array) ($args[0]['elements'] ?? []) as $el) {
            if (!is_array($el) || ($el['type'] ?? '') !== 'Identifier') {
                throw CompileError::compile('wiring_element', $line, $col, 'Exec wiring arrays contain node handles only, e.g. [stepA, stepB].');
            }
            $tName = (string) $el['name'];
            if (!isset($this->handleNodeId[$tName])) {
                throw CompileError::compile('unknown_handle', $line, $col, sprintf('Unknown handle "%s" in the wiring array.', $tName));
            }
            $targetIds[] = $this->handleNodeId[$tName];
        }

        // exeIn is the reverse form: each listed handle flows INTO this one
        // through its own `next` output (n-to-1 merge sugar).
        if ($method === 'exeIn') {
            $this->assertHasExecIn($srcName, $srcNodeId, $line, $col);
            foreach ($targetIds as $tId) {
                $this->addExec($tId, 'next', $srcNodeId, 'in');
            }
            return;
        }

        // Exec output name → graph socket. `exeOut` is the single `next`
        // output; `exeOutYes`/`exeOutNo` are the condition branches; and any
        // node-specific branch (a loop's `item`/`complete`, a try's
        // `try`/`catch`, etc.) is `exeOut<Branch>` → the lowercased socket.
        if (strncmp($method, 'exeOut', 6) !== 0) {
            throw CompileError::compile('unknown_exec_output', $line, $col, sprintf('Unknown exec output "%s" on handle "%s". Valid: exeOut, exeOutYes, exeOutNo, exeIn (and node-specific exeOut<Branch>).', $method, $srcName));
        }
        $suffix = substr($method, 6);
        $srcOutput = $suffix === '' ? 'next' : lcfirst($suffix);
        $this->assertExecOutput($srcName, $srcNodeId, $method, $line, $col);
        foreach ($targetIds as $tId) {
            $this->addExec($srcNodeId, $srcOutput, $tId, 'in');
        }
    }

    private function nodeKindFor(string $nodeId): string
    {
        foreach ($this->nodes as $n) {
            if (($n['id'] ?? '') === $nodeId) {
                return (string) ($n['type'] ?? '');
            }
        }
        return '';
    }

    /**
     * Validate an exec OUTPUT wiring against the source node's kind, matching
     * exactly what the editor renders (previewPortsForCatalogItem): pure
     * (transform/note) → none; condition → yes/no; everything else → next.
     */
    private function assertExecOutput(string $name, string $nodeId, string $method, int $line, int $col): void
    {
        $kind = $this->nodeKindFor($nodeId);
        if ($kind === 'transform' || $kind === 'note') {
            throw CompileError::compile('pure_node_no_exec', $line, $col, sprintf('Handle "%s" is a pure node (kind %s) with no exec pins — it runs when its `.out` is consumed. Do not wire its exec; just read %s.out.<pin>.', $name, $kind, $name));
        }
        if ($kind === 'trigger' && $method !== 'exeOut') {
            throw CompileError::compile('wrong_exec_output', $line, $col, sprintf('Handle "%s" is a trigger — it has a single exec output; wire it with %s.exeOut([...]).', $name, $name));
        }
        // Everything else exposes node-specific exec branches: a plain
        // condition has exeOutYes/exeOutNo; a try_catch has exeOutTry/
        // exeOutCatch; an approval has exeOutApprove/exeOutReject/exeOutPending;
        // a loop has exeOutItem/exeOutComplete; an action has exeOut plus
        // exeOutError/exeOutAttempt. The graph (and the node's own contract)
        // is the authority on which exist, so accept any exeOut<Branch> here —
        // the typings steer the LLM to the right ones per node.
    }

    private function assertHasExecIn(string $name, string $nodeId, int $line, int $col): void
    {
        $kind = $this->nodeKindFor($nodeId);
        if ($kind === 'trigger') {
            throw CompileError::compile('trigger_no_exec_in', $line, $col, sprintf('Handle "%s" is a trigger — it has no exec input. Wire FROM it with %s.exeOut([...]).', $name, $name));
        }
        if ($kind === 'transform' || $kind === 'note') {
            throw CompileError::compile('pure_node_no_exec', $line, $col, sprintf('Handle "%s" is a pure node — it has no exec pins.', $name));
        }
    }

    /**
     * await retry({attempts, backoff}, async () => { body }) →
     *   workflow.retry_block with the body wired through its `attempt`
     *   exec output. The retry block carries the policy in node.data.
     *
     * Surface form is a single retry block: nesting is allowed but the
     * v1.2 runtime does not require it. After the body runs to its tail
     * the flow continues from retry_block's `next` exec output.
     *
     * @param array<string, mixed> $callExpr
     */
    private function compileRetryCall(array $callExpr, int $line, int $col): void
    {
        $args = (array) ($callExpr['arguments'] ?? []);
        if (count($args) !== 2) {
            throw CompileError::compile('retry_arity', $line, $col, 'retry() takes exactly two arguments: a policy object and an async arrow function body.');
        }
        if (($args[0]['type'] ?? '') !== 'ObjectExpr') {
            throw CompileError::compile('retry_first_arg', $line, $col, 'retry() first argument must be a policy object like { attempts: 3, backoff: "exponential" }.');
        }
        $arrow = $args[1];
        // Accept both `async () => { ... }` and a bare `() => { ... }` —
        // the parser surfaces these as ArrowFunction in some grammars,
        // but our subset only sees them as parenthesised+brace shapes the
        // parser does not (yet) accept. For v1.2 we accept a BlockStmt
        // expression carrying the body directly. To keep the syntax
        // ergonomic we ALSO accept an object literal whose `body` field
        // is a fake arrow — falling back to "compile what the LLM probably
        // meant" via an `ArrowFunctionExpr` shape produced by the parser.
        if (($arrow['type'] ?? '') !== 'ArrowFunctionExpr') {
            throw CompileError::compile('retry_second_arg', $line, $col, 'retry() second argument must be an `async () => { ... }` arrow function carrying the body to retry.');
        }
        $verb = VerbCatalog::find('workflow.retry_block');
        if ($verb === null) {
            throw CompileError::compile('retry_unavailable', $line, $col, 'Node workflow.retry_block is not in the contract.');
        }
        $policy = $this->literalFromObjectExpr($args[0]);
        $node_id = $this->makeId('retry');
        $this->nodes[] = [
            'id' => $node_id,
            'key' => 'workflow.retry_block',
            'type' => 'action',
            'label' => 'Retry',
            'data' => $policy,
        ];
        $this->wireExec($node_id);
        // Body runs out of the retry node's `attempt` exec output.
        $this->execTail = $node_id;
        $this->execTailOutput = 'attempt';
        foreach ((array) $arrow['body'] as $s) {
            $this->compileStatement($s);
        }
        // After the body, the runtime resumes from `next` on the retry node.
        $this->execTail = $node_id;
        $this->execTailOutput = 'next';
    }

    /**
     * Helper: turn an ObjectExpr AST node into a plain assoc array of
     * literal values (used by retry's policy object).
     *
     * @param array<string, mixed> $obj
     * @return array<string, mixed>
     */
    private function literalFromObjectExpr(array $obj): array
    {
        $out = [];
        foreach ((array) ($obj['properties'] ?? []) as $prop) {
            $valueDesc = $this->compileExpression($prop['value']);
            $out[(string) $prop['key']] = $this->descriptorToData($valueDesc);
        }
        return $out;
    }

    /**
     * await approval({...}) → workflow.approval_gate with approve/reject
     * branches. Returns a node id whose `approve` exec output is true and
     * `reject` is false; if the caller binds the await to a const we map
     * the const to the gate's `decision` output pin. Since this is a
     * statement form, we simply wire the gate; control flow downstream
     * uses an explicit if (decision)? form. For v1.2 we ship the simpler
     * blocking form: `await approval({...});` puts the gate inline and
     * downstream code only runs when the approver approves.
     *
     * @param array<string, mixed> $callExpr
     */
    private function compileApprovalCall(array $callExpr, int $line, int $col): void
    {
        $args = (array) ($callExpr['arguments'] ?? []);
        if (count($args) !== 1 || ($args[0]['type'] ?? '') !== 'ObjectExpr') {
            throw CompileError::compile('approval_arity', $line, $col, 'approval() takes a single object argument like { approvers, message }.');
        }
        $verb = VerbCatalog::find('workflow.approval_gate');
        if ($verb === null) {
            throw CompileError::compile('approval_unavailable', $line, $col, 'Node workflow.approval_gate is not in the contract.');
        }
        $config = $this->literalFromObjectExpr($args[0]);
        $node_id = $this->makeId('approval');
        $this->nodes[] = [
            'id' => $node_id,
            'key' => 'workflow.approval_gate',
            'type' => 'condition',
            'label' => 'Approval',
            'data' => $config,
        ];
        $this->wireExec($node_id);
        // Default execution path continues from the `approve` branch;
        // the `reject` branch is wired to a workflow.stop so the workflow
        // terminates on rejection without further actions.
        $stop_id = $this->makeId('stop');
        $this->nodes[] = [
            'id' => $stop_id,
            'key' => 'workflow.stop',
            'type' => 'action',
            'label' => 'Stop on reject',
            'data' => [],
        ];
        $this->addExec($node_id, 'reject', $stop_id, 'in');
        $this->execTail = $node_id;
        $this->execTailOutput = 'approve';
    }

    /**
     * Auto-layout strategy: walk the exec graph from each trigger and
     * assign positions branch-aware. Nodes that branch off a condition
     * (yes/try/item/approve) move UP, the alternate (no/catch/complete/
     * reject) moves DOWN, and the linear `next` chain stays at parent's
     * Y. After placement, sweep each depth to resolve any remaining
     * vertical collisions by shifting overlapping nodes further out.
     *
     * Card geometry is 286×164; we leave one card-width of gutter
     * between columns and just over one card-height between rows so the
     * Rete viewer's zoomAt() can fit the result without overlapping.
     */
    private function autoLayoutPositions(): void
    {
        $base_x = 80.0;
        $base_y = 240.0;        // leave room above for branches that move UP
        $col_step = 340.0;       // 286 card + 54 gutter
        $row_step = 200.0;       // 164 card + 36 gutter
        $branch_offset = 220.0;  // vertical separation per nesting level

        // Build exec adjacency keyed by source, capturing the branch label.
        $children_of = [];
        foreach ($this->connections as $c) {
            if (($c['kind'] ?? '') !== 'exec') {
                continue;
            }
            $src = (string) $c['source'];
            $children_of[$src][] = [
                'target' => (string) $c['target'],
                'output' => (string) ($c['sourceOutput'] ?? 'next'),
            ];
        }

        // Find the trigger node — exactly one in the main graph.
        $roots = [];
        foreach ($this->nodes as $n) {
            if (($n['type'] ?? '') === 'trigger') {
                $roots[] = (string) $n['id'];
            }
        }

        // Position assignment.
        $position = [];
        $depth = [];
        $occupied = [];   // depth -> [y => true]

        $up_branches = ['yes', 'try', 'item', 'approve', 'attempt'];
        $down_branches = ['no', 'catch', 'complete', 'reject', 'error', 'pending'];

        foreach ($roots as $root) {
            $position[$root] = ['x' => $base_x, 'y' => $base_y];
            $depth[$root] = 0;
            $occupied[0][$base_y] = $root;
        }

        // BFS-ish walk, but visit children in branch order.
        $queue = $roots;
        while ($queue !== []) {
            $cur = array_shift($queue);
            $cur_pos = $position[$cur];
            $cur_depth = $depth[$cur];

            $children = $children_of[$cur] ?? [];
            // Order: up-branches first (so they get the cleaner spots),
            // then in-line (next), then down-branches.
            usort($children, function (array $a, array $b) use ($up_branches, $down_branches): int {
                $order = function (string $out) use ($up_branches, $down_branches): int {
                    if (in_array($out, $up_branches, true)) return 0;
                    if (in_array($out, $down_branches, true)) return 2;
                    return 1;
                };
                return $order((string) $a['output']) <=> $order((string) $b['output']);
            });

            foreach ($children as $edge) {
                $child = $edge['target'];
                if (isset($position[$child])) {
                    continue;   // already placed (e.g. merge with multiple parents)
                }
                $child_depth = $cur_depth + 1;
                // Default Y is the parent's Y.
                $y = $cur_pos['y'];
                if (in_array($edge['output'], $up_branches, true)) {
                    $y -= $branch_offset;
                } elseif (in_array($edge['output'], $down_branches, true)) {
                    $y += $branch_offset;
                }
                // Resolve collisions at this depth: if y is taken, slide
                // away from the parent's Y until we find a free row.
                $y = $this->reserveSlot($occupied, $child_depth, $y, $cur_pos['y'], $row_step);
                $position[$child] = [
                    'x' => $base_x + $child_depth * $col_step,
                    'y' => $y,
                ];
                $depth[$child] = $child_depth;
                $queue[] = $child;
            }
        }

        // Nodes that BFS never reached (orphans or unreachable): drop them
        // off to the right of the last column so they at least don't sit
        // at (0,0).
        $max_depth = empty($depth) ? 0 : max($depth);
        $orphan_depth = $max_depth + 1;
        foreach ($this->nodes as $n) {
            $id = (string) $n['id'];
            if (isset($position[$id])) {
                continue;
            }
            $y = $this->reserveSlot($occupied, $orphan_depth, $base_y, $base_y, $row_step);
            $position[$id] = [
                'x' => $base_x + $orphan_depth * $col_step,
                'y' => $y,
            ];
        }

        // Apply.
        foreach ($this->nodes as $i => $n) {
            if (isset($n['position'])) {
                continue;
            }
            $id = (string) $n['id'];
            $this->nodes[$i]['position'] = $position[$id];
        }
    }

    /**
     * Find a free slot at depth, starting at $preferred and sliding
     * away from $anchor (used as the "centre of gravity") in increments
     * of $row_step. Updates $occupied in place.
     *
     * @param array<int, array<int, string>> $occupied
     */
    private function reserveSlot(array &$occupied, int $depth, float $preferred, float $anchor, float $row_step): float
    {
        $occ = $occupied[$depth] ?? [];
        // Quantise so floats compare reliably.
        $key = (int) round($preferred);
        if (!isset($occ[$key])) {
            $occupied[$depth][$key] = '*';
            return $preferred;
        }
        // Slide outward from anchor.
        $direction = $preferred >= $anchor ? 1 : -1;
        $step = 1;
        while (true) {
            $candidate = $preferred + $direction * $step * $row_step;
            $ck = (int) round($candidate);
            if (!isset($occ[$ck])) {
                $occupied[$depth][$ck] = '*';
                return $candidate;
            }
            // Also try the opposite direction at the same offset.
            $candidate2 = $preferred - $direction * $step * $row_step;
            $ck2 = (int) round($candidate2);
            if (!isset($occ[$ck2])) {
                $occupied[$depth][$ck2] = '*';
                return $candidate2;
            }
            $step++;
            if ($step > 50) {
                // Safety: should never hit, but avoid infinite loop on
                // pathological graphs.
                $occupied[$depth][$ck] = '*';
                return $candidate;
            }
        }
    }

    /**
     * @param array<string, mixed> $decl
     */
    private function compileTriggerDecl(array $decl): void
    {
        $key = (string) $decl['triggerKey'];

        // Virtual identifier resolution: if the trigger statement
        // names a typed node (e.g. `Incidentes$Updated$Trigger`),
        // look it up in the merged resolver and translate to the
        // real catalog verb plus a structural entity filter that the
        // dispatcher honours at run time. Falls through unchanged
        // for keys that already match a registered verb (legacy
        // string form used by triggers without an entity scope:
        // `Workflow$manual$Trigger`, `Content$post_published$Trigger`,
        // etc. — those resolve to themselves with no extra data).
        $entityFilter = null;
        $resolver = $this->resolver();
        if (isset($resolver[$key])) {
            $entry = $resolver[$key];
            if (isset($entry['entityFilter']) && $entry['entityFilter'] !== '') {
                $entityFilter = (string) $entry['entityFilter'];
            }
            $key = (string) $entry['verb'];
        }

        $this->triggerKey = $key;

        $verb = VerbCatalog::find($key);
        if ($verb === null) {
            throw CompileError::compile('unknown_trigger_key', (int) $decl['line'], (int) $decl['column'], sprintf('Trigger key "%s" is not in the node catalog.', $key), 'Look up valid triggers in /lib/nodes.d.ts under triggers (kind: "trigger").');
        }
        if (($verb['kind'] ?? '') !== 'trigger') {
            throw CompileError::compile('trigger_kind_mismatch', (int) $decl['line'], (int) $decl['column'], sprintf('Node "%s" has kind "%s", expected "trigger".', $key, (string) ($verb['kind'] ?? '')));
        }

        // Resolve trigger config from the optional `with { ... }` clause.
        // Triggers have no data input pins; the entire `with { ... }`
        // payload flows into node.data. Most trigger contracts in
        // wp-pfworkflow do not formally declare every config key (e.g.
        // developer.wp_hook accepts any hookName dynamically), so we
        // accept any property here and let the runtime validate.
        $data = [];
        if (is_array($decl['config'] ?? null) && ($decl['config']['type'] ?? '') === 'ObjectExpr') {
            foreach ((array) ($decl['config']['properties'] ?? []) as $prop) {
                $argKey = (string) ($prop['key'] ?? '');
                $valueDescriptor = $this->compileExpression($prop['value']);
                $data[$argKey] = $this->descriptorToData($valueDescriptor);
            }
        }

        $node_id = $this->makeId('trigger');
        $this->triggerNodeId = $node_id;
        if ($entityFilter !== null) {
            // Structural binding emitted by the compiler from the
            // typed virtual identifier — the runtime dispatcher uses
            // this to skip the workflow when the firing event's
            // entity does not match.
            $data['entityFilter'] = $entityFilter;
        }
        $node = [
            'id' => $node_id,
            'key' => $key,
            'type' => 'trigger',
            'label' => (string) ($verb['label'] ?? $key),
            'data' => $data,
        ];
        // No per-entity extraOutputs enrichment here. The four dedicated
        // pfm trigger nodes (pfm.record_created / _updated / _deleted /
        // action_invoked) declare their uniform output pins (entity,
        // sys_id, record, changes, actor_user_id, fired_at, event_name)
        // directly in their manifest contract — no hookName-driven
        // entity-field spread, no compiler-side guesswork.
        $this->nodes[] = $node;
    }

    /**
     * @param array<int, array<string, mixed>> $stmts
     */
    private function compileStatements(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->compileStatement($stmt);
        }
    }

    /**
     * @param array<string, mixed> $stmt
     */
    private function compileStatement(array $stmt): void
    {
        switch ((string) ($stmt['type'] ?? '')) {
            case 'VarDecl':
                $this->compileVarDecl($stmt);
                return;
            case 'AssignStmt':
                $this->compileAssignStmt($stmt);
                return;
            case 'IfStmt':
                $this->compileIfStmt($stmt);
                return;
            case 'ForOfStmt':
                $this->compileForOfStmt($stmt);
                return;
            case 'TryStmt':
                $this->compileTryStmt($stmt);
                return;
            case 'ExprStmt':
                $this->compileExprStmt($stmt);
                return;
            case 'ReturnStmt':
                $this->compileReturnStmt($stmt);
                return;
            case 'StopStmt':
                $this->compileStopStmt($stmt);
                return;
            case 'BlockStmt':
                $this->compileStatements((array) $stmt['body']);
                return;
            default:
                throw CompileError::compile('unknown_statement', (int) ($stmt['line'] ?? 0), (int) ($stmt['column'] ?? 0), sprintf('Unknown statement type "%s".', (string) ($stmt['type'] ?? '')));
        }
    }

    /**
     * for (const item of arr) { body }
     *   → workflow.loop_items with 'items' input wired from arr,
     *     item exec output → body, complete exec output → downstream.
     *     Inside body, `item` aliases the loop node's 'item' output pin.
     *
     * @param array<string, mixed> $stmt
     */
    private function compileForOfStmt(array $stmt): void
    {
        $verb = VerbCatalog::find('workflow.loop_items');
        if ($verb === null) {
            throw CompileError::compile('loop_unavailable', (int) $stmt['line'], (int) $stmt['column'], 'Node workflow.loop_items is not in the contract.');
        }
        $node_id = $this->makeId('loop');
        $node = [
            'id' => $node_id,
            'key' => 'workflow.loop_items',
            'type' => 'loop',
            'label' => 'Loop',
            'data' => [],
        ];
        $iter_desc = $this->compileExpression($stmt['iterable']);
        $this->wireValueIntoNode($iter_desc, $node, $node_id, 'items');
        $this->nodes[] = $node;
        $this->wireExec($node_id);

        // Body branch: cursor walks from loop's `item` output.
        $item_name = (string) $stmt['itemName'];
        $savedAlias = $this->constAliases[$item_name] ?? null;
        $this->constAliases[$item_name] = ['nodeId' => $node_id, 'output' => 'item'];
        $savedTail = $this->execTail;
        $savedOut = $this->execTailOutput;
        $this->execTail = $node_id;
        $this->execTailOutput = 'item';
        $this->compileBranch($stmt['body']);

        // Restore the item alias (it's only valid inside the body).
        if ($savedAlias === null) {
            unset($this->constAliases[$item_name]);
        } else {
            $this->constAliases[$item_name] = $savedAlias;
        }
        // After the loop, downstream continues from the loop's `complete`
        // output. Anything after the body's tail is dead code.
        $this->execTail = $node_id;
        $this->execTailOutput = 'complete';
    }

    /**
     * try { tryBody } catch (e?) { catchBody }
     *   → workflow.try_catch with the try exec wired to tryBody and the
     *     catch exec wired to catchBody. Convergence merges both tails.
     *
     * @param array<string, mixed> $stmt
     */
    private function compileTryStmt(array $stmt): void
    {
        $verb = VerbCatalog::find('workflow.try_catch');
        if ($verb === null) {
            throw CompileError::compile('try_unavailable', (int) $stmt['line'], (int) $stmt['column'], 'Node workflow.try_catch is not in the contract.');
        }
        $node_id = $this->makeId('try');
        $this->nodes[] = [
            'id' => $node_id,
            'key' => 'workflow.try_catch',
            'type' => 'condition',
            'label' => 'Try / catch',
            'data' => [],
        ];
        $this->wireExec($node_id);

        $this->execTail = $node_id;
        $this->execTailOutput = 'try';
        foreach ((array) $stmt['tryBody'] as $s) {
            $this->compileStatement($s);
        }
        $tryTail = $this->execTail;
        $tryTailOut = $this->execTailOutput;

        // catch branch — bind errorAlias to the try_catch node's `error`
        // output pin if the body referenced it (the runtime carries that
        // payload there in wp-pfworkflow).
        $error_alias = $stmt['errorAlias'] ?? null;
        $savedAlias = null;
        if (is_string($error_alias)) {
            $savedAlias = $this->constAliases[$error_alias] ?? null;
            $this->constAliases[$error_alias] = ['nodeId' => $node_id, 'output' => 'error'];
        }
        $this->execTail = $node_id;
        $this->execTailOutput = 'catch';
        foreach ((array) $stmt['catchBody'] as $s) {
            $this->compileStatement($s);
        }
        $catchTail = $this->execTail;
        $catchTailOut = $this->execTailOutput;

        if (is_string($error_alias)) {
            if ($savedAlias === null) {
                unset($this->constAliases[$error_alias]);
            } else {
                $this->constAliases[$error_alias] = $savedAlias;
            }
        }

        // Merge both branches so downstream statements have a single tail.
        if ($tryTail !== null || $catchTail !== null) {
            $merge_id = $this->makeId('merge');
            $this->nodes[] = [
                'id' => $merge_id,
                'key' => 'workflow.merge',
                'type' => 'action',
                'label' => 'Merge',
                'data' => [],
            ];
            if ($tryTail !== null) {
                $this->addExec($tryTail, $tryTailOut, $merge_id, 'in');
            }
            if ($catchTail !== null) {
                $this->addExec($catchTail, $catchTailOut, $merge_id, 'in');
            }
            $this->execTail = $merge_id;
            $this->execTailOutput = 'next';
        }
    }

    /**
     * @param array<string, mixed> $stmt
     */
    private function compileVarDecl(array $stmt): void
    {
        $name = (string) $stmt['name'];
        $kind = (string) $stmt['kind']; // const | let | var
        $init = $stmt['init'];

        if ($kind === 'const') {
            // const = await <verb(...)>  — the const name aliases the
            // resulting node's primary output.
            if (is_array($init) && $init['type'] === 'AwaitExpr') {
                $verbResult = $this->compileVerbCallStatement($init['argument'], $stmt['line'], $stmt['column']);
                $primary = VerbCatalog::primaryOutputKey($verbResult['verb']);
                if ($primary === null) {
                    throw CompileError::compile('const_no_output', (int) $stmt['line'], (int) $stmt['column'], sprintf('Verb "%s" returns no value; cannot bind to a const.', $verbResult['verb']['key']));
                }
                $this->constAliases[$name] = ['nodeId' => $verbResult['nodeId'], 'output' => $primary];
                return;
            }
            // const = <expression> — accept any rvalue (member access on
            // event/variables, identifier, template literal, etc.). The
            // compiler materialises the expression into a source node
            // (extractor chain) and aliases the const to that source's
            // primary output. CSE shares the source if the same
            // expression already appeared. This is what lets the LLM
            // write the intuitive form `const estado = event.record.estado;`
            // instead of forcing the let/init-then-assign dance.
            if ($init === null) {
                throw CompileError::compile('const_requires_init', (int) $stmt['line'], (int) $stmt['column'], sprintf('`const %s` needs an initialiser.', $name));
            }
            $desc = $this->compileExpression($init);
            // Reject TernaryExpr / BinaryExpr / UnaryExpr / etc. — those
            // throw inside compileExpression already, before we get here.
            $source = $this->materialiseValueAsDataNode($desc);
            $this->constAliases[$name] = ['nodeId' => $source['nodeId'], 'output' => $source['output']];
            return;
        }

        // Desugar `let x = cond ? a : b;` into a literal-initialised let
        // followed by an if/else assignment, so the LLM does not need to
        // write the two-step form by hand.
        if (is_array($init) && ($init['type'] ?? '') === 'TernaryExpr') {
            $tern = $init;
            // Declare `let x = null;` first so the variable exists.
            $stub = [
                'type' => 'VarDecl',
                'kind' => 'let',
                'name' => $name,
                'init' => ['type' => 'LiteralNull', 'line' => $stmt['line'], 'column' => $stmt['column']],
                'line' => $stmt['line'],
                'column' => $stmt['column'],
            ];
            $this->compileVarDecl($stub);
            // Then assign via if/else.
            $target = ['type' => 'Identifier', 'name' => $name, 'line' => $stmt['line'], 'column' => $stmt['column']];
            $rewritten = [
                'type' => 'IfStmt',
                'test' => $tern['test'],
                'consequent' => ['type' => 'BlockStmt', 'body' => [['type' => 'AssignStmt', 'target' => $target, 'value' => $tern['consequent'], 'line' => $stmt['line'], 'column' => $stmt['column']]], 'line' => $stmt['line'], 'column' => $stmt['column']],
                'alternate' => ['type' => 'BlockStmt', 'body' => [['type' => 'AssignStmt', 'target' => $target, 'value' => $tern['alternate'], 'line' => $stmt['line'], 'column' => $stmt['column']]], 'line' => $stmt['line'], 'column' => $stmt['column']],
                'line' => $stmt['line'],
                'column' => $stmt['column'],
            ];
            $this->compileIfStmt($rewritten);
            return;
        }

        // `let` / `var` cannot declare a flow variable from source. The
        // LLM does not auto-declare variables, does not write defaults
        // and does not pick names — only the operator does that from the
        // workflow's variable editor (and the compiler auto-seeds the
        // operator-tunable pins you OMIT). To read an existing variable,
        // call its typed getter from /lib/variables.d.ts.
        throw CompileError::compile(
            'let_declares_variable',
            (int) $stmt['line'],
            (int) $stmt['column'],
            'A `let` / `var` declaration is not allowed — workflow source never declares variables (the operator does, in the variable editor; the compiler auto-seeds operator-tunable pins you OMIT). To READ an existing variable, use its typed getter from /lib/variables.d.ts, e.g. `const ' . $name . ' = await ' . $name . '$Variable$Get();`. Do NOT use `variables.x.get()` (removed).'
        );
    }

    /**
     * @param array<string, mixed> $stmt
     */
    private function compileAssignStmt(array $stmt): void
    {
        $target = $stmt['target'];
        if (!is_array($target) || $target['type'] !== 'Identifier') {
            throw CompileError::compile('invalid_assignment_target', (int) $stmt['line'], (int) $stmt['column'], 'Only assignment to a flow variable (let) is supported.');
        }
        $name = (string) $target['name'];
        if (!isset($this->flowVarIds[$name])) {
            throw CompileError::compile('assign_to_unknown', (int) $stmt['line'], (int) $stmt['column'], sprintf('Variable "%s" is not declared. Add `let %s = ...;` first.', $name, $name));
        }

        // Desugar ternary in RHS: `x = cond ? a : b;`  →  `if (cond) { x = a; } else { x = b; }`.
        if (is_array($stmt['value']) && ($stmt['value']['type'] ?? '') === 'TernaryExpr') {
            $tern = $stmt['value'];
            $rewritten = [
                'type' => 'IfStmt',
                'test' => $tern['test'],
                'consequent' => ['type' => 'BlockStmt', 'body' => [['type' => 'AssignStmt', 'target' => $target, 'value' => $tern['consequent'], 'line' => $stmt['line'], 'column' => $stmt['column']]], 'line' => $stmt['line'], 'column' => $stmt['column']],
                'alternate' => ['type' => 'BlockStmt', 'body' => [['type' => 'AssignStmt', 'target' => $target, 'value' => $tern['alternate'], 'line' => $stmt['line'], 'column' => $stmt['column']]], 'line' => $stmt['line'], 'column' => $stmt['column']],
                'line' => $stmt['line'],
                'column' => $stmt['column'],
            ];
            $this->compileIfStmt($rewritten);
            return;
        }

        $valueDescriptor = $this->compileExpression($stmt['value']);

        // Build a workflow.set_variable node.
        $verb = VerbCatalog::find('workflow.set_variable');
        if ($verb === null) {
            throw CompileError::compile('set_variable_unavailable', (int) $stmt['line'], (int) $stmt['column'], 'Node workflow.set_variable is not in the contract.');
        }
        $node_id = $this->makeId('set');
        $node = [
            'id' => $node_id,
            'key' => 'workflow.set_variable',
            'type' => 'action',
            'label' => sprintf('Set %s', $name),
            // wp-pfworkflow's SetVariableNode resolves the variable by
            // name via data_string('variableName'). Keep variableId for
            // the studio inspector + the live editor's
            // syncVariableNodes() label resolver; emit variableName so
            // the runtime can find the bucket key.
            'data' => [
                'variableName' => $name,
                'variableId' => $this->flowVarIds[$name],
            ],
        ];
        // The input pin is `valueToSet` per SetVariableNode::manifest()
        // — the runtime reads it via input_string('valueToSet', ...).
        // The legacy pin name was `value` (pre-2026-05-24 refactor); a
        // mismatch here silently dropped the assignment because the
        // runtime would look at `valueToSet` and find nothing wired.
        $this->wireValueIntoNode($valueDescriptor, $node, $node_id, 'valueToSet');
        $this->nodes[] = $node;
        $this->wireExec($node_id);
    }

    /**
     * @param array<string, mixed> $stmt
     */
    private function compileIfStmt(array $stmt): void
    {
        $cond = $stmt['test'];
        // Compound conditions desugar to nested ifs before they reach the
        // single-comparison condition node.
        if (is_array($cond) && ($cond['type'] ?? '') === 'BinaryExpr') {
            $op = (string) $cond['operator'];
            if ($op === '&&') {
                // if (A && B) X else Y  →  if (A) { if (B) X else Y } else Y
                $inner = [
                    'type' => 'IfStmt',
                    'test' => $cond['right'],
                    'consequent' => $stmt['consequent'],
                    'alternate' => $stmt['alternate'],
                    'line' => $stmt['line'],
                    'column' => $stmt['column'],
                ];
                $rewritten = [
                    'type' => 'IfStmt',
                    'test' => $cond['left'],
                    'consequent' => ['type' => 'BlockStmt', 'body' => [$inner], 'line' => $stmt['line'], 'column' => $stmt['column']],
                    'alternate' => $stmt['alternate'],
                    'line' => $stmt['line'],
                    'column' => $stmt['column'],
                ];
                $this->compileIfStmt($rewritten);
                return;
            }
            if ($op === '||') {
                // if (A || B) X else Y  →  if (A) X else if (B) X else Y
                $inner = [
                    'type' => 'IfStmt',
                    'test' => $cond['right'],
                    'consequent' => $stmt['consequent'],
                    'alternate' => $stmt['alternate'],
                    'line' => $stmt['line'],
                    'column' => $stmt['column'],
                ];
                $rewritten = [
                    'type' => 'IfStmt',
                    'test' => $cond['left'],
                    'consequent' => $stmt['consequent'],
                    'alternate' => $inner,
                    'line' => $stmt['line'],
                    'column' => $stmt['column'],
                ];
                $this->compileIfStmt($rewritten);
                return;
            }
        }

        [$operator, $left, $right] = $this->resolveCondition($cond, (int) $stmt['line'], (int) $stmt['column']);

        // Each comparison operator is its own node in the catalog. The
        // compiler picks the matching key + ports based on the operator;
        // there is no longer a single workflow.condition with an operator
        // config that dispatches at runtime.
        [$verb_key, $is_unary] = self::comparisonNodeForOperator($operator);

        $node_id = $this->makeId('cond');
        $node = [
            'id' => $node_id,
            'key' => $verb_key,
            'type' => 'condition',
            'label' => $verb_key,
            'data' => [],
        ];
        if ($is_unary) {
            // Unary tests (is_empty/is_not_empty/exists/not_exists) read
            // `value`; the second operand from resolveCondition is a null
            // sentinel literal that we drop.
            $this->wireValueIntoNode($left, $node, $node_id, 'value');
        } else {
            $this->wireValueIntoNode($left, $node, $node_id, 'left');
            $this->wireValueIntoNode($right, $node, $node_id, 'right');
        }
        $this->nodes[] = $node;
        $this->wireExec($node_id);

        // Compile consequent (yes branch).
        $savedTail = $this->execTail;
        $savedTailOutput = $this->execTailOutput;
        $this->execTail = $node_id;
        $this->execTailOutput = 'yes';
        $this->compileBranch($stmt['consequent']);
        $yesTail = $this->execTail;
        $yesTailOutput = $this->execTailOutput;

        // Compile alternate (no branch).
        $this->execTail = $node_id;
        $this->execTailOutput = 'no';
        if ($stmt['alternate'] !== null) {
            $this->compileBranch($stmt['alternate']);
        }
        $noTail = $this->execTail;
        $noTailOutput = $this->execTailOutput;

        // After if, both branches converge. We do not insert a merge node;
        // subsequent statements should wire onto BOTH branch tails. To keep
        // v1.0 simple we set execTail to the condition node + 'next' but
        // emit no exec output from it — meaning whatever statements follow
        // attach to NO ONE in v1.0. This is acceptable when the user puts
        // an if as the LAST statement in a block; for nested code after an
        // if we synthesize a merge node so downstream stmts have a single
        // exec source.
        if ($yesTail !== null || $noTail !== null) {
            $merge_id = $this->makeId('merge');
            $this->nodes[] = [
                'id' => $merge_id,
                'key' => 'workflow.merge',
                'type' => 'action',
                'label' => 'Merge',
                'data' => [],
            ];
            if ($yesTail !== null) {
                $this->addExec($yesTail, $yesTailOutput, $merge_id, 'in');
            }
            if ($noTail !== null) {
                $this->addExec($noTail, $noTailOutput, $merge_id, 'in');
            } elseif ($stmt['alternate'] === null) {
                // No `else` → wire the condition's `no` directly into merge.
                $this->addExec($node_id, 'no', $merge_id, 'in');
            }
            $this->execTail = $merge_id;
            $this->execTailOutput = 'next';
        } else {
            $this->execTail = $savedTail;
            $this->execTailOutput = $savedTailOutput;
        }
    }

    /**
     * Compile a branch (Block or single statement) keeping exec tail state.
     *
     * @param array<string, mixed> $branch
     */
    private function compileBranch(array $branch): void
    {
        if (($branch['type'] ?? '') === 'BlockStmt') {
            $this->compileStatements((array) $branch['body']);
            return;
        }
        $this->compileStatement($branch);
    }

    /**
     * @param array<string, mixed> $stmt
     */
    private function compileExprStmt(array $stmt): void
    {
        $expr = $stmt['expression'];
        $line = (int) $stmt['line'];
        $col = (int) $stmt['column'];

        // Statement-level await call.
        if (is_array($expr) && $expr['type'] === 'AwaitExpr') {
            $callExpr = $expr['argument'];
            if (is_array($callExpr) && $callExpr['type'] === 'CallExpr') {
                $sugar = $this->trySugarCall($callExpr, $line, $col, awaited: true);
                if ($sugar) {
                    return;
                }
            }
            $this->compileVerbCallStatement($expr['argument'], $line, $col);
            return;
        }
        // Pure call expression as a statement — only allow if it's a sugar
        // call (stop(), delay(), etc.). Verb calls without await are not
        // allowed: actions must be awaited so the exec chain stays explicit.
        if (is_array($expr) && $expr['type'] === 'CallExpr') {
            if ($this->trySugarCall($expr, $line, $col, awaited: false)) {
                return;
            }
            // Allow calling user-defined impure functions without await
            // would be ambiguous; require await for clarity.
            $callee = $expr['callee'] ?? null;
            if (is_array($callee) && $callee['type'] === 'Identifier') {
                throw CompileError::compile('await_required', $line, $col, sprintf('Call to "%s" needs `await` so the exec chain is explicit. Write `await %s(...);`.', (string) $callee['name'], (string) $callee['name']));
            }
            $this->compileVerbCallStatement($expr, $line, $col);
            return;
        }
        throw CompileError::compile('useless_expression', $line, $col, 'Expression statement has no effect. Use `await <verb>(...)` to invoke a node, or remove the line.');
    }

    /**
     * Intercept calls to sugar functions (delay, retry, approval, plus the
     * user-defined function call site). Returns true when the call was
     * handled as sugar and the caller should not fall through to the
     * generic verb-call path.
     *
     * @param array<string, mixed> $callExpr
     */
    private function trySugarCall(array $callExpr, int $line, int $col, bool $awaited): bool
    {
        $callee = $callExpr['callee'] ?? null;
        if (!is_array($callee) || $callee['type'] !== 'Identifier') {
            return false;
        }
        $name = (string) $callee['name'];
        if ($name === 'delay' && $awaited) {
            $this->compileDelayCall($callExpr, $line, $col);
            return true;
        }
        if ($name === 'retry' && $awaited) {
            $this->compileRetryCall($callExpr, $line, $col);
            return true;
        }
        if ($name === 'approval' && $awaited) {
            $this->compileApprovalCall($callExpr, $line, $col);
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $stmt
     */
    private function compileReturnStmt(array $stmt): void
    {
        // Only bare `return;` is allowed in the top-level flow — it
        // terminates the current exec branch. `return X` has no meaning
        // because the flow is not a function and produces no value.
        if ($stmt['value'] !== null) {
            throw CompileError::compile('return_value_in_flow', (int) $stmt['line'], (int) $stmt['column'], 'return with a value is not supported. The flow does not produce a value; use stop() to end the workflow or `return;` to leave the current branch.');
        }
        $this->execTail = null;
    }

    /**
     * @param array<string, mixed> $stmt
     */
    private function compileStopStmt(array $stmt): void
    {
        $verb = VerbCatalog::find('workflow.stop');
        if ($verb === null) {
            throw CompileError::compile('stop_unavailable', (int) $stmt['line'], (int) $stmt['column'], 'Node workflow.stop is not in the contract.');
        }
        $node_id = $this->makeId('stop');
        $this->nodes[] = [
            'id' => $node_id,
            'key' => 'workflow.stop',
            'type' => 'action',
            'label' => 'Stop',
            'data' => [],
        ];
        $this->wireExec($node_id);
        $this->execTail = null; // nothing else flows after stop()
    }

    /**
     * Compile `await delay(seconds)` to a workflow.delay node. The
     * argument compiles like any value expression (literal, token, or
     * data pin from a captured const).
     *
     * @param array<string, mixed> $callExpr
     */
    private function compileDelayCall(array $callExpr, int $line, int $column): void
    {
        $args = (array) ($callExpr['arguments'] ?? []);
        if (count($args) !== 1) {
            throw CompileError::compile('delay_arity', $line, $column, 'delay() takes exactly one argument (seconds).');
        }
        $verb = VerbCatalog::find('workflow.delay');
        if ($verb === null) {
            throw CompileError::compile('delay_unavailable', $line, $column, 'Node workflow.delay is not in the contract.');
        }
        $node_id = $this->makeId('delay');
        $node = [
            'id' => $node_id,
            'key' => 'workflow.delay',
            'type' => 'delay',
            'label' => 'Delay',
            'data' => [],
        ];
        $valueDescriptor = $this->compileExpression($args[0]);
        // workflow.delay's config field is 'seconds'.
        $this->wireValueIntoNode($valueDescriptor, $node, $node_id, 'seconds');
        $this->nodes[] = $node;
        $this->wireExec($node_id);
    }

    // -----------------------------------------------------------------
    // Verb call → node insertion.
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $callExpr
     * @return array{nodeId: string, verb: array<string, mixed>}
     */
    private function compileVerbCallStatement(array $callExpr, int $line, int $column): array
    {
        if (($callExpr['type'] ?? '') !== 'CallExpr') {
            throw CompileError::compile('await_non_call', $line, $column, 'await must be followed by a verb call like `category.verb({...})`.');
        }
        // Typed virtual surfaces (variables, entities, generic nodes)
        // are now resolved by resolveVerbKey via the per-compilation
        // resolver map (built from the three TypingsBuilder filters).
        // The legacy `tryLowerVariablesCall` desugaring that
        // recognised `variables.X.get()` MemberExpr chains is gone —
        // the LLM writes `Email$Variable$Get()` as a single identifier
        // and the resolver translates it.
        // The typed entity API (incidentes.create / .update / etc.) is
        // intentionally NOT lowered any more. It was a literal in
        // disguise: the entity slug looked like an identifier in the
        // source but the compiler injected it into the generic verb's
        // entity arg. Under the no-literals rule the entity slug must
        // travel as a real data value — coming from event.entity, from
        // an upstream node's output, or from a workflow variable the
        // operator declares. The LLM uses the generic verbs directly
        // (pfm.record_create/update/delete/query) and supplies entity
        // through its own pin.
        $verbKey = $this->resolveVerbKey($callExpr['callee'], $line, $column);
        $verb = VerbCatalog::find($verbKey);
        if ($verb === null) {
            throw CompileError::compile('unknown_verb', $line, $column, sprintf('Verb "%s" is not in the node catalog.', $verbKey), 'See /lib/nodes.d.ts for the available verbs.');
        }
        $args = (array) ($callExpr['arguments'] ?? []);
        if (count($args) > 1) {
            throw CompileError::compile('too_many_args', $line, $column, sprintf('Verb "%s" accepts a single named-object argument.', $verbKey));
        }
        $arg_obj = $args === [] ? ['type' => 'ObjectExpr', 'properties' => []] : $args[0];
        if ($arg_obj['type'] !== 'ObjectExpr') {
            throw CompileError::compile('verb_arg_not_object', $line, $column, sprintf('Verb "%s" expects a named-object argument like { key: value, ... }.', $verbKey));
        }

        $node_id = $this->makeId($this->shortFor($verbKey));
        $node = [
            'id' => $node_id,
            'key' => $verbKey,
            'type' => (string) $verb['kind'],
            'label' => (string) ($verb['label'] ?? $verbKey),
            'data' => [],
        ];

        // Structural entity slug inlined onto node.data when the LLM
        // used a typed virtual identifier (`Incidentes$Create` etc.).
        // The slug is compiler-emitted, never written by the LLM, so
        // it lands directly in node.data['entity'] without going
        // through the input-pin literal check.
        if ($this->pendingEntityInjection !== null) {
            $node['data']['entity'] = $this->pendingEntityInjection;
            $this->pendingEntityInjection = null;
        }
        if ($this->pendingVariableInjection !== null) {
            $slug = $this->pendingVariableInjection;
            $node['data']['variableName'] = $slug;
            // Editor's render reads node.data.variableId to look up
            // the variable in studio.variables and pull the live
            // label; the node label is dynamic, not stored — if the
            // operator renames the variable, every Get/Set card
            // reflects the new name immediately, and if they delete
            // the variable, the editor prunes the orphan nodes.
            $node['data']['variableId'] = $slug;
            $this->pendingVariableInjection = null;
        }

        $wired_pin_keys = [];
        foreach ((array) $arg_obj['properties'] ?? [] as $prop) {
            $key = (string) ($prop['key'] ?? '');
            $argKind = VerbCatalog::argKind($verb, $key);
            if ($argKind === null) {
                $allowed = $this->describeAllowedArgs($verb);
                throw CompileError::compile('unknown_arg', (int) ($prop['line'] ?? $line), (int) ($prop['column'] ?? $column), sprintf('Verb "%s" has no arg named "%s". %s', $verbKey, $key, $allowed));
            }
            $valueDescriptor = $this->compileExpression($prop['value']);
            $this->wireValueIntoNode($valueDescriptor, $node, $node_id, $key);
            $wired_pin_keys[$key] = true;
        }

        $this->nodes[] = $node;
        $this->wireExec($node_id);

        // Auto-variable seeding. Pins marked `requiresAutoVariable` are
        // operator-tunable configuration that does NOT come from the
        // data model (EQL filters, cron expressions, template strings,
        // thresholds, etc.). When the LLM does not wire them
        // explicitly, the compiler seeds a workflow variable + emits
        // the get_variable → pin wire transparently. The variable lives
        // in studio.variables so the operator can edit its default
        // value from the editor; the LLM never has to ask for it.
        $this->seedAutoVariablesForVerb($verb, $node_id, $wired_pin_keys);

        // Condition-type verbs (data.greater_than, data.equals, workflow.router,
        // data.is_empty, etc.) expose `yes`/`no` exec outputs instead of `next`.
        // When the LLM awaits one as a statement — e.g.
        //   const overloaded = await data.greater_than({ left, right });
        //   if (overloaded.matched) { ... }
        // — the previous-tail.next default would synthesize an invalid
        // `<cond>.next → <next-node>.in` exec connection. Fork yes+no into a
        // merge so the exec chain has a real `next` to attach to, and the
        // condition's data output (matched/route) stays available downstream
        // via the const alias.
        if ((string) ($verb['kind'] ?? '') === 'condition') {
            $merge_id = $this->makeId('merge');
            $this->nodes[] = [
                'id' => $merge_id,
                'key' => 'workflow.merge',
                'type' => 'action',
                'label' => 'Merge',
                'data' => [],
            ];
            $this->addExec($node_id, 'yes', $merge_id, 'in');
            $this->addExec($node_id, 'no', $merge_id, 'in');
            $this->execTail = $merge_id;
            $this->execTailOutput = 'next';
        }

        return ['nodeId' => $node_id, 'verb' => $verb];
    }

    /**
     * Detect a call to a per-variable virtual function declared in the
     * per-workflow /lib/variables.d.ts:
     *
     *   await variables.<X>.get()           ↓
     *   await variables.<X>.set({ value })  ↓
     *
     *   await workflow.get_variable({ variableName: 'X' })
     *   await workflow.set_variable({ variableName: 'X', value })
     *
     * Both runtime nodes accept `variableName` as a config field. The
     * lowering injects it as a literal string property on the args
     * object and rewrites the callee to point at the real workflow.*
     * verb. The rest of the dispatch handles the new shape unchanged.
     *
     * Returns the rewritten CallExpr, or null when the call is not a
     * variable-virtual.
     *
     * @param array<string, mixed> $callExpr
     * @return array<string, mixed>|null
     */
    private function tryLowerVariablesCall(array $callExpr, int $line, int $column): ?array
    {
        $parts = $this->dottedCallee($callExpr['callee'] ?? null);
        if ($parts === null || count($parts) !== 3 || $parts[0] !== 'variables') {
            return null;
        }
        $varName = $parts[1];
        $verb = $parts[2];
        if (!in_array($verb, ['get', 'set'], true)) {
            return null;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $varName) !== 1) {
            throw CompileError::compile(
                'invalid_variable_name',
                $line,
                $column,
                sprintf('variables.%s.%s: variable name must be a JS identifier.', $varName, $verb)
            );
        }

        // No auto-declare. The LLM does not create workflow variables,
        // does not pick names, does not write defaults — the operator
        // owns the variable editor. If the source references a variable
        // that does not exist in studio.variables, the compile fails
        // here with a typed error and the LLM must either propose a
        // different design or tell the operator to declare the variable
        // first (in plain language, never via the compiler error).
        if (!isset($this->flowVarIds[$varName])) {
            throw CompileError::compile(
                'variable_not_declared',
                $line,
                $column,
                sprintf('Workflow variable "%s" is not declared. Variables are owned by the operator and declared via the variable editor; the workflow source cannot create them.', $varName)
            );
        }

        $args = (array) ($callExpr['arguments'] ?? []);
        $arg_obj = $args === []
            ? ['type' => 'ObjectExpr', 'properties' => [], 'line' => $line, 'column' => $column]
            : $args[0];
        if (($arg_obj['type'] ?? '') !== 'ObjectExpr') {
            // Caller passed something weird like a positional arg; let the
            // normal flow surface the error against the real verb.
            return null;
        }
        // get() takes no args; set() takes { value: ... }. We don't
        // validate the value here — the existing dispatch will reject
        // unknown keys against the real workflow.set_variable contract.

        $properties = (array) ($arg_obj['properties'] ?? []);
        $properties[] = [
            'type' => 'Property',
            'key' => 'variableName',
            'value' => [
                'type' => 'LiteralString',
                'value' => $varName,
                'compilerEmitted' => true,
                'line' => $line,
                'column' => $column,
            ],
            'line' => $line,
            'column' => $column,
        ];

        return [
            'type' => 'CallExpr',
            'callee' => [
                'type' => 'MemberExpr',
                'object' => ['type' => 'Identifier', 'name' => 'workflow', 'line' => $line, 'column' => $column],
                'property' => $verb === 'get' ? 'get_variable' : 'set_variable',
                'computed' => false,
                'line' => $line,
                'column' => $column,
            ],
            'arguments' => [[
                'type' => 'ObjectExpr',
                'properties' => $properties,
                'line' => $line,
                'column' => $column,
            ]],
            'line' => $line,
            'column' => $column,
        ];
    }

    /**
     * Collapse a MemberExpr / Identifier chain into its dotted parts in
     * source order: `a.b.c` -> ['a','b','c']. Returns null when the chain
     * is not a pure dotted name (computed access, call mid-chain, etc.).
     *
     * @param mixed $callee
     * @return array<int, string>|null
     */
    private function dottedCallee($callee): ?array
    {
        if (!is_array($callee)) {
            return null;
        }
        if ($callee['type'] === 'Identifier') {
            return [(string) $callee['name']];
        }
        if ($callee['type'] !== 'MemberExpr' || !empty($callee['computed'])) {
            return null;
        }
        $parts = [];
        $cur = $callee;
        while (is_array($cur) && $cur['type'] === 'MemberExpr') {
            if (!empty($cur['computed'])) {
                return null;
            }
            $parts[] = (string) $cur['property'];
            $cur = $cur['object'];
        }
        if (!is_array($cur) || $cur['type'] !== 'Identifier') {
            return null;
        }
        $parts[] = (string) $cur['name'];
        return array_reverse($parts);
    }

    /**
     * Resolves a callee AST node into a verb key like "email.send_email".
     *
     * Accepts three syntactic forms:
     *   1. A bare Identifier whose name contains `$` — the virtual
     *      typed node (e.g. `Email$send_email`, `Incidentes$Create`).
     *      The compiler looks up the name in the resolver map and
     *      returns the real catalog verb. Side-effect: stashes the
     *      structural entity slug in $this->pendingEntityInjection
     *      so the caller (compileVerbCallStatement) can inline it
     *      onto the emitted node's data.
     *   2. `Category.verb` MemberExpr — legacy direct dotted form.
     *   3. `Category.deeper.verb` chain — collapsed greedily for
     *      historical reasons.
     */
    private function resolveVerbKey(array $callee, int $line, int $column): string
    {
        $this->pendingEntityInjection = null;

        // HANDLE-GRAPH model: `nodes.<Method>(...)`. The method name IS the
        // typed virtual identifier (Category$verb, Entity$Operation,
        // Name$Variable$Get, ...) — resolve it through the merged resolver
        // exactly like the legacy bare-identifier form.
        if (($callee['type'] ?? '') === 'MemberExpr'
            && empty($callee['computed'])
            && is_array($callee['object'] ?? null)
            && ($callee['object']['type'] ?? '') === 'Identifier'
            && (string) ($callee['object']['name'] ?? '') === 'nodes'
        ) {
            $name = (string) $callee['property'];
            $resolver = $this->resolver();
            if (isset($resolver[$name])) {
                $entry = $resolver[$name];
                if (isset($entry['entity']) && $entry['entity'] !== '') {
                    $this->pendingEntityInjection = (string) $entry['entity'];
                }
                if (isset($entry['entityFilter']) && $entry['entityFilter'] !== '') {
                    $this->pendingEntityFilterInjection = (string) $entry['entityFilter'];
                }
                if (isset($entry['variableName']) && $entry['variableName'] !== '') {
                    $this->pendingVariableInjection = (string) $entry['variableName'];
                }
                return (string) $entry['verb'];
            }
            throw CompileError::compile(
                'unknown_virtual_node',
                $line,
                $column,
                sprintf('nodes.%s does not resolve to a node. Use the exact method names declared in /lib/nodes.d.ts and /lib/manage.d.ts (Category$verb / Entity$Operation).', $name)
            );
        }

        if ($callee['type'] === 'Identifier') {
            $name = (string) $callee['name'];
            $resolver = $this->resolver();
            if (isset($resolver[$name])) {
                $entry = $resolver[$name];
                if (isset($entry['entity']) && $entry['entity'] !== '') {
                    $this->pendingEntityInjection = (string) $entry['entity'];
                }
                if (isset($entry['variableName']) && $entry['variableName'] !== '') {
                    $this->pendingVariableInjection = (string) $entry['variableName'];
                }
                return (string) $entry['verb'];
            }
            // Bare identifier with no resolver match — almost
            // certainly a typo of a $-form node name. Tell the LLM
            // to look in the typings.
            throw CompileError::compile(
                'unknown_virtual_node',
                $line,
                $column,
                sprintf('Identifier "%s" does not resolve to a node. Use the node names declared in /lib/nodes.d.ts and /lib/manage.d.ts (Category$verb / Entity$Operation).', $name)
            );
        }
        if ($callee['type'] === 'MemberExpr' && $callee['object']['type'] === 'Identifier') {
            return (string) $callee['object']['name'] . '.' . (string) $callee['property'];
        }
        if ($callee['type'] === 'MemberExpr' && $callee['object']['type'] === 'MemberExpr') {
            // a.b.c → only supported when a.b is a known category prefix the
            // catalogue uses (e.g. content.create_post). We collapse dots
            // greedily.
            $parts = [];
            $cur = $callee;
            while ($cur['type'] === 'MemberExpr') {
                $parts[] = (string) $cur['property'];
                $cur = $cur['object'];
            }
            if ($cur['type'] !== 'Identifier') {
                throw CompileError::compile('complex_callee', $line, $column, 'Verb callee must be a dotted name like `category.verb`.');
            }
            $parts[] = (string) $cur['name'];
            return implode('.', array_reverse($parts));
        }
        throw CompileError::compile('complex_callee', $line, $column, 'Verb callee must be a dotted name like `category.verb`.');
    }

    /**
     * Set by resolveVerbKey when the resolved identifier was a typed
     * entity virtual (`Incidentes$Create` etc.) so the caller can
     * inline the entity slug onto the emitted node's data slot
     * without it travelling through the LLM source as a literal.
     */
    private ?string $pendingEntityInjection = null;

    /**
     * Set by resolveVerbKey when the resolved identifier was a typed
     * variable virtual (`Email$Variable$Get`, `Email$Variable$Set`)
     * so the caller can inline the variableName onto the emitted
     * workflow.*_variable node's data — the variable name never
     * appears as a literal in the LLM source.
     */
    private ?string $pendingVariableInjection = null;

    /**
     * Set by resolveVerbKey when the resolved identifier was an entity-scoped
     * TRIGGER (`Incidentes$Created$Trigger` etc.) so the caller can inline the
     * entityFilter onto the trigger node's data.
     */
    private ?string $pendingEntityFilterInjection = null;

    /**
     * @param array<string, mixed> $verb
     */
    private function describeAllowedArgs(array $verb): string
    {
        $inputs = array_map(static fn(array $p): string => (string) $p['key'], (array) ($verb['inputs'] ?? []));
        $configs = array_map(static fn(array $p): string => (string) $p['key'], (array) ($verb['config'] ?? []));
        $merged = array_values(array_unique(array_merge($inputs, $configs)));
        if ($merged === []) {
            return 'Verb takes no args.';
        }
        return 'Allowed: ' . implode(', ', $merged) . '.';
    }

    // -----------------------------------------------------------------
    // Expression → value descriptor.
    //
    // A descriptor is one of:
    //   ['kind' => 'literal', 'value' => mixed, 'type' => 'string'|'number'|'boolean'|'null'|'object'|'array']
    //   ['kind' => 'token',   'token' => 'event.x.y' | 'variable.foo' | 'node.<id>.<out>' | 'site.x' | 'execution.x']
    //   ['kind' => 'dataPin', 'sourceNodeId' => 'N',  'sourceOutput' => 'pin']
    //   ['kind' => 'template','segments' => [['lit', str], ['tok', 'event.x']]]
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $expr
     * @return array<string, mixed>
     */
    private function compileExpression(array $expr): array
    {
        switch ((string) $expr['type']) {
            case 'LiteralString':
                $desc = ['kind' => 'literal', 'value' => (string) $expr['value'], 'type' => 'string'];
                if (!empty($expr['compilerEmitted'])) {
                    // Synthesised by a lowering pass (entity slug, variableName, ...).
                    // The literal-in-sensitive-pin check skips these because they
                    // are not authored by the LLM — they identify the target
                    // resource the typed virtual called against.
                    $desc['compilerEmitted'] = true;
                }
                return $desc;
            case 'LiteralNumber':
                $v = $expr['value'];
                return ['kind' => 'literal', 'value' => $v, 'type' => is_int($v) ? 'integer' : 'number'];
            case 'LiteralBool':
                return ['kind' => 'literal', 'value' => (bool) $expr['value'], 'type' => 'boolean'];
            case 'LiteralNull':
                return ['kind' => 'literal', 'value' => null, 'type' => 'null'];
            case 'NowExpr':
                return ['kind' => 'token', 'token' => 'execution.date'];
            case 'Identifier':
                return $this->resolveIdentifier((string) $expr['name'], (int) $expr['line'], (int) $expr['column']);
            case 'MemberExpr':
                return $this->resolveMember($expr);
            case 'TemplateExpr':
                return $this->compileTemplate($expr);
            case 'ObjectExpr':
                return $this->compileObjectLiteral($expr);
            case 'ArrayExpr':
                return $this->compileArrayLiteral($expr);
            case 'BinaryExpr':
                if (in_array((string) $expr['operator'], ['+', '-', '*', '/', '%'], true)) {
                    throw CompileError::compile('arithmetic_unsupported', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), 'Arithmetic in expressions is not in v1.0. Use the explicit math verb that matches the operator (data.math_add, data.math_subtract, data.math_multiply, data.math_divide).');
                }
                throw CompileError::compile('binary_in_expression', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), 'Binary operators in value positions are not in v1.0. Use them only inside `if (...)`.');
            case 'TernaryExpr':
                throw CompileError::compile('ternary_unsupported', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), 'Ternary `? :` in value positions is not in v1.0. Use if/else with assignment to a `let` variable.');
            case 'UnaryExpr':
                throw CompileError::compile('unary_unsupported', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), 'Unary operators in value positions are not in v1.0.');
            case 'AwaitExpr':
                throw CompileError::compile('inline_await', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), 'Inline `await` inside an expression is not supported. Bind it to a const first: `const x = await ...; useIt(x);`.');
            default:
                throw CompileError::compile('unknown_expression', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), sprintf('Unknown expression type "%s".', (string) $expr['type']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveIdentifier(string $name, int $line, int $column): array
    {
        if ($name === $this->eventAlias) {
            return ['kind' => 'token', 'token' => 'event'];
        }
        if (isset($this->constAliases[$name])) {
            $alias = $this->constAliases[$name];
            return ['kind' => 'token', 'token' => 'node.' . $alias['nodeId'] . '.' . $alias['output']];
        }
        if (isset($this->flowVarIds[$name])) {
            return ['kind' => 'token', 'token' => 'variable.' . $this->flowVarIds[$name]];
        }
        if ($name === 'site') {
            return ['kind' => 'token', 'token' => 'site'];
        }
        if ($name === 'execution') {
            return ['kind' => 'token', 'token' => 'execution'];
        }
        // A bare handle used where a value is expected. Data flows only
        // through a node's typed OUTPUT pins — point the LLM at `.out.<pin>`.
        if (isset($this->handleNodeId[$name])) {
            throw CompileError::compile('handle_needs_out_pin', $line, $column, sprintf('"%s" is a node handle, not a value. Read one of its outputs: %s.out.<pin>.', $name, $name));
        }
        throw CompileError::compile('unknown_identifier', $line, $column, sprintf('Unknown name "%s". Reference a node output (handle.out.<pin>) or read a workflow variable with nodes.<Name>$Variable$Get().', $name));
    }

    /**
     * @param array<string, mixed> $expr
     * @return array<string, mixed>
     */
    private function resolveMember(array $expr): array
    {
        if (!empty($expr['computed'])) {
            throw CompileError::compile('computed_member', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), 'Computed member access `[...]` is not in v1.0.');
        }
        $property = (string) $expr['property'];

        // HANDLE-GRAPH model: `<handle>.out.<pin>` → the pin output of the
        // node the handle created. This is the ONLY way data flows between
        // nodes. `event.out.story`, `query1.out.count`, etc.
        $obj = $expr['object'] ?? null;
        if (is_array($obj)
            && ($obj['type'] ?? '') === 'MemberExpr'
            && empty($obj['computed'])
            && (string) ($obj['property'] ?? '') === 'out'
            && is_array($obj['object'] ?? null)
            && ($obj['object']['type'] ?? '') === 'Identifier'
            && isset($this->handleNodeId[(string) ($obj['object']['name'] ?? '')])
        ) {
            $handleName = (string) $obj['object']['name'];
            return ['kind' => 'token', 'token' => 'node.' . $this->handleNodeId[$handleName] . '.' . $property];
        }

        // const aliases pre-bind to a SPECIFIC output of the aliased
        // node (the verb's primary output, set in compileVarDecl). When
        // the user accesses `alias.<property>` and <property> matches a
        // declared output pin of the same node, emit
        // `node.<id>.<property>` directly. Without this short-circuit
        // the resolver would append `.<property>` to the already-
        // appended primary output and produce a double key like
        // `node.execute_command_X.jobId.jobId`.
        if (is_array($expr['object'] ?? null)
            && ($expr['object']['type'] ?? '') === 'Identifier'
            && isset($this->constAliases[(string) ($expr['object']['name'] ?? '')])
        ) {
            $alias_name = (string) $expr['object']['name'];
            $alias = $this->constAliases[$alias_name];
            $node_id = (string) $alias['nodeId'];
            $aliased_node = null;
            foreach ($this->nodes as $n) {
                if (is_array($n) && (string) ($n['id'] ?? '') === $node_id) {
                    $aliased_node = $n;
                    break;
                }
            }
            if ($aliased_node !== null) {
                $verb = VerbCatalog::find((string) ($aliased_node['key'] ?? ''));
                $outputs = $verb !== null && is_array($verb['outputs'] ?? null) ? $verb['outputs'] : [];
                foreach ($outputs as $out) {
                    if (is_array($out) && (string) ($out['key'] ?? '') === $property) {
                        return ['kind' => 'token', 'token' => 'node.' . $node_id . '.' . $property];
                    }
                }
            }
        }

        $object = $this->compileExpression($expr['object']);

        if ($object['kind'] === 'token') {
            $base = (string) $object['token'];
            // node.<id>.<out>.<deeper>? — only allow when out is a structured pin.
            // We just append the path.
            return ['kind' => 'token', 'token' => $base . '.' . $property];
        }

        throw CompileError::compile('member_on_value', (int) ($expr['line'] ?? 0), (int) ($expr['column'] ?? 0), 'Member access is only supported on the event, a const-bound result, a flow variable, site, or execution.');
    }

    /**
     * @param array<string, mixed> $expr
     * @return array<string, mixed>
     */
    private function compileTemplate(array $expr): array
    {
        // Template literals always carry literal text (`[URGENTE] ${x}`
        // has `[URGENTE] ` as a literal segment). Workflows accept no
        // literals — including format templates — so the LLM cannot
        // write `` `...` `` syntax. If a constant text is needed it has
        // to come from an existing workflow variable.
        throw CompileError::compile(
            'template_literal_not_allowed',
            (int) ($expr['line'] ?? 0),
            (int) ($expr['column'] ?? 0),
            'Template literals are not allowed. Workflows accept no literals; read the text from an existing workflow variable.'
        );
    }

    /**
     * @param array<string, mixed> $expr
     * @return array<string, mixed>
     */
    private function compileObjectLiteral(array $expr): array
    {
        // First pass: compile every property; classify each as constant
        // literal vs dynamic (token / template / dataPin / nested dynamic).
        $compiled = [];
        $has_dynamic = false;
        foreach ((array) $expr['properties'] as $prop) {
            $key = (string) $prop['key'];
            $val = $this->compileExpression($prop['value']);
            $compiled[$key] = $val;
            if ((string) ($val['kind'] ?? '') !== 'literal') {
                $has_dynamic = true;
            }
        }

        // All-constant object → keep the cheap inline literal descriptor.
        // wireValueIntoNode will reject it on an input pin (workflows
        // accept no literals); on a config slot it lands inline as a
        // structural identifier.
        if (!$has_dynamic) {
            $out = [];
            foreach ($compiled as $k => $val) {
                $out[$k] = $val['value'];
            }
            return ['kind' => 'literal', 'value' => $out, 'type' => 'object'];
        }

        // At least one dynamic property → the object literal needed an
        // opaque builder chain. We no longer have data.object_set or
        // data.object_merge in the catalog (intentionally removed —
        // opaque-object generators), so an inline object with runtime
        // values cannot be constructed here. The customer must either
        // call a typed virtual API (eg. `<entity>.create({...})`,
        // which the compiler lowers to per-field pins on the runtime
        // verb), or pass the values via discrete pins that the
        // receiving verb declares one by one.
        throw CompileError::compile(
            'object_literal_with_runtime_values',
            (int) ($expr['line'] ?? 0),
            (int) ($expr['column'] ?? 0),
            'Inline object literal mixes constants with runtime values, but the opaque object builder nodes (data.object_set / data.object_merge) were removed. Use the entity\'s typed API (eg. `incidentes.create({...})`) so each field maps to its own pin, or split the values into individual pin arguments on the receiving verb.'
        );
    }

    /**
     * @param array<string, mixed> $expr
     * @return array<string, mixed>
     */
    private function compileArrayLiteral(array $expr): array
    {
        // Mirror of compileObjectLiteral but elements are indexed by
        // position. data.object_set's path_set helper accepts numeric
        // keys, so we reuse the same builder with paths "0", "1", ...
        $compiled = [];
        $has_dynamic = false;
        foreach ((array) $expr['elements'] as $el) {
            $val = $this->compileExpression($el);
            $compiled[] = $val;
            if ((string) ($val['kind'] ?? '') !== 'literal') {
                $has_dynamic = true;
            }
        }

        if (!$has_dynamic) {
            $out = [];
            foreach ($compiled as $val) {
                $out[] = $val['value'];
            }
            return ['kind' => 'literal', 'value' => $out, 'type' => 'array'];
        }

        // Array with at least one runtime element. The opaque
        // builder chain (data.object_set on numeric indices) was
        // removed with the rest of the opaque object generators.
        // Customers that need a list of runtime values should pipe
        // them via data.array_concat / array_take / array_filter
        // operators, each of which takes typed inputs.
        throw CompileError::compile(
            'array_literal_with_runtime_values',
            (int) ($expr['line'] ?? 0),
            (int) ($expr['column'] ?? 0),
            'Inline array literal mixes constants with runtime values, but the opaque array builder nodes (data.object_set with numeric indices) were removed. Pipe the values through array_concat / array_take / etc., each of which takes typed pin arguments.'
        );
    }

    /**
     * Convert a value descriptor to a serializable representation that
     * lives inside node.data (for literals/tokens/templates) or returns
     * the descriptor unchanged (for data pins, handled by the caller).
     *
     * @param array<string, mixed> $desc
     * @return mixed
     */
    private function descriptorToData(array $desc)
    {
        return match ($desc['kind']) {
            'literal' => $desc['value'],
            'token' => $this->renderToken((string) $desc['token']),
            'dataPin' => $this->renderToken('node.' . $desc['sourceNodeId'] . '.' . $desc['sourceOutput']),
            default => $desc,
        };
    }

    private function renderToken(string $token): string
    {
        return '{{' . $token . '}}';
    }

    /**
     * Translate any value descriptor into a (nodeId, outputPin) pair so the
     * caller can wire it as a real data connection. This centralises the
     * pin-only data flow rule: every runtime value — trigger output, flow
     * variable, upstream node output, template literal, even a constant —
     * appears in the graph as a SOURCE NODE wired into its consumer, never
     * as an embedded `{{token}}` string inside node.data.
     *
     * Used by wireValueIntoNode (top-level slot wiring) AND by
     * compileObjectLiteral / compileArrayLiteral when they materialise
     * dynamic properties as a chain of data.object_set builders.
     *
     * @param array<string, mixed> $desc
     * @return array{nodeId: string, output: string}
     */
    private function materialiseValueAsDataNode(array $desc): array
    {
        // CSE: if we have already produced a source for this exact
        // descriptor in this flow, return that source instead of
        // emitting a duplicate node. Drastically cuts the graph size
        // when the LLM reads the same trigger field twice (e.g.
        // event.record.estado in both branches of an if/else) or
        // repeats the same literal (e.g. 'demanda' in three places).
        $cacheKey = $this->descriptorCacheKey($desc);
        if ($cacheKey !== '' && isset($this->materialiseCache[$cacheKey])) {
            return $this->materialiseCache[$cacheKey];
        }

        $source = $this->materialiseValueAsDataNodeUncached($desc);
        if ($cacheKey !== '') {
            $this->materialiseCache[$cacheKey] = $source;
        }
        return $source;
    }

    /**
     * @param array<string, mixed> $desc
     */
    private function descriptorCacheKey(array $desc): string
    {
        $kind = (string) ($desc['kind'] ?? '');
        switch ($kind) {
            case 'dataPin':
                return sprintf('pin:%s.%s', (string) ($desc['sourceNodeId'] ?? ''), (string) ($desc['sourceOutput'] ?? ''));
            case 'token':
                return 'tok:' . (string) ($desc['token'] ?? '');
            case 'literal':
                // Distinguish int/float/string/bool/null by both type
                // and JSON-encoded value (so 0, 0.0 and '0' stay separate).
                $type = (string) ($desc['type'] ?? '');
                $value = $desc['value'] ?? null;
                return 'lit:' . $type . ':' . wp_json_encode($value);
            case 'template':
                return 'tpl:' . wp_json_encode($desc['segments'] ?? []);
            default:
                // Unknown shapes are not cached (let them re-emit each time).
                return '';
        }
    }

    /**
     * @param array<string, mixed> $desc
     * @return array{nodeId: string, output: string}
     */
    private function materialiseValueAsDataNodeUncached(array $desc): array
    {
        $kind = (string) ($desc['kind'] ?? '');

        // dataPin descriptor → already references a real node output.
        if ($kind === 'dataPin') {
            return [
                'nodeId' => (string) $desc['sourceNodeId'],
                'output' => (string) $desc['sourceOutput'],
            ];
        }

        if ($kind === 'token') {
            $token = (string) ($desc['token'] ?? '');

            // node.<id>.<out> → direct reference to an existing node's output.
            if (self::isNodeOutputToken($token)) {
                [$src_node, $src_pin] = self::splitNodeToken($token);
                if ($src_node !== '' && $src_pin !== '') {
                    return ['nodeId' => $src_node, 'output' => $src_pin];
                }
            }

            // event.<pin> → direct wire to a top-level trigger
            // output pin. Pin-only flat model: every field the
            // trigger emits is its own pin (no Object navigation,
            // no sub-pin paths). If the LLM wrote `event.X` and X
            // is not a declared output, that is a compile error.
            if (strncmp($token, 'event.', 6) === 0
                && $this->triggerNodeId !== null
                && $this->triggerKey !== null
            ) {
                $name = substr($token, 6);
                if ($name !== '' && strpos($name, '.') === false && $this->triggerHasOutput($name)) {
                    return ['nodeId' => $this->triggerNodeId, 'output' => $name];
                }
                throw CompileError::compile(
                    'unknown_event_pin',
                    0,
                    0,
                    sprintf(
                        'Trigger "%s" has no output pin named "%s". Every accessible value is a flat pin on the trigger — declare it in the trigger\'s contract instead of trying to navigate sub-properties.',
                        $this->triggerKey,
                        $name
                    )
                );
            }

            // variable.<name> → workflow.get_variable.valueToGet pin.
            // No sub-pin navigation: every variable carries a single
            // typed value pin (the LLM should `let x = ...` on the
            // primitive value, not the wrapping object). Pin key is
            // `valueToGet` per GetVariableNode::manifest(); the older
            // `value` name was renamed in the 2026-05-24 refactor and
            // any wire emitted with the legacy name dangles silently.
            if (strncmp($token, 'variable.', 9) === 0) {
                $name = substr($token, 9);
                if ($name !== '' && strpos($name, '.') === false) {
                    $get_id = $this->emitGetVariableExtractor($name);
                    return ['nodeId' => $get_id, 'output' => 'valueToGet'];
                }
            }
        }

        // Templates and bare literals cannot reach a data input as a
        // source node. Workflows accept no literals: every constant must
        // come from an existing workflow variable read via
        // `await variables.<name>.get()`. The LLM never writes a literal
        // here and the compiler never synthesises one — if this code is
        // reached it means the wireValueIntoNode gate let something
        // through that should have been rejected.
        throw CompileError::compile(
            'literal_not_allowed',
            (int) ($desc['line'] ?? 0),
            (int) ($desc['column'] ?? 0),
            sprintf(
                'Literal of kind "%s" cannot be materialised as a data source. Workflows accept no literals; every value must flow from an existing workflow variable or an upstream node output.',
                $kind === '' ? 'unknown' : $kind
            )
        );
    }

    /**
     * Wire a value descriptor into a node's input or config slot.
     *
     * @param array<string, mixed> $valueDescriptor
     * @param array<string, mixed> $node
     */
    private function wireValueIntoNode(array $valueDescriptor, array &$node, string $nodeId, string $slotKey): void
    {
        $verbKey = (string) $node['key'];
        $verb = VerbCatalog::find($verbKey);
        $argKind = $verb !== null ? VerbCatalog::argKind($verb, $slotKey) : 'config';
        $argKind = $argKind ?? 'config';

        $kind = (string) ($valueDescriptor['kind'] ?? '');

        // Literals (string / number / boolean / null) cannot land on a
        // data input pin. Workflows accept no literals; the LLM has the
        // info it has and must read every constant from an existing
        // workflow variable via `await variables.<name>.get()`. The only
        // literals the compiler tolerates on a data argument are the
        // structural identifiers it injects itself (entity slug for a
        // typed entity API, variableName for `variables.X.get()`); those
        // are pinned to config slots, not input pins (see below).
        if ($argKind === 'input' && $kind === 'literal') {
            throw CompileError::compile(
                'literal_on_input_pin',
                (int) ($valueDescriptor['line'] ?? 0),
                (int) ($valueDescriptor['column'] ?? 0),
                sprintf('Arg "%s" on node "%s" is a literal, but input pins are wires: they take an upstream node output (handle.out.<pin>) or a workflow variable, never a literal constant. If this pin is operator-tunable (filters, cron, templates, thresholds), OMIT it — it auto-seeds a variable. Otherwise read a variable with its pure getter node: const v = nodes.Name$Variable$Get(); then pass v.out.valueToGet. See the AUTHORING MODEL at the top of /lib/nodes.d.ts.', $slotKey, $verbKey)
            );
        }
        if ($argKind === 'input' && $kind === 'template') {
            throw CompileError::compile(
                'template_on_input_pin',
                (int) ($valueDescriptor['line'] ?? 0),
                (int) ($valueDescriptor['column'] ?? 0),
                sprintf('Arg "%s" on node "%s" is a template literal (still literal text), which cannot land on an input pin. OMIT the pin to auto-seed a tunable variable, or build the text upstream (a text-render node, or a variable read via nodes.Name$Variable$Get()) and wire that node output. See the AUTHORING MODEL at the top of /lib/nodes.d.ts.', $slotKey, $verbKey)
            );
        }

        // Input pins → wire from the upstream node output the descriptor
        // resolves to. Only tokens (event.<pin>, variable.<name>,
        // node.<id>.<out>) and dataPin descriptors survive the rejection
        // above, so materialiseValueAsDataNode never sees a literal here.
        if ($argKind === 'input') {
            $source = $this->materialiseValueAsDataNode($valueDescriptor);
            $this->addData($source['nodeId'], $source['output'], $nodeId, $slotKey);
            return;
        }

        // Config slot → write the rendered representation inline. Config
        // is the node's own structural setting (entity slug, variableName,
        // operator, hook name); literals are intrinsic to identifying what
        // the node operates on, not data values flowing at runtime.
        $node['data'][$slotKey] = $this->descriptorToData($valueDescriptor);
    }


    /**
     * Does the active trigger's contract declare an output pin with this key?
     */
    private function triggerHasOutput(string $output_key): bool
    {
        if ($this->triggerKey === null) {
            return false;
        }
        $verb = VerbCatalog::find($this->triggerKey);
        $outputs = is_array($verb['outputs'] ?? null) ? $verb['outputs'] : [];
        foreach ($outputs as $out) {
            if (is_array($out) && (string) ($out['key'] ?? '') === $output_key) {
                return true;
            }
        }
        // Triggers can carry dynamic outputs in their node studio
        // metadata — eg. developer.wp_hook subscribed to
        // pfm/event/<slug>.* exposes every entity field as a top-
        // level pin. The compiler attaches the list at compile time
        // (compileTriggerDecl); look it up on the live trigger node.
        if ($this->triggerNodeId !== null) {
            foreach ($this->nodes as $node) {
                if (($node['id'] ?? '') !== $this->triggerNodeId) continue;
                $extra = is_array($node['studio']['extraOutputs'] ?? null) ? $node['studio']['extraOutputs'] : [];
                foreach ($extra as $pin) {
                    if (is_array($pin) && (string) ($pin['key'] ?? '') === $output_key) {
                        return true;
                    }
                }
                break;
            }
        }
        return false;
    }


    /**
     * Synthesise a workflow.get_variable extractor that emits the flow
     * variable's current value on its `value` output. The compiler inserts
     * one per consumer reference so the graph SHOWS every read as a real
     * node; the runtime never resolves `{{variable.X}}` against the global
     * bucket.
     */
    /**
     * Seed a workflow variable + emit the get_variable→pin wire for
     * every input pin of $verb marked `requiresAutoVariable` that the
     * LLM did not wire explicitly. Idempotent on the variable name —
     * if a variable with the canonical autovariable name already
     * exists in studio (either declared by the operator or seeded by
     * an earlier node) it is reused; otherwise a fresh one is added.
     *
     * @param array<string, mixed> $verb
     * @param array<string, bool>  $wired_pin_keys  Pin keys already wired by the LLM (skip).
     */
    private function seedAutoVariablesForVerb(array $verb, string $node_id, array $wired_pin_keys): void
    {
        $inputs = is_array($verb['inputs'] ?? null) ? $verb['inputs'] : [];
        foreach ($inputs as $pin) {
            if (!is_array($pin) || empty($pin['requiresAutoVariable'])) {
                continue;
            }
            $pin_key = (string) ($pin['key'] ?? '');
            if ($pin_key === '' || isset($wired_pin_keys[$pin_key])) {
                continue;
            }
            $var_name = $this->autoVariableNameFor((string) ($verb['key'] ?? ''), $pin_key, $pin);
            $this->seedAutoVariable($var_name, $pin);
            $getvar_id = $this->emitGetVariableExtractor($var_name);
            $this->addData($getvar_id, 'valueToGet', $node_id, $pin_key);
        }
    }

    /**
     * Build a per-NODE-instance autovariable name. The name is the
     * HUMAN-READABLE string the editor shows in the variable list
     * (mixed case, spaces) — not a snake_case identifier. The TS
     * surface for the LLM is built from this name by
     * VariablesTypingsBuilder via tsIdent, which slugifies it to a
     * valid identifier (`Plantilla` → `Plantilla`, `Connector key` →
     * `Connector_key`). The runtime keys variables by the exact name
     * string. This keeps the editor UI readable while still giving
     * the LLM clean identifiers in /lib/variables.d.ts.
     *
     * Each occurrence of the same base name in a workflow gets its
     * own variable, suffixed with a space + counter starting at 2
     * for the second occurrence — three `Data$text_render` calls
     * each get their own `Plantilla` / `Plantilla 2` / `Plantilla 3`.
     *
     * @param array<string, mixed> $pin
     */
    private function autoVariableNameFor(string $verb_key, string $pin_key, array $pin = []): string
    {
        $base = self::humanLabelForAutoVariable((string) ($pin['label'] ?? ''));
        if ($base === '') {
            // Pin shipped no usable label — derive from the pin key
            // (camelCase / snake_case turn into "Camel Case" /
            // "snake case" then first-letter-uppercased).
            $base = self::humanLabelForAutoVariable($pin_key);
        }
        if ($base === '') {
            $base = 'Variable';
        }
        $candidate = $base;
        $suffix = 2;
        while ($this->autoVariableExists($candidate)) {
            $candidate = $base . ' ' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    /**
     * Build a clean, editor-presentable name from an arbitrary pin
     * label. Rules:
     *   1. Strip everything after the first parenthetical, em-dash
     *      ("—" or "--") or semicolon — those are descriptive
     *      continuations, not part of the name.
     *   2. Strip a trailing comma/colon continuation when there are
     *      already 2+ words before it (so "Sort clause, e.g. …" →
     *      "Sort clause" but a single-word label like "Plantilla"
     *      survives intact).
     *   3. Split camelCase / PascalCase: insert a space before any
     *      uppercase that follows lowercase or digit ("MetaKey" →
     *      "Meta Key", "IdempotencyKeyTemplate" → "Idempotency Key
     *      Template").
     *   4. Collapse internal whitespace, trim.
     *   5. Capitalise the first letter so the name reads as a proper
     *      label in the variable list ("noindex" → "Noindex").
     */
    private static function humanLabelForAutoVariable(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        // Drop trailing parenthetical / bracket / em-dash / semicolon.
        $value = preg_replace('/\s*[\(\[\;].*$/u', '', $value) ?? $value;
        $value = preg_replace('/\s*(?:—|--).*$/u', '', $value) ?? $value;
        // Drop a comma/colon-introduced suffix only when ≥2 words come first.
        $value = preg_replace('/^((?:\S+\s+){1,}\S+?)\s*[,:].*$/u', '$1', $value) ?? $value;
        // Split camelCase / PascalCase.
        $value = preg_replace('/([a-z0-9])([A-Z])/u', '$1 $2', $value) ?? $value;
        // Collapse internal whitespace.
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if ($value === '') {
            return '';
        }
        // Capitalise the first character (UTF-8 safe).
        $first = function_exists('mb_substr') ? mb_substr($value, 0, 1) : substr($value, 0, 1);
        $rest = function_exists('mb_substr') ? mb_substr($value, 1) : substr($value, 1);
        $first_up = function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
        return $first_up . $rest;
    }

    /**
     * Derive a snake_case id from a human-readable variable name.
     * Used as the variable's `id` field (stable, ASCII-only) while
     * `name` stays the human display. The TypingsBuilder builds the
     * TS identifier from `name` (via tsIdent); the runtime can index
     * by either (the WorkflowValidator accepts both).
     */
    private static function variableIdFromName(string $name): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($converted) && $converted !== '') {
                $name = $converted;
            }
        }
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim((string) preg_replace('/_+/', '_', $slug), '_');
        return $slug !== '' ? $slug : 'variable';
    }

    private function autoVariableExists(string $name): bool
    {
        foreach ($this->variables as $v) {
            if (is_array($v) && (string) ($v['name'] ?? '') === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map a pin's brand or type to the canonical workflow-variable
     * type identifier (matches WorkflowGraphMutator::is_allowed_variable_type).
     * Branded pins get the brand-typed variable; unbranded pins fall
     * back to the underlying primitive.
     *
     * @param array<string, mixed> $pin
     */
    private function varTypeForPin(array $pin): string
    {
        // Pin ships dropdown options → variable is a typed enum with
        // exactly the contract's allowed values. The TypingsBuilder
        // emits this as a TS literal union and the editor renders a
        // select bounded by the same set.
        if (self::pinEnumValues($pin) !== []) {
            return 'enum';
        }
        static $brand_to_type = [
            'Email'    => 'email',
            'Url'      => 'url',
            'Phone'    => 'phone',
            'DateTime' => 'datetime',
            'Currency' => 'currency',
            'UserRef'  => 'user',
            'GroupRef' => 'group',
            'MediaRef' => 'media',
            'PostRef'  => 'post',
        ];
        $brand = (string) ($pin['brand'] ?? '');
        if ($brand !== '' && isset($brand_to_type[$brand])) {
            return $brand_to_type[$brand];
        }
        $type = (string) ($pin['type'] ?? 'string');
        return match ($type) {
            'integer', 'number', 'boolean' => $type,
            'text', 'string' => 'text',
            default => 'text',
        };
    }

    /**
     * Extract the closed list of allowed string values for an enum-
     * carrying pin. Tolerates both the `[value, value, ...]` shape
     * (passed straight through from a static options list) and the
     * `[['value' => ..., 'label' => ...], ...]` shape used by
     * ConfigSchemaHelper's dynamic option resolvers.
     *
     * @param array<string, mixed> $pin
     * @return array<int, string>
     */
    private static function pinEnumValues(array $pin): array
    {
        $opts = $pin['options'] ?? null;
        if (!is_array($opts) || $opts === []) {
            return [];
        }
        $values = [];
        foreach ($opts as $opt) {
            if (is_scalar($opt)) {
                $s = (string) $opt;
                if ($s !== '' && !in_array($s, $values, true)) {
                    $values[] = $s;
                }
                continue;
            }
            if (is_array($opt) && isset($opt['value']) && is_scalar($opt['value'])) {
                $s = (string) $opt['value'];
                if ($s !== '' && !in_array($s, $values, true)) {
                    $values[] = $s;
                }
            }
        }
        return $values;
    }

    /**
     * Add a variable to studio.variables if not already present. The
     * compiler writes into $this->variables; the output graph carries
     * it under graph.studio.variables and apply_draft merges it with
     * any operator-declared variables on the live workflow.
     *
     * @param array<string, mixed> $pin
     */
    private function seedAutoVariable(string $name, array $pin): void
    {
        foreach ($this->variables as $v) {
            if (is_array($v) && (string) ($v['name'] ?? '') === $name) {
                return;
            }
        }
        $default = (string) ($pin['default'] ?? '');
        $type = $this->varTypeForPin($pin);
        $id = self::variableIdFromName($name);
        $entry = [
            // `id` is the snake_case slug — stable, ASCII-only,
            // suitable as a registry key.
            'id' => $id,
            // `name` is the human-readable display the editor shows
            // (mixed case, spaces). The TypingsBuilder slugifies it
            // for the LLM-facing TS identifier; the runtime keys by
            // this exact string.
            'name' => $name,
            'type' => $type,
            'default' => $default,
            'defaultValue' => $default,
            'label' => $name,
            'autoSeeded' => true,
        ];
        if ($type === 'enum') {
            $values = self::pinEnumValues($pin);
            if ($values !== []) {
                $entry['enumValues'] = $values;
            }
        }
        $this->variables[] = $entry;
        // Track name → id mapping so the get_variable extractor
        // emits a valid variableId alongside variableName (the
        // validator accepts either, but both being present keeps
        // legacy lookups happy).
        if (!isset($this->flowVarIds[$name])) {
            $this->flowVarIds[$name] = $id;
        }
    }

    private function emitGetVariableExtractor(string $name): string
    {
        $node_id = $this->makeId('getvar');
        // Emit BOTH variableName (runtime read key) AND variableId (studio
        // inspector + WorkflowValidator::validate_node_references). When
        // the variable was declared on the live workflow via the editor
        // the id is the canonical name itself; when it was declared in
        // source via `let X = ...` flowVarIds[X] === X.
        $variable_id = $this->flowVarIds[$name] ?? $name;
        $this->nodes[] = [
            'id' => $node_id,
            'key' => 'workflow.get_variable',
            'type' => 'transform',
            'label' => $name,
            'data' => [
                'variableName' => $name,
                'variableId' => $variable_id,
            ],
        ];
        return $node_id;
    }

    // emitLiteralValueExtractor / emitTextRenderExtractor / literalLabel
    // intentionally removed. Workflows accept no literals; the data.literal_value
    // node was deleted from the catalog and template literals are rejected
    // upstream in compileTemplate. The only legitimate paths into the graph
    // are token references (event.<pin>, variable.<name>, node.<id>.<out>)
    // and dataPin descriptors — every one of them is wired from a real upstream
    // output that the operator controls.

    private static function isNodeOutputToken(string $token): bool
    {
        return strncmp($token, 'node.', 5) === 0 && substr_count($token, '.') >= 2;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitNodeToken(string $token): array
    {
        // token = node.<id>.<out>(.<deeper>)?
        $rest = substr($token, 5);
        $dot = strpos($rest, '.');
        if ($dot === false) {
            return ['', ''];
        }
        return [substr($rest, 0, $dot), substr($rest, $dot + 1)];
    }

    // -----------------------------------------------------------------
    // Condition resolution.
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $cond
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function resolveCondition(array $cond, int $line, int $column): array
    {
        if (($cond['type'] ?? '') === 'BinaryExpr') {
            $op = (string) $cond['operator'];
            $map = [
                '===' => 'equals',
                '==' => 'equals',
                '!==' => 'not_equals',
                '!=' => 'not_equals',
                '>' => 'greater_than',
                '<' => 'less_than',
                '>=' => 'greater_or_equal',
                '<=' => 'less_or_equal',
            ];
            if (!isset($map[$op])) {
                throw CompileError::compile('condition_operator', $line, $column, sprintf('Operator "%s" is not supported in conditions.', $op));
            }
            return [$map[$op], $this->compileExpression($cond['left']), $this->compileExpression($cond['right'])];
        }
        if (($cond['type'] ?? '') === 'UnaryExpr' && (string) $cond['operator'] === '!') {
            return ['is_empty', $this->compileExpression($cond['argument']), ['kind' => 'literal', 'value' => null, 'type' => 'null']];
        }
        // Bare identifier → truthiness
        return ['is_not_empty', $this->compileExpression($cond), ['kind' => 'literal', 'value' => null, 'type' => 'null']];
    }

    /**
     * Map a resolveCondition operator name to the catalog comparison node
     * that implements it. Returns [verbKey, isUnary]. Unary nodes read
     * `value`; binary nodes read `left` + `right`.
     *
     * @return array{0: string, 1: bool}
     */
    private static function comparisonNodeForOperator(string $operator): array
    {
        // Unary tests — single `value` input.
        $unary = [
            'is_empty' => 'data.is_empty',
            'is_not_empty' => 'data.is_not_empty',
            'empty' => 'data.is_empty',
            'not_empty' => 'data.is_not_empty',
            'exists' => 'data.exists',
            'not_exists' => 'data.not_exists',
        ];
        if (isset($unary[$operator])) {
            return [$unary[$operator], true];
        }
        // Binary tests — `left` + `right` inputs.
        $binary = [
            'equals' => 'data.equals',
            'not_equals' => 'data.not_equals',
            'contains' => 'data.contains',
            'not_contains' => 'data.not_contains',
            'starts_with' => 'data.starts_with',
            'ends_with' => 'data.ends_with',
            'greater_than' => 'data.greater_than',
            'less_than' => 'data.less_than',
            'greater_or_equal' => 'data.greater_or_equal',
            'less_or_equal' => 'data.less_or_equal',
            'in' => 'data.is_in',
            'not_in' => 'data.is_not_in',
            'in_all' => 'data.contains_all',
            'length_equal' => 'data.length_equals',
            'between' => 'data.between',
            'regex' => 'data.matches_regex',
            'matches_regex' => 'data.matches_regex',
            'before' => 'data.is_before',
            'after' => 'data.is_after',
        ];
        if (isset($binary[$operator])) {
            return [$binary[$operator], false];
        }
        // Fallback: treat unknown operator as equals so old workflows keep
        // compiling. Honest path: extend the binary map above when a new
        // operator becomes a node.
        return ['data.equals', false];
    }

    // -----------------------------------------------------------------
    // Exec wiring + id helpers.
    // -----------------------------------------------------------------

    private function wireExec(string $targetNodeId, string $targetInput = 'in'): void
    {
        if ($this->execTail === null) {
            return;
        }
        // Pure-transform verbs (workflow.get_variable and any other
        // `kind: transform` verb) are evaluated on demand when
        // something pulls their data output — they MUST NOT appear
        // in the exec chain. Connecting them as exec targets put
        // phantom in→next pins on the rendered card that the palette
        // version doesn't have. Every other kind (action, condition,
        // trigger, loop, delay) has exec semantics and participates
        // in the chain normally.
        //
        // Earlier this check looked at $verb['exec']['inputs'] === []
        // — wrong, because VerbCatalog::find() doesn't expose an
        // `exec` sub-array at all, so the check returned true for
        // EVERY verb and no exec wires ever got emitted. The
        // workflow's data wires were fine but trigger.next never
        // reached the condition node, the if body was orphaned, and
        // the runtime couldn't fire anything past the trigger.
        $verb = $this->verbForNodeId($targetNodeId);
        if ($verb !== null && $this->isLazyVerb($verb)) {
            return;
        }
        $this->addExec($this->execTail, $this->execTailOutput, $targetNodeId, $targetInput);
        $this->execTail = $targetNodeId;
        $this->execTailOutput = 'next';
    }

    /** @return array<string, mixed>|null */
    private function verbForNodeId(string $nodeId): ?array
    {
        foreach ($this->nodes as $n) {
            if (($n['id'] ?? '') === $nodeId) {
                $key = (string) ($n['key'] ?? '');
                $found = VerbCatalog::find($key);
                return is_array($found) ? $found : null;
            }
        }
        return null;
    }

    /**
     * Lazy = pure data transform with no exec semantics. The
     * VerbCatalog exposes a `kind` discriminator on every verb;
     * `transform` is the lazy kind, every other kind (action,
     * condition, trigger, loop, delay) participates in the exec
     * chain. Keep this in sync with NodeManifest::manifest()['type']
     * values across wp-pfworkflow's Nodes/ tree.
     *
     * @param array<string, mixed> $verb
     */
    private function isLazyVerb(array $verb): bool
    {
        return (string) ($verb['kind'] ?? '') === 'transform';
    }

    private function addExec(string $src, string $srcOut, string $tgt, string $tgtIn): void
    {
        $this->connections[] = [
            'id' => $this->makeId('e'),
            'source' => $src,
            'sourceOutput' => $srcOut,
            'target' => $tgt,
            'targetInput' => $tgtIn,
            'kind' => 'exec',
        ];
    }

    private function addData(string $src, string $srcOut, string $tgt, string $tgtIn): void
    {
        $this->connections[] = [
            'id' => $this->makeId('d'),
            'source' => $src,
            'sourceOutput' => $srcOut,
            'target' => $tgt,
            'targetInput' => $tgtIn,
            'kind' => 'data',
        ];
    }

    private function makeId(string $prefix): string
    {
        $this->idCounter++;
        return $prefix . '_' . $this->idCounter;
    }

    private function shortFor(string $verbKey): string
    {
        $parts = explode('.', $verbKey);
        return end($parts) ?: 'node';
    }

}
