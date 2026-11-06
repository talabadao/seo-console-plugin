#!/usr/bin/env bash
# Builds an installable dist/seo-console-<version>.zip (production vendor + built JS only).
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
version=$(grep -m1 "Version:" "$root/seo-console.php" | awk '{print $NF}')
stage="$(mktemp -d)/seo-console"
mkdir -p "$stage" "$root/dist"
cd "$root"
npm run build >/dev/null
git ls-files -co --exclude-standard | grep -vE '^(tests|bin|docs|src|dev|\.github|node_modules)/|^(phpunit|phpcs)\.xml\.dist$|^package(-lock)?\.json$|^playwright\.config|^CLAUDE\.md$|^AGENTS\.md$|^\.' | while read -r f; do
  [ -f "$f" ] && mkdir -p "$stage/$(dirname "$f")" && cp "$f" "$stage/$f"
done
cp -r build "$stage/build"
cp composer.json composer.lock "$stage/"
(cd "$stage" && composer install --no-dev --prefer-source --no-interaction --quiet --optimize-autoloader && rm -f composer.json composer.lock)
(cd "$(dirname "$stage")" && rm -f "$root/dist/seo-console-$version.zip" && zip -qr "$root/dist/seo-console-$version.zip" seo-console)
echo "$root/dist/seo-console-$version.zip"
