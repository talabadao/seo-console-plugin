#!/usr/bin/env bash
# Starts (or reuses) the throwaway MariaDB used by PHPUnit on 127.0.0.1:3306.
set -euo pipefail
if ! docker info >/dev/null 2>&1; then
  echo "Docker daemon not running. In a cloud session start it with: nohup dockerd >/tmp/dockerd.log 2>&1 &" >&2
  exit 1
fi
if [ -z "$(docker ps -q -f name=^seoc-db$)" ]; then
  if [ -n "$(docker ps -aq -f name=^seoc-db$)" ]; then docker start seoc-db >/dev/null;
  else docker run -d --name seoc-db -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress_test -p 3306:3306 mariadb:11 >/dev/null; fi
fi
for i in $(seq 1 30); do
  php -r 'exit(@(new mysqli("127.0.0.1","root","root","wordpress_test",3306))->connect_errno ? 1 : 0);' 2>/dev/null && { echo "test DB ready"; exit 0; }
  sleep 1
done
echo "test DB did not start" >&2; exit 1
