<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add market column to book_prices and switch to composite PK (isbn, market)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_prices DROP CONSTRAINT book_prices_pkey');
        $this->addSql("ALTER TABLE book_prices ADD market VARCHAR(2) NOT NULL DEFAULT 'FR'");
        $this->addSql('ALTER TABLE book_prices ADD PRIMARY KEY (isbn, market)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_prices DROP CONSTRAINT book_prices_pkey');
        $this->addSql('ALTER TABLE book_prices DROP COLUMN market');
        $this->addSql('ALTER TABLE book_prices ADD PRIMARY KEY (isbn)');
    }
}
