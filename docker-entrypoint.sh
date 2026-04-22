#!/bin/bash
set -e

ENV=${APP_ENV:-prod}

# Ensure var directory exists with proper permissions
mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var

# Wait for PostgreSQL to be ready (max 30s)
echo "Waiting for database..."
for i in $(seq 1 30); do
    php /app/bin/console dbal:run-sql "SELECT 1" --env=$ENV --no-interaction > /dev/null 2>&1 && break
    echo "  attempt $i/30..."
    sleep 1
done

# Create missing tables (idempotent - doctrine:schema:update removed in ORM 3.0)
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS library_profiles (node_id VARCHAR(128) NOT NULL, write_token VARCHAR(64) NOT NULL, display_name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, book_count INTEGER NOT NULL DEFAULT 0, location_country VARCHAR(5) DEFAULT NULL, requires_approval BOOLEAN NOT NULL DEFAULT TRUE, accept_from VARCHAR(20) NOT NULL DEFAULT 'everyone', is_listed BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (node_id))" --env=$ENV --no-interaction || echo "WARNING: library_profiles creation failed"

php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS cached_catalogs (node_id VARCHAR(128) NOT NULL, isbn_payload TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (node_id), CONSTRAINT fk_cached_catalogs_profile FOREIGN KEY (node_id) REFERENCES library_profiles(node_id) ON DELETE CASCADE)" --env=$ENV --no-interaction || echo "WARNING: cached_catalogs creation failed"

php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS follows (id SERIAL NOT NULL, follower_node_id VARCHAR(128) NOT NULL, followed_node_id VARCHAR(128) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT unique_follow UNIQUE (follower_node_id, followed_node_id))" --env=$ENV --no-interaction || echo "WARNING: follows creation failed"

php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_library_profiles_listed ON library_profiles (is_listed)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_library_profiles_last_seen ON library_profiles (last_seen_at)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_cached_catalogs_expires ON cached_catalogs (expires_at)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_follows_followed_status ON follows (followed_node_id, status)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_follows_follower_status ON follows (follower_node_id, status)" --env=$ENV --no-interaction || true

# relay_mailboxes and relay_messages (Version20260220170000 - E2EE relay)
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS relay_mailboxes (uuid VARCHAR(36) NOT NULL PRIMARY KEY, read_token VARCHAR(64) NOT NULL, write_token VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP, last_accessed TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)" --env=$ENV --no-interaction || echo "WARNING: relay_mailboxes creation failed"

php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS relay_messages (id SERIAL NOT NULL PRIMARY KEY, mailbox_uuid VARCHAR(36) NOT NULL, blob BYTEA NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_relay_messages_mailbox FOREIGN KEY (mailbox_uuid) REFERENCES relay_mailboxes(uuid) ON DELETE CASCADE)" --env=$ENV --no-interaction || echo "WARNING: relay_messages creation failed"

php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_relay_messages_mailbox ON relay_messages (mailbox_uuid)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_relay_messages_created ON relay_messages (created_at)" --env=$ENV --no-interaction || true

# invite_tokens (Version20260226170000 - short invite links)
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS invite_tokens (token VARCHAR(12) NOT NULL PRIMARY KEY, encrypted_payload TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP)" --env=$ENV --no-interaction || echo "WARNING: invite_tokens creation failed"
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_invite_tokens_created ON invite_tokens (created_at)" --env=$ENV --no-interaction || true

# catalog_payload enriched column (Version20260302000000)
php /app/bin/console dbal:run-sql "ALTER TABLE cached_catalogs ADD COLUMN IF NOT EXISTS catalog_payload TEXT DEFAULT NULL" --env=$ENV --no-interaction || true

# view_count + cooldowns (Version20260303100000 - library view counter)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS view_count INTEGER NOT NULL DEFAULT 0" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS library_view_cooldowns (profile_node_id VARCHAR(128) NOT NULL, visitor_id VARCHAR(128) NOT NULL, last_counted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (profile_node_id, visitor_id))" --env=$ENV --no-interaction || echo "WARNING: library_view_cooldowns creation failed"

# borrow_requests (ADR-018 - borrowing via hub)
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS borrow_requests (id SERIAL NOT NULL PRIMARY KEY, requester_node_id VARCHAR(128) NOT NULL, lender_node_id VARCHAR(128) NOT NULL, isbn VARCHAR(20) NOT NULL, book_title VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)" --env=$ENV --no-interaction || echo "WARNING: borrow_requests creation failed"
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_borrow_req_lender ON borrow_requests (lender_node_id, status)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_borrow_req_requester ON borrow_requests (requester_node_id, status)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_borrow_req_expires ON borrow_requests (expires_at)" --env=$ENV --no-interaction || true

# allow_borrowing toggle (Version20260304130000)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS allow_borrowing BOOLEAN NOT NULL DEFAULT TRUE" --env=$ENV --no-interaction || true

# x25519 public key + website on profiles, encrypted_contact on follows (Version20260304200000 - E2EE contact sharing)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS x25519_public_key VARCHAR(64) DEFAULT NULL" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS website VARCHAR(255) DEFAULT NULL" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "ALTER TABLE follows ADD COLUMN IF NOT EXISTS encrypted_contact TEXT DEFAULT NULL" --env=$ENV --no-interaction || true

# registration_failures (Version20260316120000 - 401 logging for admin BO)
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS registration_failures (id SERIAL NOT NULL PRIMARY KEY, node_id VARCHAR(128) NOT NULL, display_name VARCHAR(255) NOT NULL, book_count INTEGER NOT NULL DEFAULT 0, client_ip VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP)" --env=$ENV --no-interaction || echo "WARNING: registration_failures creation failed"
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_reg_failures_node ON registration_failures (node_id)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_reg_failures_created ON registration_failures (created_at)" --env=$ENV --no-interaction || true

# admin user table (required for /admin/login)
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS \"user\" (id SERIAL NOT NULL PRIMARY KEY, email VARCHAR(180) NOT NULL, roles JSON NOT NULL DEFAULT '[]', password VARCHAR(255) NOT NULL, CONSTRAINT UNIQ_IDENTIFIER_EMAIL UNIQUE (email))" --env=$ENV --no-interaction || echo "WARNING: user creation failed"

# device_model + device_fingerprint on profiles (Version20260316130000 - duplicate detection)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS device_model VARCHAR(255) DEFAULT NULL" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS device_fingerprint VARCHAR(128) DEFAULT NULL" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_library_profiles_fingerprint ON library_profiles (device_fingerprint)" --env=$ENV --no-interaction || true

# relay credentials on profiles (Version20260319120000 - relay-only peer recovery)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS relay_url VARCHAR(255) DEFAULT NULL" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS relay_mailbox_id VARCHAR(128) DEFAULT NULL" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS relay_write_token VARCHAR(128) DEFAULT NULL" --env=$ENV --no-interaction || true

# avatar_config on profiles (Version20260403100000 - relay-only peer avatar display)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS avatar_config TEXT DEFAULT NULL" --env=$ENV --no-interaction || true

# recovery_code_hash on profiles (Version20260404100000 - post-reinstall identity recovery)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS recovery_code_hash VARCHAR(64) DEFAULT NULL" --env=$ENV --no-interaction || true

# catalog_hash on cached_catalogs (Version20260414120000 - ADR-027 diff-based push)
php /app/bin/console dbal:run-sql "ALTER TABLE cached_catalogs ADD COLUMN IF NOT EXISTS catalog_hash VARCHAR(64) DEFAULT NULL" --env=$ENV --no-interaction || true

# app_version on profiles (Version20260415120000 - client version diagnostics)
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS app_version VARCHAR(32) DEFAULT NULL" --env=$ENV --no-interaction || true

# app_version on registration_failures (Version20260415130000 - per-version failure diagnostics)
php /app/bin/console dbal:run-sql "ALTER TABLE registration_failures ADD COLUMN IF NOT EXISTS app_version VARCHAR(32) DEFAULT NULL" --env=$ENV --no-interaction || true

# hub_events for BO monitoring (Version20260319130000)
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS hub_events (id SERIAL PRIMARY KEY, level VARCHAR(10) NOT NULL, channel VARCHAR(30) NOT NULL, message VARCHAR(500) NOT NULL, context TEXT DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT NOW())" --env=$ENV --no-interaction || echo "WARNING: hub_events creation failed"
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_hub_events_created ON hub_events (created_at DESC)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_hub_events_channel ON hub_events (channel)" --env=$ENV --no-interaction || true

# Mailbox ownership enforcement (Version20260421120000 - ADR-031)
# owner_node_id claim-on-first-reference + monotonic hijack counter.
# Backfill is idempotent via the WHERE owner_node_id IS NULL guard.
php /app/bin/console dbal:run-sql "ALTER TABLE relay_mailboxes ADD COLUMN IF NOT EXISTS owner_node_id VARCHAR(128) DEFAULT NULL" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_relay_mailboxes_owner_node_id ON relay_mailboxes (owner_node_id)" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS hijack_attempts_total INTEGER NOT NULL DEFAULT 0" --env=$ENV --no-interaction || true
php /app/bin/console dbal:run-sql "UPDATE relay_mailboxes rm SET owner_node_id = lp.node_id FROM library_profiles lp WHERE rm.owner_node_id IS NULL AND lp.relay_mailbox_id IS NOT NULL AND LOWER(rm.uuid) = LOWER(lp.relay_mailbox_id)" --env=$ENV --no-interaction || true

# deposit_404_log (Version20260422120000 - aggregated deposit-404 counter)
# Replaces the per-event warning rows that flooded hub_events (~80% of rows).
php /app/bin/console dbal:run-sql "CREATE TABLE IF NOT EXISTS deposit_404_log (mailbox_uuid VARCHAR(36) NOT NULL, hour_bucket TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, count INTEGER NOT NULL DEFAULT 1, first_seen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), last_seen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), PRIMARY KEY (mailbox_uuid, hour_bucket))" --env=$ENV --no-interaction || echo "WARNING: deposit_404_log creation failed"
php /app/bin/console dbal:run-sql "CREATE INDEX IF NOT EXISTS idx_deposit_404_log_hour_bucket ON deposit_404_log (hour_bucket DESC)" --env=$ENV --no-interaction || true

# Clear and warm up cache
php /app/bin/console cache:clear --env=$ENV --no-debug || true
php /app/bin/console cache:warmup --env=$ENV || true

# Start FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile
