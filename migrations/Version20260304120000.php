<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Borrow requests: hub-mediated loan requests between libraries.
 *
 * Libraries can request to borrow a book from another library via the hub,
 * without requiring a direct P2P connection (ADR-018).
 * Requests expire after 30 days and are pruned probabilistically.
 */
final class Version20260304120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create borrow_requests table for hub-mediated loan requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS borrow_requests (
            id SERIAL PRIMARY KEY,
            requester_node_id VARCHAR(128) NOT NULL,
            lender_node_id VARCHAR(128) NOT NULL,
            isbn VARCHAR(20) NOT NULL,
            book_title VARCHAR(500) NOT NULL DEFAULT \'\',
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            created_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP(0),
            expires_at TIMESTAMP(0) NOT NULL
        )');

        $this->addSql('CREATE INDEX idx_borrow_req_lender ON borrow_requests (lender_node_id, status)');
        $this->addSql('CREATE INDEX idx_borrow_req_requester ON borrow_requests (requester_node_id, status)');
        $this->addSql('CREATE INDEX idx_borrow_req_expires ON borrow_requests (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS borrow_requests');
    }
}
