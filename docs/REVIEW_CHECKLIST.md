# SEO Console: review checklist (per PR)

Copy this into the review and tick every line, or write why it does not apply. A PR with an unticked **Security** or
**Tests** line is not mergeable. References: `docs/ARCHITECTURE.md` (contract), `docs/SECURITY.md` (threat model),
`docs/TESTING.md` (how to run and mock).

## 0. Scope and contract
- [ ] The PR only touches files its task owns; shared files (Plugin, Core, shared JS, ARCHITECTURE.md, CLAUDE.md) have
      minimal, explained changes.
- [ ] Names follow §2: `SEOConsole\` namespace, `seoc_` options/hooks/transients/tables, `_seoc_` hidden meta, `SEOC_`
      constants, REST under `seo-console/v1/<module>/…`, AS hooks `seoc_<module>_<job>` in group `seo-console`.
- [ ] Any new or changed REST response shape, hook, setting or table is documented in ARCHITECTURE.md (REST response
      shapes section included).
- [ ] Modules talk to each other only through the hooks in §11, never by calling another module's classes.

## 1. Security (§14)
- [ ] Every `register_rest_route` has a real `permission_callback` (`manage_options` unless the contract says otherwise);
      never `__return_true`.
- [ ] Every route declares `args` with `type`/`enum`/`minimum`/`maximum`/`maxLength`; no unvalidated `$request` fields.
      (Note: a custom `sanitize_callback` disables WordPress's schema validation for that arg; prefer schema types.)
- [ ] Admin actions outside REST (if any) check a nonce and the capability.
- [ ] Input sanitized (`sanitize_text_field`, `sanitize_textarea_field`, `absint`, `esc_url_raw`, `sanitize_key`) or
      validated by `Settings`/an applier.
- [ ] Output escaped at the point of output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_json_encode` for
      JSON in HTML/inline scripts. JSON-LD uses `wp_json_encode` with `JSON_HEX_TAG` style escaping (no `</script>`).
- [ ] SQL with variables uses `$wpdb->prepare`; table names come from a whitelist (`Schema::table()`, module constants),
      never from input. `IN (...)` lists are built with placeholders.
- [ ] No `eval`, `create_function`, `unserialize` of external data, `extract`, backticks, `exec`/`shell_exec`/`system`,
      `include` of variable paths, remote code/assets; file writes only under `wp_upload_dir()/seo-console/`.
- [ ] Outbound HTTP only to the site itself, `api.anthropic.com`, `www.googleapis.com`, `chromeuxreport.googleapis.com`;
      only after the module is enabled; listed in `readme.txt` "External services". User-supplied URLs use
      `wp_safe_remote_get` and are restricted to the site's host where they are crawled.
- [ ] Secrets only through `Secrets`; never in REST output, `window.seocConfig`, logs, error messages or exceptions.
- [ ] AI: site content wrapped with `ContextBuilder::wrap()`, system prompt includes `untrusted_notice()`; output goes
      through a JSON schema, is re-validated field by field, and only ever becomes a Suggestion. No AI output is
      written to the site without human approval.
- [ ] Site changes go through `ChangeManager` (an applier), so they are logged, health-checked and undoable. Appliers
      whitelist the keys/objects they write and validate values.

## 2. PHP compatibility and standards
- [ ] PHP 7.4 syntax: no `match`, enums, `readonly`, named arguments, union/intersection types in signatures, `mixed`/
      `static` return types, nullsafe `?->`, constructor promotion, `str_contains`/`str_starts_with` without polyfill.
- [ ] Works on WordPress 6.5 (no functions newer than 6.5 without `function_exists`).
- [ ] `vendor/bin/phpcs` clean (errors **and** warnings).
- [ ] No new Composer runtime dependencies; JS dependencies only `@wordpress/*`.

## 3. i18n
- [ ] Every user-facing PHP string uses `__()`/`esc_html__()`/`_n()` with text domain `seo-console`; JS strings use
      `@wordpress/i18n` with `seo-console`.
- [ ] Placeholders use `sprintf` with a `/* translators: */` comment; no string concatenation of translated fragments
      that breaks word order.
- [ ] UI language is English and plain (written for a non-technical site owner).

## 4. REST behaviour
- [ ] Errors are `WP_Error` with a `seoc_*` code and an HTTP status in `data.status`; conflict/stale/health errors carry
      the documented data (`current_value`, `checks`).
- [ ] Lists return `{ items, total }` (+ `X-WP-Total`), `per_page` ≤ 100.
- [ ] Permission tests exist for subscriber (403) and logged-out (401).

## 5. Tests
- [ ] Happy path, invalid input, permission denied, external API error (mocked), and for appliers apply → revert
      round trip + revert conflict.
- [ ] No real external HTTP: `pre_http_request` + fixtures; loopback health check mocked or short-circuited.
- [ ] Tests do not depend on order or on leftover rows (clean tables in `set_up()`).
- [ ] `vendor/bin/phpunit` green, plus the stub runs when the PR touches SEO/cache integrations.
- [ ] JS: `npm run build`, `npm run lint:js`, `npm run test:js` green for UI changes.

## 6. UI states (admin pages)
- [ ] Loading, empty ("nothing yet, here is what to do"), error (message + retry), partial/stale data labelled
      (`DataHonesty`), success feedback.
- [ ] Works without the module's API key (explains what to do) and in Safe Mode.
- [ ] Destructive actions confirm; Undo is offered where a change was made.
- [ ] Accessible: labels on inputs, buttons are buttons, focus visible, status not by colour alone, live regions for
      async results.
- [ ] CSS scoped under `.seoc-app`; assets load only on SEO Console pages.

## 7. Performance on shared hosting
- [ ] No heavy work in a web request: crawls, AI batches, speed tests run in Action Scheduler jobs with `TimeBudget`
      (≤ 20 s per batch) and re-enqueue themselves; jobs are idempotent.
- [ ] Nothing expensive on every page load: no remote calls, no unbounded queries, no `autoload` of large options;
      frontend hooks only when the module is enabled and Safe Mode is off.
- [ ] Queries are indexed and paginated; no `SELECT *` over unbounded tables in loops; memory stays well under 128 MB.
- [ ] Long-running state lives in the module's tables, not transients.
- [ ] Recurring jobs are scheduled from `seoc_ensure_jobs` (idempotent) and removed in `on_disable()`.

## 8. Release hygiene
- [ ] `readme.txt` updated if behaviour visible to users or external services changed.
- [ ] Uninstall removes the module's tables/options (core removes `{prefix}seoc_*` tables, `seoc_*` options, `_seoc_*`
      meta automatically; anything else needs handling).
- [ ] Plugin Check job green.
