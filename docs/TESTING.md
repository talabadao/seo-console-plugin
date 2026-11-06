# SEO Console: testing

How to run every check locally and what CI runs. Reviewers: a PR is only ready when all of this is green.

## 1. Overview

| Check | Command | CI job |
|---|---|---|
| PHP tests (WordPress loaded) | `vendor/bin/phpunit` | `phpunit` (PHP 7.4 / 8.1 / 8.3 × WP 6.5 / latest) |
| PHP tests with plugin stubs | `SEOC_TEST_STUB=rankmath vendor/bin/phpunit` (also `yoast`, `litespeed`, `wp-rocket`) | `phpunit` |
| Coding standards + PHP 7.4 compatibility | `vendor/bin/phpcs` | `phpcs` |
| Static analysis | (CI only) `vendor/bin/phpstan analyse` | `phpstan` (level 5, `phpstan.neon.dist`) |
| Admin JS | `npm run build`, `npm run lint:js`, `npm run test:js` | `js` |
| WordPress.org Plugin Check | (CI only) on the unzipped `bin/make-zip.sh` output | `plugin-check` |
| End-to-end | `npx playwright test` against `bin/dev-site.sh up` | not in CI (needs the Docker site) |

Workflow file: `.github/workflows/ci.yml`. It runs on every push and pull request.

## 2. PHP tests

```bash
bin/test-db.sh                                          # MariaDB on 127.0.0.1:3306 (Docker)
SEOC_DB_NAME=wordpress_test_<you> vendor/bin/phpunit     # pick your own DB name when several people share the server
vendor/bin/phpunit --filter ChangeManagerTest            # one class
vendor/bin/phpunit --testsuite integration
```

- Bootstrap: `tests/phpunit/bootstrap.php` loads the WordPress test suite (`wp-phpunit`) against
  `vendor/johnpbloch/wordpress-core`, then the plugin on `muplugins_loaded`. Database settings come from
  `SEOC_DB_NAME`, `SEOC_DB_HOST`, `SEOC_DB_USER`, `SEOC_DB_PASSWORD`, `SEOC_DB_PREFIX` (`tests/phpunit/wp-tests-config.php`).
  The suite drops tables: never point it at a real site.
- Each test runs in a transaction that is rolled back. `CREATE TABLE` inside a test becomes a temporary table; plugin
  tables are created once at boot (outside the transaction), so do not assume they are empty: delete rows in `set_up()`
  (the core base class does).
- Layout: `tests/phpunit/unit/` (no DB writes needed), `tests/phpunit/integration/<Area>/…Test.php`. Core tests extend
  `tests/phpunit/integration/Core/CoreTestCase.php`.

### Plugin stubs

`SEOC_TEST_STUB=<name>` loads `tests/phpunit/stubs/<name>.php` before the plugin:

| Stub | Defines |
|---|---|
| `rankmath` | `RANK_MATH_VERSION`, class `RankMath`, `RankMath\Helper::replace_vars()` (`%title%`, `%sitename%`, `%sep%`) |
| `yoast` | `WPSEO_VERSION`, `wpseo_replace_vars()` (`%%title%%`, `%%sitename%%`, `%%sep%%`) |
| `litespeed` | `LSCWP_V`, `litespeed_purge_all` / `litespeed_purge_url` actions; purges recorded in `$GLOBALS['seoc_stub_purges']` |
| `wp-rocket` | `WP_ROCKET_VERSION`, `rocket_clean_domain()`, `rocket_clean_files()`; purges recorded in `$GLOBALS['seoc_stub_purges']` |

Constants cannot be undefined, so stub-specific tests skip themselves unless their stub is loaded:
`if ( 'rankmath' !== getenv( 'SEOC_TEST_STUB' ) ) { $this->markTestSkipped(); }`. Without stubs you can still force the
SEO plugin: `Plugin::instance()->settings()->set( 'general', 'seo_plugin', 'yoast' )` makes `SeoPlugins` use Yoast keys.

### Fixtures

`tests/phpunit/fixtures/<area>/`: JSON bodies of external API responses (`fixtures/core/claude-*.json` for the
Messages API: structured, text, refusal, max_tokens, 400/401/429/529 errors) and helper classes
(`fixtures/core/FakeModule.php`, a module with a settings schema, a table and an admin page).

## 3. Mocking (no real external calls, ever)

Tests must never reach `api.anthropic.com`, Google APIs or the network. `CoreTestCase` installs a `pre_http_request`
filter that records every request in `$this->http_requests` and answers `200 <html>ok</html>` unless you set a handler:

```php
$this->http_handler = static function ( $url, $args ) {
	return CoreTestCase::response( 200, CoreTestCase::fixture( 'claude-structured.json' ) );
};
```

- **HTTP in your own base class:** `add_filter( 'pre_http_request', fn( $pre, $args, $url ) => [ 'response' => [ 'code' => 200 ], 'body' => '…', 'headers' => [] ], 10, 3 );`
- **Claude:** either mock HTTP with fixtures (tests the real client) or replace the service with a fake implementing
  `AI\ClientInterface`: `Plugin::instance()->replace_service( 'claude', $fake );` (call `reset_service( 'claude' )` in
  `tear_down`). `ClaudeClient::set_sleeper( fn( $s ) => null )` removes retry waits.
- **Health check:** every frontend-affecting change runs a loopback GET of the home page. Either let the HTTP mock answer
  200, make it fail with `CoreTestCase::break_loopback()` (HTTP 500 → auto-revert), or short-circuit:
  `add_filter( 'seoc_health_check_pre', fn() => [ 'ok' => true, 'checks' => [] ] );`.
- **Cache plugins:** `add_action( 'litespeed_purge_all', $recorder )` makes `CachePlugins` detect LiteSpeed and lets you
  count purges without a stub.
- **Action Scheduler** is loaded in tests; `Queue` methods work against the real AS tables (inside the transaction).
- **Time:** pass `$now` to `Queue::next_local_time()`; use small `TimeBudget( 0.05 )` budgets.

### What every feature PR tests (ARCHITECTURE §15)

Happy path, invalid input, permission denied (REST as subscriber → 403 and logged out → 401), external API error
(mocked), and for appliers the apply → revert round trip plus a revert conflict.

## 4. Static analysis

`phpstan.neon.dist`: level 5 on `includes/`, WordPress extension (`szepeviktor/phpstan-wordpress`), PHP 7.4 target,
plugin constants from `tests/phpstan/bootstrap.php`, signatures of third-party plugin functions from
`tests/phpstan/third-party-stubs.php`. PHPStan is installed only in CI (`composer require --dev phpstan/phpstan
szepeviktor/phpstan-wordpress`). If it fails on existing code, the job uploads a generated `phpstan-baseline.neon`
artifact you can commit and include; new code must not grow the baseline.

## 5. JavaScript

```bash
npm ci
npm run build           # src/admin/<page>/index.js → build/<page>.js + .asset.php + .css
npm run lint:js
npm run test:js         # = wp-scripts test-unit-js tests/js (Jest)
```

## 6. End-to-end (Playwright)

```bash
bin/dev-site.sh up      # WordPress on http://localhost:8080 (admin / admin), plugin folder mounted
npx playwright test     # config: playwright.config.js, specs: tests/e2e/
WP_BASE_URL=http://localhost:8888 npx playwright test   # another site
```

- `tests/e2e/helpers.js`: `login()`, `collectErrors()` (console/page errors; ignores `net::ERR_*` errors for blocked
  external assets), `rest()` (calls the REST API with the logged-in nonce), `wpCli()` (runs `bin/dev-site.sh wp …`;
  override with `SEOC_WP_CLI="…"`).
- Specs restore what they change (settings, keys, seeded posts).
- Inside the Docker site, `http://localhost:8080` is not reachable from PHP (Apache listens on 80), so loopback
  requests (SEO Console's health check, Site Health) would fail and every frontend-affecting approval would be
  auto-reverted. `dev/mu-plugins/seoc-dev-loopback.php` (only when `WP_ENVIRONMENT_TYPE` is `local`) re-routes them to
  port 80 with the original `Host` header.
- If the bundled Playwright browser is missing, the config uses `/opt/pw-browsers/chromium` when it exists, or set
  `PW_CHROMIUM_PATH`.

## 7. Plugin Check and the release zip

`bin/make-zip.sh` builds `dist/seo-console-<version>.zip` (production vendor, built JS, no tests/dev files). CI unzips
it and runs `WordPress/plugin-check-action@v1` on it. Locally you can install the zip on the dev site and run
`bin/dev-site.sh wp plugin check seo-console` if the Plugin Check plugin is installed there.
