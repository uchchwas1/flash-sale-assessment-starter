#!/bin/sh
# Container startup: prepare env, wait for MySQL, migrate + seed, then serve.
set -e

# Ensure a .env exists and has an app key (env vars from compose override it).
[ -f .env ] || cp .env.example .env
php artisan key:generate --force --no-interaction

# Wait for MySQL to accept connections, then migrate + seed the flash-sale item.
echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT} ..."
until php artisan migrate:fresh --seed --force 2>/dev/null; do
  echo "  ...database not ready, retrying in 2s"
  sleep 2
done
echo "Database ready and seeded (item 1, stock 10)."

# Real parallel workers are required for the concurrency test to be meaningful
# (PHP_CLI_SERVER_WORKERS is only honored together with --no-reload).
exec env PHP_CLI_SERVER_WORKERS=10 php artisan serve --host=0.0.0.0 --port=8000 --no-reload
