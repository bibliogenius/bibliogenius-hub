<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create invite_tokens table for short invite links.
 *
 * Each row stores an AES-256-GCM encrypted invite payload keyed by
 * a 12-char alphanumeric token. The hub cannot read payloads without
 * knowing the token (which only appears in the URL).
 */
final class Version20260226170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create invite_tokens table for short invite links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE invite_tokens (
                token VARCHAR(12) NOT NULL PRIMARY KEY,
                encrypted_payload TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Index for TTL cleanup queries
        $this->addSql('CREATE INDEX idx_invite_tokens_created ON invite_tokens (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS invite_tokens');
    }
}
