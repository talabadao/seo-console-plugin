#!/usr/bin/env bash
# Starts the local WordPress (dev/docker-compose.yml), installs it once and activates SEO Console.
# Usage: bin/dev-site.sh [up|down|wp <args>]
set -euo pipefail
cd "$(dirname "$0")/../dev"
cmd="${1:-up}"
case "$cmd" in
  up)
    docker compose up -d db wordpress
    for i in $(seq 1 60); do curl -s -o /dev/null http://localhost:8080/ && break; sleep 2; done
    if ! docker compose run --rm cli core is-installed >/dev/null 2>&1; then
      docker compose run --rm cli core install --url=http://localhost:8080 --title="SEO Console Dev" \
        --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email
      docker compose run --rm cli rewrite structure '/%postname%/'
    fi
    docker compose run --rm cli plugin activate seo-console
    echo "WordPress ready: http://localhost:8080/wp-admin (admin / admin)"
    ;;
  down) docker compose down -v ;;
  wp) shift; docker compose run --rm cli "$@" ;;
  *) echo "usage: $0 [up|down|wp ...]" >&2; exit 1 ;;
esac
