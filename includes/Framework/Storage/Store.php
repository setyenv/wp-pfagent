<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Storage;

use ProjectFlash\Agent\Framework\Conversation;
use ProjectFlash\Agent\Framework\Message;

/**
 * Persistence boundary. Two concrete implementations live in this repo:
 *   - Storage\PdoStore       — pure PHP, SQLite or MySQL, no WP.
 *   - WordPress\Storage\WpDbStore — uses $wpdb, lives under wp-pfagent at runtime.
 *
 * The Loop only talks through this interface; the framework core has zero
 * WordPress knowledge.
 *
 * Concurrency note: there is intentionally no transaction primitive. Every
 * mutating call must be self-contained and idempotent at the SQL level.
 * Replay / fork uses logical ordering through `ordinal` on messages and
 * `round` on traces; we never depend on transactional isolation.
 */
interface Store
{
    /** Apply the schema. Idempotent. */
    public function migrate(): void;

    /** Create a new conversation. Returns its id. Provider + model are
     *  NOT pinned on the conversation — they are a global operator selection
     *  injected into the Loop on each turn. */
    public function createConversation(
        string $label,
        array $metadata = [],
    ): string;

    /** Load a conversation with all its messages in ordinal order. */
    public function loadConversation(string $id): ?Conversation;

    /** Append a message and return its assigned ordinal. */
    public function appendMessage(string $conversationId, Message $message): int;

    /** Update conversation metadata (merged, not replaced). */
    public function updateConversationMetadata(string $conversationId, array $partial): void;

    /** Mark conversation as closed / aborted. */
    public function closeConversation(string $conversationId, string $status = 'closed'): void;

    /**
     * Log a tool-call execution.
     *
     * @param array<string, mixed> $arguments
     * @param mixed $result
     * @param mixed $stateAfter
     */
    public function logToolCall(
        string $conversationId,
        int $messageOrdinal,
        string $toolCallId,
        string $toolName,
        array $arguments,
        bool $sideEffect,
        string $status,
        mixed $result,
        mixed $stateAfter,
        string $errorCode,
        string $errorMessage,
        string $fingerprint,
        int $durationMs,
        string $startedAt,
        string $endedAt,
    ): string;

    /**
     * Look up a prior tool call by fingerprint. Used by the idempotency guard:
     * if an identical (tool_name + args) call already ran successfully in this
     * conversation AND the tool declares idempotent=true, we can reuse the
     * cached result instead of re-running.
     *
     * @return array{result: mixed, stateAfter: mixed}|null
     */
    public function findIdempotentResult(string $conversationId, string $fingerprint): ?array;

    /** Count how many prior tool calls in this conversation match the given fingerprint. Used by Fingerprint::oscillating(). */
    public function countFingerprint(string $conversationId, string $fingerprint, int $sinceOrdinal = 0): int;

    /**
     * Count side-effect tool calls in this conversation that completed
     * with status='ok'. Used by the Loop's honesty cross-check: if the
     * LLM's final text claims to have created/modified/deleted something
     * (first-person past tense) but this count is zero, the claim is a
     * fabrication and the loop must reject the reply.
     */
    public function countSuccessfulSideEffects(string $conversationId): int;

    /**
     * Log a generic trace event. `$systemFingerprint` is the provider
     * backend tag (only meaningful on llm_round traces); empty string for
     * other kinds.
     */
    public function logTrace(
        string $conversationId,
        int $turn,
        int $round,
        string $kind,
        array $payload,
        string $systemFingerprint = '',
    ): void;
}
