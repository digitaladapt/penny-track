#!/bin/sh
set -eu

echo "→ Preparing var directory…"
mkdir -p /app/var/data

echo "→ Running database migrations…"
php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "→ Starting FrankenPHP (dev mode)…"
exec frankenphp run --config /app/docker/Caddyfile
