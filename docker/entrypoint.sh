#!/bin/sh
set -eu

echo "→ Preparing var directory…"
mkdir -p /app/var/data

echo "→ Running database migrations…"
php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "→ Warming cache…"
php /app/bin/console cache:warm --env=prod

echo "→ Starting parse job worker (MAX_BACKGROUND_JOBS=${MAX_BACKGROUND_JOBS:-2})…"
php /app/bin/console app:parse-jobs:worker &

echo "→ Starting FrankenPHP…"
exec frankenphp run --config /app/docker/Caddyfile
