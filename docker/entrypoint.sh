#!/bin/sh
set -eu

echo "→ Preparing var directory…"
mkdir -p /app/var/data

echo "→ Running database migrations…"
php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "→ Warming cache…"
php /app/bin/console cache:warm --env=prod

echo "→ Starting FrankenPHP…"
exec frankenphp run --config /app/docker/Caddyfile
