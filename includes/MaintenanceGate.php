<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Maintenance-mode consumer (operator dictate 2026-07-24). The table of
 * record and the decision live in wp-pfmanagement; this plugin consumes the
 * cross-plugin contract:
 *
 *   - apply_filters('pfm_maintenance_should_block', false)      → bool
 *   - apply_filters('pfm_maintenance_page_html', '')            → full document
 *   - apply_filters('pfm_maintenance_seconds_until_next', null) → ?int
 *
 * Without PFM active the filters fall through to their defaults and nothing
 * blocks. Today every PFA surface (admin page and every REST route) already
 * requires `manage_options`, and administrators bypass maintenance — so the
 * gate is defense in depth: it exists so that if any PFA surface ever opens
 * beyond administrators, maintenance covers it from day one. The agent
 * EXECUTES things: during maintenance it must be as unusable as the rest.
 */
final class MaintenanceGate
{
    private const REST_NAMESPACE = 'wp-pfagent/v1';

    public static function register_hooks(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'gate_rest'], 5, 3);

        // The status endpoint the SPA pre-warning toast polls.
        add_action('rest_api_init', static function (): void {
            register_rest_route(self::REST_NAMESPACE, '/maintenance/status', [
                'methods'             => 'GET',
                'callback'            => static function () {
                    $seconds = apply_filters('pfm_maintenance_seconds_until_next', null);
                    return rest_ensure_response([
                        'in_maintenance'     => (bool) apply_filters('pfm_maintenance_should_block', false),
                        'seconds_until_next' => is_numeric($seconds) ? (int) $seconds : null,
                    ]);
                },
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]);
        });
    }

    public static function should_block(): bool
    {
        return (bool) apply_filters('pfm_maintenance_should_block', false);
    }

    /**
     * Emit the maintenance page as the whole response and stop, when a
     * window is in force for this user. Called from the admin page's
     * `load-` hook — before any output.
     */
    public static function render_and_exit_if_blocked(): void
    {
        if (!self::should_block()) {
            return;
        }
        $html = (string) apply_filters('pfm_maintenance_page_html', '');
        if ($html === '') {
            return; // contract provider absent — fail open, never a blank page
        }
        nocache_headers();
        status_header(503);
        header('Retry-After: 3600');
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped by the provider
        exit;
    }

    /**
     * @param mixed            $result
     * @param \WP_REST_Server  $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function gate_rest($result, $server, $request)
    {
        if ($result !== null) {
            return $result;
        }
        $route = is_object($request) && method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        if ($route === '' || strpos($route, '/' . self::REST_NAMESPACE . '/') !== 0) {
            return $result;
        }
        // The status endpoint stays reachable: it reports state, it does no work.
        if ($route === '/' . self::REST_NAMESPACE . '/maintenance/status') {
            return $result;
        }
        if (!self::should_block()) {
            return $result;
        }
        header('Retry-After: 3600');
        return new \WP_Error(
            'pfa_maintenance',
            __('The system is under maintenance. Please try again later.', 'wp-pfagent'),
            ['status' => 503]
        );
    }
}
