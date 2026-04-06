<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create book_prices table for nudger.fr Open Data price import (ODbL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE book_prices (
                isbn VARCHAR(13) NOT NULL,
                price_cents INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                offers_count INTEGER DEFAULT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'nudger',
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (isbn)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_book_prices_updated_at ON book_prices (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE book_prices');
    }
}
