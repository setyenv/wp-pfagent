<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

use ProjectFlash\Agent\Framework\Llm\CompletionRequest;
use ProjectFlash\Agent\Framework\Llm\CompletionResponse;
use ProjectFlash\Agent\Framework\Llm\Gateway;
use ProjectFlash\Agent\Framework\Storage\Store;
use ProjectFlash\Agent\Framework\Tools\Registry;

/**
 * The agent loop. Stateful enough to run a turn end-to-end; stateless across
 * turns (state lives in the Store).
 *
 * Per-round responsibilities, in order:
 *
 *   1. Lazily discover model caps (context_length + max_output_tokens) on first
 *      use of a model in this conversation and persist them in metadata.
 *   2. Ask the LLM for the next step with the full message history + tool schemas.
 *      The CompletionRequest carries every behaviour-control lever the host
 *      configured on LoopOptions (penalties, top_p, seed, logprobs, logit_bias,
 *      stop, tool_choice, parallel_tool_calls, reasoning_effort).
 *   3. Auto-continuation of length-truncated responses is handled inside the
 *      gateway; we never have to know.
 *   4. If the LLM returned text-only (no tool calls) → run the OutputFilter:
 *        a) if filter rejects (jargon / permission-asking), append a
 *           system-reminder message and re-loop (capped by maxRewrites).
 *           If `useStructuredFinalReply` is on, the NEXT round is forced
 *           through response_format=json_schema (grammar-constrained) so
 *           the LLM physically cannot emit anything outside {"text": str}.
 *           If `forbiddenTokenStrings` + logprobs are set, harvest the
 *           offending token IDs from this round's logprobs and add them
 *           to the runtime logit_bias for the next round.
 *        b) if filter accepts, persist the final assistant message and return.
 *   5. If the LLM emitted tool calls → take up to maxToolCallsPerResponse:
 *        - Compute fingerprint(name, args).
 *        - If host approval is required (side-effect tool) → return needs_confirmation
 *          with a token the host hands back via Loop::resume().
 *        - Idempotency: if tool.idempotent=true AND identical fingerprint already
 *          ran ok in this conversation → reuse the cached result without
 *          re-running.
 *        - Oscillation: if same fingerprint ran ≥ maxFingerprintRepeats times
 *          within current turn → break with error_fingerprint_loop.
 *        - Run tool.execute(). Persist the call. Append tool result message
 *          (content = result + state_after JSON).
 *   6. Cap with maxTurns; on exhaustion, force a final-text call (no tools)
 *      with response_format on (when configured) and return what comes back.
 *
 * Termination is always through LoopResult with a recognisable subtype.
 */
final class Loop
{
    /**
     * Framework-level execution discipline prepended to the host's system
     * prompt on every LLM call. Tells the model how the loop is structured
     * so it stops trying to do everything in one shot and instead emits
     * one step per iteration. This is REQUIRED for PHP hosts because PHP
     * is single-request; the LLM can't parallelise across tool calls and
     * crammed multi-tool turns are what drain DeepSeek's 8K output budget.
     *
     * Hosts can override this via LoopOptions::frameworkDiscipline if
     * they need to phrase the discipline differently for their model.
     */
    public const DEFAULT_FRAMEWORK_DISCIPLINE = <<<'TXT'
[framework execution discipline — read first, applies to every turn]

The host that runs you is single-request synchronous PHP. Each LLM round
trip is one inference: you see the conversation so far, you decide ONE
next step, the host executes it and feeds the result back. You CANNOT
parallelise. You CANNOT batch.

How to plan without burning the output budget:

- Plan your whole strategy internally (in your reasoning) — what entities
  you need, what workflows, what order, what dependencies between steps.
  Do NOT write the plan to the user.
- Emit AT MOST THREE tool calls per response, and only batch when the
  calls are INDEPENDENT (different reads, no result feeds the next).
  If the next call depends on what the previous one returned, you MUST
  wait — emit only the first, see its result next round, then decide.
- The host honours up to THREE tool calls per response; any extras
  are silently dropped. Emitting more wastes your output budget and may
  truncate your reply.
- Side-effect tools (create / update / delete) follow the same cap, but
  a side-effect that requires user confirmation PAUSES the batch — any
  tool calls emitted after it in the same response are discarded and you
  will see only the confirmed call's result on the next round.
- After the side-effect runs, the next round's tool_result message
  carries `state_after` — read it. Do NOT infer "done" from the action
  you intended to take; verify against `state_after` before saying so.
- When you genuinely have nothing left to do, respond with a brief
  natural-language summary (no tool call). The host treats text-without-
  tool-call as the terminal signal and ends the turn.
- Idempotent tools dedupe automatically; re-calling with identical args
  returns the cached result tagged `idempotent_reuse: true`. Don't fight
  it — treat it as a successful no-op.
- If the loop blocks a call with `loop_detected`, you've called the same
  tool with the same arguments too many times. Change approach or stop.

HOW TO WRITE THE FINAL TEXT REPLY (a hard contract — not preference):

- Declarative voice ONLY. State what happened or what is. Never offer
  a follow-up conditioned on the user ("if you want…", "let me know if…",
  "notify me when…", "should I…", "would you like me to…").
- ZERO question marks. Not "?", not "¿". If you think you need to ask
  something, you don't — pick a reasonable default and act, or state
  the constraint and stop. The user can always send another message.
- No hedging openers ("I think…", "maybe…", "perhaps…", "it could…").
  No second-person prompts at the end ("tell me…", "ask me…",
  "I need you to confirm…").
- No internal jargon: never mention the plugin names, the tool names,
  filters, hooks, the framework, the schema, the discipline. Speak
  in the customer's domain words only.
- No provider control markers as text (`<｜｜DSML｜｜…>`, `<|tool_calls|>`,
  `<|fim_…|>`). If you intend to call a tool, emit a real tool_call;
  never type the markers inline.
- DO NOT CLAIM TO HAVE DONE SOMETHING YOU DID NOT ACTUALLY DO. If the
  available tools cannot perform the user's request (no matching tool,
  the call failed, the platform doesn't expose that capability), state
  the limitation declaratively — "There is no tool for X in this
  session." — and stop. Never narrate a fictional success ("I created
  the workflow…") when no tool ran that creation. The host audits
  tool-call telemetry against your claims; a fabricated success is a
  bigger failure than admitting the gap.
- DO NOT ASK THE USER FOR PERMISSION TO RUN A TOOL. The framework owns
  authorisation: it has an ApprovalGate that pauses on side-effect tools
  when the host requires confirmation, and it surfaces a separate UI
  prompt to the user. Your job is to TRY THE TOOL. If a tool error says
  "force flag required" / "confirm required" / "data loss requires
  acknowledgement", re-invoke the same tool with the requested flag set
  (e.g. `{"force": true}`) on the next round — do NOT write "confirm
  and I will apply it" to the user. If the framework ultimately denies the call,
  you will see the denial as a tool_result error, and THEN you state it
  declaratively ("The operation was rejected by the deletion policy.
  Do it from the panel."). The customer never sees an "are you sure?"
  from you.
- NO INTERNAL DATA-WIRING SYNTAX IN THE REPLY. Workflow source code,
  node identifiers, and template-style data refs are implementation
  details. Never paste fragments like `{{node.record_create_2.record.record.nombre}}`
  or `node.<id>.<pin>` or `${event.record.field}` into the customer
  reply. If you need to describe what a flow does with the customer's
  data, say it in plain words: "the email includes the customer name"
  — not the interpolation syntax that wires it.
- NAME PLACEHOLDERS WHEN YOU FILL THEM IN. If the customer asked you
  to wire something to a person, a list, a channel, a URL, etc. AND
  you did not have the real value (the user didn't give it, the system
  doesn't expose it, your search came back empty), you may insert a
  reasonable placeholder so the flow is structurally complete — but
  you MUST flag it explicitly in the reply. Correct: "I left it
  pointing to `juanjoworld@gmail.com` as a placeholder because
  there is no user with the 'head of operations' role. When you tell me
  the real recipient I will change it." Incorrect: silently leaving the
  placeholder and saying "Done, it is sending to the head of operations."
  The customer must know exactly what to verify.

Examples of CORRECT closings:
  "I created X." / "They are available for review." / "Done, finished."
  / "There is no way to do Y; drop that path or try Z."
Examples of INCORRECT closings:
  "Would you like me to continue?" / "Let me know if you need help." / "Tell me if X."
  / "Should I proceed?"

The host will REJECT and re-prompt any reply that breaks this contract.
Rewrites cost you a round; comply on the first attempt.
TXT;

    /**
     * Structured-final-reply schema. Used on rounds where the loop has
     * decided the LLM should produce ONLY a text reply (no tool calls).
     * The provider's constrained-decoding implementation makes it
     * physically impossible to emit anything outside this shape — the
     * primary lever against permission-asking text leaking back to the
     * customer.
     *
     * Three required fields:
     *
     *   - text: the actual customer-facing reply. Strict-mode JSON schemas
     *     don't support `pattern` consistently across providers, so we
     *     enforce content rules via (a) a hyper-specific description the
     *     constrained decoder honours and (b) the self_audit booleans
     *     below + the OutputFilter as backstops.
     *
     *   - self_audit.contains_question_mark / asks_permission: the LLM
     *     must classify its OWN reply. If it lies (sets false when the
     *     text actually contains `?`/`¿` or a "si quieres" pattern), the
     *     Loop logs the mismatch and feeds the contradiction back as the
     *     rewrite directive — the model is more reliable at admitting
     *     than at suppressing.
     *
     *   - self_audit.next_step: an explicit terminal signal. "done"
     *     means the conversation is closed from the model's POV; the
     *     two awaiting_* variants tell the host UI what the model
     *     considers itself blocked on.
     */
    /**
     * The tool result that stands in for a confirmation the user walked away
     * from. One constant so the settled row and its replayed twin are the
     * same bytes — a difference between them would move the prefix and cost
     * a cache hit on every later turn.
     */
    private const ABANDONED_TOOL_RESULT = '{"error":{"code":"user_abandoned","message":"The user left this action unconfirmed and moved on. It never ran.","retriable":false}}';

    private const STRUCTURED_FINAL_REPLY_FORMAT = [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'pf_agent_final_reply',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'text' => [
                        'type' => 'string',
                        // F15 Part 2: grammar-constrain `?` and `¿` out at
                        // the provider boundary. Strict-mode OpenAI honours
                        // this and refuses to emit those chars; other
                        // providers may degrade — Part 1 (analyse + scrub
                        // in driveLoop) is the safety net.
                        'pattern' => '^[^?¿]+$',
                        'description' => 'Final reply to the customer, declarative voice. '
                            . 'NEVER use question marks anywhere (? ¿). '
                            . 'NEVER offer a follow-up action ("if you want I can", "let me know if", "tell me when"). '
                            . 'NEVER hedge ("it could", "I think", "maybe", "perhaps"). '
                            . 'Just state what happened or what is.',
                    ],
                    'self_audit' => [
                        'type' => 'object',
                        'properties' => [
                            'contains_question_mark' => [
                                'type' => 'boolean',
                                'description' => 'TRUE if the text field contains ? or ¿ ANYWHERE. The framework checks this against the actual text and rejects mismatches, so be honest.',
                            ],
                            'asks_permission' => [
                                'type' => 'boolean',
                                'description' => 'TRUE if the text offers an action conditional on the user ("if you want", "let me know if", "should I", "would you like me to"), FALSE if you only stated facts about what is or what just happened.',
                            ],
                            'next_step' => [
                                'type' => 'string',
                                'enum' => ['done', 'awaiting_user_data', 'awaiting_user_decision'],
                                'description' => '"done" = conversation closed from your POV. "awaiting_user_data" = you need a specific value (a number, an email). "awaiting_user_decision" = you need the user to pick between options you already laid out.',
                            ],
                        ],
                        'required' => ['contains_question_mark', 'asks_permission', 'next_step'],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['text', 'self_audit'],
                'additionalProperties' => false,
            ],
        ],
    ];

    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Gateway $gateway,
        private readonly string $systemPrompt,
        private readonly OutputFilter $outputFilter,
        private readonly LoopOptions $options = new LoopOptions(),
        private readonly ?ApprovalGate $approval = null,
        /**
         * Optional LLM-driven compactor. When set AND the running prompt
         * estimate exceeds `compactionThresholdRatio * caps.contextLength`,
         * older history is folded into a summary message before the next
         * gateway call (Kilo Tier 2.7). The persisted conversation in the
         * Store is left untouched — only the wire shape sees the fold.
         */
        private readonly ?LlmCompactor $compactor = null,
        /**
         * Provider + model authoritative for THIS run. The conversation
         * itself no longer pins a model: the operator's wizard selection
         * is the source of truth, sent on every turn from the frontend and
         * passed through FrameworkRuntime → Loop. Used for capability
         * discovery, request `model` field, and any per-turn cache keys.
         * Empty strings fall back to LoopOptions::providerId/model for
         * backwards compatibility with stand-alone framework callers.
         */
        private readonly string $activeProviderId = '',
        private readonly string $activeModel = '',
    ) {
    }

    /** The model authoritative for this run (frontend choice). Required:
     *  the caller MUST construct the Loop with a non-empty activeModel
     *  because the conversation no longer carries any model state. */
    private function modelInUse(): string
    {
        if ($this->activeModel === '') {
            throw new \RuntimeException('Loop requires a non-empty activeModel — the conversation no longer pins a model and the frontend must send one per turn.');
        }
        return $this->activeModel;
    }

    /** The provider authoritative for this run (frontend choice). */
    private function providerInUse(): string
    {
        return $this->activeProviderId;
    }

    /** Trigger compaction when the wire-shape token estimate exceeds this
     *  fraction of the model's context window. 0.75 leaves 25 % headroom
     *  for the assistant's reply + any tool-result expansion mid-round. */
    private const COMPACTION_THRESHOLD_RATIO = 0.75;

    /** Bytes-per-token convention used to estimate prompt size cheaply.
     *  Matches Kilo Code's heuristic (provider tokenizers vary, but the
     *  trigger is conservative enough that the estimate is good enough). */
    private const APPROX_CHARS_PER_TOKEN = 4;

    /** Tail messages kept verbatim outside the summary. Older messages
     *  get folded; these stay so the model has fresh-and-true context to
     *  decide the next step. */
    private const COMPACTION_KEEP_TAIL = 6;

    /** Read-only reference files the host VFS exposes that the authoring
     *  model must always see verbatim (node/trigger catalog, management API,
     *  variables). When compaction would fold one of these away, it is
     *  instead PINNED in a stable position (right after the system message)
     *  so the vocabulary never silently disappears from context — the exact
     *  failure mode the reverted tool-result clamp caused. The generic Loop
     *  treats them as opaque path markers; the host owns the real files. */
    private const PINNED_REFERENCE_PATHS = ['/lib/nodes.d.ts', '/lib/manage.d.ts', '/lib/variables.d.ts'];

    /** Schema marker that identifies a tool result as a VFS file read. */
    private const VFS_READ_SCHEMA = 'projectflash.agent.vfs.read';

    /** Appended to the folded-summary message so the model re-reads a
     *  reference file before emitting identifiers from it when the catalog
     *  had to be stubbed (too large to keep inline within the budget). */
    private const COMPACTION_REREAD_NOTE = 'Note: reference catalogs (/lib/*.d.ts) above may be summarised or stubbed. Before emitting any node, trigger or management identifier, confirm it appears verbatim in a <reference-file> block; if that file is stubbed or absent, call read_file on it first.';

    /**
     * Compose the system message the gateway sees. Framework discipline
     * goes FIRST so the host's product-specific rules immediately follow it
     * and inherit its mental model. Both are stable strings → both cached
     * by the provider's prompt cache without churn between rounds.
     */
    /**
     * Compact the wire-shape messages when the running prompt estimate
     * approaches the model's context window. No-op when:
     *   - no compactor was injected (host opted out),
     *   - the conversation is short enough that the estimate is well under
     *     the trigger threshold,
     *   - there are not enough middle messages to be worth summarising.
     *
     * Compaction keeps:
     *   - the fresh system message at index 0,
     *   - the last COMPACTION_KEEP_TAIL messages.
     * Everything in between gets passed to the compactor, which returns a
     * single anchored-summary text. That text replaces the middle slice
     * as one user message marked with a `<previous-summary>` wrapper so
     * the model can keep updating it on subsequent compactions.
     *
     * @param list<Message> $messages
     * @param array{contextLength: int, maxOutputTokens: int} $caps
     * @return list<Message>
     */
    private function maybeCompactWireMessages(array $messages, array $caps, string $conversationId, int $turn, int $round, ?array $priorState = null): array
    {
        if ($this->compactor === null) {
            return $messages;
        }
        if (count($messages) <= 1 + self::COMPACTION_KEEP_TAIL + 2) {
            return $messages;
        }
        $contextLength = (int) ($caps['contextLength'] ?? 0);
        if ($contextLength <= 0) {
            return $messages;
        }
        $threshold = (int) ($contextLength * self::COMPACTION_THRESHOLD_RATIO);

        $prevSummary = is_string($priorState['summary'] ?? null) ? (string) $priorState['summary'] : '';
        $prevFolded = (int) ($priorState['foldedThrough'] ?? 0);
        $prevFolded = max(0, min($prevFolded, count($messages) - 1));

        // Reuse path (the cache fix). If we already have an anchored summary
        // and re-applying it WITHOUT a fresh LLM fold keeps the prompt under
        // the threshold, emit the SAME folded prefix again. The prefix
        // (system + pinned catalogs + persisted summary) does not churn every
        // round, so the provider prompt cache stays warm — cache reads resume
        // after the first fold instead of paying a full-price miss each turn.
        // We only re-summarise when the verbatim tail has grown enough to
        // re-cross the threshold (hysteresis), not every round.
        if ($prevFolded > 0 && $prevSummary !== '') {
            $reused = $this->buildCompactedWire($messages, $prevSummary, $prevFolded, $caps);
            if ($this->estimatePromptTokens($reused) < $threshold) {
                return $reused;
            }
        }

        $estimate = $this->estimatePromptTokens($messages);
        if ($prevFolded === 0 && $estimate < $threshold) {
            // Early conversation, nothing folded yet, comfortably under budget.
            return $messages;
        }

        // Fold boundary: keep at least the last COMPACTION_KEEP_TAIL messages
        // verbatim, never splitting an assistant(tool_calls) → tool(result)
        // pair across it. Providers reject a `tool` message that doesn't
        // immediately follow an assistant with tool_calls (DeepSeek returns
        // HTTP 400 and bricks the conversation), so advance the boundary past
        // any leading tool messages; the skipped tool results stay folded.
        // Because the wire is rebuilt from the store every round, this also
        // self-heals conversations that were already bricked.
        $tailStart = count($messages) - self::COMPACTION_KEEP_TAIL;
        while ($tailStart < count($messages) - 1 && $messages[$tailStart]->role === Message::ROLE_TOOL) {
            $tailStart++;
        }
        $foldTo = $tailStart - 1;            // last wire index folded into the summary
        $from = max(1, $prevFolded + 1);     // first NEW message to fold (extends the prior fold)

        if ($foldTo < $from) {
            // Nothing new to fold beyond the prior boundary.
            if ($prevFolded > 0 && $prevSummary !== '') {
                return $this->buildCompactedWire($messages, $prevSummary, $prevFolded, $caps);
            }
            return $messages;
        }

        // Fold the new middle slice, EXCLUDING reference-catalog reads — those
        // are pinned verbatim by buildCompactedWire(), so summarising them
        // would both waste compactor tokens and risk paraphrasing the very
        // vocabulary we need kept exact.
        $middle = array_slice($messages, $from, $foldTo - $from + 1);
        $middleForSummary = array_values(array_filter(
            $middle,
            fn(Message $m): bool => $this->referencePathOf($m) === null,
        ));

        if ($middleForSummary === []) {
            // The only thing in the new slice was a pinned catalog. No textual
            // delta to summarise: advance the boundary (so the catalog leaves
            // the tail and becomes a stable pin) reusing the existing summary.
            if ($prevSummary === '') {
                return $messages;
            }
            $this->persistCompactionState($conversationId, $prevSummary, $foldTo);
            return $this->buildCompactedWire($messages, $prevSummary, $foldTo, $caps);
        }

        try {
            $summary = $this->compactor->compact($middleForSummary, $prevSummary !== '' ? $prevSummary : null);
        } catch (\Throwable $e) {
            $this->store->logTrace($conversationId, $turn, $round, 'compaction_failed', [
                'error' => $e->getMessage(),
                'middleCount' => count($middleForSummary),
            ]);
            if ($prevFolded > 0 && $prevSummary !== '') {
                return $this->buildCompactedWire($messages, $prevSummary, $prevFolded, $caps);
            }
            return $messages;
        }

        $summary = trim($summary);
        if ($summary === '') {
            if ($prevFolded > 0 && $prevSummary !== '') {
                return $this->buildCompactedWire($messages, $prevSummary, $prevFolded, $caps);
            }
            return $messages;
        }

        $this->persistCompactionState($conversationId, $summary, $foldTo);
        $this->store->logTrace($conversationId, $turn, $round, 'compaction_applied', [
            'middleCount' => count($middleForSummary),
            'foldedFrom' => $from,
            'foldedThrough' => $foldTo,
            'extendedPriorFold' => $prevFolded > 0,
            'originalEstimate' => $estimate,
            'thresholdAt' => $threshold,
            'contextLength' => $contextLength,
            'summaryBytes' => strlen($summary),
        ]);

        return $this->buildCompactedWire($messages, $summary, $foldTo, $caps);
    }

    /** Persist the anchored summary + fold boundary in conversation metadata
     *  (NOT in the message rows — the audit trail / replay history stays
     *  byte-for-byte intact). Read back at the top of the next round so the
     *  fold is reused instead of recomputed. */
    private function persistCompactionState(string $conversationId, string $summary, int $foldedThrough): void
    {
        $this->store->updateConversationMetadata($conversationId, [
            'compaction' => [
                'summary' => $summary,
                'foldedThrough' => $foldedThrough,
            ],
        ]);
    }

    /**
     * Assemble the compacted wire: system, then any reference catalogs that
     * would otherwise have been folded away (pinned verbatim in a STABLE
     * position so they stay in context AND cache), then the anchored summary,
     * then the verbatim recent tail (everything after $foldedThrough).
     *
     * @param list<Message> $messages
     * @param array{contextLength:int, maxOutputTokens:int} $caps
     * @return list<Message>
     */
    private function buildCompactedWire(array $messages, string $summary, int $foldedThrough, array $caps): array
    {
        $head = $messages[0];
        $tail = array_slice($messages, $foldedThrough + 1);

        $summaryMsg = Message::user(
            '<previous-summary>' . "\n" . $summary . "\n" . '</previous-summary>'
            . "\n\n" . self::COMPACTION_REREAD_NOTE,
        );

        // Pin budget = context window − (system + summary + tail) − reply
        // headroom. Catalogs are pinned newest-first while they fit; any that
        // don't are stubbed with a re-read instruction, never silently dropped.
        $base = $this->estimatePromptTokens(array_merge([$head, $summaryMsg], $tail));
        $reply = (int) ($caps['maxOutputTokens'] ?? 0);
        $budget = max(0, (int) ($caps['contextLength'] ?? 0) - $base - $reply);

        $pins = $this->buildReferencePins($messages, $foldedThrough, $budget);

        return array_merge([$head], $pins, [$summaryMsg], $tail);
    }

    /**
     * Build the pinned reference-catalog messages for catalogs whose latest
     * read was folded away (i.e. is NOT already verbatim in the tail). Newest
     * read first; each is kept verbatim while it fits the token budget,
     * otherwise stubbed with an explicit re-read instruction — never silently
     * dropped (that was the reverted tool-result clamp's failure).
     *
     * @param list<Message> $messages
     * @return list<Message>
     */
    private function buildReferencePins(array $messages, int $foldedThrough, int $budget): array
    {
        $count = count($messages);

        // Latest read index per reference path WITHIN the folded region.
        $foldedLatest = [];
        for ($i = 1; $i <= $foldedThrough && $i < $count; $i++) {
            $path = $this->referencePathOf($messages[$i]);
            if ($path !== null) {
                $foldedLatest[$path] = $i;   // ascending loop → latest wins
            }
        }
        if ($foldedLatest === []) {
            return [];
        }
        // Skip any catalog that is ALSO present (more recently) in the verbatim
        // tail — it is already in context there, no need to pin a second copy.
        for ($i = $foldedThrough + 1; $i < $count; $i++) {
            $path = $this->referencePathOf($messages[$i]);
            if ($path !== null) {
                unset($foldedLatest[$path]);
            }
        }
        if ($foldedLatest === []) {
            return [];
        }
        // Newest-read catalog first so the most relevant one wins the budget.
        arsort($foldedLatest);

        $pins = [];
        foreach ($foldedLatest as $path => $idx) {
            $source = $this->extractReferenceSource($messages[$idx]);
            $tokens = (int) ceil(strlen($source) / self::APPROX_CHARS_PER_TOKEN);
            if ($tokens <= $budget) {
                $pins[] = Message::user(
                    '<reference-file path="' . $path . '" note="authoritative catalog, kept verbatim across compaction">'
                    . "\n" . $source . "\n" . '</reference-file>',
                );
                $budget -= $tokens;
            } else {
                $pins[] = Message::user(
                    '<reference-file path="' . $path . '" status="stubbed" '
                    . 'note="too large to keep inline after compaction; call read_file(\'' . $path . '\') before emitting any identifier from it" />',
                );
            }
        }
        return $pins;
    }

    /**
     * If the message is a VFS read result for one of the pinned reference
     * files, return that path; else null. Cheap substring probe so we don't
     * JSON-decode every tool result every round.
     */
    private function referencePathOf(Message $message): ?string
    {
        if ($message->role !== Message::ROLE_TOOL) {
            return null;
        }
        $content = (string) ($message->content ?? '');
        if ($content === '' || !str_contains($content, self::VFS_READ_SCHEMA)) {
            return null;
        }
        foreach (self::PINNED_REFERENCE_PATHS as $path) {
            if (str_contains($content, '"path":"' . $path . '"')) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Pull the verbatim file source out of a VFS read result. Falls back to
     * the whole tool-result content (still verbatim) if the expected shape
     * is absent.
     */
    private function extractReferenceSource(Message $message): string
    {
        $content = (string) ($message->content ?? '');
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $source = $decoded['result']['content']['source'] ?? null;
            if (is_string($source) && $source !== '') {
                return $source;
            }
        }
        return $content;
    }

    /**
     * Cheap byte-based prompt-size estimate. Same convention as
     * PromptCacheInjector — ~4 chars/token. Good enough for the
     * "is it worth compacting" gate; we don't need the real tokenizer.
     *
     * @param list<Message> $messages
     */
    private function estimatePromptTokens(array $messages): int
    {
        $bytes = 0;
        foreach ($messages as $m) {
            $bytes += strlen((string) ($m->content ?? ''));
            $bytes += strlen((string) $m->reasoning);
            foreach ($m->toolCalls as $call) {
                $args = $call['arguments'] ?? [];
                $bytes += strlen((string) ($call['name'] ?? ''));
                $bytes += strlen(is_string($args) ? $args : (string) json_encode($args));
            }
        }
        return (int) ceil($bytes / self::APPROX_CHARS_PER_TOKEN);
    }

    private function composedSystemPrompt(): string
    {
        $discipline = $this->options->frameworkDiscipline ?? self::DEFAULT_FRAMEWORK_DISCIPLINE;
        if ($discipline === '') {
            return $this->systemPrompt;
        }
        return $discipline . "\n\n" . $this->systemPrompt;
    }

    /**
     * Start or continue a conversation by adding a user message and running
     * the loop. Pass conversationId=null to create a new conversation.
     */
    public function run(?string $conversationId, string $userMessage, ?string $label = null): LoopResult
    {
        if ($conversationId === null) {
            $conversationId = $this->store->createConversation(
                label: $label ?? '',
            );
            // Seed the conversation with the system prompt as ordinal 1 so the
            // wire-shape build can always include it without special-casing.
            $this->store->appendMessage($conversationId, Message::system($this->systemPrompt));
        }

        $this->settleAbandonedToolCalls($conversationId);
        $userOrdinal = $this->store->appendMessage($conversationId, Message::user($userMessage));
        $turn = $this->store->loadConversation($conversationId)?->turnCount ?? 1;

        return $this->driveLoop($conversationId, $userOrdinal, $turn);
    }

    /**
     * Close any tool call the user walked away from.
     *
     * A confirmation dialog the user never answers — they reload the page, or
     * just type their next message instead of clicking — leaves an assistant
     * message whose tool_calls have no matching tool results. Appending the
     * next user message on top of that yields a transcript every
     * OpenAI-compatible provider rejects ("an assistant message with
     * 'tool_calls' must be followed by tool messages responding to each
     * tool_call_id"), and because a transcript only ever grows, the
     * conversation then fails on EVERY later turn: the chat is dead for good
     * and nothing in the UI explains why.
     *
     * Runs BEFORE the new user message is appended, so from here on the
     * settlement lands where the wire protocol wants it and the stored history
     * needs no fixing up later. A call that is already buried under later
     * messages (a conversation recorded by an older build) cannot be closed by
     * appending — its id is recorded in conversation metadata instead, ONCE,
     * and repairOrphanToolCalls() replays that record identically on every
     * request so the provider's prefix cache keeps hitting.
     */
    private function settleAbandonedToolCalls(string $conversationId): void
    {
        $conv = $this->store->loadConversation($conversationId);
        if ($conv === null) {
            return;
        }

        // A call counts as answered only when its result sits in the block
        // right after the assistant message — that is the only place the wire
        // protocol accepts one. A result parked anywhere else answers nothing.
        /** @var array<string, string> $awaiting call id => tool name */
        $awaiting = [];
        /** @var array<string, string> $buried call id => tool name */
        $buried = [];
        foreach ($conv->messages as $message) {
            if ($message->role === Message::ROLE_TOOL) {
                unset($awaiting[$message->toolCallId]);
                continue;
            }
            foreach ($awaiting as $callId => $toolName) {
                $buried[$callId] = $toolName;
            }
            $awaiting = [];
            if ($message->role !== Message::ROLE_ASSISTANT) {
                continue;
            }
            foreach ($message->toolCalls as $call) {
                $callId = (string) ($call['id'] ?? '');
                if ($callId !== '') {
                    $awaiting[$callId] = (string) ($call['name'] ?? '');
                }
            }
        }

        // Still awaiting at the end of the history: the assistant's call is the
        // last thing recorded, so its result can simply be appended and the
        // stored history comes out well-formed.
        foreach ($awaiting as $callId => $toolName) {
            $this->store->appendMessage($conversationId, Message::tool(
                toolCallId: $callId,
                content: self::ABANDONED_TOOL_RESULT,
                toolName: $toolName,
            ));
        }

        if ($buried === []) {
            return;
        }
        $alreadyRecorded = is_array($conv->metadata['settledToolCalls'] ?? null)
            ? array_map('strval', $conv->metadata['settledToolCalls'])
            : [];
        $recorded = array_values(array_unique(array_merge($alreadyRecorded, array_keys($buried))));
        if ($recorded !== $alreadyRecorded) {
            $this->store->updateConversationMetadata($conversationId, ['settledToolCalls' => $recorded]);
        }
    }

    /**
     * H5: continue a turn that paused on its wall-clock budget
     * (SUBTYPE_PAUSED_TIME_BUDGET). Unlike resume(), there is no held tool call
     * and no user verdict — the conversation state is already fully persisted;
     * we just resume driving more rounds in a fresh request. The host keeps
     * calling this while the result is SUBTYPE_PAUSED_TIME_BUDGET, so the work
     * finishes across several short requests instead of one that fatals.
     */
    public function continueAfterBudget(string $conversationId): LoopResult
    {
        $turn = $this->store->loadConversation($conversationId)?->turnCount ?? 0;
        return $this->driveLoop($conversationId, 0, $turn);
    }

    /**
     * Continue after a needs_confirmation. The host calls this with the token
     * from the LoopResult and the user's approval verdict.
     */
    public function resume(string $conversationId, string $confirmationToken, bool $approved): LoopResult
    {
        $pending = $this->options->approvalStore->loadPending($confirmationToken);
        if ($pending === null || (string) ($pending['conversation_id'] ?? '') !== $conversationId) {
            return new LoopResult(
                subtype: LoopResult::SUBTYPE_ERROR_LLM,
                conversationId: $conversationId,
                finalText: '',
                rounds: 0,
                usage: [],
                errorMessage: 'Unknown confirmation token.',
            );
        }

        // The same call may already have been settled — the user typed instead
        // of answering and settleAbandonedToolCalls() closed it. Executing now
        // would append a second tool result for one tool_call_id, which is the
        // malformed transcript we just stopped producing.
        $heldCallId = (string) ($pending['tool_call_id'] ?? '');
        $conversation = $this->store->loadConversation($conversationId);
        if ($heldCallId !== '' && $conversation !== null) {
            foreach ($conversation->messages as $message) {
                if ($message->role === Message::ROLE_TOOL && $message->toolCallId === $heldCallId) {
                    $this->options->approvalStore->resolve($confirmationToken);

                    return new LoopResult(
                        subtype: LoopResult::SUBTYPE_ERROR_LLM,
                        conversationId: $conversationId,
                        finalText: '',
                        rounds: 0,
                        usage: [],
                        errorMessage: 'This confirmation is no longer pending: the conversation moved on without it.',
                    );
                }
            }
        }

        if (!$approved) {
            // Surface to the model as a tool result so it can pick a different path.
            $this->store->appendMessage($conversationId, Message::tool(
                toolCallId: (string) $pending['tool_call_id'],
                content: (string) json_encode(['error' => [
                    'code' => 'user_denied',
                    'message' => 'The user denied this side-effect action.',
                    'retriable' => false,
                ]], JSON_UNESCAPED_UNICODE),
            ));
            $this->options->approvalStore->resolve($confirmationToken);
            return $this->driveLoop($conversationId, 0, 0);
        }

        // Execute the held-back tool call.
        $tool = $this->registry->get((string) $pending['tool_name']);
        if ($tool === null) {
            return new LoopResult(
                subtype: LoopResult::SUBTYPE_ERROR_LLM,
                conversationId: $conversationId,
                finalText: '',
                rounds: 0,
                usage: [],
                errorMessage: sprintf('Tool "%s" no longer registered.', $pending['tool_name']),
            );
        }
        $def = $tool->definition();
        $arguments = (array) ($pending['arguments'] ?? []);
        $startedAt = microtime(true);
        try {
            $result = $tool->execute($arguments);
        } catch (\Throwable $e) {
            $result = ToolResult::failure('tool_threw', $e->getMessage(), false);
        }
        $endedAt = microtime(true);
        // Mirror the execute-case logging in driveLoop: every executed
        // tool MUST land in pfaf_tool_calls so downstream consumers
        // (countSuccessfulSideEffects, /agent-runtime/progress, the
        // executions list shipped to the chat) see the same surface
        // regardless of whether the tool went through auto-execute or
        // through pause → confirm → resume. Skipping this write was
        // the root cause of the honesty-check false-positive: a
        // side-effect tool that paused for confirmation never got
        // counted, so the "you claim to have created X but no side
        // effect ran" rewrite fired even though the LLM had built
        // exactly what it claimed.
        $this->store->logToolCall(
            $conversationId,
            (int) ($pending['message_ordinal'] ?? 0),
            (string) $pending['tool_call_id'],
            (string) $pending['tool_name'],
            $arguments,
            $def->sideEffect,
            $result->error === null ? 'ok' : 'error',
            $result->result,
            $result->stateAfter,
            $result->error['code'] ?? '',
            $result->error['message'] ?? '',
            (string) ($pending['fingerprint'] ?? ''),
            (int) round(($endedAt - $startedAt) * 1000),
            gmdate('c', (int) $startedAt),
            gmdate('c', (int) $endedAt),
        );
        $this->store->appendMessage($conversationId, Message::tool(
            toolCallId: (string) $pending['tool_call_id'],
            content: $result->toContent(),
        ));
        $this->options->approvalStore->resolve($confirmationToken);

        return $this->driveLoop($conversationId, 0, 0);
    }

    /**
     * Resolve what to do with one tool call from the LLM's response, WITHOUT
     * actually executing it yet. Possible outcomes (the `kind` field):
     *   - unknown_tool       — no Tool registered under this name
     *   - bad_args           — arguments fail the tool's JSON Schema
     *   - fingerprint_loop   — same call ran >= maxFingerprintRepeats already
     *   - idempotent_reuse   — idempotent tool + cached identical call
     *   - user_denied        — sideEffect tool, ApprovalGate said DENY
     *   - pause              — sideEffect tool, ApprovalGate said PENDING
     *   - execute            — go ahead and run the tool
     *
     * @param array{id?: string, name?: string, arguments?: array<string, mixed>} $call
     * @return array{kind: string, tool?: \ProjectFlash\Agent\Framework\Tools\Tool, fingerprint?: string, hits?: int, cached?: array{result: mixed, stateAfter: mixed}, errors?: list<string>}
     */
    private function planToolCall(Conversation $conv, array $call, int $userOrdinal): array
    {
        $toolName = (string) ($call['name'] ?? '');
        $arguments = (array) ($call['arguments'] ?? []);
        $tool = $this->registry->get($toolName);
        if ($tool === null) {
            return ['kind' => 'unknown_tool'];
        }
        $def = $tool->definition();

        // Arguments the provider sent but nobody could read.
        $argumentsError = (string) ($call['argumentsError'] ?? '');
        if ($argumentsError !== '') {
            return ['kind' => 'bad_args', 'errors' => [$argumentsError . ' — send the call again with the complete arguments object.']];
        }

        // A call that changes data has to say WHAT it changes. An empty
        // argument set on a side-effecting tool can only produce a
        // confirmation dialog with nothing in it: the customer is asked to
        // approve a blank, and approving it runs the write with no payload.
        // Some of these tools accept several shapes and so declare nothing
        // strictly required, which is exactly why the schema does not catch
        // this one.
        if ($def->sideEffect && $arguments === []) {
            return ['kind' => 'bad_args', 'errors' => ['This tool changes data and you sent no arguments. Send the call again with the full payload.']];
        }

        // First fill missing optional fields with their schema `default`
        // — relieves bridges from having to coerce nulls on middle-
        // positional args every time.
        $arguments = (array) JsonSchemaValidator::normalize($def->parameters, $arguments);

        // Validate arguments against the tool's JSON Schema BEFORE doing
        // anything else. Catches type mistakes / missing required fields /
        // unknown keys without ever invoking the bridge — the LLM sees an
        // actionable list of errors and fixes forward in the next round.
        $validation = JsonSchemaValidator::validate($def->parameters, $arguments);
        if (!$validation['ok']) {
            return ['kind' => 'bad_args', 'errors' => $validation['errors']];
        }

        $fingerprint = Fingerprint::of($toolName, $arguments);

        $hits = $this->store->countFingerprint($conv->id, $fingerprint, max(0, $userOrdinal));
        if ($hits >= $this->options->maxFingerprintRepeats) {
            return ['kind' => 'fingerprint_loop', 'fingerprint' => $fingerprint, 'hits' => $hits];
        }

        if ($def->idempotent && ($cached = $this->store->findIdempotentResult($conv->id, $fingerprint)) !== null) {
            return ['kind' => 'idempotent_reuse', 'fingerprint' => $fingerprint, 'cached' => $cached];
        }

        if ($def->sideEffect && $this->approval !== null) {
            $decision = $this->approval->request($toolName, $arguments, $conv);
            if ($decision === ApprovalGate::DECISION_PENDING) {
                return ['kind' => 'pause', 'tool' => $tool, 'fingerprint' => $fingerprint, 'normalizedArgs' => $arguments];
            }
            if ($decision === ApprovalGate::DECISION_DENY) {
                return ['kind' => 'user_denied'];
            }
        }

        return ['kind' => 'execute', 'tool' => $tool, 'fingerprint' => $fingerprint, 'normalizedArgs' => $arguments];
    }

    /**
     * Put back the tool results a legacy transcript is missing — from the
     * decision already taken and stored, never from a fresh scan.
     *
     * Conversations recorded before settleAbandonedToolCalls() existed can
     * carry an assistant tool_call with the user's next message on top of it
     * and no tool result in between: a shape providers reject outright, which
     * killed every later turn of that conversation. settleAbandonedToolCalls()
     * decides ONCE which calls were abandoned and writes the ids into
     * conversation metadata; this only replays that record, so what goes on
     * the wire is the same bytes turn after turn and the provider's prefix
     * cache still hits. Anything not in the record is left exactly as stored.
     *
     * @param list<Message> $messages
     * @param mixed $settledCallIds ids recorded by settleAbandonedToolCalls()
     * @return list<Message>
     */
    private static function repairOrphanToolCalls(array $messages, mixed $settledCallIds): array
    {
        if (!is_array($settledCallIds) || $settledCallIds === []) {
            return $messages;
        }
        $settled = array_fill_keys(array_map('strval', $settledCallIds), true);

        $repaired = [];
        foreach ($messages as $message) {
            // A settlement row an older build appended at the END of the
            // history sits after the user message, where it is as malformed
            // as the gap it was meant to close. Its replacement goes in at
            // the right place below.
            if ($message->role === Message::ROLE_TOOL && isset($settled[$message->toolCallId])) {
                continue;
            }

            $repaired[] = $message;

            if ($message->role !== Message::ROLE_ASSISTANT) {
                continue;
            }
            foreach ($message->toolCalls as $call) {
                $callId = (string) ($call['id'] ?? '');
                if ($callId === '' || !isset($settled[$callId])) {
                    continue;
                }
                $repaired[] = Message::tool(
                    toolCallId: $callId,
                    content: self::ABANDONED_TOOL_RESULT,
                    toolName: (string) ($call['name'] ?? ''),
                );
            }
        }

        return $repaired;
    }

    /**
     * Build the CompletionRequest from LoopOptions for one round.
     *
     * `$mode`:
     *   - 'tools'  — tools are wired, no response_format, no stop sequences
     *                (truncating mid-tool-call corrupts the wire shape)
     *   - 'final'  — no tools, response_format wraps the reply with json_schema
     *                strict (when useStructuredFinalReply is on), stop
     *                sequences propagate.
     *
     * @param list<Message> $messages
     * @param array<int, int> $extraLogitBias merged on top of LoopOptions::logitBias
     */
    private function buildRequest(Conversation $conv, array $messages, string $mode, array $extraLogitBias = []): CompletionRequest
    {
        $model = $this->modelInUse();
        $caps = $conv->modelCaps($model) ?? $this->gateway->discoverCaps($model);
        $isFinal = $mode === 'final';

        $logitBias = $this->options->logitBias;
        foreach ($extraLogitBias as $id => $bias) {
            $logitBias[(int) $id] = (int) $bias;
        }

        return new CompletionRequest(
            model: $model,
            messages: $messages,
            tools: $isFinal ? [] : $this->registry->llmDefinitions(),
            maxOutputTokens: $caps['maxOutputTokens'],
            temperature: $this->options->temperature,
            topP: $this->options->topP,
            topK: $this->options->topK,
            presencePenalty: $this->options->presencePenalty,
            frequencyPenalty: $this->options->frequencyPenalty,
            toolChoice: $isFinal ? 'none' : $this->options->toolChoice,
            parallelToolCalls: $isFinal ? null : $this->options->parallelToolCalls,
            seed: $this->options->seed,
            logprobs: $this->options->logprobs,
            topLogprobs: $this->options->topLogprobs,
            logitBias: $logitBias,
            stop: $isFinal ? $this->options->stopSequences : [],
            responseFormat: ($isFinal && $this->options->useStructuredFinalReply)
                ? self::STRUCTURED_FINAL_REPLY_FORMAT
                : null,
            reasoningEffort: $this->options->reasoningEffort,
            extraBody: $this->options->extraBody,
        );
    }

    /**
     * Walk the response's logprobs payload looking for any of the
     * `forbiddenTokenStrings`. When a match is found, harvest the integer
     * token ID (when the provider exposes one) so we can add it to the
     * next round's logit_bias.
     *
     * The provider may expose the token ID under different keys; OpenAI's
     * /v1/chat/completions does NOT echo it (only the string + bytes),
     * but several OpenAI-compatible backends (vLLM, sglang, DeepSeek
     * `top_logprobs` extensions) add a numeric id. We probe both common
     * fields and silently ignore providers that don't surface IDs — in
     * those cases the framework still emits the OutputFilter rewrite,
     * just without a token-level guard.
     *
     * @param array<string, mixed>|null $logprobs
     * @param list<string> $needles
     * @return array<int, int> map of token-id → -100 (ban)
     */
    private static function harvestForbiddenTokenIds(?array $logprobs, array $needles): array
    {
        if ($logprobs === null || $needles === []) {
            return [];
        }
        $needlesLc = array_map('mb_strtolower', $needles);
        $found = [];
        $content = is_array($logprobs['content'] ?? null) ? $logprobs['content'] : [];
        foreach ($content as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $candidates = [$entry];
            if (isset($entry['top_logprobs']) && is_array($entry['top_logprobs'])) {
                foreach ($entry['top_logprobs'] as $alt) {
                    if (is_array($alt)) {
                        $candidates[] = $alt;
                    }
                }
            }
            foreach ($candidates as $cand) {
                $token = is_string($cand['token'] ?? null) ? $cand['token'] : '';
                if ($token === '') {
                    continue;
                }
                $tokenLc = mb_strtolower($token);
                foreach ($needlesLc as $needle) {
                    if ($needle !== '' && str_contains($tokenLc, $needle)) {
                        $id = $cand['token_id'] ?? ($cand['id'] ?? null);
                        if (is_numeric($id)) {
                            $found[(int) $id] = -100;
                        }
                    }
                }
            }
        }
        return $found;
    }

    private function driveLoop(string $conversationId, int $userOrdinal, int $turn): LoopResult
    {
        // H5: a multi-round turn can outlive PHP's own max_execution_time (30s on
        // the web SAPI) and die as an opaque fatal mid-round. Lift PHP's timer so
        // that is never the failure mode; the wall-clock budget below (a clean,
        // resumable pause between rounds) is what keeps each request short enough
        // for the WEB SERVER's own timeout. ignore_user_abort so a client that
        // gives up doesn't kill an in-flight tool mid-write. Both best-effort:
        // some hosts disable set_time_limit — the budget pause covers that too.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        // Wall-clock budget for THIS request. <= 0 disables the pause entirely
        // (single long request, legacy behaviour). Default 20s: under a typical
        // 30s server timeout, filterable up for dev / hosts with a longer cap.
        $turnStartedAt = microtime(true);
        $timeBudgetSeconds = (float) apply_filters('pfa_turn_time_budget_seconds', 20);

        $rewriteCount = 0;
        $totalRounds = 0;
        $totalCostMicros = 0;
        $totalUsage = [
            'promptTokens' => 0,
            'completionTokens' => 0,
            'totalTokens' => 0,
            'cacheHitTokens' => 0,
            'cacheMissTokens' => 0,
        ];

        // Ensure we have caps cached for the model the operator selected
        // for THIS turn (may differ from the previous turn — model is a
        // global wizard selection, not a property of the conversation).
        $conv = $this->store->loadConversation($conversationId);
        if ($conv === null) {
            return new LoopResult(LoopResult::SUBTYPE_ERROR_LLM, $conversationId, '', 0, [], errorMessage: 'Conversation not found.');
        }
        $activeModel = $this->modelInUse();
        $caps = $conv->modelCaps($activeModel);
        if ($caps === null) {
            $caps = $this->gateway->discoverCaps($activeModel);
            // Cache per-model so caps for previously-used models survive
            // the operator switching back; the new per-model shape is
            // keyed on the model id, the legacy flat shape is read-only
            // fallback in modelCaps().
            $perModel = is_array($conv->metadata['modelCaps'] ?? null) && !isset($conv->metadata['modelCaps']['contextLength'])
                ? $conv->metadata['modelCaps']
                : [];
            $perModel[$activeModel] = $caps;
            $this->store->updateConversationMetadata($conversationId, ['modelCaps' => $perModel]);
            $this->store->logTrace($conversationId, $turn, 0, 'model_caps_discovered', ['model' => $activeModel] + $caps);
        }

        // Discovered token-level bias accumulates across rounds inside the
        // same drive call, on top of the per-conversation map persisted in
        // metadata. Initialise from conversation metadata so a previously
        // discovered ban survives across turns.
        $discoveredBias = is_array($conv->metadata['discoveredBias'] ?? null)
            ? array_map('intval', $conv->metadata['discoveredBias'])
            : [];

        for ($round = 1; $round <= $this->options->maxTurns; $round++) {
            // H5: wall-clock budget check, BETWEEN rounds only — never mid-round,
            // so a tool already executing (e.g. a .pfflow compile) always finishes
            // and its result is persisted before we consider pausing. We only get
            // here on round >1 with tool work still pending (a final answer would
            // have returned SUBTYPE_SUCCESS already), so there is genuinely more to
            // do. Return a clean, resumable pause; the host continues the same
            // conversation via continueAfterBudget(). maxTurns below stays the
            // ultimate cap so the continuation chain can never loop forever.
            if ($round > 1 && $timeBudgetSeconds > 0 && (microtime(true) - $turnStartedAt) >= $timeBudgetSeconds) {
                $this->store->logTrace($conversationId, $turn, $totalRounds, 'turn_time_budget_paused', [
                    'budgetSeconds' => $timeBudgetSeconds,
                    'elapsedSeconds' => round(microtime(true) - $turnStartedAt, 3),
                    'roundsSoFar' => $totalRounds,
                ]);
                return new LoopResult(LoopResult::SUBTYPE_PAUSED_TIME_BUDGET, $conversationId, '', $totalRounds, $totalUsage, costMicros: $totalCostMicros);
            }

            $totalRounds++;
            $conv = $this->store->loadConversation($conversationId);
            if ($conv === null) {
                return new LoopResult(LoopResult::SUBTYPE_ERROR_LLM, $conversationId, '', $totalRounds, $totalUsage);
            }

            // Strip the system message from the persisted history because we
            // send a fresh systemPrompt up front and the persisted system row
            // is purely for replay. The gateway expects only the chat tail.
            $wireMessages = array_values(array_filter($conv->messages, static fn(Message $m) => $m->role !== Message::ROLE_SYSTEM));
            $wireMessages = array_merge([Message::system($this->composedSystemPrompt())], $wireMessages);
            // Conversations recorded before settleAbandonedToolCalls() existed
            // can still carry an unanswered tool_call with a user message on
            // top of it — a shape providers reject outright, which made every
            // later turn in that conversation fail. Repair the wire shape here
            // so those histories keep working; the stored rows stay untouched.
            $wireMessages = self::repairOrphanToolCalls($wireMessages, $conv->metadata['settledToolCalls'] ?? null);

            // Kilo Tier 2.7: auto-compaction. When the running prompt
            // estimate gets close to the model's context window, fold the
            // older middle of the conversation into a single anchored-summary
            // message produced by an LLM call (LlmCompactor + Prompts::COMPACTION).
            // The anchored summary + fold boundary are persisted in conversation
            // metadata (NOT the message rows) so the next round REUSES the fold
            // instead of re-summarising every round — keeping the prompt-cache
            // prefix stable. The Store message rows are untouched, so the audit
            // trail and replayability stay byte-for-byte intact.
            $priorCompaction = is_array($conv->metadata['compaction'] ?? null) ? $conv->metadata['compaction'] : null;
            $wireMessages = $this->maybeCompactWireMessages($wireMessages, $caps, $conversationId, $turn, $round, $priorCompaction);

            // Mid-loop rounds always carry tools; structured-final-reply is
            // reserved for the maxTurns exhaustion branch below (forcing
            // JSON on rewrite rounds terminated tool work prematurely in
            // live testing).
            $request = $this->buildRequest($conv, $wireMessages, 'tools', $discoveredBias);

            try {
                $resp = $this->gateway->complete($request);
            } catch (\Throwable $e) {
                $this->store->logTrace($conversationId, $turn, $round, 'llm_error', ['message' => $e->getMessage()]);
                return new LoopResult(LoopResult::SUBTYPE_ERROR_LLM, $conversationId, '', $totalRounds, $totalUsage, errorMessage: $e->getMessage());
            }

            $totalUsage['promptTokens'] += (int) ($resp->usage['promptTokens'] ?? 0);
            $totalUsage['completionTokens'] += (int) ($resp->usage['completionTokens'] ?? 0);
            $totalUsage['totalTokens'] += (int) ($resp->usage['totalTokens'] ?? 0);
            $totalUsage['cacheHitTokens'] += (int) ($resp->usage['cacheHitTokens'] ?? 0);
            $totalUsage['cacheMissTokens'] += (int) ($resp->usage['cacheMissTokens'] ?? 0);
            $totalCostMicros += $resp->costMicros;

            // F14: hard budget cap. When the operator sets maxBudgetMicros>0,
            // surface a deterministic stop once the cumulative cost crosses
            // the limit, instead of continuing to spend silently.
            if ($this->options->maxBudgetMicros > 0
                && $totalCostMicros >= $this->options->maxBudgetMicros
            ) {
                $this->store->logTrace($conversationId, $turn, $round, 'budget_exceeded', [
                    'cumulativeCostMicros' => $totalCostMicros,
                    'limitMicros' => $this->options->maxBudgetMicros,
                ]);
                return new LoopResult(
                    LoopResult::SUBTYPE_ERROR_MAX_BUDGET,
                    $conversationId,
                    '',
                    $totalRounds,
                    $totalUsage,
                    costMicros: $totalCostMicros,
                    errorMessage: sprintf(
                        'Budget cap exceeded: %d micros >= %d micros',
                        $totalCostMicros,
                        $this->options->maxBudgetMicros
                    ),
                );
            }

            $tracePayload = [
                'finishReason' => $resp->finishReason,
                'toolCallCount' => count($resp->toolCalls),
                'continued' => $resp->continued,
                'usage' => $resp->usage,
                'rawModel' => $resp->rawModel,
            ];
            if ($resp->logprobs !== null) {
                $tracePayload['logprobs'] = $resp->logprobs;
            }
            $this->store->logTrace($conversationId, $turn, $round, 'llm_round', $tracePayload, $resp->systemFingerprint);

            // Signal-only: the catalog couldn't price this round's model, so
            // costMicros is a silent 0. Surface a cost_unknown trace so the
            // dashboards read it as "uncounted" rather than "free". Charging
            // is unchanged (cost stays 0).
            if ($resp->costUnknown) {
                $this->store->logTrace($conversationId, $turn, $round, 'cost_unknown', [
                    'model' => $resp->rawModel !== '' ? $resp->rawModel : $activeModel,
                ], $resp->systemFingerprint);
            }

            // ── Branch A: text-only reply, no tool calls. ───────────────────
            if ($resp->toolCalls === []) {
                // Mid-loop rounds don't ride the structured-final-reply
                // envelope, but the DeepSeek-class providers occasionally
                // exude their internal control markers (`<｜｜DSML｜｜...`)
                // when they tried to emit a tool call as text. Sanitise
                // before the OutputFilter sees the string so the marker
                // gets either retried-against or stripped.
                $effectiveText = self::stripProviderControlMarkers($resp->text);

                // Honesty cross-check: if the LLM is claiming to have
                // created/configured/activated/deleted something but no
                // side-effect tool ran successfully in this conversation,
                // it's hallucinating a success. Treat it as a rejected
                // output and re-prompt with a targeted directive — much
                // more reliable than hoping the model self-polices via
                // the discipline preamble (see live scenario S3 / S3b
                // where it kept inventing workflow creations).
                if (self::claimsSideEffect($effectiveText) && $this->store->countSuccessfulSideEffects($conversationId) === 0) {
                    $analysis = [
                        'ok' => false,
                        'reason' => 'lying_about_side_effect',
                        'found' => [],
                        'directive' => 'Your last reply claims you created / configured / activated something, but no side-effect tool (create / update / delete) has run successfully in this conversation. Do NOT invent successes. Rewrite the reply honestly stating that you could not do it and why — for example: "There is no tool for X in this session; drop it or request it from the relevant panel."',
                    ];
                } else {
                    $analysis = $this->outputFilter->analyse($effectiveText);
                }
                if (!$analysis['ok'] && $rewriteCount < $this->options->maxRewrites) {
                    $rewriteCount++;
                    $this->store->logTrace($conversationId, $turn, $round, 'output_rewrite', [
                        'reason' => $analysis['reason'],
                        'found' => $analysis['found'],
                        'attempt' => $rewriteCount,
                    ]);
                    // Persist the rejected assistant text so the trail is auditable.
                    $this->store->appendMessage($conversationId, Message::assistant(
                        content: $effectiveText,
                        toolCalls: [],
                        reasoning: $resp->reasoning,
                        finishReason: $resp->finishReason,
                    ));
                    // Harvest token IDs of the offending characters from the
                    // logprobs payload (if any) so the next round bans them
                    // outright. Cheap; quietly skipped on providers that
                    // don't expose token IDs.
                    if ($this->options->forbiddenTokenStrings !== []) {
                        $harvested = self::harvestForbiddenTokenIds($resp->logprobs, $this->options->forbiddenTokenStrings);
                        if ($harvested !== []) {
                            $discoveredBias = $harvested + $discoveredBias;
                            $this->store->updateConversationMetadata($conversationId, ['discoveredBias' => $discoveredBias]);
                            $this->store->logTrace($conversationId, $turn, $round, 'discovered_bias', ['ids' => array_keys($harvested)]);
                        }
                    }
                    // Append a system-reminder telling the model to rewrite.
                    $this->store->appendMessage($conversationId, Message::user(
                        sprintf("[system-reminder] %s", $analysis['directive']),
                    ));
                    // Deliberately DO NOT force structured-final-reply on
                    // rewrite rounds. A rewrite is often an intermediate
                    // explanation the model wanted to give before doing more
                    // tool work; forcing the JSON envelope here terminates
                    // the conversation prematurely (the loop took
                    // multi_step_count_then_create from 10 tool calls + 1
                    // side-effect down to 2 tool calls + 0 side-effects).
                    // The penalties + the system-reminder + a fresh round
                    // are enough; structured-final-reply stays reserved for
                    // the maxTurns exhaustion branch.
                    continue;
                }
                // Accept the text reply.
                $this->store->appendMessage($conversationId, Message::assistant(
                    content: $effectiveText,
                    toolCalls: [],
                    reasoning: $resp->reasoning,
                    finishReason: $resp->finishReason,
                ));
                return new LoopResult(
                    subtype: $resp->finishReason === 'refusal' ? LoopResult::SUBTYPE_REFUSAL : LoopResult::SUBTYPE_SUCCESS,
                    conversationId: $conversationId,
                    finalText: $effectiveText,
                    rounds: $totalRounds,
                    usage: $totalUsage,
                    costMicros: $totalCostMicros,
                );
            }

            // ── Branch B: tool calls. Honour up to N (LoopOptions::maxToolCallsPerResponse).
            // Two phases:
            //   1) Plan each call (resolve tool, fingerprint check, idempotency,
            //      side-effect approval). The plan tells us whether to execute,
            //      surface an error, reuse a cached result, or pause for human
            //      confirmation. If we hit a PAUSE, we stop planning further
            //      calls in this batch — they would have to be decided after
            //      the user's verdict.
            //   2) Log the assistant message with EXACTLY the honoured calls
            //      (truncated to the plan length so the next request's wire
            //      stays consistent: one assistant tool_call ↔ one tool_result).
            //   3) Execute plans in order, appending tool_result messages. If
            //      a plan is PAUSE, save state, return needs_confirmation.
            $selected = array_slice($resp->toolCalls, 0, max(1, $this->options->maxToolCallsPerResponse));
            $plans = [];
            foreach ($selected as $call) {
                $plan = $this->planToolCall($conv, $call, $userOrdinal);
                $plans[] = $plan;
                if ($plan['kind'] === 'pause') {
                    break;
                }
            }
            $honoredCalls = array_slice($selected, 0, count($plans));

            $assistantMessage = Message::assistant(
                content: $resp->text,
                toolCalls: $honoredCalls,
                reasoning: $resp->reasoning,
                finishReason: $resp->finishReason,
            );
            $assistantOrdinal = $this->store->appendMessage($conversationId, $assistantMessage);

            $pausePayload = null;
            foreach ($plans as $i => $plan) {
                $call = $honoredCalls[$i];
                $toolCallId = (string) ($call['id'] ?? 'call_' . $round . '_' . $i);
                $toolName = (string) $call['name'];
                $arguments = (array) ($call['arguments'] ?? []);

                switch ($plan['kind']) {
                    case 'unknown_tool':
                        $this->store->appendMessage($conversationId, Message::tool(
                            toolCallId: $toolCallId,
                            content: (string) json_encode(['error' => [
                                'code' => 'tool_unknown',
                                'message' => sprintf('No tool "%s" exists. Available: %s', $toolName, implode(', ', $this->registry->names())),
                                'retriable' => false,
                            ]], JSON_UNESCAPED_UNICODE),
                        ));
                        break;
                    case 'bad_args':
                        $this->store->logTrace($conversationId, $turn, $round, 'bad_args', [
                            'tool' => $toolName,
                            'errors' => $plan['errors'],
                        ]);
                        $this->store->appendMessage($conversationId, Message::tool(
                            toolCallId: $toolCallId,
                            content: (string) json_encode(['error' => [
                                'code' => 'bad_args',
                                'message' => 'Your tool arguments did not match the schema. Fix and try again.',
                                'errors' => $plan['errors'],
                                'retriable' => true,
                            ]], JSON_UNESCAPED_UNICODE),
                        ));
                        break;
                    case 'fingerprint_loop':
                        $this->store->logTrace($conversationId, $turn, $round, 'fingerprint_loop', [
                            'tool' => $toolName,
                            'fingerprint' => $plan['fingerprint'],
                            'hits' => $plan['hits'],
                        ]);
                        $this->store->appendMessage($conversationId, Message::tool(
                            toolCallId: $toolCallId,
                            content: (string) json_encode(['error' => [
                                'code' => 'loop_detected',
                                'message' => sprintf('You have called "%s" with the same arguments %d times in this turn. Try a different approach or stop.', $toolName, $plan['hits']),
                                'retriable' => false,
                            ]], JSON_UNESCAPED_UNICODE),
                        ));
                        break;
                    case 'idempotent_reuse':
                        $this->store->logTrace($conversationId, $turn, $round, 'idempotent_hit', ['tool' => $toolName]);
                        $this->store->appendMessage($conversationId, Message::tool(
                            toolCallId: $toolCallId,
                            content: (string) json_encode([
                                'result' => $plan['cached']['result'],
                                'state_after' => $plan['cached']['stateAfter'],
                                'idempotent_reuse' => true,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ));
                        break;
                    case 'user_denied':
                        $this->store->appendMessage($conversationId, Message::tool(
                            toolCallId: $toolCallId,
                            content: (string) json_encode(['error' => [
                                'code' => 'user_denied',
                                'message' => 'The user denied this action.',
                                'retriable' => false,
                            ]], JSON_UNESCAPED_UNICODE),
                        ));
                        break;
                    case 'pause':
                        // message_ordinal + fingerprint travel with
                        // the pending payload so resume() can write
                        // a complete pfaf_tool_calls row that mirrors
                        // what the execute case would have written —
                        // same ordinal anchoring the call to its
                        // originating assistant turn, same
                        // fingerprint so countFingerprint() and the
                        // idempotency cache stay aligned.
                        $token = $this->options->approvalStore->savePending([
                            'conversation_id' => $conversationId,
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                            'arguments' => $arguments,
                            'message_ordinal' => $assistantOrdinal,
                            'fingerprint' => (string) ($plan['fingerprint'] ?? ''),
                        ]);
                        $pausePayload = [
                            'toolCallId' => $toolCallId,
                            'name' => $toolName,
                            'arguments' => $arguments,
                            'token' => $token,
                        ];
                        break 2;
                    case 'execute':
                        $tool = $plan['tool'];
                        $def = $tool->definition();
                        // Use the normalized args (schema defaults filled in)
                        // instead of the raw LLM call. Keeps the bridge from
                        // having to write defensive null-coercions for every
                        // optional middle-positional parameter.
                        $argsToRun = $plan['normalizedArgs'] ?? $arguments;
                        $started = microtime(true);
                        try {
                            $result = $tool->execute($argsToRun);
                        } catch (\Throwable $e) {
                            $result = ToolResult::failure('tool_threw', $e->getMessage(), false);
                        }
                        $endedAt = microtime(true);
                        $duration = (int) round(($endedAt - $started) * 1000);
                        $status = $result->error === null ? 'ok' : 'error';

                        $this->store->logToolCall(
                            $conversationId,
                            $assistantOrdinal,
                            $toolCallId,
                            $toolName,
                            $argsToRun,
                            $def->sideEffect,
                            $status,
                            $result->result,
                            $result->stateAfter,
                            $result->error['code'] ?? '',
                            $result->error['message'] ?? '',
                            $plan['fingerprint'],
                            $duration,
                            gmdate('c', (int) $started),
                            gmdate('c', (int) $endedAt),
                        );
                        $this->store->appendMessage($conversationId, Message::tool(
                            toolCallId: $toolCallId,
                            content: $result->toContent(),
                        ));
                        break;
                }
            }

            if ($pausePayload !== null) {
                // finalText stays empty on a pause: the surface message is
                // the confirmation modal itself (built from pendingToolCall
                // + confirmationToken), and a hardcoded "needs confirmation"
                // text printed as an assistant bubble used to leak runtime
                // wording into the chat — one bubble per side-effect call.
                // The frontend attaches the pending payload to the previous
                // assistant bubble instead, so the operator sees the modal
                // without a duplicate "this action requires…" line.
                return new LoopResult(
                    subtype: LoopResult::SUBTYPE_NEEDS_CONFIRMATION,
                    conversationId: $conversationId,
                    finalText: '',
                    rounds: $totalRounds,
                    usage: $totalUsage,
                    costMicros: $totalCostMicros,
                    pendingToolCall: [
                        'toolCallId' => $pausePayload['toolCallId'],
                        'name' => $pausePayload['name'],
                        'arguments' => $pausePayload['arguments'],
                    ],
                    confirmationToken: $pausePayload['token'],
                );
            }
        }

        // Rounds budget exhausted. Force a no-tools final answer with the
        // structured-final-reply schema on (when configured).
        $conv = $this->store->loadConversation($conversationId);
        if ($conv === null) {
            return new LoopResult(LoopResult::SUBTYPE_ERROR_MAX_TURNS, $conversationId, '', $totalRounds, $totalUsage);
        }
        $wireMessages = array_values(array_filter($conv->messages, static fn(Message $m) => $m->role !== Message::ROLE_SYSTEM));
        $wireMessages = array_merge([Message::system($this->composedSystemPrompt() . "\n\n[system-reminder] You have hit the per-turn round budget. Stop calling tools and reply with a final natural-language summary now.")], $wireMessages);

        try {
            $finalResp = $this->gateway->complete($this->buildRequest($conv, $wireMessages, 'final', $discoveredBias));
            // Same cost_unknown signal as the in-loop rounds: this forced
            // final call also bills a silent 0 when the model is unpriced.
            if ($finalResp->costUnknown) {
                $this->store->logTrace($conversationId, $turn, $totalRounds + 1, 'cost_unknown', [
                    'model' => $finalResp->rawModel !== '' ? $finalResp->rawModel : $this->modelInUse(),
                ], $finalResp->systemFingerprint);
            }
            if ($this->options->useStructuredFinalReply) {
                $envelope = $this->extractStructuredReplyEnvelope($finalResp->text);
                $finalText = $envelope['text'];
                if ($envelope['audit'] !== null) {
                    // Log mismatches between the model's self-claim and the
                    // observable truth — pure diagnostic; surfaces whether
                    // the structured-output approach is actually buying us
                    // honesty or the model is just bluffing.
                    $claimedQ = (bool) ($envelope['audit']['contains_question_mark'] ?? false);
                    $actualQ = str_contains($finalText, '?') || str_contains($finalText, '¿');
                    $claimedPerm = (bool) ($envelope['audit']['asks_permission'] ?? false);
                    $this->store->logTrace($conversationId, $turn, $totalRounds + 1, 'structured_audit', [
                        'audit' => $envelope['audit'],
                        'actual_contains_question_mark' => $actualQ,
                        'lied_about_question_mark' => ($claimedQ === false && $actualQ === true),
                        'admitted_permission_ask' => $claimedPerm,
                    ]);
                }
                // F15 Part 1: apply OutputFilter to the structured inner
                // text. The mid-loop branch (line ~816) does this; the
                // maxTurns exhaustion branch did not — final reply slipped
                // unchecked. Re-prompting here is not viable (we're past
                // the round budget); the next best thing is to scrub the
                // forbidden punctuation + permission-asking suffix in
                // place and log the slip for telemetry.
                $analysis = $this->outputFilter->analyse($finalText);
                if (!$analysis['ok']) {
                    $scrubbed = self::scrubInlineSlip($finalText);
                    $this->store->logTrace($conversationId, $turn, $totalRounds + 1, 'final_text_scrubbed', [
                        'reason' => $analysis['reason'],
                        'found' => $analysis['found'],
                        'original_length' => strlen($finalText),
                        'scrubbed_length' => strlen($scrubbed),
                    ]);
                    $finalText = $scrubbed;
                }
            } else {
                $finalText = self::stripProviderControlMarkers($finalResp->text);
            }
            $this->store->appendMessage($conversationId, Message::assistant(
                content: $finalText,
                toolCalls: [],
                reasoning: $finalResp->reasoning,
                finishReason: $finalResp->finishReason,
            ));
            return new LoopResult(
                subtype: LoopResult::SUBTYPE_ERROR_MAX_TURNS,
                conversationId: $conversationId,
                finalText: $finalText,
                rounds: $totalRounds,
                usage: $totalUsage,
                costMicros: $totalCostMicros,
                errorMessage: sprintf('Hit maxTurns=%d', $this->options->maxTurns),
            );
        } catch (\Throwable $e) {
            return new LoopResult(LoopResult::SUBTYPE_ERROR_MAX_TURNS, $conversationId, '', $totalRounds, $totalUsage, errorMessage: $e->getMessage());
        }
    }

    /**
     * Parse the JSON envelope produced by structured-final-reply mode and
     * pull out the inner `text` field. Falls back to the raw payload when
     * the response isn't JSON — happens when the gateway degraded the
     * request because the provider rejected `response_format`.
     *
     * Always strips provider control markers from the result so a leaking
     * `<｜｜DSML｜｜tool_calls>` (DeepSeek) never lands in the customer reply.
     *
     * Returns a tuple { text, audit } where audit is the self_audit object
     * the model attached (or null if it didn't), so the caller can log
     * mismatches between what the model claimed about its own reply and
     * what the text actually contains.
     *
     * @return array{text: string, audit: array<string, mixed>|null}
     */
    private function extractStructuredReplyEnvelope(string $raw): array
    {
        $raw = self::stripProviderControlMarkers(trim($raw));
        if ($raw === '' || ($raw[0] !== '{' && $raw[0] !== '[')) {
            return ['text' => $raw, 'audit' => null];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['text' => $raw, 'audit' => null];
        }
        $text = is_string($decoded['text'] ?? null) ? self::stripProviderControlMarkers($decoded['text']) : $raw;
        $audit = is_array($decoded['self_audit'] ?? null) ? $decoded['self_audit'] : null;
        return ['text' => $text, 'audit' => $audit];
    }

    /**
     * Backwards-compat thin wrapper around extractStructuredReplyEnvelope
     * for callers that only need the user-facing text.
     */
    private function extractStructuredReplyText(string $raw): string
    {
        return $this->extractStructuredReplyEnvelope($raw)['text'];
    }

    /**
     * F15 Part 1 fallback. The maxTurns final-reply path can't re-prompt
     * (we're past the round budget), so when the OutputFilter rejects the
     * scaffolded text we apply a minimal in-place scrub:
     *
     *   1. Strip standalone `?` and `¿` characters (replace with `.` if
     *      they were sentence-final, drop otherwise). The structured
     *      schema's `pattern` keyword (Part 2) prevents this on
     *      strict-mode providers; this is the belt for providers that
     *      degrade strict mode.
     *   2. Drop the last sentence if it matches a permission-asking
     *      pattern. We approximate "last sentence" as the substring
     *      after the rightmost `.` `!` `…` that is followed only by
     *      whitespace + the permission tail. Conservative — leaves the
     *      rest of the reply intact.
     *
     * The scrub loses some LLM intent (the permission tail typically
     * carries useful context). Acceptable trade-off: the customer
     * never sees a question mark or a "si quieres puedo…" suffix.
     * Loud telemetry on every scrub (final_text_scrubbed trace event)
     * makes the operator's investigation easy.
     */
    private static function scrubInlineSlip(string $text): string
    {
        $text = (string) $text;
        // Strip the question-mark family. Sentence-final `?` becomes `.`;
        // mid-sentence ones get dropped to keep punctuation noise minimal.
        $text = preg_replace('/\s*\?\s*$/u', '.', $text) ?? $text;
        $text = preg_replace('/\?+/u', '', $text) ?? $text;
        $text = preg_replace('/\s*¿\s*/u', ' ', $text) ?? $text;

        // Drop trailing permission-asking sentence. Match a final sentence
        // (after the last `.` or `!`) that opens with the canonical
        // permission tells in Spanish / English. The OutputFilter has the
        // authoritative pattern list; we apply a narrower regex here to
        // avoid over-stripping.
        $tail_pattern = '/[.!…]\s*(?:Si\s+(?:quieres|necesitas|prefieres)\b|Dime\s+si\b|Avísame\b|Indícamelo\b|Should\s+I\b|Let\s+me\s+know\b|Would\s+you\s+like\b).*$/iu';
        $text = preg_replace($tail_pattern, '.', $text) ?? $text;

        // Tidy double whitespace + double full stops the scrub introduces.
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\.{2,}/u', '.', $text) ?? $text;

        return trim($text);
    }

    /**
     * Heuristic: does the assistant text claim to have completed a
     * side-effect action? Used by the Loop's honesty cross-check to
     * detect first-person past-tense claims that have no matching
     * successful side-effect tool call in the conversation.
     *
     * Spanish + English. First-person past ("creé", "I created"),
     * impersonal-past ("se ha creado", "está listo"), and "ya tienes X
     * con tal cosa" follow-ups. Conservative — we accept false negatives
     * (the model gets away with a fake claim that doesn't match the
     * regex) over false positives (rejecting a legitimate descriptive
     * reply that happens to use a verb from this list).
     */
    private static function claimsSideEffect(string $text): bool
    {
        // IMPORTANT — only ambiguity-free verb forms. Earlier versions
        // included "cree", "configure", "active", "borre" etc.; those
        // are present-subjunctive 3rd-person ("cuando se cree la
        // incidencia") and false-positive against legitimate
        // descriptive sentences. We keep ONLY the accented preterite
        // forms ("creé", "configuré", ...) which can ONLY be
        // first-person past-tense Spanish.
        $patterns = [
            // Spanish first-person past (preterite) — accented forms only.
            '/\b(?:creé|configuré|activé|añadí|edité|modifiqué|eliminé|borré|monté|preparé)\b/iu',
            // Spanish present-perfect first-person ("he creado").
            '/\bhe\s+(?:creado|configurado|activado|añadido|anadido|editado|modificado|eliminado|borrado|preparado|montado)\b/iu',
            // Impersonal past on platform objects ("se ha creado el flujo",
            // "está listo en el panel"). Constrain the noun side to avoid
            // matching "se ha creado un buen ambiente"-style noise.
            '/\bse\s+ha\s+(?:creado|configurado|activado|añadido|anadido|modificado|eliminado|borrado)\s+(?:el|la|los|las|un|una)\b/iu',
            '/\bestá\s+(?:listo|creado|configurado|activado|montado|preparado)\s+(?:en|y|para|con|desde)\b/iu',
            '/\bquedó\s+(?:creado|configurado|activado|listo|montado)\b/iu',
            // English equivalents (for hosts using English prompts).
            '/\bI\s+(?:created|configured|activated|added|edited|modified|deleted|removed|set\s+up)\b/i',
            '/\bhas\s+been\s+(?:created|configured|activated|added|edited|modified|deleted|removed|set\s+up)\b/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove provider-internal control markers that occasionally leak into
     * the assistant text — most commonly DeepSeek's `<｜...｜>` /
     * `<｜｜DSML｜｜tool_calls>` framing tokens, and the OpenAI-compat
     * `<|...|>` family. Leaving these in would surface raw provider
     * jargon to the customer.
     *
     * Probe round 6 caught one shape we missed: `<｜｜DSML｜｜tool_calls>`
     * ends with a literal `>` with NO closing `｜｜` before it (it's a
     * tag-open style, not a balanced wrapper). Catch any `<...>` block
     * that contains the U+FF5C / `|` markers — won't match plain HTML
     * because plain HTML rarely contains those chars.
     */
    private static function stripProviderControlMarkers(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        // Regex note: with the /u flag, `\xEF` means U+00EF (ï), NOT the
        // raw byte 0xEF. We want the U+FF5C codepoint (｜), so use the
        // explicit `\x{ff5c}` form. Earlier versions of this method used
        // `\xEF\xBD\x9C` and silently never matched.
        //
        // Order matters: paired DSML tags must be eaten FIRST (open + body
        // + close in one bite), otherwise the lone-tag patterns below
        // remove just the opens and leave the body as orphaned text.
        $patterns = [
            // Paired DSML tag: `<｜｜...｜｜tag ...>BODY</｜｜...｜｜tag>`
            // — strip open + body + close as one block (cross-line).
            '/<\x{ff5c}{1,2}[^<>]*\x{ff5c}{1,2}([A-Za-z_][\w-]*)[^<>]*>.*?<\/\x{ff5c}{1,2}[^<>]*\x{ff5c}{1,2}\1[^<>]*>/us',
            // Any `<...>` block whose content contains at least one U+FF5C
            // (｜) anywhere — catches `<｜...｜>`, `<｜｜DSML｜｜...>`,
            // `</｜｜DSML｜｜...>`, etc.
            '/<[^<>]*\x{ff5c}[^<>]*>/u',
            // Any `<...>` block whose content contains an ASCII `|` and at
            // least one of the known control-token keywords.
            '/<[^<>]*\|[^<>]*(?:DSML|tool_calls|fim_|im_start|im_end|endoftext)[^<>]*>/u',
            // Bare `<|tool_calls|>` style (no FF5C, no extra keyword inside).
            '/<\|[^<>]{0,200}\|>/u',
        ];
        $out = preg_replace($patterns, '', $text) ?? $text;
        return trim($out);
    }
}
