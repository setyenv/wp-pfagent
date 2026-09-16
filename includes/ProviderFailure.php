<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Turn a provider's failure into something a person can act on.
 *
 * A turn that dies because the AI provider rejected the API key used to reach
 * the chat as this, verbatim:
 *
 *   The action ran but the final response failed.
 *   LLM error: unknown — LLM HTTP 401: {"error":{"message":"Authentication
 *   Fails, Your api key: ****14f1 is invalid","type":"authentication_error"…
 *
 * Three lies in four lines: no action ran, the code is not "unknown", and the
 * user is handed the vendor's raw JSON. Worst of all it reads like OUR product
 * broke, when the truth is "the key in Settings is not valid any more" — which
 * the user could fix in thirty seconds if anybody told them.
 *
 * The taxonomy is the one this plugin already uses for provider health
 * (auth / quota / rate_limit / network / invalid_model / configuration /
 * provider_error), so a failure is named the same way wherever it surfaces.
 * The raw provider text is never thrown away — it stays in the turn's
 * errorMessage for support.
 */
final class ProviderFailure
{
    /**
     * Classify a raw runtime/provider error into a code plus a sentence
     * that says what happened and what to do about it.
     *
     * @return array{code: string, message: string, providerStatus: int}
     */
    public static function describe(string $raw): array
    {
        $status = self::http_status($raw);
        $code = self::classify($status, $raw);

        return [
            'code' => $code,
            'message' => self::explain($code, $status),
            'providerStatus' => $status,
        ];
    }

    /** The HTTP status the provider answered with, or 0 when it never answered. */
    private static function http_status(string $raw): int
    {
        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $raw, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /** Same taxonomy as ProviderHealth: one vocabulary for one kind of fact. */
    private static function classify(int $http_status, string $message): string
    {
        $normalized = strtolower($message);

        // Not every failed turn is the provider's. A stale confirmation — the
        // customer clicked Approve twice, or came back to a dialog the
        // conversation had already moved past — never reaches the provider at
        // all, and telling them "the AI provider did not complete the request"
        // sends them looking in exactly the wrong place.
        if ($http_status === 0 && str_contains($normalized, 'confirmation')) {
            return 'stale_confirmation';
        }
        if ($http_status === 0 && (str_contains($normalized, 'conversation not found') || str_contains($normalized, 'no longer registered'))) {
            return 'agent_error';
        }

        if ($http_status === 401 || $http_status === 403) {
            return 'auth';
        }
        // "insufficient" on its own is not about money: DeepSeek says
        // "insufficient tool messages following tool_calls message" for a
        // malformed transcript, and we told the user to top up an account
        // that was perfectly funded. Match the phrases that do mean credit.
        if ($http_status === 402
            || str_contains($normalized, 'quota')
            || str_contains($normalized, 'insufficient balance')
            || str_contains($normalized, 'insufficient credit')
            || str_contains($normalized, 'insufficient funds')
            || str_contains($normalized, 'balance')) {
            return 'quota';
        }
        if ($http_status === 429
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'too many requests')) {
            return 'rate_limit';
        }
        if (str_contains($normalized, 'invalid model') || str_contains($normalized, 'model not found')) {
            return 'invalid_model';
        }
        if ($http_status >= 500 && $http_status < 600) {
            return 'provider_unavailable';
        }
        if ($http_status === 0
            && ($normalized === '' || str_contains($normalized, 'network') || str_contains($normalized, 'timed out') || str_contains($normalized, 'timeout'))) {
            return 'network';
        }
        if (str_contains($normalized, 'setting') || str_contains($normalized, 'base url')) {
            return 'configuration';
        }

        return 'provider_error';
    }

    private static function explain(string $code, int $http_status): string
    {
        switch ($code) {
            case 'auth':
                return __('The AI provider rejected the credentials: its API key is not valid any more. Update it in the provider settings and try again.', 'wp-pfagent');
            case 'quota':
                return __('The AI provider refused the request for lack of credit or quota on the account. Top the account up and try again.', 'wp-pfagent');
            case 'rate_limit':
                return __('The AI provider is rate limiting this account. Wait a moment and try again.', 'wp-pfagent');
            case 'invalid_model':
                return __('The AI provider does not recognise the selected model. Pick another model in the provider settings.', 'wp-pfagent');
            case 'provider_unavailable':
                return __('The AI provider is unavailable right now. Nothing was left half-done; try again shortly.', 'wp-pfagent');
            case 'network':
                return __('This site could not reach the AI provider. Check the connection or any egress restriction, then try again.', 'wp-pfagent');
            case 'configuration':
                return __('The provider configuration on this site is incomplete. Review the provider settings.', 'wp-pfagent');
            case 'stale_confirmation':
                return __('That confirmation is no longer valid: it was already answered, or the conversation moved on without it. Ask again and confirm the new one.', 'wp-pfagent');
            case 'agent_error':
                return __('This turn could not be completed. Nothing was left half-done; ask again.', 'wp-pfagent');
            default:
                return $http_status > 0
                    ? sprintf(
                        /* translators: %d: HTTP status the AI provider answered with. */
                        __('The AI provider answered with an error (HTTP %d). This is the provider, not this site.', 'wp-pfagent'),
                        $http_status
                    )
                    : __('The AI provider did not complete the request. This is the provider, not this site.', 'wp-pfagent');
        }
    }
}
