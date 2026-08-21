<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pooled discovery cache (ADR-060 external discovery resolver).
 *
 * Keyed by bibliographic identity only (kind + cache_key), shared by all
 * users: '*_lookup' rows map one ISBN-13 to entity ids, entity rows hold
 * the full language-neutral resolved payload. TTLs live per row in
 * expires_at (30 days resolved, 7 days negative); the nightly app:db:prune
 * sweeps expired rows.
 *
 * Mirrored in docker-entrypoint.sh.
 */
final class Version20260821060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create discovery_cache (ADR-060 external discovery resolver)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS discovery_cache (
                kind VARCHAR(16) NOT NULL,
                cache_key VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL,
                payload TEXT DEFAULT NULL,
                source VARCHAR(32) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (kind, cache_key)
            )
        SQL);

        // Supports the nightly prune (expires_at < NOW()) and the serve-time
        // freshness check.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_discovery_cache_expires ON discovery_cache (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_discovery_cache_expires');
        $this->addSql('DROP TABLE IF EXISTS discovery_cache');
    }
}
