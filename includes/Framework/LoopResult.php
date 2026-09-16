<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * The terminal value of one Loop::run() call.
 *
 *   subtype = success | needs_confirmation | error_max_turns | error_max_budget |
 *             error_fingerprint_loop | error_llm | error_tool | refusal
 *
 * On `needs_confirmation`, `pendingToolCall` is set and the caller is expected
 * to either confirm (call Loop::resume) or deny (call Loop::abort).
 */
final class LoopResult
{
    public const SUBTYPE_SUCCESS = 'success';
    public const SUBTYPE_NEEDS_CONFIRMATION = 'needs_confirmation';
    // H5: the turn hit its wall-clock budget with tool work still pending. This
    // is a CLEAN, resumable pause (state fully persisted) — NOT an error like
    // max_turns. The host continues the same conversation via Loop::continueAfterBudget
    // so multi-round work spans several short requests, each safely under the
    // web-server timeout, instead of one long request that fatals.
    public const SUBTYPE_PAUSED_TIME_BUDGET = 'paused_time_budget';
    public const SUBTYPE_ERROR_MAX_TURNS = 'error_max_turns';
    public const SUBTYPE_ERROR_MAX_BUDGET = 'error_max_budget';
    public const SUBTYPE_ERROR_FINGERPRINT_LOOP = 'error_fingerprint_loop';
    public const SUBTYPE_ERROR_LLM = 'error_llm';
    public const SUBTYPE_REFUSAL = 'refusal';

    /**
     * @param array{toolCallId: string, name: string, arguments: array<string, mixed>}|null $pendingToolCall
     * @param array<string, mixed> $usage
     */
    public function __construct(
        public readonly string $subtype,
        public readonly string $conversationId,
        public readonly string $finalText,
        public readonly int $rounds,
        public readonly array $usage,
        public readonly int $costMicros = 0,
        public readonly ?array $pendingToolCall = null,
        public readonly string $confirmationToken = '',
        public readonly string $errorMessage = '',
    ) {
    }
}
