<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Account E2EE sync - blind per-account encrypted blob store (ADR-043, ST-04).
 *
 * The hub moves from an ephemeral relay (relay_mailboxes/relay_messages) to a
 * durable per-account store while staying content-blind: it never decrypts a
 * blob, never reads entity type, never reads device authorization.
 *
 * State is a set of lanes keyed by (account_id, opaque_id, device_id), each
 * holding one current ciphertext rewritten in place (PK = the lane, so no
 * history accumulates). The hub assigns a per-account monotonic change_seq to
 * every write; that is the pull cursor and the only ordering signal exposed.
 * The application HLC stays inside the ciphertext (ADR-042 section 14 / H5).
 *
 * What is deliberately NOT a clear column: entity_type (M1/section 6),
 * version_hlc (H5), any device authorized/revoked flag (H3 - the device
 * registry is an opaque signed blob enforced client-side).
 *
 * Mirrored in docker-entrypoint.sh (dual migration system - required, else
 * prod 500s). PostgreSQL syntax; idempotent CREATE ... IF NOT EXISTS.
 */
final class Version20260625120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account E2EE sync store: accounts, account_entities (lanes), wrapped keys, device registry, auth challenges (ADR-043)';
    }

    public function up(Schema $schema): void
    {
        // accounts - identity, Ed25519 auth, KDF params, pinned version triple
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS accounts (
                account_id VARCHAR(64) NOT NULL,
                email VARCHAR(255) NOT NULL,
                account_salt VARCHAR(64) NOT NULL,
                kdf_params TEXT NOT NULL,
                account_auth_pk VARCHAR(64) NOT NULL,
                auth_verifier_hash VARCHAR(128) NOT NULL,
                schema_version INTEGER NOT NULL,
                auth_method VARCHAR(32) NOT NULL,
                aead_alg VARCHAR(32) NOT NULL,
                descriptor_sig VARCHAR(128) NOT NULL,
                change_counter BIGINT NOT NULL DEFAULT 0,
                quota_bytes_used BIGINT NOT NULL DEFAULT 0,
                quota_bytes_limit BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (account_id),
                CONSTRAINT uniq_accounts_email UNIQUE (email)
            )
        SQL);

        // Per-account-global monotonic ordering source for the pull cursor.
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS account_entities_change_seq');

        // account_entities - the lane store. PK = the lane => overwrite-in-place.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS account_entities (
                account_id VARCHAR(64) NOT NULL,
                opaque_id VARCHAR(64) NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                change_seq BIGINT NOT NULL,
                deleted BOOLEAN NOT NULL DEFAULT FALSE,
                size_bucket INTEGER NOT NULL,
                blob BYTEA DEFAULT NULL,
                received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                tombstoned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (account_id, opaque_id, device_id),
                CONSTRAINT fk_account_entities_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_account_entities_cursor ON account_entities (account_id, change_seq)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_account_entities_tomb ON account_entities (tombstoned_at)');

        // wrapped_account_keys - wrapped AccountKeyBundle copies (opaque to hub)
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wrapped_account_keys (
                account_id VARCHAR(64) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                blob BYTEA NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (account_id, kind),
                CONSTRAINT fk_wrapped_keys_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
            )
        SQL);

        // account_device_registry - signed+encrypted, opaque to the hub (H3)
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS account_device_registry (
                account_id VARCHAR(64) NOT NULL,
                blob BYTEA NOT NULL,
                registry_seq BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (account_id),
                CONSTRAINT fk_device_registry_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
            )
        SQL);

        // account_auth_challenges - one-time login/keybundle nonces with TTL
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS account_auth_challenges (
                id SERIAL NOT NULL,
                account_id VARCHAR(64) NOT NULL,
                challenge VARCHAR(64) NOT NULL,
                purpose VARCHAR(16) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_auth_chal_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_account_auth_chal_expires ON account_auth_challenges (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS account_auth_challenges');
        $this->addSql('DROP TABLE IF EXISTS account_device_registry');
        $this->addSql('DROP TABLE IF EXISTS wrapped_account_keys');
        $this->addSql('DROP TABLE IF EXISTS account_entities');
        $this->addSql('DROP SEQUENCE IF EXISTS account_entities_change_seq');
        $this->addSql('DROP TABLE IF EXISTS accounts');
    }
}
