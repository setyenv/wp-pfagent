<?php
/**
 * WP-PFAgent update channel — hooked into WordPress's STANDARD mechanism
 * (`Update URI:` header + `update_plugins_<host>` filter), a functional twin
 * of the WP-PFWorkflow / WP-PFManagement one, but with NOTHING license-related.
 *
 * PFAgent is open source (GPL-2.0) and carries no LicenseClient: there is no
 * key to send. The channel still needs an IDENTITY to deal out turns in the
 * staggered rollout — the `check.php` census answers the installed version
 * ("nothing new yet") to anyone sending an empty identity, so without an
 * identity a site would NEVER see a new version. That is why this client mints
 * an ANONYMOUS, stable per-site identity: a one-time random id, stored in an
 * option. It says who nobody is and vouches for no entitlement; it only gives
 * the stagger a stable handle.
 *
 * The rest of the cycle is identical to the paid plugins':
 *   1. Core fires the filter; `check.php` is queried via POST.
 *   2. The channel answers the version THIS site is due (or the installed one
 *      when the stagger says "not yet"): clean silence.
 *   3. The returned `package` carries NO token; on clicking "Update",
 *      `upgrader_pre_download` mints a single-use link and downloads.
 *
 * @see https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/
 */

declare(strict_types=1);

namespace ProjectFlash\Agent;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(__NAMESPACE__ . '\\UpdateChannel')) {

final class UpdateChannel
{
    /**
     * Channel host. Single source of that string: it matches, character for
     * character, the host in the main file's `Update URI:` header, because
     * that is where the filter name core fires comes from. A host move forces
     * listening to both for the whole transition (sites on the old version
     * keep declaring the old host).
     */
    public const HOST = 'updates.setyenv.com';

    /** Option where the site's anonymous, stable identity lives. */
    private const IDENTITY_OPTION = 'wp_pfagent_channel_site_id';

    /** Seconds to wait on the channel. Short: the check runs on admin page
     *  load and a downed channel must not hang anyone's dashboard. */
    private const CHECK_TIMEOUT = 5;

    /** @var array<string, array{slug:string, version:string, domain:string}> by basename */
    private static array $plugins = [];

    private static bool $hooked = false;

    /**
     * Registers the plugin with the channel.
     *
     * @param string $plugin_file absolute path of the main file (__FILE__)
     * @param string $slug        plugin slug (wp-pfagent)
     * @param string $version     installed version
     * @param string $text_domain text domain for the download messages
     */
    public static function register(string $plugin_file, string $slug, string $version, string $text_domain = 'wp-pfagent'): void
    {
        self::$plugins[plugin_basename($plugin_file)] = [
            'slug'    => $slug,
            'version' => $version,
            'domain'  => $text_domain,
        ];

        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        add_filter('update_plugins_' . self::HOST, [self::class, 'check'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'pre_download'], 10, 4);
    }

    /**
     * The site's ANONYMOUS, stable identity towards the channel.
     *
     * It is not a license key (there is none): it is a random id minted once
     * and stored, so the stagger deals turns out stably across distinct
     * installs without identifying anyone. Minted lazily the first time the
     * channel needs it.
     */
    private static function identity(): string
    {
        $id = get_option(self::IDENTITY_OPTION, '');
        if (is_string($id) && $id !== '') {
            return $id;
        }
        // 32 hex of cryptographic randomness; once per site.
        try {
            $id = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $id = md5((string) home_url('/') . '|' . (string) wp_rand());
        }
        update_option(self::IDENTITY_OPTION, $id, false);
        return $id;
    }

    /**
     * Does this site have auto-updates turned on for THIS plugin?
     *
     * The channel needs it for the census: only self-updating sites take part
     * in the stagger, and once that cohort is current the version is released
     * to everyone. Core requires TWO things (`WP_Automatic_Updater::should_update()`):
     * automatic updates enabled globally AND the plugin present in the list.
     * Looking only at the list would count as cohort a site with automatics
     * switched off entirely, which would never update.
     */
    private static function auto_update_enabled(string $plugin_file): bool
    {
        if (!function_exists('wp_is_auto_update_enabled_for_type')) {
            $file = ABSPATH . 'wp-admin/includes/update.php';
            if (!is_readable($file)) {
                return false;
            }
            require_once $file;
        }
        if (!wp_is_auto_update_enabled_for_type('plugin')) {
            return false;
        }
        return in_array($plugin_file, (array) get_site_option('auto_update_plugins', []), true);
    }

    /**
     * The channel's answer for THIS plugin. The filter fires per host, so
     * filter by `$plugin_file` and return anything not ours untouched.
     *
     * @param array|false $update
     * @param array       $plugin_data
     * @param string      $plugin_file basename
     * @return array|false
     */
    public static function check($update, array $plugin_data, string $plugin_file)
    {
        unset($plugin_data);

        if (!isset(self::$plugins[$plugin_file])) {
            return $update;
        }
        $me = self::$plugins[$plugin_file];

        $response = wp_remote_post('https://' . self::HOST . '/check.php', [
            'timeout' => self::CHECK_TIMEOUT,
            'body'    => [
                'slug'    => $me['slug'],
                'version' => $me['version'],
                'id'      => self::identity(),
                'auto'    => self::auto_update_enabled($plugin_file) ? '1' : '0',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['version'])) {
            return false;
        }

        return [
            'slug'         => $me['slug'],
            'plugin'       => $plugin_file,
            'version'      => (string) $data['version'],
            'url'          => (string) ($data['url'] ?? ''),
            'package'      => (string) ($data['package'] ?? ''),
            'tested'       => (string) ($data['tested'] ?? ''),
            'requires_php' => (string) ($data['requires_php'] ?? ''),
        ];
    }

    /**
     * Download: the link is minted HERE. The check's `package` carries NO
     * token (WordPress caches that response ~12 h and the links are single-use
     * and short-lived); it is requested at the moment of download.
     *
     * @param bool|\WP_Error $reply
     * @param string         $package
     * @return bool|string|\WP_Error
     */
    public static function pre_download($reply, $package, $upgrader = null, $hook_extra = [])
    {
        unset($upgrader);
        if (!is_string($package) || strpos($package, 'https://' . self::HOST . '/token.php') !== 0) {
            return $reply;
        }

        $plugin_file = (string) ($hook_extra['plugin'] ?? '');
        $me = self::$plugins[$plugin_file] ?? null;
        if ($me === null) {
            return $reply;
        }

        $query = [];
        parse_str((string) parse_url($package, PHP_URL_QUERY), $query);

        $minted = wp_remote_post('https://' . self::HOST . '/token.php', [
            'timeout' => 15,
            'body'    => [
                'slug'    => $me['slug'],
                'version' => (string) ($query['version'] ?? ''),
                'id'      => self::identity(),
            ],
        ]);
        if (is_wp_error($minted)) {
            return $minted;
        }
        // 404 = nonexistent version (do not retry); 503 = "not now, come back later".
        $status = (int) wp_remote_retrieve_response_code($minted);
        if ($status !== 200) {
            $message = $status === 503
                ? __('The update channel is busy right now. Please try again in a minute.', $me['domain'])
                : __('This update is not available from the update channel.', $me['domain']);
            return new \WP_Error('setyenv_update_unavailable', $message, ['code' => $status]);
        }
        $body = json_decode((string) wp_remote_retrieve_body($minted), true);
        if (!is_array($body) || empty($body['url'])) {
            return new \WP_Error('setyenv_update_unavailable', __('The update channel returned no download link.', $me['domain']));
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // The dispatch gate's 503 arrives THROUGH HERE (token.php has no gate
        // of its own): untranslated, core shows "Update failed: Service
        // Unavailable". download_url packs the HTTP code into the error data
        // (it uses http_404 for anything non-200, so look at the data, not the
        // error name).
        $file = download_url((string) $body['url']);
        if (is_wp_error($file)) {
            $data = $file->get_error_data();
            if (is_array($data) && (int) ($data['code'] ?? 0) === 503) {
                return new \WP_Error(
                    'setyenv_update_busy',
                    __('The update channel is busy right now. Please try again in a minute.', $me['domain'])
                );
            }
        }

        return $file;
    }
}

}
