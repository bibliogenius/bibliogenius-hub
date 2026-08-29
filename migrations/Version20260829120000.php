<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * accounts.recovery_verifier_hash - marker for the recovery-key holder (ADR-042 section 16.3).
 *
 * Hash of an HKDF of the recovery key, derived client-side under a label
 * distinct from the one that wraps the kind=recovery bundle: holding this
 * value grants nothing but the right to reach that bundle, which stays
 * AEAD-sealed. A fast hash is enough here, the input is a uniform HKDF
 * output and not a low-entropy secret (section 14 / L5).
 *
 * NULLABLE on purpose, and null is meaningful: it marks an account created
 * before the client derived the marker. Those can only be retrofitted by
 * their own user, from the phrase on their paper (section 16.5), so null is
 * also what a later admin count measures. Never backfilled server-side.
 *
 * Mirrored in docker-entrypoint.sh (dual migration system - required, else
 * prod and dev drift apart silently).
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable accounts.recovery_verifier_hash (ADR-042 section 16.3 recovery marker)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts ADD COLUMN IF NOT EXISTS recovery_verifier_hash VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts DROP COLUMN IF EXISTS recovery_verifier_hash');
    }
}
