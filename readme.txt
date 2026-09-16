=== WP-PFAgent ===
Contributors: setyenv
Tags: ai, assistant, agent, llm, automation
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.2.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI agent for WordPress: chat to manage content, users, comments, WooCommerce, SEO and more — your own API key, your approval on every change.

== Description ==

WP-PFAgent puts an AI agent in your WordPress dashboard. Tell it what you need in plain language — it finds, writes and edits content, moderates comments, runs WooCommerce tasks, tunes your SEO and more, always showing you what it wants to change and waiting for your click.

Bring your own AI: connect Anthropic (Claude), OpenAI, Google Gemini, or any OpenAI-compatible API (DeepSeek, Groq, OpenRouter, local models…). Your key is stored encrypted in your own database, and WP-PFAgent makes no calls to any AI service until you configure a provider.

= You approve every change =

* Every action that would modify your site opens a confirmation dialog first. The agent proposes; you decide. It never takes a side effect on its own.
* It respects WordPress permissions: every tool checks real capabilities, so the agent can never do more than the logged-in user could.
* Site options and custom fields are writable only from strict allow-lists — no arbitrary database writes, and other plugins' settings are out of reach.
* It never reads, sets or reveals passwords, and role changes are guarded against privilege escalation.
* It works exclusively through WordPress APIs — no shell commands, no file editing, no remote code.
* Your prompts — and the site content the agent reads to fulfil them — are sent only to the AI provider you configured, never to us.

= What you can ask for =

* **Posts, pages & custom post types** — list, search, read, create (drafts by default, content sanitized), edit, and send to trash; set public custom fields (protected internal fields stay off-limits).
* **Categories, tags & taxonomies** — list them, create terms, and assign them to content.
* **Media** — browse the library, read an item's details, and import an image or file from a URL (with a safety guard that rejects private and internal addresses).
* **Users** — list users and read profiles; create users (auto-generated password, never revealed) and update profiles or roles.
* **Comments** — list and moderate: approve, hold, mark as spam, trash, or reply.
* **Site settings** — read and adjust a safe allow-list of basics: site title, tagline, timezone, date and time format, posts per page.
* **Menus & widgets** — list navigation menus and widget areas; create a menu or add items to it.
* **Site overview & discovery** — WordPress version, active theme, language, content counts, installed plugins, and every content type and taxonomy other plugins register — which the agent can then read and manage with the same content tools.

= WP-PFAgent + WooCommerce =

Read recent orders and products, or look one up by id. Then, always with your confirmation: add order notes (private or customer-facing), change an order's status, cancel an order, create a pending order, add or remove line items, apply a coupon, set a product's stock, and create or edit simple products. Refunds are never issued automatically: the agent records a refund request on the order for a person to review and process.

= WP-PFAgent + Yoast SEO, Rank Math or SEOPress =

Auto-detects your SEO plugin. Read the SEO of any post or page, and optimize its SEO title, meta description and focus keyword.

= WP-PFAgent + Gravity Forms, Fluent Forms, WPForms or Contact Form 7 =

List the forms of every form plugin present — indicating which ones store entries — and browse a form's entries. Manage an entry where the plugin allows it: mark it read or unread, spam, trash, star it, or add a note. Honest limits: Contact Form 7 does not store submissions, and WPForms entries require WPForms Pro.

= WP-PFAgent + LearnDash =

Read courses, lessons and a user's enrollments; enroll or unenroll a user in a course — with your confirmation.

= WP-PFAgent + MemberPress =

Read membership products and a member's active memberships; grant or revoke a member's access (recorded as a manual transaction) — always confirmed, never automatic.

These integrations appear only when the matching plugin is active, each one talks to that plugin's own public API, and their absence never affects the rest.

= Speaks your language =

The interface ships localized in 14 languages, and you can chat with the agent in any language your AI model understands.

= Part of the Setyenv platform =

PFAgent is part of Setyenv, a WordPress-native work platform. The premium suite — PFManagement, a low-code data platform, and PFWorkflow, a visual workflow engine — extends the same conversational approach to data modeling and automation, driven natively by the full edition, PFAgent. Everything on this page is complete and fully functional on its own: no suite required, no account, no locked features.

Setyenv and the Setyenv logo are trademarks of Setyenv™.

== External services ==

WP-PFAgent uses the Large Language Model (LLM) provider **you** configure, to power the assistant. Nothing is sent anywhere until you enter a provider and API key in the plugin's settings.

* **What is sent:** your chat messages and the specific WordPress content the assistant needs to read to answer you (for example, a post it is editing). Requests go to the provider you chose, authenticated with your own API key.
* **Supported providers and their terms/privacy:**
  * Anthropic (Claude) — https://www.anthropic.com/legal/consumer-terms — https://www.anthropic.com/legal/privacy
  * OpenAI — https://openai.com/policies/terms-of-use — https://openai.com/policies/privacy-policy
  * Google (Gemini) — https://ai.google.dev/gemini-api/terms — https://policies.google.com/privacy
  * Any OpenAI-compatible provider you configure (e.g. DeepSeek, Groq, OpenRouter, or a self-hosted endpoint) — governed by that provider's own terms.

No data is sent to Setyenv. The plugin does not phone home.

== Frequently Asked Questions ==

= Do I need to create an account with you? =
No. There is no Setyenv account and no sign-up. You only need an API key from the LLM provider of your choice.

= What data leaves my site, and where does it go? =
Only what the assistant needs to answer you — your messages and the content it reads for the task — and only to the LLM provider you configured, using your own key. See the "External services" section. Nothing is sent to Setyenv.

= Does it work without any paid plugins? =
Yes. The WordPress features (content, taxonomies, media, users, comments, settings, menus) work on their own. WooCommerce, SEO and forms tools light up only if you already run those plugins. The Setyenv suite (WP-PFManagement / WP-PFWorkflow) is optional.

= Does it run remote code or shell commands? =
No. The assistant acts only through WordPress's own APIs. It does not execute system commands and has no remote-execution component.

= When does the plugin call an external service? =
Only when you send a message to the assistant after configuring a provider and key — then it calls that provider's LLM API. With no provider configured, it makes no external calls.

= Will it change my site without asking? =
No. Every action that modifies data opens a confirmation dialog; you approve each change. Read-only actions (listing/reading) run without a prompt.

== Installation ==

1. Upload the plugin to `wp-content/plugins/` or install the zip via **Plugins → Add New → Upload**, then activate it.
2. Open **PF Agent** in the admin menu.
3. Add your LLM provider and API key in the settings, and pick a model.
4. Start a conversation and ask the assistant to do something.

== Screenshots ==

1. The split-screen agent: chat on the left, your live WordPress admin on the right.
2. Every change is proposed first — the agent waits for your approval before it acts.
3. Approved: the agent creates the draft, and the WordPress panel jumps to the result.
4. The built-in WordPress tab is a live admin beside the chat — here, the new draft open in the editor.

== Changelog ==

= 1.2.8 =

* Released in lockstep with WP-PFManagement and WP-PFWorkflow so the three plugins of the suite stay on the same version.

= 1.2.7 =

* A conversation and a trace now belong to a person of the platform, not to a WordPress account id. The owner of a conversation and the user of a trace entry point to the same person record used everywhere else in the suite, and existing rows are converted on update, before any table is reconciled.

= 1.2.6 =

* Released in lockstep with WP-PFManagement and WP-PFWorkflow so the three plugins of the suite stay on the same version. **Nothing you use changes**: the work in this release is internal housekeeping, and updating leaves everything here exactly as it was in 1.2.5.

= 1.2.5 =

* **A site that has been running the plugin for a while could not start a conversation at all.** The chat answered "Chat session not found after creation": the write reported success and reading back the row it had just created found nothing. The conversations table on those installs still carried the old numeric key, so the new identifier was mangled on the way in — and whether a given attempt worked depended on how the identifier happened to start. The move to the new key was written and correct; it simply never ran, because it hung off plugin *activation*, and a site that **updates** never activates. So the longest-running installs — the ones with the most conversations to lose — were exactly the ones left behind. The schema now catches up on update as well, once, and only records itself as done after it finishes.

= 1.2.4 =

* A confirmation now says what it is about to do. Asking for a task opened a modal reading "Create record in an entity?" — a write with no table named and no value shown; it reads "Create record in "Agile task" (Review the backups)?" now. And a deletion, the most dangerous thing on offer, fell through to a generic "The agent is about to apply a change": it names what it deletes, says when it takes every record in a table with it, and says that it cannot be undone.
* A failed turn is explained in the product's words instead of the machine's. A dead connection read "Failed to fetch" and a truncated answer read "WP PFAgent API POST agent-runtime/turn-v2 returned an invalid empty response" — a verb and an endpoint in front of somebody who asked how many tasks they had. The two failures you can act on are named: the assistant could not be reached, or the answer did not arrive complete. Anything the server worded for a person still travels unchanged.
* Thirteen languages, complete. 33 strings into each locale — the sentences you read at the worst moment, when the provider refuses the key, the account is out of credit, or the agent is about to delete something — with one plural form per slot each language declares.
* The chat-session listing no longer sends the `X-WP-Total` and `X-WP-TotalPages` headers. The count was never computed there: `total`, `page`, `perPage` and `totalPages` are in the body of the answer and stay exactly as they were.

= 1.2.3 =

* A conversation is keyed the same way on every site. Conversations and everything under them — messages, traces and tool calls — leave the numeric key, in the table, in the API and in the browser. An auto-increment id only means something inside one database, so this is what makes a chat history portable between environments. The migration runs on update and carries the existing conversations with it.
* The conversation list counted four times the turns you had. It counted every row appended — your message, the answer, and one per tool result — so a chat seven turns long offered itself as twenty-eight. Only a message you send opens a turn now; conversations that already exist keep the number they had and count correctly from here.
* The confirmation modal called every update a creation. It asked "Create entity X?" while the agent was about to overwrite an existing one, because it decided by whether the id looked like a number. It asks about what is actually going to happen.
* The workflow preview never opened. Four places demanded a numeric workflow id, which Workflow stopped minting; the pane sat on "Waiting for the agent" for good, and the workflow paired with a business rule was never brought into view.
* "How many" is answered by the tool or not at all. Asked how many tasks there were, the agent listed them, read the twenty-five of the first page and answered twenty-five — there were thirty-five. A list is a page, and the agent no longer reports a page as a total.
* The agent is handed a map of your data model and asked for one entity at a time, instead of the whole model on every turn. Turns are cheaper and there is room left for the conversation.
* A chat survives an unanswered confirmation instead of getting stuck behind it, and a workflow variable can be created.
* What the model is told about your data no longer contradicts the product: its catalogue and its prompt still described business rules and row-visibility rules as keyed by a number, which stopped being true two versions ago. An instruction that is wrong is not a cosmetic defect.

= 1.2.2 =
* When the AI provider refuses a request, the chat now says so in plain words. A rejected API key produced "The action ran but the final response failed" followed by the provider's raw error data — which read as if this plugin had broken, when the fix was one field in the provider settings. Rejected credentials, exhausted quota, rate limits, an unknown model, an unreachable provider and a provider outage are each named for what they are, with what to do about them.
* Reading orders no longer fails on a shop that has issued refunds. Asking the agent about WooCommerce orders crashed on any store with a refund in it: refunds came back mixed into the order list and are not orders, so the whole request died. It now reads orders only, and no order type a store adds can bring that listing down again.
* The active engine can no longer be wiped by an incomplete request. Saving the provider/model selection accepted anything, including an empty payload, and overwrote the operator's choice with a blank one — after which the chat came up with no engine and no explanation. An incomplete or unknown selection is now refused and the stored one is left alone.
* Asking about a provider that does not exist says "not found", like every other provider action, instead of reporting a health check that "failed".
* Chat session listings report their totals in the standard `X-WP-Total` headers as well as in the response, so any tool that pages through them can tell when it has seen them all.
* German: the "Setyenv views" label still carried the product's former name. Corrected in place; the other twelve languages were already right.
* Released in lockstep with WP-PFManagement and WP-PFWorkflow 1.2.2, so the three plugins of the suite stay on the same version.

= 1.2.1 =
* Lockstep release with WP-PFWorkflow 1.2.1 (fixes the 1.2.0 upgrade leaving the workflow library empty; workflows were safe in the database). No PFAgent code changes — version alignment only.

= 1.2.0 =
* Suite-wide version unification (operator-authorized minor).
* Workflow authoring goes fully opaque: workflow ids are normalized (int or uuid-string) across the agent apply lane, narrator, import-preview, ChatSessions, compiler and API bridge — no silent numeric drops.
* Decompile cache and the virtual file system no longer leave postmeta behind: the agent's source and path-slug state live in the workflow state store, opaque ids end to end (paths, bridge, builders, CLI tools).
* Maintenance-mode consumer: the plugin shows the maintenance page and returns REST 503 while a window is in force, with the final-hour pre-warning toast; admins pass through and the WordPress login stays intact.
* Update-channel client comments/docblocks translated to English; the release ZIP now ships the frontend source alongside the compiled bundle (source-complete, GPL) and the product documentation, with the internal-only tree excluded.

= 1.0.8 =
* Chat: the confirmation prompt and the assistant's answer now render in the message that requested them, never on the previous one.
* New "WordPress" tab: a live view of your admin that follows the agent's actions to the relevant screen as it works.
* Header refresh: product logo and name.

= 1.0.7 =
* Cross-cutting WordPress layer: direct tools for posts, pages and custom post types, taxonomies, media, users, comments, site settings, menus and site discovery.
* Integrations, active only when the plugin is present: WooCommerce (orders, products, notes, status, coupons, stock, refund requests), SEO (Yoast / Rank Math / SEOPress), forms (Contact Form 7 / Fluent Forms / Gravity Forms / WPForms), LearnDash and MemberPress.
* Human confirmation gate on every data-changing action; capability-checked, allow-listed writes.
* Bring-your-own-AI: Anthropic (Claude), OpenAI, Google Gemini, and any OpenAI-compatible provider.
