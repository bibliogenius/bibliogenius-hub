<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\AccountEntity;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;

/**
 * Structural guards for the blind lane store (ADR-043 acceptance criteria b, c,
 * d). These run without a database: they assert, by reflection and by reading
 * the migration sources, that the schema can never leak plaintext/type/auth and
 * that overwrite-in-place is enforced by the primary key.
 */
final class AccountStoreBlindnessTest extends TestCase
{
    /** The only clear columns a lane may carry (everything else is ciphertext). */
    private const ALLOWED_LANE_PROPERTIES = [
        'accountId', 'opaqueId', 'deviceId', 'changeSeq',
        'deleted', 'sizeBucket', 'blob', 'receivedAt', 'tombstonedAt',
    ];

    private const FORBIDDEN_SUBSTRINGS = ['type', 'hlc', 'author', 'revok', 'plain'];

    private const STORE_TABLES = [
        'accounts',
        'account_entities',
        'wrapped_account_keys',
        'account_device_registry',
        'account_auth_challenges',
    ];

    /**
     * Criterion (b): a lane exposes only blind metadata. No entity_type, no
     * HLC, no authorization column - a future column with such a name fails.
     */
    public function testLaneEntityExposesOnlyBlindColumns(): void
    {
        $reflection = new \ReflectionClass(AccountEntity::class);
        $mapped = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) !== []) {
                $mapped[] = $property->getName();
            }
        }

        sort($mapped);
        $expected = self::ALLOWED_LANE_PROPERTIES;
        sort($expected);
        $this->assertSame($expected, $mapped, 'Lane columns drifted from the blind set (ADR-043 H3/H5/M1).');

        foreach ($mapped as $name) {
            foreach (self::FORBIDDEN_SUBSTRINGS as $bad) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $bad,
                    $name,
                    sprintf('Lane column "%s" looks like it leaks %s', $name, $bad),
                );
            }
        }
    }

    /**
     * Criterion (c): the primary key IS the lane (account_id, opaque_id,
     * device_id), so an upsert overwrites in place and no history accumulates.
     */
    public function testLanePrimaryKeyIsTheCompositeLane(): void
    {
        $reflection = new \ReflectionClass(AccountEntity::class);
        $idProps = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(ORM\Id::class) !== []) {
                $idProps[] = $property->getName();
            }
        }

        sort($idProps);
        $this->assertSame(['accountId', 'deviceId', 'opaqueId'], $idProps);
    }

    /**
     * Criterion (d): the dual migration system stays in sync - every store
     * table and the ordering sequence exist in BOTH the Doctrine migration and
     * the docker-entrypoint.sh mirror (else prod 500s).
     */
    public function testMigrationAndEntrypointDefineTheSameStore(): void
    {
        $root = dirname(__DIR__, 3);
        $migration = file_get_contents($root . '/migrations/Version20260625120000.php');
        $entrypoint = file_get_contents($root . '/docker-entrypoint.sh');

        $this->assertNotFalse($migration);
        $this->assertNotFalse($entrypoint);

        foreach (self::STORE_TABLES as $table) {
            $this->assertStringContainsString($table, $migration, "migration missing $table");
            $this->assertStringContainsString($table, $entrypoint, "entrypoint mirror missing $table");
        }
        $this->assertStringContainsString('account_entities_change_seq', $migration);
        $this->assertStringContainsString('account_entities_change_seq', $entrypoint);
    }

    /**
     * The recovery marker (ADR-042 section 16.3) lands in BOTH migration systems
     * and stays nullable. Null is not an oversight, it is the readable marker of
     * "no marker was ever derived", which only the account's own user can fix
     * (16.5) and which a later admin count reads to size that population.
     */
    public function testRecoveryVerifierColumnIsNullableInBothMigrationSystems(): void
    {
        $root = dirname(__DIR__, 3);
        $migration = file_get_contents($root . '/migrations/Version20260829120000.php');
        $entrypoint = file_get_contents($root . '/docker-entrypoint.sh');

        $this->assertNotFalse($migration);
        $this->assertNotFalse($entrypoint);

        $ddl = 'ADD COLUMN IF NOT EXISTS recovery_verifier_hash VARCHAR(128) DEFAULT NULL';
        $this->assertStringContainsString($ddl, (string) $migration, 'migration missing the recovery marker');
        $this->assertStringContainsString($ddl, (string) $entrypoint, 'entrypoint mirror missing the recovery marker');

        $attributes = (new \ReflectionClass(Account::class))
            ->getProperty('recoveryVerifierHash')
            ->getAttributes(ORM\Column::class);
        $this->assertNotEmpty($attributes, 'recoveryVerifierHash must be a mapped column');
        $this->assertTrue(
            $attributes[0]->newInstance()->nullable,
            'the recovery marker must stay nullable (ADR-042 16.5 counts the nulls)',
        );
    }

    /**
     * Criterion (b), at the SQL level: the store DDL must not declare a clear
     * entity_type, version_hlc, or device-authorization column.
     */
    public function testStoreSqlHasNoClearTypeHlcOrAuthColumn(): void
    {
        $root = dirname(__DIR__, 3);
        $migration = file_get_contents($root . '/migrations/Version20260625120000.php');
        $this->assertNotFalse($migration);

        // Strip comments so the explanatory docblock (which names these tokens
        // precisely to say they are absent) does not trip the guard; assert on
        // the actual DDL only.
        $ddl = preg_replace('!/\*.*?\*/!s', '', $migration);
        $ddl = preg_replace('!//[^\n]*!', '', (string) $ddl);

        foreach (['entity_type', 'version_hlc', 'device_authorized', 'revoked'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                (string) $ddl,
                "store DDL must not carry a clear $forbidden column",
            );
        }
    }
}
