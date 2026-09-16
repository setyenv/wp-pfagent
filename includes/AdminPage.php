<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

final class AdminPage
{
    private const SCRIPT_HANDLE = 'pfagent-app';
    private const STYLE_HANDLE  = 'pfagent-app';

    private string $hook_suffix = '';

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        // Force `type="module"` on our bundle, otherwise the browser refuses
        // the ES-module imports vite emits.
        add_filter('script_loader_tag', [$this, 'filter_script_module_tag'], 10, 3);
        // WP's wp_set_script_translations looks for languages/<domain>-<locale>-<md5(src)>.json
        // by default. The md5 changes every build because the script URL embeds
        // the vite content hash. Redirect to our deterministic
        // <domain>-<locale>-<handle>.json so translations survive rebuilds.
        add_filter('load_script_translation_file', [$this, 'filter_script_translation_file'], 10, 3);
    }

    public function filter_script_translation_file(string $file, string $handle, string $domain): string
    {
        if ($handle !== self::SCRIPT_HANDLE || $domain !== 'wp-pfagent') {
            return $file;
        }
        $locale = determine_locale();
        $custom = WP_PFAGENT_DIR . 'languages/' . $domain . '-' . $locale . '-' . $handle . '.json';

        return file_exists($custom) ? $custom : $file;
    }

    public function register_menu(): void
    {
        $this->hook_suffix = add_menu_page(
            __('WP PFAgent', 'wp-pfagent'),
            __('PF Agent', 'wp-pfagent'),
            'manage_options',
            'wp-pfagent',
            [$this, 'render_fallback'],
            'dashicons-format-chat',
            57
        );

        // Render the SPA full-screen, outside the standard wp-admin chrome.
        // Hooking into load-{hook_suffix} fires before any admin output, so we
        // can emit our own HTML document and exit; this keeps the menu entry
        // (so the link appears in the sidebar) while replacing the cramped
        // admin canvas with the full viewport.
        add_action('load-' . $this->hook_suffix, [$this, 'render_full_screen']);
    }

    /**
     * Make the bundle's tag a real ES module.
     *
     * Not cosmetic: vite emits an ESM entrypoint, and a classic <script> both
     * rejects its top-level imports AND — when the bundle has none — leaks
     * every top-level declaration into the page's globals. wp-pfworkflow was
     * shipping its editor that way and its minified `_` replaced WordPress'
     * underscore, killing wp.Backbone on the screen.
     *
     * The attribute is INJECTED into the existing tag, never rebuilt. What
     * WordPress passes here is the whole block for the handle: any inline
     * script attached to it (wp_add_inline_script) comes glued in front, and
     * returning a freshly-built tag silently throws that away — which is
     * exactly how wp-pfworkflow's SPA bootstrap disappeared for one commit.
     * This page prints its own bootstrap today, so nothing is attached; the
     * injection keeps it that way if that ever changes.
     */
    public function filter_script_module_tag(string $tag, string $handle, string $src): string
    {
        if ($handle !== self::SCRIPT_HANDLE) {
            return $tag;
        }
        if (strpos($tag, 'type="module"') !== false) {
            return $tag;
        }

        $id = self::SCRIPT_HANDLE . '-js';

        return (string) preg_replace_callback(
            '#<script\b[^>]*>#i',
            static function (array $m) use ($id): string {
                // Only this handle's own src tag; an inline sibling must stay a
                // classic script (a module would scope its assignment away
                // from the window).
                if (strpos($m[0], 'id="' . $id . '"') === false || stripos($m[0], ' type=') !== false) {
                    return $m[0];
                }

                return str_replace('<script ', '<script type="module" ', $m[0]);
            },
            $tag
        );
    }

    public function render_full_screen(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Maintenance window in force → the maintenance page replaces the SPA.
        // Defense in depth: today only administrators (who bypass) reach this
        // page, but if the capability model ever widens, the gate is standing.
        MaintenanceGate::render_and_exit_if_blocked();

        nocache_headers();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        }

        $entry = $this->asset_entry();
        $version_qs = WP_PFAGENT_VERSION;

        // Register + enqueue the bundle and its CSS through the official WP
        // pipeline. The wp-i18n dependency ensures the global wp.i18n is
        // available BEFORE our bundle runs (we import @wordpress/i18n in
        // the TSX, which vite externalises to that global). And
        // wp_set_script_translations is what causes WP to inject the
        // matching <script>wp.i18n.setLocaleData(...)</script> for the
        // active locale right before our bundle script tag.
        if (is_array($entry)) {
            wp_register_script(
                self::SCRIPT_HANDLE,
                WP_PFAGENT_URL . 'dist/' . $entry['file'],
                ['wp-i18n'],
                $version_qs,
                true
            );
            wp_set_script_translations(
                self::SCRIPT_HANDLE,
                'wp-pfagent',
                WP_PFAGENT_DIR . 'languages'
            );
            wp_enqueue_script(self::SCRIPT_HANDLE);

            foreach (is_array($entry['css'] ?? null) ? $entry['css'] : [] as $index => $css_file) {
                $style_handle = self::STYLE_HANDLE . ($index === 0 ? '' : '-' . $index);
                wp_register_style(
                    $style_handle,
                    WP_PFAGENT_URL . 'dist/' . $css_file,
                    [],
                    $version_qs
                );
                wp_enqueue_style($style_handle);
            }
        }

        $config_json = (string) wp_json_encode($this->app_config());
        $admin_url = esc_url(admin_url());
        $title = __('WP PFAgent', 'wp-pfagent');
        $back_label = __('Back to admin', 'wp-pfagent');
        $missing_label = __('WP PFAgent assets are missing. Run npm install and npm run build inside the plugin directory.', 'wp-pfagent');
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="same-origin">
<title><?php echo esc_html($title); ?></title>
<style>
html, body { margin: 0; padding: 0; min-height: 100vh; background: #0c1118; color: #edf4ff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
/* Override the embedded-in-admin layout rules from styles.css when running
   full-screen. The React layer renders its own top bar (.pfa-fullscreen-bar)
   so the SPA root only needs to occupy the remaining viewport. */
body.pfa-fullscreen .pfa-root { min-height: calc(100vh - 40px); margin-left: 0; }
body.pfa-fullscreen .pfa-shell { min-height: calc(100vh - 40px); }
.pfa-fullscreen-fallback { padding: 24px; color: #ffd58a; background: #3a2a14; border: 1px solid #5a4214; margin: 16px; border-radius: 6px; }
.pfa-fullscreen-fallback a { color: #4d8dff; }
</style>
<?php
        // Bring dashicons into the document so the React full-screen bar
        // can render its arrow + plugin icons without dragging the rest
        // of the wp-admin stylesheet in. wp_print_styles also flushes any
        // styles we just enqueued (the pfagent-app bundle CSS).
        wp_print_styles(['dashicons', self::STYLE_HANDLE]);
        ?>
</head>
<body class="pfa-fullscreen">
<div id="wp-pfagent-root" class="pfa-root"></div>
<?php if (!is_array($entry)): ?>
<div class="pfa-fullscreen-fallback">
  <strong><?php echo esc_html__('WP PFAgent assets are not built.', 'wp-pfagent'); ?></strong>
  <p><?php echo esc_html($missing_label); ?></p>
  <p><a href="<?php echo esc_url($admin_url); ?>"><?php echo esc_html($back_label); ?></a></p>
</div>
<?php endif; ?>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- SPA bootstrap-config inline script: the app's entry data, which cannot be a separately-loaded file. ?>
<script>
<?php
        // The SPA bootstrap config. $config_json is wp_json_encode() output —
        // a safe JS value injected into a <script> context (NOT HTML): running
        // it through esc_html() would corrupt the JSON and break the app, so it
        // is emitted verbatim. This inline bootstrap script IS the SPA's entry
        // data; it cannot be a separately-enqueued file.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output injected into a JS context; escaping would corrupt it.
        echo 'window.ProjectFlashAgent = ' . $config_json . ';';
        ?>
</script>
<?php
        // wp_print_scripts emits wp-i18n (dependency) and our pfagent-app
        // bundle. We then explicitly emit the translations script for our
        // handle: in this admin_init+exit code path the standard print
        // pipeline doesn't always invoke print_translations alongside the
        // bundle, so we force it ourselves AFTER wp-i18n is on the page
        // (which load_script_textdomain queries via the registered handle).
        wp_print_scripts([self::SCRIPT_HANDLE]);
        if (is_array($entry)) {
            $translations = wp_scripts()->print_translations(self::SCRIPT_HANDLE, false);
            if (is_string($translations) && $translations !== '') {
                // $translations is WordPress' own print_translations() output
                // (a generated <script> block of Jed locale data) — trusted
                // core output, not user input; emitted verbatim by design.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated translations script block, not user data.
                echo "<script id='" . esc_attr(self::SCRIPT_HANDLE) . "-js-translations'>\n" . $translations . "\n</script>\n";
            }
        }
        ?>
</body>
</html>
<?php
        exit;
    }

    /**
     * Fallback admin renderer for the rare case the load-{hook_suffix} hook
     * is bypassed (eg. early permission denial). Mirrors the previous
     * embedded behavior so the user still sees something.
     */
    public function render_fallback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage WP PFAgent.', 'wp-pfagent'));
        }

        echo '<div id="wp-pfagent-root" class="pfa-root"></div>';

        if ($this->asset_entry() === null) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('WP PFAgent assets are missing. Run npm install and npm run build inside the plugin directory.', 'wp-pfagent');
            echo '</p></div>';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function app_config(): array
    {
        $workflow_dependency = WorkflowDependency::status_payload();
        $workflow_capabilities = is_array($workflow_dependency['capabilities'] ?? null)
            ? $workflow_dependency['capabilities']
            : [];

        $active_llm_raw = get_option('wp_pfagent_active_llm', []);
        if (!is_array($active_llm_raw)) {
            $active_llm_raw = [];
        }
        $active_llm = [
            'providerId' => (string) ($active_llm_raw['providerId'] ?? ''),
            'model' => (string) ($active_llm_raw['model'] ?? ''),
            'sessionId' => isset($active_llm_raw['sessionId']) && $active_llm_raw['sessionId'] !== null
                ? (string) $active_llm_raw['sessionId']
                : null,
            'updatedAt' => (string) ($active_llm_raw['updatedAt'] ?? ''),
        ];

        $is_admin_user = current_user_can('manage_options');

        return [
            'restUrl' => esc_url_raw(rest_url('wp-pfagent/v1/')),
            'workflowRestUrl' => (string) ($workflow_dependency['restUrl'] ?? ''),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => WP_PFAGENT_VERSION,
            // Header title, derived from THIS plugin's own name header so the
            // full build and the extracted standalone build each show their own
            // name without a hard-coded string in the SPA.
            'name' => $this->plugin_display_name(),
            'adminUrl' => esc_url_raw(admin_url()),
            'iconUrl' => esc_url_raw(WP_PFAGENT_URL . 'assets/static/icon.png'),
            // Setyenv vendor logo shown top-right in the header (links setyenv.com).
            'setyenvLogoUrl' => esc_url_raw(WP_PFAGENT_URL . 'assets/img/setyenv-logo.png'),
            // Suite switcher (header hamburger). The HOME link is listed for
            // EVERYONE (the exception) so the hamburger is always present; it
            // points at the site's domain base. Manage/Workflow (when active) +
            // WP Admin are ADMIN-ONLY. Labels mirror each module's admin-menu title.
            'products' => array_values(array_filter([
                self::suite_home_link(),
                ($is_admin_user && ManagementDependency::is_active()) ? [
                    'slug' => 'pfmanagement',
                    'label' => 'PF Manage',
                    'url' => ManagementDependency::admin_url(),
                ] : null,
                ($is_admin_user && WorkflowDependency::is_active()) ? [
                    'slug' => 'pfworkflow',
                    'label' => 'PF Workflow',
                    'url' => WorkflowDependency::admin_url(),
                ] : null,
                $is_admin_user ? [
                    'slug' => 'wpadmin',
                    'label' => __('WP Admin', 'wp-pfagent'),
                    'url' => admin_url(),
                ] : null,
            ])),
            'workflowDependency' => $workflow_dependency,
            'managementDependency' => ManagementDependency::status_payload(),
            'activeLlm' => $active_llm,
            'capabilities' => [
                'manageAgent' => Capabilities::can_manage_agent(),
                'manageCredentials' => Capabilities::can_manage_credentials(),
                'viewWorkflows' => (bool) ($workflow_capabilities['viewWorkflows'] ?? false),
                'manageWorkflows' => (bool) ($workflow_capabilities['manageWorkflows'] ?? false),
                'runWorkflows' => (bool) ($workflow_capabilities['runWorkflows'] ?? false),
                'viewLogs' => (bool) ($workflow_capabilities['viewLogs'] ?? false),
            ],
        ];
    }

    /**
     * The "home" entry for the suite switcher — visible to EVERYONE.
     *
     * Label = the first host label, capitalised (an IP is shown verbatim):
     *   own.setyenv.com → "Own", setyenv.com → "Setyenv", localhost → "Localhost".
     * TARGET = the current host's ROOT, always "/". No domain logic: a computed base
     * dropped the port on a host:port (localhost:8095 → dead bare "localhost"); "/"
     * works on any host:port — on localhost and everywhere.
     *
     * @return array{slug:string,label:string,url:string}
     */
    private static function suite_home_link(): array
    {
        $host   = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'localhost');
        $is_ip  = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $labels = explode('.', $host);

        return [
            'slug'  => 'home',
            'label' => $is_ip ? $host : ucfirst($labels[0]),
            'url'   => '/',
        ];
    }

    /**
     * This plugin's display name, read from its own plugin-header "Plugin Name".
     * The full and standalone builds are separate plugin files, so each returns
     * its own name — no hard-coded product string lives in the SPA.
     */
    private function plugin_display_name(): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data(WP_PFAGENT_FILE, false, false);
        $name = trim((string) ($data['Name'] ?? ''));
        return $name !== '' ? $name : 'WP-PFAgent';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function asset_entry(): ?array
    {
        $manifest_path = WP_PFAGENT_DIR . 'dist/.vite/manifest.json';

        if (!file_exists($manifest_path)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifest_path), true);
        if (!is_array($manifest)) {
            return null;
        }

        $entry = $manifest['assets/src/main.tsx'] ?? null;

        return is_array($entry) ? $entry : null;
    }
}
