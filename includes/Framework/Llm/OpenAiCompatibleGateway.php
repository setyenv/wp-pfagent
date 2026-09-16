<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

use ProjectFlash\Agent\Framework\Message;

/**
 * OpenAI-compatible HTTP gateway: DeepSeek, OpenAI, Qwen, xAI, anything that
 * speaks the /v1/chat/completions + /v1/models surface.
 *
 * Three responsibilities the framework leans on:
 *
 *  1. discoverCaps(model)
 *     Hits GET /v1/models/{model}. Reads context_length / max_output_tokens
 *     when present. Falls back to a per-provider default map only when the
 *     endpoint omits them (some providers do). NEVER returns hardcoded
 *     values when the real ones are available.
 *
 *  2. complete(req)
 *     POST /v1/chat/completions with the wire-shape messages + tool schemas
 *     + every behavioural lever set on CompletionRequest (temperature, top_p,
 *     penalties, seed, logprobs, logit_bias, stop, response_format,
 *     parallel_tool_calls, reasoning_effort). Null / empty values are
 *     omitted so the provider's own defaults win when we have no opinion.
 *     Returns a CompletionResponse with text, tool calls, finish_reason,
 *     reasoning_content (DeepSeek thinking models echo this), usage,
 *     system_fingerprint, and logprobs (when requested).
 *
 *  3. Auto-continue on length truncation
 *     When finish_reason === 'length', the gateway re-issues the call with
 *     the truncated assistant message appended as a prefix (Chat Prefix
 *     Completion / OpenAI 'prediction'). Concatenates the continuation text
 *     and returns ONE coherent response with continued=true. Tool calls are
 *     never split across continuations — if truncation hit during tool_call
 *     emission, the gateway makes one continuation attempt and gives up.
 *
 *  4. response_format degradation
 *     When the caller sets responseFormat and the provider rejects it
 *     (HTTP 400 with one of the well-known error markers), the gateway
 *     transparently retries ONCE without the field. The Loop then sees a
 *     plain text response and the OutputFilter takes over as backstop.
 */
final class OpenAiCompatibleGateway implements Gateway
{
    // Exceptions carry the provider's HTTP/API error text — caught, returned as
    // a JSON error and matched by ErrorClassifier; never echoed as HTML, so
    // esc_html would corrupt the classified/displayed text. Justified, scoped.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    /** @var array<string, array{contextLength: int, maxOutputTokens: int}> */
    private array $capsCache = [];

    /**
     * @param array<string, array{contextLength: int, maxOutputTokens: int}> $fallbackCaps
     *        Legacy inline fallback table. Prefer passing `ModelCatalog` —
     *        that's the single source of truth shared with cost computation.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly array $fallbackCaps = [],
        // 2 min is plenty for any sane chat completions call (even a long
        // DeepSeek auto-continue stays well under). The previous 10-min
        // ceiling was a misjudged "won't hurt" default — when a connection
        // hangs server-side we'd burn ten minutes of wall clock waiting.
        private readonly int $httpTimeoutSeconds = 120,
        private readonly int $maxContinuationAttempts = 1,
        private readonly ?ModelCatalog $catalog = null,
    ) {
    }

    public function discoverCaps(string $model): array
    {
        if (isset($this->capsCache[$model])) {
            return $this->capsCache[$model];
        }

        $payload = $this->httpGet('/models/' . rawurlencode($model));
        $context = 0;
        $output = 0;

        // OpenAI-compat models endpoints expose these under varying keys;
        // probe the common ones.
        if (is_array($payload)) {
            foreach (['context_length', 'context_window', 'max_input_tokens', 'context_size'] as $key) {
                if (isset($payload[$key]) && is_numeric($payload[$key])) {
                    $context = (int) $payload[$key];
                    break;
                }
            }
            foreach (['max_output_tokens', 'max_completion_tokens', 'max_tokens', 'output_token_limit'] as $key) {
                if (isset($payload[$key]) && is_numeric($payload[$key])) {
                    $output = (int) $payload[$key];
                    break;
                }
            }
        }

        if ($context === 0 || $output === 0) {
            $fb = $this->fallbackCaps[$model] ?? null;
            if ($fb !== null) {
                $context = $context ?: (int) ($fb['contextLength'] ?? 0);
                $output = $output ?: (int) ($fb['maxOutputTokens'] ?? 0);
            }
        }

        if (($context === 0 || $output === 0) && $this->catalog !== null) {
            $cat = $this->catalog->capsFor($model);
            if ($cat !== null) {
                $context = $context ?: $cat['contextLength'];
                $output = $output ?: $cat['maxOutputTokens'];
            }
        }

        if ($context === 0 || $output === 0) {
            throw new \RuntimeException(sprintf(
                'Could not discover caps for model "%s" via /v1/models, and neither inline fallback nor ModelCatalog supplied them.',
                $model,
            ));
        }

        return $this->capsCache[$model] = ['contextLength' => $context, 'maxOutputTokens' => $output];
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $maxOutput = $request->maxOutputTokens;
        if ($maxOutput <= 0) {
            $caps = $this->discoverCaps($request->model);
            $maxOutput = $caps['maxOutputTokens'];
        }

        $messages = array_map(static fn(Message $m): array => $m->toWire(), $request->messages);
        $body = $this->buildBody($request, $messages, $maxOutput);

        // Inject content-level cache_control markers when the model
        // declares support (Kilo Tier 1.10). DeepSeek auto-caches regardless;
        // Qwen / OpenRouter / Together honour the marker when present;
        // vanilla OpenAI silently drops the extra field. We only inject when
        // the catalog entry explicitly opts in via the `cache_control`
        // feature flag, so providers that reject unknown fields are safe.
        if ($this->catalog?->hasFeature($request->model, 'cache_control') ?? false) {
            $minTokens = $this->catalog?->minCacheTokensFor($request->model) ?? 0;
            $body = PromptCacheInjector::injectOpenAiCompat($body, $minTokens);
        }

        try {
            $first = $this->httpPost('/chat/completions', $body);
        } catch (UnsupportedParameterException $e) {
            if ($request->responseFormat !== null) {
                // Provider doesn't support structured outputs. Retry once
                // without the field; OutputFilter is our backstop.
                $degraded = $body;
                unset($degraded['response_format']);
                $first = $this->httpPost('/chat/completions', $degraded);
            } else {
                throw $e;
            }
        }
        $parsed = $this->parseChoice($first);

        // Chat Prefix Completion (DeepSeek `prefix: true`) is incompatible
        // with tool-capable conversations: even if we strip `tools` and
        // `tool_choice` from the continuation body the provider returns
        // HTTP 400 "Function call should not be used with prefix" because
        // the same session previously surfaced tool definitions. When
        // tools were in scope for the first call, we do NOT attempt to
        // continue truncated assistant text — we hand the truncated
        // response back to the Loop, which knows how to ask the model
        // to break the task into smaller steps if needed.
        $tools_in_scope = isset($body['tools']) && is_array($body['tools']) && $body['tools'] !== [];

        $attempts = 0;
        $continued = false;
        while (!$tools_in_scope && $parsed['finishReason'] === 'length' && $attempts < $this->maxContinuationAttempts && $parsed['toolCalls'] === []) {
            // Append the truncated assistant text as a partial assistant
            // message and ask the model to continue from where it stopped.
            // This is the Chat Prefix Completion pattern DeepSeek exposes and
            // OpenAI's prediction feature mirrors.
            $continueMessages = $messages;
            $continueMessages[] = [
                'role' => Message::ROLE_ASSISTANT,
                'content' => $parsed['text'],
                'prefix' => true,
            ];
            $continueBody = $body;
            $continueBody['messages'] = $continueMessages;
            // DeepSeek rejects `prefix:true` continuation whenever `tools`
            // is present in the body even if the first response returned
            // pure text — HTTP 400 "Function call should not be used with
            // prefix". The continuation is for completing truncated text;
            // tool emission is a fresh-turn concern, not a continuation
            // concern. Strip tools + tool_choice from the continuation.
            unset($continueBody['tools'], $continueBody['tool_choice']);

            $second = $this->httpPost('/chat/completions', $continueBody);
            $secondParsed = $this->parseChoice($second);

            $parsed['text'] .= $secondParsed['text'];
            $parsed['finishReason'] = $secondParsed['finishReason'];
            $parsed['toolCalls'] = $secondParsed['toolCalls'];
            $parsed['reasoning'] = ($parsed['reasoning'] ?? '') . ($secondParsed['reasoning'] ?? '');
            $parsed['usage'] = $this->mergeUsage($parsed['usage'] ?? [], $secondParsed['usage'] ?? []);
            $continued = true;
            $attempts++;
        }

        $usage = $parsed['usage'] ?? [];
        $cost = $this->catalog?->computeCostMicros($request->model, $usage) ?? 0;
        // Signal-only: a catalog that exists but cannot price this model means
        // the round is uncounted, not free. The cost stays 0; the flag lets
        // the Loop emit a cost_unknown trace.
        $costUnknown = $this->catalog !== null && $this->catalog->pricingFor($request->model) === null;

        return new CompletionResponse(
            text: $parsed['text'],
            toolCalls: $parsed['toolCalls'],
            finishReason: $parsed['finishReason'],
            reasoning: $parsed['reasoning'] ?? '',
            usage: $usage,
            costMicros: $cost,
            continued: $continued,
            rawModel: (string) ($first['model'] ?? $request->model),
            systemFingerprint: (string) ($first['system_fingerprint'] ?? ''),
            logprobs: $parsed['logprobs'] ?? null,
            costUnknown: $costUnknown,
        );
    }

    /**
     * Build the wire body. Null / empty fields are omitted so we don't
     * pin a default the provider has its own opinion about — this also
     * keeps the request compact and the prompt cache warm (extra keys
     * change request hashes some providers use for caching).
     *
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function buildBody(CompletionRequest $request, array $messages, int $maxOutput): array
    {
        $body = [
            'model' => $request->model,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_tokens' => $maxOutput,
            'tool_choice' => $request->toolChoice,
        ];
        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }
        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }
        if ($request->presencePenalty !== null) {
            $body['presence_penalty'] = $request->presencePenalty;
        }
        if ($request->frequencyPenalty !== null) {
            $body['frequency_penalty'] = $request->frequencyPenalty;
        }
        if ($request->seed !== null) {
            $body['seed'] = $request->seed;
        }
        if ($request->logprobs) {
            $body['logprobs'] = true;
            if ($request->topLogprobs !== null) {
                $body['top_logprobs'] = $request->topLogprobs;
            }
        }
        if ($request->logitBias !== []) {
            // Wire format: object with token-id string keys → int bias.
            // JSON encoding turns int keys back into strings, which is the
            // shape OpenAI / DeepSeek expect, so a plain array works.
            $body['logit_bias'] = $request->logitBias;
        }
        if ($request->stop !== []) {
            $body['stop'] = array_values($request->stop);
        }
        if ($request->responseFormat !== null) {
            $body['response_format'] = $request->responseFormat;
        }
        if ($request->parallelToolCalls !== null) {
            $body['parallel_tool_calls'] = $request->parallelToolCalls;
        }
        if ($request->reasoningEffort !== null) {
            $body['reasoning_effort'] = $request->reasoningEffort;
        }
        if ($request->extraBody !== []) {
            // Host-provided fields win over framework-managed ones: the
            // host knows the provider better than we do (e.g. DeepSeek
            // `thinking: {type:"enabled"}` requires us to STOP sending
            // temperature/penalties, which the host can express by
            // overriding here).
            $body = array_replace($body, $request->extraBody);
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{text: string, toolCalls: list<array{id: string, name: string, arguments: array<string, mixed>}>, finishReason: string, reasoning: string, usage: array<string, int>, logprobs: array<string, mixed>|null}
     */
    private function parseChoice(array $payload): array
    {
        $choice = $payload['choices'][0] ?? null;
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        $reasoning = is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : '';
        $finish = (string) ($choice['finish_reason'] ?? 'unknown');
        $logprobs = is_array($choice['logprobs'] ?? null) ? $choice['logprobs'] : null;

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $func = is_array($tc['function'] ?? null) ? $tc['function'] : [];
            // Arguments that do not parse are NOT "no arguments". Reading a
            // broken JSON string as an empty object turned a data-changing
            // call into a confirmation dialog with nothing in it, waiting for
            // a customer to approve a change with no content. Say it did not
            // parse and let the loop hand that back to the model.
            $rawArgs = $func['arguments'] ?? '{}';
            $args = [];
            $argsError = '';
            if (is_array($rawArgs)) {
                $args = $rawArgs;
            } else {
                $raw = trim((string) $rawArgs);
                $decoded = $raw === '' ? [] : json_decode($raw, true);
                if (is_array($decoded)) {
                    $args = $decoded;
                } else {
                    $argsError = sprintf(
                        'arguments were not valid JSON (%d characters received)',
                        strlen($raw)
                    );
                }
            }
            $toolCalls[] = [
                'id' => (string) ($tc['id'] ?? ''),
                'name' => (string) ($func['name'] ?? ''),
                'arguments' => $args,
                'argumentsError' => $argsError,
            ];
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        // DeepSeek extension surfaces cache hit/miss; OpenAI gpt-4o family
        // surfaces only the hit count under prompt_tokens_details.cached_tokens.
        // Normalise both shapes to the canonical fields.
        $cacheHit = (int) ($usage['prompt_cache_hit_tokens']
            ?? ($usage['prompt_tokens_details']['cached_tokens'] ?? 0));
        $cacheMiss = (int) ($usage['prompt_cache_miss_tokens']
            ?? max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cacheHit));

        return [
            'text' => $text,
            'toolCalls' => $toolCalls,
            'finishReason' => $finish,
            'reasoning' => $reasoning,
            'usage' => [
                'promptTokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completionTokens' => (int) ($usage['completion_tokens'] ?? 0),
                'totalTokens' => (int) ($usage['total_tokens'] ?? 0),
                'cacheHitTokens' => $cacheHit,
                'cacheMissTokens' => $cacheMiss,
                'cacheWriteTokens' => 0,  // not exposed by OpenAI-compat surface
                'reasoningTokens' => (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
            ],
            'logprobs' => $logprobs,
        ];
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    private function mergeUsage(array $a, array $b): array
    {
        $keys = ['promptTokens', 'completionTokens', 'totalTokens', 'cacheHitTokens', 'cacheMissTokens', 'cacheWriteTokens', 'reasoningTokens'];
        $merged = [];
        foreach ($keys as $key) {
            $merged[$key] = (int) ($a[$key] ?? 0) + (int) ($b[$key] ?? 0);
        }
        return $merged;
    }

    /** @return array<string, mixed>|null */
    private function httpGet(string $path): ?array
    {
        // Blocking GET (no streaming) → WordPress HTTP API is equivalent.
        $response = wp_remote_get(rtrim($this->baseUrl, '/') . $path, [
            'timeout' => $this->httpTimeoutSeconds,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function httpPost(string $path, array $body): array
    {
        // Blocking POST (no streaming) → WordPress HTTP API is equivalent.
        $response = wp_remote_post(rtrim($this->baseUrl, '/') . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => $this->httpTimeoutSeconds,
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('LLM HTTP failed: ' . $response->get_error_message());
        }
        $raw = wp_remote_retrieve_body($response);
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            $excerpt = substr((string) $raw, 0, 400);
            // Probe the response body for the common "this param is not
            // supported" markers so the caller can degrade gracefully
            // instead of dying. We DON'T just trust the HTTP code — 400 is
            // also raised for malformed messages and we'd hide those.
            if (self::looksLikeUnsupportedParameter($excerpt)) {
                throw new UnsupportedParameterException($excerpt);
            }
            throw new \RuntimeException(sprintf('LLM HTTP %d: %s', $status, $excerpt));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('LLM returned non-JSON: ' . substr((string) $raw, 0, 200));
        }
        return $decoded;
    }

    private static function looksLikeUnsupportedParameter(string $body): bool
    {
        $lc = strtolower($body);
        foreach (['response_format', 'json_schema', 'unsupported parameter', 'unsupported_parameter', 'not supported'] as $needle) {
            if (str_contains($lc, $needle)) {
                return true;
            }
        }
        return false;
    }
}
