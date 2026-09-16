<?php
/**
 * Plugin Name: WP-PFAgent
 * Description: Open-source AI agent console for the Setyenv suite. Drives WP-PFWorkflow and WP-PFManagement from natural language.
 * Version: 1.2.8
 * Author: Setyenv Build
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-pfagent
 * Update URI: https://updates.setyenv.com/wp-pfagent/
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_PFAGENT_VERSION')) {
    define('WP_PFAGENT_VERSION', '1.2.7');
}
if (!defined('WP_PFAGENT_FILE')) {
    define('WP_PFAGENT_FILE', __FILE__);
}
if (!defined('WP_PFAGENT_DIR')) {
    define('WP_PFAGENT_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WP_PFAGENT_URL')) {
    define('WP_PFAGENT_URL', plugin_dir_url(__FILE__));
}

require_once WP_PFAGENT_DIR . 'includes/PersonColumns.php';
require_once WP_PFAGENT_DIR . 'includes/UpdateChannel.php';
require_once WP_PFAGENT_DIR . 'includes/Capabilities.php';
require_once WP_PFAGENT_DIR . 'includes/RateLimiter.php';
require_once WP_PFAGENT_DIR . 'includes/WorkflowDependency.php';
require_once WP_PFAGENT_DIR . 'includes/ManagementDependency.php';
require_once WP_PFAGENT_DIR . 'includes/ProviderPresets.php';
require_once WP_PFAGENT_DIR . 'includes/CredentialStore.php';
require_once WP_PFAGENT_DIR . 'includes/ProviderModelDiscovery.php';
require_once WP_PFAGENT_DIR . 'includes/ProviderHealth.php';
require_once WP_PFAGENT_DIR . 'includes/ProviderFailure.php';
require_once WP_PFAGENT_DIR . 'includes/PromptCacheHelper.php';
require_once WP_PFAGENT_DIR . 'includes/ProviderBackoff.php';
require_once WP_PFAGENT_DIR . 'includes/ProviderRuntime.php';
require_once WP_PFAGENT_DIR . 'includes/AgentToolRegistry.php';

// The Setyenv-suite half — the .pfflow compiler and the PFM/PFW bridges — is
// loaded only when present. A derived, standalone distribution of this plugin
// simply omits these files; the requires are guarded so their absence is not a
// fatal, and the tool registry / SPA then degrade to the WordPress-only surface
// by presence. The plugin source itself carries the complete set.
foreach ([
    'includes/WorkflowApiBridge.php',
    'includes/ManagementApiBridge.php',
    'includes/Sourcecode/CompileError.php',
    'includes/Sourcecode/Lexer.php',
    'includes/Sourcecode/Parser.php',
    'includes/Sourcecode/VerbCatalog.php',
    'includes/Sourcecode/LibraryBuilder.php',
    'includes/Sourcecode/Compiler.php',
    'includes/Sourcecode/Decompiler.php',
    'includes/Sourcecode/DecompileCache.php',
    'includes/Sourcecode/TemplateDecompileCache.php',
    'includes/Sourcecode/VirtualFileSystem.php',
    'includes/WorkflowVfsBridge.php',
] as $suite_file) {
    if (file_exists(WP_PFAGENT_DIR . $suite_file)) {
        require_once WP_PFAGENT_DIR . $suite_file;
    }
}

require_once WP_PFAGENT_DIR . 'includes/WpCore/WpCoreAgentApi.php';
require_once WP_PFAGENT_DIR . 'includes/ThirdParty/ThirdPartyPresence.php';
require_once WP_PFAGENT_DIR . 'includes/ThirdParty/WooCommerceAgentApi.php';
require_once WP_PFAGENT_DIR . 'includes/ThirdParty/SeoAgentApi.php';
require_once WP_PFAGENT_DIR . 'includes/ThirdParty/FormsAgentApi.php';
require_once WP_PFAGENT_DIR . 'includes/ThirdParty/LearnDashAgentApi.php';
require_once WP_PFAGENT_DIR . 'includes/ThirdParty/MemberPressAgentApi.php';
require_once WP_PFAGENT_DIR . 'includes/SystemPrompt.php';
require_once WP_PFAGENT_DIR . 'includes/AgentContract.php';
require_once WP_PFAGENT_DIR . 'includes/AgentFixAdvisor.php';
require_once WP_PFAGENT_DIR . 'includes/AgentInternalDocs.php';
require_once WP_PFAGENT_DIR . 'includes/ChatSessions.php';
require_once WP_PFAGENT_DIR . 'includes/TraceLogger.php';
require_once WP_PFAGENT_DIR . 'includes/BetaReadiness.php';
require_once WP_PFAGENT_DIR . 'includes/RestApi.php';
require_once WP_PFAGENT_DIR . 'includes/MaintenanceGate.php';
require_once WP_PFAGENT_DIR . 'includes/AdminPage.php';
require_once WP_PFAGENT_DIR . 'includes/DashboardWidget.php';

// Multi-provider LLM framework (ported from pf-agent). Centralises the
// Gateway adapter pattern (OpenAI-compat / Anthropic / Gemini), token + cache
// accounting, cost computation via ModelCatalog, and the multi-round Loop
// with output filter + approval gate. wp-pfagent's REST endpoints can
// progressively migrate from the inline LlmGateway/AgentRuntime above to
// this framework as each surface is verified.
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/Gateway.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/CompletionRequest.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/CompletionResponse.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/UnsupportedParameterException.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/ModelCatalog.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/PromptCacheInjector.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/OpenAiCompatibleGateway.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/AnthropicGateway.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/GeminiGateway.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/GatewayFactory.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Message.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Conversation.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Fingerprint.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/JsonSchemaValidator.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/OutputFilter.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/ApprovalGate.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/ApprovalStore.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/PermissionRuleset.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/ToolDefinition.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/ToolResult.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Tools/Tool.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Tools/ClosureTool.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Tools/Registry.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Storage/Store.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Storage/PdoStore.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/WordPress/Storage/WpDbStore.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/WordPress/Tools/FilterBridgeTool.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/WordPress/TransientApprovalStore.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/WordPress/HumanModalApprovalGate.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/LoopOptions.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/LoopResult.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Loop.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/Llm/Prompts.php';
require_once WP_PFAGENT_DIR . 'includes/Framework/LlmCompactor.php';
require_once WP_PFAGENT_DIR . 'includes/FrameworkRuntime.php';

// Update channel (WP's standard mechanism: `Update URI:` header + per-host
// filter). No LicenseClient — PFAgent is OSS; the channel uses a stable
// anonymous identity only for its turn in the staggered rollout.
\ProjectFlash\Agent\UpdateChannel::register(WP_PFAGENT_FILE, 'wp-pfagent', WP_PFAGENT_VERSION);

register_activation_hook(WP_PFAGENT_FILE, ['\\ProjectFlash\\Agent\\TraceLogger', 'install']);
// Compiler caches — only when the .pfflow compiler ships (guarded so a derived
// standalone build without it does not fatal on activation).
if (class_exists('\\ProjectFlash\\Agent\\Sourcecode\\DecompileCache')) {
    register_activation_hook(WP_PFAGENT_FILE, ['\\ProjectFlash\\Agent\\Sourcecode\\DecompileCache', 'activate']);
    register_activation_hook(WP_PFAGENT_FILE, ['\\ProjectFlash\\Agent\\Sourcecode\\TemplateDecompileCache', 'activate']);
}
// Framework Loop tables (wp_pfaf_conversations/messages/tool_calls/traces).
register_activation_hook(WP_PFAGENT_FILE, ['\\ProjectFlash\\Agent\\Framework\\WordPress\\Storage\\WpDbStore', 'run_schema_upgrade']);

// AND ON UPGRADE, which is the case that matters.
//
// This used to live only on the activation hook, with a comment saying dbDelta
// would therefore handle version-to-version upgrades too. It does not: a
// customer who UPDATES a plugin never calls activate(), so on exactly the
// installs that have been running the longest the schema stayed where it was.
//
// Measured on the gate instance at 1.2.4: conversations were still keyed on the
// pre-uuid auto-increment column, so every conversation the product created
// wrote a uuid into a numeric key — MySQL coerced it, and reading the row back
// by the uuid it had just minted found nothing. The chat answered "Chat session
// not found after creation" and the whole product was unusable there, while a
// freshly activated install was fine.
//
// The check costs one autoloaded option read per request and does nothing until
// the version actually moves.
add_action('plugins_loaded', static function (): void {
    if (get_option('wp_pfagent_schema_version') === WP_PFAGENT_VERSION) {
        return;
    }
    \ProjectFlash\Agent\Framework\WordPress\Storage\WpDbStore::run_schema_upgrade();
}, 1);

// Keep each workflow's decompiled source in postmeta so the LLM's
// virtual filesystem reads are instantaneous. The hook fires whenever
// any workflow is saved (via designer or via pfagent's apply tool).
add_action('plugins_loaded', static function (): void {
    if (class_exists('\\ProjectFlash\\Agent\\Sourcecode\\DecompileCache')) {
        \ProjectFlash\Agent\Sourcecode\DecompileCache::init();
    }
    if (class_exists('\\ProjectFlash\\Agent\\Sourcecode\\TemplateDecompileCache')) {
        \ProjectFlash\Agent\Sourcecode\TemplateDecompileCache::init();
    }
    // Flush the per-request compiler verb-catalog cache when wp-pfworkflow
    // signals a workflow save (the in-memory cache may have data the LLM
    // touched in this same request). The .d.ts files themselves are owned
    // by each plugin's TypingsBuilder and rebuilt by hooks over there.
    add_action('projectflash_workflow_changed', static function (): void {
        if (class_exists('\\ProjectFlash\\Agent\\Sourcecode\\VerbCatalog')) {
            \ProjectFlash\Agent\Sourcecode\VerbCatalog::flush();
        }
    }, 30);
}, 20);

add_action('init', static function (): void {
    load_plugin_textdomain(
        'wp-pfagent',
        false,
        dirname(plugin_basename(WP_PFAGENT_FILE)) . '/languages'
    );
}, 1);

// Make every pfagent REST call honour the logged-in user's locale.
// Without this, `determine_locale()` in REST context returns the site
// WPLANG and the user's per-account language preference is ignored —
// resulting in mixed-locale agent responses (e.g. JP system strings
// inside a Spanish admin's chat). Confined to /wp-pfagent/v1/* routes
// so other plugins' REST handlers keep their behaviour.
add_filter('determine_locale', static function ($locale) {
    if (!is_user_logged_in()) {
        return $locale;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        $route = isset($GLOBALS['wp']->query_vars['rest_route'])
            ? (string) $GLOBALS['wp']->query_vars['rest_route']
            : '';
        if ($route !== '' && strpos($route, '/wp-pfagent/') === 0) {
            $user_locale = get_user_locale(wp_get_current_user());
            if ($user_locale) {
                return $user_locale;
            }
        }
    }
    return $locale;
}, 10, 1);

add_action('plugins_loaded', static function (): void {
    // BEFORE any table is reconciled. Our two person columns hold the sys_id of
    // a PFM `user` row now, not a wp_users id, and dbDelta asked to reconcile
    // CHAR(32) against a live BIGINT rewrites every id as its own digits, which
    // name nobody. The engine resolves them through `user.account` first and
    // does nothing at all once a column is already a person.
    \ProjectFlash\Agent\PersonColumns::run_all();
    \ProjectFlash\Agent\TraceLogger::maybe_install();

    $rate_limiter = new \ProjectFlash\Agent\RateLimiter();
    $chat_sessions = new \ProjectFlash\Agent\ChatSessions($rate_limiter);
    add_action('init', [$chat_sessions, 'register']);
    add_action('rest_api_init', [$chat_sessions, 'register_routes']);

    (new \ProjectFlash\Agent\RestApi())->init();
    // Maintenance-mode consumer: REST choke point for our namespace + the
    // status endpoint the SPA pre-warning toast polls. The admin-page gate
    // hooks in AdminPage::render_full_screen.
    \ProjectFlash\Agent\MaintenanceGate::register_hooks();
    (new \ProjectFlash\Agent\AdminPage())->init();
    (new \ProjectFlash\Agent\DashboardWidget())->init();

    // One-shot purge of the legacy VFS draft/preview options
    // (pfa_draft_<hash>, pfa_preview_<hash>, pfa_draft_index). The VFS
    // no longer carries a separate draft layer — write_file persists
    // directly into wp-pfworkflow as a real draft. Any rows left over
    // from previous installs are stale data and would only confuse a
    // future debugger reading wp_options. Gated on a flag option so it
    // runs once per install.
    if (!get_option('pfa_legacy_drafts_purged_v1')) {
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pfa_draft_%' OR option_name LIKE 'pfa_preview_%'");
            delete_option('pfa_draft_index');
        }
        update_option('pfa_legacy_drafts_purged_v1', gmdate('c'), false);
    }

    // Publish the local WorkflowVfsBridge to the Framework's tool resolver.
    // The legacy AgentRuntime (deleted in commit d89b9ab) injected this
    // bridge by constructor; the new Framework path resolves bridges via
    // `apply_filters('projectflash_agent_vfs_bridge', null)`, so the
    // FilterBridgeTool entries registered for list_files / read_file /
    // write_file / edit_file / move_file / delete_file in agent-tools.json
    // need a registered handler to call into. Without this filter every VFS
    // call returns `bridge_unavailable` and the LLM falls back to emitting
    // tool calls as plain text into the chat. Guarded on the class so a derived
    // standalone build (which omits WorkflowVfsBridge) does NOT register the
    // filter — the tool registry then hides the workflow file tools by presence.
    if (class_exists('\\ProjectFlash\\Agent\\WorkflowVfsBridge')) {
        add_filter('projectflash_agent_vfs_bridge', static function ($service) {
            if (is_object($service)) {
                return $service;
            }
            return new \ProjectFlash\Agent\WorkflowVfsBridge();
        });
    }

    // Publish the self-contained WP-core cross-cutting tool module. This is an
    // INDEPENDENT tool-set (posts / taxonomies / media / users / comments /
    // settings / navigation) that shares NO code with the suite (PFM/PFW)
    // bridges — it talks only to WordPress core, and works with the Setyenv
    // suite absent. The FilterBridgeTool entries for the wp_* tools in
    // agent-tools.json resolve their handler here.
    add_filter('pfa_wpcore_agent_api', static function ($service) {
        if (is_object($service)) {
            return $service;
        }
        return new \ProjectFlash\Agent\WpCore\WpCoreAgentApi();
    });

    // Publish the lean third-party adapters (phase 2). Each is a SELF-CONTAINED
    // module talking directly to the plugin's own public API — no PFW
    // dependency, present only when the plugin is (ThirdPartyPresence gates the
    // tools). Same isolation contract as the WP-core module.
    add_filter('pfa_woocommerce_agent_api', static function ($service) {
        return is_object($service) ? $service : new \ProjectFlash\Agent\ThirdParty\WooCommerceAgentApi();
    });
    add_filter('pfa_seo_agent_api', static function ($service) {
        return is_object($service) ? $service : new \ProjectFlash\Agent\ThirdParty\SeoAgentApi();
    });
    add_filter('pfa_forms_agent_api', static function ($service) {
        return is_object($service) ? $service : new \ProjectFlash\Agent\ThirdParty\FormsAgentApi();
    });
    add_filter('pfa_learndash_agent_api', static function ($service) {
        return is_object($service) ? $service : new \ProjectFlash\Agent\ThirdParty\LearnDashAgentApi();
    });
    add_filter('pfa_memberpress_agent_api', static function ($service) {
        return is_object($service) ? $service : new \ProjectFlash\Agent\ThirdParty\MemberPressAgentApi();
    });
});
