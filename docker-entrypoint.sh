#!/bin/bash
set -e

# Ensure var directory exists with proper permissions
mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var

# Wait for PostgreSQL to be ready (max 30s)
echo "Waiting for database..."
for i in $(seq 1 30); do
    php /app/bin/console dbal:run-sql "SELECT 1" --env=prod --no-interaction > /dev/null 2>&1 && break
    echo "  attempt $i/30..."
    sleep 1
done

# Apply schema (creates or updates tables from entity definitions)
php /app/bin/console doctrine:schema:update --force --env=prod --no-interaction 2>/dev/null || true

# Clear cache for production
php /app/bin/console cache:clear --env=prod --no-debug 2>/dev/null || true
php /app/bin/console cache:warmup --env=prod 2>/dev/null || true

# Start FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile
