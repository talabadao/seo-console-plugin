# SEO Console — Architecture (the contract)

This file is the contract every contributor (human or agent) codes against. If code and this file disagree, fix one
of them in the same PR. Product plan (Vietnamese): `docs/PLAN.vi.md`.

## 1. Goals and non-goals

- A WordPress plugin that runs entirely on the site's own hosting. One install per site; no central server.
- It **adds** technical SEO tooling next to Rank Math or Yoast SEO; it never replaces them and never duplicates their
  output (titles, meta, schema graph, sitemaps, robots.txt).
- Every change it makes to a site is: proposed → reviewed (editable) → approved by a person → applied → logged → undoable.
  Nothing AI-generated is applied without approval.
- It never writes PHP files, never `eval`s, never loads code from remote sources (WordPress.org guideline 8).
- Shared-hosting friendly: PHP 7.4–8.4, WordPress 6.5+, 128 MB RAM, 30 s requests. Heavy work runs in background
  batches (Action Scheduler) with a time budget per batch.

## 2. Naming and conventions

| Thing | Rule |
|---|---|
| PHP namespace | `SEOConsole\` → `includes/` (PSR-4, own autoloader `includes/Autoloader.php`) |
| Global prefix | `seoc_` for options, hooks, transients, post meta (`_seoc_` for hidden meta), AS hooks, DB tables (`{$wpdb->prefix}seoc_*`) |
| Constants | `SEOC_*` |
| REST | namespace `seo-console/v1` (shared with the SEO Console web app's helper, see §9) |
| Text domain | `seo-console`; UI language English; every UI string translatable |
| Capability | `manage_options` for everything, unless stated otherwise (`Capabilities::MANAGE`) |
| Code style | WordPress Coding Standards (`phpcs.xml.dist`), PHP 7.4 syntax (no enums, no readonly, no `match`, no named args, no union types in signatures — use docblocks) |
| JS | `@wordpress/scripts`, React via `@wordpress/element`, UI from `@wordpress/components`, requests via `@wordpress/api-fetch` |

Frontend output only happens when a module that needs it is enabled **and** Safe Mode is off. Admin assets load only on
SEO Console's own admin pages.

## 3. Directory layout

```
seo-console.php            Bootstrap: version gate, autoloader, Action Scheduler, Plugin::boot on plugins_loaded
uninstall.php              Calls Core\Uninstaller::run()
includes/
  Plugin.php               Service locator + boot sequence
  Core/                    Settings, Secrets, Capabilities, SafeMode, Logger, Http, Environment helpers,
                           Database\Schema (tables + migrations), Uninstaller, HealthCheck
  Rest/                    Controller base + core REST routes (settings, suggestions, changes, status)
  Changes/                 ChangeRequest, ApplierInterface, ChangeManager, built-in appliers
  Suggestions/             SuggestionStore + SuggestionService (approval queue)
  Jobs/                    Queue (Action Scheduler wrapper), TimeBudget
  Integrations/            SeoPlugins (Rank Math / Yoast adapter), CachePlugins (detect + purge), HelperCompat
  AI/                      ClaudeClient, Usage (tokens + cost), ContextBuilder
  Admin/                   Menu, Assets (enqueue per page), Notices
  Modules/<Name>/          One folder per module: <Name>Module.php + its services
src/admin/<page>/index.js  One JS entry per admin page (built to build/<page>.js)
src/admin/shared/          Shared React components and helpers (imported by entries)
tests/phpunit/unit|integration   PHPUnit (WordPress loaded in both; "unit" = no DB writes needed)
tests/phpunit/stubs/       Fake Rank Math / Yoast / cache plugins for integration tests
tests/e2e/                 Playwright against bin/dev-site.sh
```

## 4. Boot sequence

`plugins_loaded` (priority 5) → `Plugin::boot()`:

1. Build services (lazily): Settings, Secrets, Logger, Schema (runs migrations when `seoc_db_version` differs),
   ChangeManager, SuggestionService, Queue, SeoPlugins, CachePlugins, ClaudeClient, HealthCheck.
2. Register built-in modules (filterable through `seoc_modules`), then for each **enabled** module call
   `register( $plugin )`; if Safe Mode is off and the request is not admin/REST/cron/CLI, also call
   `register_frontend( $plugin )`.
3. Register core REST routes, admin menu (admin only), HelperCompat.

`Plugin::instance()` returns the booted instance. Accessors: `settings()`, `secrets()`, `logger()`, `changes()`,
`suggestions()`, `queue()`, `seo()`, `cache()`, `claude()`, `health()`, `modules()`, `module( $id )`, `safe_mode()`.

## 5. Modules

```php
interface ModuleInterface {
    public function id(): string;              // 'speed', 'audit', 'meta', 'schema', 'chat'
    public function title(): string;           // translated
    public function description(): string;     // translated, one sentence
    public function default_enabled(): bool;
    public function settings_schema(): array;  // see Settings below
    public function tables(): array;           // CREATE TABLE statements for dbDelta (use {prefix} and {charset})
    public function register( Plugin $plugin ): void;          // hooks for admin, REST, jobs (always, when enabled)
    public function register_frontend( Plugin $plugin ): void; // public-page hooks (skipped in Safe Mode)
    public function admin_pages(): array;      // list of ['slug','title','entry','position'] (see Admin)
    public function on_disable(): void;        // unschedule jobs etc.
}
```

`AbstractModule` gives empty defaults. Built-in modules (MVP): `speed`, `audit`, `meta`, `schema`, `chat`.
Modules talk to each other only through actions/filters listed in §11, never by calling each other's classes.

## 6. Settings and secrets

- One option `seoc_settings` (autoload yes, small): `{ general: {...}, modules: { <id>: { enabled: bool, ...module keys } } }`.
- `settings_schema()` returns `[ key => [ 'type' => 'bool|int|string|enum|url|string_list|url_list', 'default' => …, 'enum' => […], 'min' => …, 'max' => … ] ]`.
  Settings sanitizes every write against the schema; unknown keys are dropped.
- API: `get( $module, $key )`, `set( $module, $key, $value )`, `all( $module )`, `is_enabled( $module )`, `set_enabled( $module, bool )`.
  `general` keys: `ai_model` (enum, default `claude-opus-5-5`; also `claude-sonnet-5-5`, `claude-haiku-5-5`),
  `ai_monthly_budget_usd` (int, default 20, 0 = AI off), `keep_data_on_uninstall` (bool, false), `seo_plugin` (enum
  `auto|rankmath|yoast|none`, default `auto`), `safe_mode` (bool, false; the constant `SEOC_SAFE_MODE` wins),
  `log_level` (enum `debug|info|warning|error`, default `info`).
  Extra API: `update( $module, array $values )` (all-or-nothing), `validate( $module, $key, $value )` (clean value or
  WP_Error), `schema( $module )`. Disabling a module fires `seoc_module_toggled( $id, $enabled )` → `on_disable()`.
- `Secrets` stores API keys in option `seoc_secrets` (autoload no), encrypted with libsodium `crypto_secretbox`
  (key derived from `wp_salt( 'auth' )`; WordPress ships sodium_compat). A constant overrides storage:
  `SEOC_CLAUDE_API_KEY`, `SEOC_GOOGLE_API_KEY`. API: `get( $name )`, `set( $name, $value )`, `delete( $name )`,
  `has( $name )`, `masked( $name )` (`sk-ant-…3fA9`), `source( $name )` (`constant|stored|none`).
  Secrets are never sent to the browser, never logged, never included in REST responses (only `has`/`masked`/`source`).
  If salts change and decryption fails, `get()` returns null and an admin notice asks to re-enter the key.

## 7. Changes (apply + undo) and Suggestions (approval queue)

### 7.1 ChangeRequest / Appliers

```php
final class ChangeRequest {  // value object
    public $type;        // applier id, e.g. 'seo_meta'
    public $object_type; // 'post' | 'term' | 'attachment' | 'option' | 'site'
    public $object_id;   // int|null
    public $field;       // e.g. 'title', 'description', 'alt', 'schema', 'speed.disable_emojis'
    public $value;       // new value (scalar or array, JSON-serialisable)
    public $source;      // 'user' | 'ai' | 'rule' | 'mcp' | 'system'
    public $module;      // module id
    public $suggestion_id; // int|null
    public $note;        // string
}

interface ApplierInterface {
    public function type(): string;
    public function read( string $object_type, ?int $object_id, string $field );           // current value
    /** @return true|\WP_Error */
    public function validate( $value, string $object_type, ?int $object_id, string $field );
    /** @return true|\WP_Error */
    public function write( string $object_type, ?int $object_id, string $field, $value );
    public function affects_frontend(): bool;   // true → purge caches + run health check after apply/revert
    public function label( string $field ): string; // human label for the change log
}
```

Built-in appliers (core): `seo_meta` (title / description / focus_keyword through `SeoPlugins`), `image_alt`
(`_wp_attachment_image_alt`), `module_setting` (field `<module>.<key>`, writes through Settings), `post_meta_json`
(namespaced `_seoc_*` meta only). Modules register more with `$plugin->changes()->register_applier( $applier )`.

### 7.2 ChangeManager

- `apply( ChangeRequest $r ): Change|WP_Error` — read before → validate → write → insert `seoc_changes` row. If the
  applier `affects_frontend()`: purge caches (`CachePlugins::purge_all()`), then `HealthCheck::run()`; on failure
  revert immediately, store status `failed` + reason, return WP_Error `seoc_health_failed`.
- `apply_batch( ChangeRequest[] ): array` — one health check for the batch, all-or-nothing revert on failure.
- `revert( int $change_id, bool $force = false ): true|WP_Error` — refuses (`seoc_conflict`) when the current value is
  no longer the change's `after` value, unless `$force`. Reverts are themselves logged (`reverted_at`, `reverted_by`).
- Table `seoc_changes`: `id, created_at (UTC datetime), user_id, source, module, type, object_type, object_id, field,
  before_value (JSON), after_value (JSON), status ('applied'|'reverted'|'failed'), error, suggestion_id, batch_id,
  note, reverted_at, reverted_by`.

### 7.3 Suggestions

Table `seoc_suggestions`: `id, created_at, updated_at, module, type, object_type, object_id, field,
current_value (JSON, value when proposed), proposed_value (JSON), final_value (JSON, what was applied, may be edited),
rationale (text), confidence (float 0–1, nullable), source ('ai'|'rule'|'mcp'), model (varchar, nullable),
status ('pending'|'applied'|'rejected'|'failed'|'stale'), change_id, error, created_by, meta (JSON object, nullable,
e.g. { "warnings": ["…"] })`.

`SuggestionService`:
- `create( array $data, array $meta = [] ): int|WP_Error` — validates through the applier; a pending suggestion for the
  same `type/object_type/object_id/field` is updated instead of duplicated. `$data['meta']` (array) and `$meta` are
  merged into the `meta` column, so `[ $service, 'create' ]` can be passed as a sink callable. A proposal equal to the
  live value returns `seoc_no_change`.
- `query( array $args ): array` — filters `status, module, type, object_type, object_id`, `page`, `per_page` (≤100);
  returns `[ 'items' => [...], 'total' => int ]`. Items include `object_title` and `edit_link` for posts/terms.
- `approve( int $id, $edited_value = null, bool $force = false ): Change|WP_Error` — if the live value differs from
  `current_value` → status `stale`, error `seoc_stale` (unless `$force`). Applies through ChangeManager with
  `source` = suggestion source, `suggestion_id` set.
- `reject( int $id ): true|WP_Error`, `bulk( string $action, int[] $ids ): array` (per-id results).

### 7.4 HealthCheck

`run( array $urls = [] ): array{ ok: bool, checks: array }` — loopback GET of the home page plus up to 3 URLs (with a
`seoc_hc=<random>` query arg and `Cache-Control: no-cache`), timeout 15 s. Fails on WP_Error, HTTP ≥ 500, or a body
containing WordPress's critical-error message. A timeout on a host where loopback is known to be blocked
(`seoc_loopback_ok` = no) is reported as `inconclusive`, not failure.

## 8. Background jobs

`Jobs\Queue` wraps Action Scheduler (group `seo-console`):
`schedule_daily( $hook, $args, $local_time = '02:30' )`, `schedule_recurring( $hook, $seconds, $args )`,
`enqueue( $hook, $args )`, `unschedule( $hook )`, `is_scheduled( $hook )`. Hooks are named `seoc_<module>_<job>`.
A job must finish within `Jobs\TimeBudget` (default 20 s) and re-enqueue itself to continue. Jobs must be idempotent.
Long-running state lives in the module's tables, never in transients.

## 9. Integrations

- **SeoPlugins** — `active(): 'rankmath'|'yoast'|'none'` (setting `seo_plugin` can force one), `get( $post_id, $field )`,
  `set( $post_id, $field, $value )` for `title|description|focus_keyword` using exactly these keys (shared with the
  SEO Console web app): Yoast `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`; Rank Math
  `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` (first keyword of the comma list).
  `resolved_title( $post_id )` returns what the SEO plugin would output (template variables replaced when the plugin
  offers a function for it; otherwise the raw value).
- **CachePlugins** — `detect(): array` of `{ id: 'litespeed'|'wp-rocket'|'sg-optimizer'|'w3tc'|'wp-super-cache'|'wp-fastest-cache', name, version, page_cache: bool }`,
  `purge_all()`, `purge_url( $url )`.
- **HelperCompat** — the SEO Console web app (artemisconsole/seo-console, Content module) sends posts through the core
  WP REST API with an Application Password and needs the SEO meta keys above in REST. This plugin replaces its
  one-file helper `seo-console-meta.php`: it registers those six keys with `show_in_rest` (string, single,
  `sanitize_text_field`, auth = `current_user_can( 'edit_post', $post_id )`) for every public post type that supports
  the editor, and `GET seo-console/v1/status` (permission `edit_posts`) returning
  `{ ok: true, version, plugin: 'seo-console', features: [...] }`. If the old helper is active, an admin notice offers
  to deactivate it.

## 10. AI (Claude)

- `AI\ClaudeClient` calls `POST https://api.anthropic.com/v1/messages` with `wp_remote_post` (no SDK: avoids bundling
  Guzzle/PSR dependencies that conflict with other plugins, and keeps PHP 7.4 support). Headers: `x-api-key`,
  `anthropic-version: 2026-10-21`, `content-type: application/json`. Never send `temperature`/`top_p`/`top_k`.
- Default model `claude-opus-5-5` (setting `ai_model`). For `claude-opus-5-5` and `claude-sonnet-5-5` the request adds
  `"fallbacks": "default"` with header `anthropic-beta: server-side-fallback-2026-07-01` so a safety decline falls back
  server-side. Always check `stop_reason` (`refusal`, `max_tokens`) before reading content.
- `AI\ClientInterface` (implemented by `ClaudeClient`) — modules depend on the interface. `structured( string $system, array $messages, array $json_schema, array $opts ): array|WP_Error` uses
  `output_config: { format: { type: 'json_schema', schema }, effort }` and returns the decoded object; the caller still
  validates every field (lengths, allowed values, strip tags). `chat( string $system, array $messages, array $opts )`
  returns text. Options: `effort` (`low` default for extraction/meta, `medium` for chat), `max_tokens` (default 16000),
  `cache_system` (bool → `cache_control: {type:'ephemeral'}` on the system block).
- Errors: 401/403 → `seoc_ai_auth`, 400 (other 4xx) → `seoc_ai_request`, 429/5xx/529 → up to 2 retries honoring
  `retry-after` (cap 10 s) then `seoc_ai_unavailable`; network → `seoc_ai_network`; request timed out →
  `seoc_ai_timeout`. Timeout 120 s. Response checks: `stop_reason` `refusal` → `seoc_ai_refusal` (data `category`),
  `max_tokens` → `seoc_ai_truncated`, structured output not decodable → `seoc_ai_invalid_json`. Also `seoc_ai_no_key`
  (no key) and `seoc_ai_budget` (data `reason`: `disabled` when the budget is 0, `exhausted`).
- `AI\Usage` records per call: model, input/output/cache tokens, estimated USD (prices per 1M tokens: Opus 5.5 $4/$20,
  Sonnet 5.5 $2/$10, Haiku 5.5 $0.10/$0.50; cache reads 10% of input). When this month's estimate reaches
  `ai_monthly_budget_usd`, calls return `seoc_ai_budget` until next month or until the budget is raised.
- **Prompt-injection rule:** site content (page HTML/text, titles, crawled data) is always wrapped as data in a tagged
  block, e.g. `<page_content>…</page_content>`, and the system prompt states it is untrusted data, not instructions.
  The model's output can only become a Suggestion (validated data for a known applier) — never code, never a direct write.

## 11. Cross-module hooks

| Hook | Type | Payload | Owner → consumer |
|---|---|---|---|
| `seoc_ai_site_context` | filter | `array $sections` (`key => markdown string`) | any module → chat/meta (adds e.g. latest audit summary, speed summary) |
| `seoc_request_ai_suggestions` | action | `array $items` (`[ 'object_type', 'object_id', 'fields' => [...] ]`), `string $origin` | audit → meta (queue AI suggestions) |
| `seoc_audit_completed` | action | `int $run_id`, `array $summary` | audit → others |
| `seoc_audit_rule_definitions` | filter | `array $definitions` (`id, severity, category, title, why, how_to_fix, fixable_by, scope, check`) | any module → audit (add/alter audit rules) |
| `seoc_speed_run_completed` | action | `int $run_id`, `array $summary` | speed → others |
| `seoc_change_applied` / `seoc_change_reverted` | action | `Change $change` | core → modules (e.g. speed re-test) |
| `seoc_dashboard_cards` | filter | `array $cards` | modules → Dashboard page |

## 12. REST API (core)

All routes under `seo-console/v1`, permission `manage_options` unless noted, JSON in/out, errors are `WP_Error`
with `seoc_*` codes and HTTP status.

| Method + route | Purpose |
|---|---|
| `GET /status` | HelperCompat (permission `edit_posts`) |
| `GET /settings`, `POST /settings` | General + per-module settings; secrets only as `{ has, masked, source }`; `POST` accepts `secrets: { claude_api_key, google_api_key }` (empty string deletes) |
| `POST /settings/test-ai` | One tiny request to verify the key + model. Body `{ model?, api_key? }`: an unsaved typed key is used for this request only (never stored, never echoed) |
| `GET /suggestions` | Query (§7.3) |
| `POST /suggestions/{id}/approve` | Body `{ value?, force? }` |
| `POST /suggestions/{id}/reject` | |
| `POST /suggestions/bulk` | Body `{ action: 'approve'|'reject', ids: [] }` |
| `GET /changes` | Paged change log, filters `module, status, object_type, object_id` |
| `POST /changes/{id}/revert` | Body `{ force? }` |
| `GET /environment` | Environment facts (see Speed module) |

Modules add their own routes under `seo-console/v1/<module>/…`.

## 13. Admin UI

- Top-level menu "SEO Console" (dashicons `dashicons-chart-area`). Core pages: Dashboard, Suggestions, Change log,
  Settings. Module pages come from `admin_pages()`: `[ 'slug' => 'seo-console-speed', 'title' => 'Speed',
  'entry' => 'speed', 'position' => 20 ]`.
- Each page renders `<div id="seoc-root" data-page="<entry>"></div>` (the React `Page` adds `.seoc-app` and the
  `wp-header-end` marker) and enqueues `build/<entry>.js` with its
  `build/<entry>.asset.php` dependencies plus a shared inline config `window.seocConfig` = `{ restNamespace,
  adminUrl, pluginUrl, version, page, modules: {id: enabled}, seoPlugin, cachePlugins, safeMode, aiConfigured }`.
- Shared components in `src/admin/shared/`: `api.js` (apiFetch wrapper with error → message), `Page` (header + notices),
  `Card`, `MetricTile`, `StatusBadge` (good/needs-work/poor/unknown), `EmptyState`, `DataHonesty` (no data / stale /
  partial labels), `SuggestionList` (current ↔ proposed, inline edit, char counters, approve/reject/bulk),
  `ChangeLogTable`, `TrendChart` (lightweight SVG line chart, no chart library).
- Visual rules: WordPress admin look (components), no global CSS; any CSS is scoped under `.seoc-app`.

## 14. Security checklist (every PR)

1. Every REST route has a real `permission_callback` (never `__return_true` except none at all), every admin action a
   nonce + capability check.
2. Every input sanitized (`sanitize_text_field`, `absint`, `esc_url_raw`, schema-validated), every output escaped
   (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_json_encode` for JSON in HTML).
3. Every SQL with variables goes through `$wpdb->prepare`; table names from a whitelist.
4. No `eval`, `create_function`, `unserialize` of external data, `extract`, backticks, `exec`/`shell_exec`, file writes
   outside `wp_upload_dir()/seo-console/`, remote code loading.
5. Outbound requests only to: the site itself (crawl, health check), `api.anthropic.com`, `www.googleapis.com`
   (PSI), `chromeuxreport.googleapis.com` (CrUX); all only after the related module is enabled; all listed in readme.txt.
6. Loopback/crawl requests: only URLs on the site's own host; `wp_safe_remote_get` for anything user-supplied.
7. Secrets never leave the server; never logged.
8. AI output is data → validated → suggestion → human approval → applier. Never executed, never written directly.

## 15. Testing

- `bin/test-db.sh` then `vendor/bin/phpunit` (WordPress test suite against MariaDB in Docker).
- `vendor/bin/phpcs` must be clean.
- `npm run build` must succeed; `bin/dev-site.sh up` gives a real WordPress at http://localhost:8080 (admin/admin).
- External APIs (Claude, PSI, CrUX) are never called in tests: use `pre_http_request` filters with fixture JSON in
  `tests/phpunit/fixtures/`.
- Each feature PR ships tests for: happy path, permission denied, invalid input, external API error, and (for appliers)
  apply → revert round trip.

## 16. REST response shapes

All dates are ISO 8601 UTC (`2026-11-06T10:00:00Z`). JSON values (`*_value`, `meta`) are decoded. Errors are
`{ code, message, data: { status, … } }`.

### Shared item shapes

**Suggestion item** (`SuggestionService::to_rest()`):
`{ id, created_at, updated_at, module, type, label, field_label, object_type, object_id, field, current_value,
proposed_value, final_value, rationale, confidence (0–1|null), source, model, status, change_id, error, created_by,
meta (object|null), object_title, edit_link, view_link }` — `label` and `field_label` are the applier's label for the field.

**Change item** (`ChangeManager::to_rest()`):
`{ id, created_at, user_id, user_name, source, module, type, label, field_label, object_type, object_id, field,
before_value, after_value, status, error, suggestion_id, batch_id, note, reverted_at, reverted_by, reverted_by_name,
can_revert, object_title, edit_link, view_link }`.

`object_title` / `edit_link` / `view_link`: post/attachment → title, edit URL, permalink (attachment file URL); term →
name, edit URL, archive URL; option/site → site name, '', home URL.

### Routes

| Route | 200 body |
|---|---|
| `GET /status` | `{ ok: true, version, plugin: 'seo-console', features: string[] }` (features: `seo_meta_rest`, `suggestions`, `change_log`, `module:<id>` per enabled module; filter `seoc_status_features`) |
| `GET /settings`, `POST /settings` | `{ general: { ai_model, ai_monthly_budget_usd, keep_data_on_uninstall, seo_plugin, safe_mode, log_level }, modules: { <id>: { enabled, …module keys } }, module_info: { <id>: { title, description } }, schema: { general: { key: schemaEntry }, modules: { <id>: { title, description, default_enabled, settings: { key: schemaEntry } } } }, secrets: { claude_api_key: { has, masked, source }, google_api_key: { has, masked, source } }, safe_mode: { on, source: 'constant'|'setting'|'off' }, ai_usage: { month, calls, input_tokens, output_tokens, cost_usd, month_cost_usd, budget_usd, remaining_usd } }` |
| `POST /settings` body | `{ general?: {…}, modules?: { <id>: {…} }, secrets?: { claude_api_key?, google_api_key? } }` — partial sections; validated first, nothing saved on error: `400 seoc_invalid_settings`, `data.errors = { 'general.ai_model': message, … }`. Empty secret string deletes it. Returns the full GET shape |
| `POST /settings/test-ai` | `{ ok: true, model, message, latency_ms }`; errors `seoc_ai_*` |
| `GET /suggestions` | `{ items: SuggestionItem[], total, page, per_page, counts: { pending, applied, rejected, failed, stale } }` + headers `X-WP-Total`, `X-WP-TotalPages`. Query: `status` (default `pending`, or `all`), `module`, `type`, `object_type`, `object_id`, `page`, `per_page` ≤ 100 |
| `POST /suggestions/{id}/approve` | `{ suggestion: SuggestionItem, change: ChangeItem }`. Errors: `409 seoc_stale` (`data.current_value`), `409 seoc_invalid_status`, `404 seoc_not_found`, `400` validation codes (suggestion stays pending), `500 seoc_health_failed` (`data.checks`, `data.change_id`; suggestion → failed) |
| `POST /suggestions/{id}/reject` | `{ suggestion: SuggestionItem }` |
| `POST /suggestions/bulk` | `{ results: [ { id, ok, change_id?, code?, message? } ] }` (approvals run as one batch with one health check) |
| `GET /changes` | `{ items: ChangeItem[], total, page, per_page }` + `X-WP-Total`, `X-WP-TotalPages`. Query: `module`, `status`, `object_type`, `object_id`, `page`, `per_page` ≤ 100 |
| `POST /changes/{id}/revert` | `{ change: ChangeItem }`. Errors: `409 seoc_conflict` (`data.current_value`, `data.after_value`), `409 seoc_not_applied`, `404 seoc_not_found`, `500 seoc_health_failed` (`data.checks`) |
| `GET /environment` | `{ facts: { php_version, wp_version, plugin_version, server_software, memory_limit, wp_memory_limit, seo_plugin, seo_plugins, cache_plugins, safe_mode, multisite }, checks: [ { id, label, value, status: 'good'|'needs-work'|'poor'|'unknown', description } ], generated_at }` (filter `seoc_environment_checks( $checks, $facts )`) |

`window.seocConfig` (§13) also carries `moduleInfo: { <id>: { title, description, page (first admin page slug|null) } }`,
`dashboardCards` (filter `seoc_dashboard_cards`), `checklist: { speedTested, auditRun }` (filter `seoc_checklist`,
default false), `googleConfigured`, `pages: [ { slug, title, entry, url } ]`, `canManage`.

### Core hooks added by the core implementation

`seoc_builtin_modules` (filter, class names), `seoc_module_toggled( $id, $enabled )`, `seoc_ensure_jobs( $plugin )`
(schedule recurring jobs; fired after activation, after a module is enabled and daily), `seoc_change_failed( $change )`,
`seoc_suggestion_created( $id, $updated )`, `seoc_suggestion_applied( $id, $change )`, `seoc_suggestion_rejected( $id )`,
`seoc_cache_purged( $ids, $url|null )`, `seoc_health_check_pre` (filter, short-circuit; useful in tests),
`seoc_safe_mode` (filter), `seoc_is_frontend_request` (filter), `seoc_cache_plugins` (filter), `seoc_time_budget` (filter).
