<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * A loaded conversation: id, label, status, ordered messages, turn count,
 * and free-form metadata. Provider + model are NOT part of the conversation
 * — they are a global operator selection living in the credential store
 * and injected into the Loop per turn. Storing them here meant a stale
 * value could outlive a wizard change and force the LLM call onto a model
 * the credential no longer carried.
 *
 * Conversations are NOT mutable in place; the Loop appends through the Store.
 * This object is a read snapshot the loop uses to build the next LLM request.
 */
final class Conversation
{
    /**
     * @param array<int, Message> $messages
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $status,
        public readonly array $messages,
        public readonly int $turnCount,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Cached model caps the gateway wrote into metadata on first use.
     * Returns null when the model has not been probed yet. Caller supplies
     * the model name (since the conversation no longer pins one) so the
     * cache key is unambiguous when a session has been driven by more
     * than one model over its lifetime.
     *
     * @return array{contextLength: int, maxOutputTokens: int}|null
     */
    public function modelCaps(?string $model = null): ?array
    {
        $perModel = is_array($this->metadata['modelCaps'] ?? null) ? $this->metadata['modelCaps'] : [];
        // Legacy shape: a flat {contextLength, maxOutputTokens} pair lived
        // here when the conversation pinned a single model. Honour it as a
        // fallback for any caller that does not yet pass $model.
        if (isset($perModel['contextLength'], $perModel['maxOutputTokens']) && is_int($perModel['contextLength'])) {
            $flat = [
                'contextLength' => (int) $perModel['contextLength'],
                'maxOutputTokens' => (int) $perModel['maxOutputTokens'],
            ];
            if ($model === null) {
                return $flat;
            }
        }
        if ($model !== null && is_array($perModel[$model] ?? null)) {
            $caps = $perModel[$model];
            if (isset($caps['contextLength'], $caps['maxOutputTokens'])) {
                return [
                    'contextLength' => (int) $caps['contextLength'],
                    'maxOutputTokens' => (int) $caps['maxOutputTokens'],
                ];
            }
        }
        return null;
    }

    /** Returns a copy with metadata extended (does not persist). */
    public function withMetadata(array $extra): self
    {
        return new self(
            $this->id,
            $this->label,
            $this->status,
            $this->messages,
            $this->turnCount,
            array_replace_recursive($this->metadata, $extra),
        );
    }
}
