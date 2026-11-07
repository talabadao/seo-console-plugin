#!/usr/bin/env bash
# Builds an installable dist/seo-console-<version>.zip (production vendor + built JS only).
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
version=$(grep -m1 "Version:" "$root/seo-console.php" | awk '{print $NF}')
stage="$(mktemp -d)/seo-console"
mkdir -p "$stage" "$root/dist"
cd "$root"
npm run build >/dev/null
git ls-files -co --exclude-standard | grep -vE '^(tests|bin|docs|src|dev|\.github|node_modules)/|^(phpunit|phpcs)\.xml\.dist$|^phpstan\.neon\.dist$|^package(-lock)?\.json$|^playwright\.config|^webpack\.config\.js$|^CLAUDE\.md$|^AGENTS\.md$|^\.' | while read -r f; do
  [ -f "$f" ] && mkdir -p "$stage/$(dirname "$f")" && cp "$f" "$stage/$f"
done
cp -r build "$stage/build"
cp composer.json composer.lock "$stage/"
(cd "$stage" && composer install --no-dev --prefer-source --no-interaction --quiet --optimize-autoloader && rm -f composer.lock)
# Ship only runtime files of bundled libraries: no VCS metadata, tests, docs or dev tooling (WordPress.org Plugin Check flags them).
find "$stage/vendor" -mindepth 3 -maxdepth 3 \( -name '.git' -o -name '.github' -o -name 'tests' -o -name 'docs' -o -name 'bin' \) -prune -exec rm -rf {} +
find "$stage/vendor" -mindepth 3 -maxdepth 3 -type f \( -name '.*' -o -name 'package.json' -o -name 'package-lock.json' -o -name 'composer.lock' -o -name 'phpunit*' -o -name 'phpcs*' -o -name '*.config.js' -o -name 'Gruntfile.js' \) -delete
(cd "$(dirname "$stage")" && rm -f "$root/dist/seo-console-$version.zip" && zip -qr "$root/dist/seo-console-$version.zip" seo-console)
echo "$root/dist/seo-console-$version.zip"
