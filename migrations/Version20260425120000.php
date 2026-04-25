<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * location_city_id on library_profiles (ADR-035 Phase 1).
 *
 * Stores a GeoNames populated-place ID (feature class P) opted into by the
 * user. The hub does not validate the value against a reference table: city
 * data is shipped as per-country gzipped JSON files served from
 * /static/cities/{CC}.json.gz, and the picker is closed-list client-side, so
 * a forged ID is just a label with no security or privacy impact.
 *
 * Mirrored in docker-entrypoint.sh.
 */
final class Version20260425120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'location_city_id on library_profiles (ADR-035 city-level location, opt-in)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles ADD COLUMN IF NOT EXISTS location_city_id INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_profiles DROP COLUMN IF EXISTS location_city_id');
    }
}
