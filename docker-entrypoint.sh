#!/bin/bash
set -e

# Ensure var directory exists with proper permissions
mkdir -p /app/var/cache /app/var/log /app/var/data
chmod -R 777 /app/var

# Create database and run migrations if needed
php /app/bin/console doctrine:database:create --if-not-exists --env=prod --no-interaction 2>/dev/null || true
php /app/bin/console doctrine:schema:update --force --env=prod --no-interaction 2>/dev/null || true

# Clear cache for production
php /app/bin/console cache:clear --env=prod --no-debug 2>/dev/null || true
php /app/bin/console cache:warmup --env=prod 2>/dev/null || true

# Start FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile
