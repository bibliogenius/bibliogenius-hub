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

# Create missing tables (idempotent - doctrine:schema:update removed in ORM 3.0)
php /app/bin/console dbal:run-sql \
    "CREATE TABLE IF NOT EXISTS library_profiles (
        node_id         VARCHAR(128) NOT NULL,
        write_token     VARCHAR(64)  NOT NULL,
        display_name    VARCHAR(255) NOT NULL,
        description     TEXT         DEFAULT NULL,
        book_count      INTEGER      NOT NULL DEFAULT 0,
        location_country VARCHAR(5)  DEFAULT NULL,
        requires_approval BOOLEAN    NOT NULL DEFAULT TRUE,
        accept_from     VARCHAR(20)  NOT NULL DEFAULT 'everyone',
        is_listed       BOOLEAN      NOT NULL DEFAULT FALSE,
        created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
        last_seen_at    TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
        PRIMARY KEY (node_id)
    )" --env=prod --no-interaction

php /app/bin/console dbal:run-sql \
    "CREATE TABLE IF NOT EXISTS cached_catalogs (
        node_id      VARCHAR(128) NOT NULL,
        isbn_payload TEXT         NOT NULL,
        updated_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
        expires_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
        PRIMARY KEY (node_id),
        CONSTRAINT fk_cached_catalogs_profile
            FOREIGN KEY (node_id) REFERENCES library_profiles(node_id) ON DELETE CASCADE
    )" --env=prod --no-interaction

php /app/bin/console dbal:run-sql \
    "CREATE TABLE IF NOT EXISTS follows (
        id               SERIAL       NOT NULL,
        follower_node_id VARCHAR(128) NOT NULL,
        followed_node_id VARCHAR(128) NOT NULL,
        status           VARCHAR(20)  NOT NULL DEFAULT 'pending',
        created_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
        resolved_at      TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
        PRIMARY KEY (id),
        CONSTRAINT unique_follow UNIQUE (follower_node_id, followed_node_id)
    )" --env=prod --no-interaction

# Indexes (idempotent via IF NOT EXISTS)
php /app/bin/console dbal:run-sql \
    "CREATE INDEX IF NOT EXISTS idx_library_profiles_listed  ON library_profiles (is_listed);
     CREATE INDEX IF NOT EXISTS idx_library_profiles_last_seen ON library_profiles (last_seen_at);
     CREATE INDEX IF NOT EXISTS idx_cached_catalogs_expires  ON cached_catalogs (expires_at);
     CREATE INDEX IF NOT EXISTS idx_follows_followed_status  ON follows (followed_node_id, status);
     CREATE INDEX IF NOT EXISTS idx_follows_follower_status  ON follows (follower_node_id, status)" \
    --env=prod --no-interaction

# Clear and warm up cache
php /app/bin/console cache:clear --env=prod --no-debug || true
php /app/bin/console cache:warmup --env=prod || true

# Start FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile
