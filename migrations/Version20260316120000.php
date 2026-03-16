<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Log failed hub directory registrations (401 - write_token mismatch).
 * Allows admins to identify stuck libraries and manually unblock them.
 */
final class Version20260316120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create registration_failures table for 401 logging';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registration_failures (
            id SERIAL PRIMARY KEY,
            node_id VARCHAR(128) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            book_count INTEGER NOT NULL DEFAULT 0,
            client_ip VARCHAR(45),
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )');
        $this->addSql('CREATE INDEX idx_reg_failures_node ON registration_failures (node_id)');
        $this->addSql('CREATE INDEX idx_reg_failures_created ON registration_failures (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registration_failures');
    }
}
