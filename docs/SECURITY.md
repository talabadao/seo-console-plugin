# SEO Console: security model

This document is for reviewers. It describes what SEO Console exposes, who can reach it, what it stores, where it
connects to, and what an attacker can and cannot do. The rules every PR must follow are in
`docs/ARCHITECTURE.md` §14; the per-PR checklist is `docs/REVIEW_CHECKLIST.md`.

## 1. Assets worth protecting

| Asset | Where | Why it matters |
|---|---|---|
| Claude API key | option `seoc_secrets` (encrypted) or constant `SEOC_CLAUDE_API_KEY` | Spends the site owner's money |
| Google API key | option `seoc_secrets` (encrypted) or constant `SEOC_GOOGLE_API_KEY` | Quota abuse |
| Site content and SEO meta | posts, post meta (`_yoast_wpseo_*`, `rank_math_*`, `_seoc_*`), attachment alt text | Defacement, SEO spam |
| Plugin settings | option `seoc_settings` | Turning modules on/off, raising the AI budget |
| Change log and suggestions | tables `{prefix}seoc_changes`, `{prefix}seoc_suggestions` | Audit trail of every change |
| Availability of the public site | frontend output of enabled modules | A bad change must never take the site down |

## 2. Who can reach what

| Entry point | Who | Check |
|---|---|---|
| Admin pages `admin.php?page=seo-console*` | `manage_options` | `add_menu_page`/`add_submenu_page` capability + `Capabilities::can_manage()` in `render()` |
| `seo-console/v1/*` REST routes (settings, suggestions, changes, environment, module routes) | `manage_options` | `permission_callback` on every route (`Rest\Controller::can_manage`). Logged-out → 401, other roles → 403 (tested in `RestTest`) |
| `GET seo-console/v1/status` | `edit_posts` | Returns only `{ ok, version, plugin, features }` |
| SEO meta keys in the core REST API (`/wp/v2/posts`, `meta`) | users who can `edit_post` that post | `register_post_meta` `auth_callback` → `current_user_can( 'edit_post', $id )`; values `sanitize_text_field` |
| Background jobs (Action Scheduler, group `seo-console`) | nobody directly (WP-Cron / AS runner) | Jobs act as "system"; they can create suggestions but never apply AI output |
| Public pages | everyone | Only enabled modules add output, never in Safe Mode; output is escaped |

There are no `admin-ajax` handlers, no `nopriv` actions, and no form posts outside REST. REST requests from the admin
use the core `wp-api-fetch` nonce middleware (cookie auth needs `X-WP-Nonce`); the SEO Console web app uses an
Application Password over HTTPS.

## 3. Secrets

- Stored in option `seoc_secrets` with `autoload = no`, encrypted with libsodium `crypto_secretbox`
  (`Core\Secrets`). The key is `sodium_crypto_generichash( 'seo-console-secrets|' . wp_salt( 'auth' ) )`; each value has
  a random 24-byte nonce. Format: `v1:` + base64( nonce . ciphertext ).
- A constant `SEOC_<NAME>` (e.g. `SEOC_CLAUDE_API_KEY`) overrides storage and cannot be changed from the UI.
- Never sent to the browser: REST and `window.seocConfig` only expose `{ has, masked: 'sk-ant-…3fA9', source }`
  (`Secrets::describe()`); `aiConfigured`/`googleConfigured` are booleans.
- Never logged: `Logger::redact()` replaces context keys that look like secrets (`api_key`, `token`, `password`,
  `authorization`, `secret`, `salt`, …) and masks strings that look like Anthropic (`sk-ant-…`) or Google (`AIza…`)
  keys. Settings saves log only the names of changed keys.
- `POST /settings/test-ai` may receive a typed, unsaved key: it is used for that one request and neither stored nor
  echoed.
- If the salts change, decryption fails: `get()` returns null and an admin notice asks to re-enter the key.
- What the encryption does and does not protect: it protects a database dump or a SQL-injection read of
  `wp_options` from revealing the key. It does not protect against someone who can read `wp-config.php` or run PHP on
  the server (they can derive the key). That is accepted: such an attacker owns the site anyway.

## 4. Outbound connections

Only these hosts, and only after the related module is enabled and a key is entered (listed in `readme.txt`):

| Host | Code | Data sent |
|---|---|---|
| The site itself (`home_url()` host) | `Core\HealthCheck`, audit crawl | GET of own pages (`seoc_hc=<random>`), no credentials |
| `api.anthropic.com` | `AI\ClaudeClient` (`wp_remote_post`, 120 s timeout) | Prompt + the page data needed for the task, key in `x-api-key` |
| `www.googleapis.com` (PageSpeed Insights) | Speed module | Public URLs + Google key |
| `chromeuxreport.googleapis.com` | Speed module | Origin / public URLs + Google key |

Rules: health checks and crawls only request URLs on the site's own host (`HealthCheck::targets()` drops other hosts);
any user-supplied URL goes through `wp_safe_remote_get`. No code, fonts, scripts or styles are loaded from remote
sources; admin JS is bundled in `build/`.

## 5. AI and prompt injection

Site content (titles, page text, crawled HTML, comments) is attacker-controllable: anyone who can publish a post, or
any page the crawler reads, can put instructions in it.

Defences:

1. **Content is data.** It is always wrapped in a tagged block (`AI\ContextBuilder::wrap( 'page_content', … )`), and
   occurrences of the closing tag inside the content are neutralised so it cannot break out. System prompts include
   `ContextBuilder::untrusted_notice()`.
2. **Output is constrained.** Structured calls use `output_config.format` (JSON schema); the caller validates every
   field again (length, allowed values, tags stripped).
3. **Output can only become a Suggestion** for a known applier (`seo_meta`, `image_alt`, `post_meta_json` with
   `_seoc_*` keys, module appliers). The applier validates it again on create and on approve.
4. **A person approves.** Nothing AI-generated is applied without a `manage_options` user approving it (optionally
   after editing). Approval re-checks that the live value has not changed (`seoc_stale`).
5. **No tools that act.** The model is never given tools that write to the site, run code or fetch URLs.

So the worst a prompt injection can do is produce a bad suggestion that a human sees before it is applied, or waste
AI budget (capped by `ai_monthly_budget_usd`).

## 6. Change and approval flow

`ChangeManager::apply()`: read current value → validate → write → log a row with before/after → for appliers that
affect the frontend: purge page caches, run a loopback health check of the home page + the changed URL; on HTTP ≥ 500,
WordPress's critical-error page or a network error, the change is reverted immediately and logged as `failed`.

`revert()` refuses (`seoc_conflict`) when the value was edited since the change, unless forced; reverts are also health
checked. Batches (bulk approve) are all-or-nothing.

Appliers whitelist what they write: `seo_meta` only the six SEO keys through `SeoPlugins`; `image_alt` only
`_wp_attachment_image_alt` of image attachments; `module_setting` only keys in a module's settings schema (validated by
`Settings`); `post_meta_json` only meta keys matching `_seoc_[a-z0-9_]+`.

## 7. Safe Mode

`SEOC_SAFE_MODE` (wp-config.php) or the `safe_mode` setting: modules' `register_frontend()` is not called, so SEO
Console adds nothing to public pages. Admin, REST, reports and the change log keep working. The admin bar shows
"SEO Console: Safe Mode" while it is on. Use it first when something looks wrong on the site.

## 8. Attacker scenarios

**Subscriber (or any non-admin) account**
- Can: call `GET /status` only if they have `edit_posts` (contributors and up); it reveals the plugin version and
  enabled modules. Edit the six SEO meta keys of posts they can already edit (same as editing the post).
- Cannot: read or change settings, keys, suggestions or the change log; approve or revert anything; see any secret;
  trigger AI calls; reach admin pages (all 401/403, see `RestTest::test_subscriber_and_logged_out_are_denied`).

**Logged-out visitor**
- Can: see public pages (with whatever enabled modules output, escaped).
- Cannot: reach any SEO Console REST route (401) or admin page.

**Someone who stole the Claude API key**
- Can: spend the owner's Anthropic credit outside WordPress. Rotate the key at console.anthropic.com and save the new
  one (or the constant).
- Cannot: change the site through SEO Console; the key gives no WordPress access.

**Author of a malicious post / a crawled page with hostile content**
- Can: put instructions or HTML in content that the AI reads; make AI suggestions for that post odd; spend budget when
  an admin requests suggestions.
- Cannot: get anything applied without an admin approving it; inject script through suggestions (appliers reject HTML
  in titles/descriptions/alt text and output is escaped); make the plugin fetch other hosts or run code.

**Administrator** (trusted by design)
- Can do everything WordPress allows. SEO Console adds no extra power: it never writes PHP files, never `eval`s,
  never loads remote code, and every change it makes is logged and undoable.

**Database read access (SQL injection elsewhere, leaked backup)**
- Can: read settings, suggestions, logs; read encrypted secrets.
- Cannot: decrypt secrets without `wp-config.php` salts.

## 9. Reporting

Report vulnerabilities privately to the plugin owner (Artemis Digital); do not open a public issue.
