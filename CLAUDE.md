# SEO Console (WordPress plugin) — instructions for agents

Read `docs/ARCHITECTURE.md` before writing code: it is the contract (naming, module interface, change/suggestion
flow, security rules). Product plan in Vietnamese: `docs/PLAN.vi.md`.

## Commands

```bash
composer install --prefer-source   # dist downloads from api.github.com are blocked in cloud sessions
bin/test-db.sh                     # throwaway MariaDB for PHPUnit (needs dockerd running)
vendor/bin/phpunit                 # all tests (WordPress loaded)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpcs                   # WordPress Coding Standards + PHP 7.4 compatibility
npm run build                      # admin JS → build/
bin/dev-site.sh up                 # real WordPress on http://localhost:8080 (admin/admin) with the plugin mounted
bin/make-zip.sh                    # installable dist/seo-console-<version>.zip
```

In a cloud session Docker's daemon may need starting first: `nohup dockerd >/tmp/dockerd.log 2>&1 &`.

## Rules

- PHP 7.4 syntax only. WordPress Coding Standards. Everything prefixed (`seoc_`, `SEOConsole\`).
- Never weaken a security rule in ARCHITECTURE.md §14 to make something work.
- No new Composer runtime dependencies (only Action Scheduler). JS dependencies only from `@wordpress/*`.
- Never call real external APIs in tests; mock with `pre_http_request` + fixtures.
- Stay inside the files your task owns; if you need a change in a shared file (core, shared JS, this file,
  ARCHITECTURE.md), keep it minimal and say so in your final report.
- Commit in small, working steps on your own branch. Run phpunit + phpcs before each commit.
