<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CachedCatalog;
use App\Entity\HubEvent;
use App\Entity\RegistrationFailure;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the raw SQL in LibraryIdentityExporter.
 *
 * Four of its reads bypass the ORM, either because the table carries no entity
 * (library_view_cooldowns) or because the query needs a shape the repositories
 * do not expose. The behavioural tests mock the DBAL connection, so those
 * column names are never executed: a rename in a later migration would surface
 * only at runtime, the day an admin clicks Export.
 *
 * This asserts, without a database, that every column the exporter names still
 * exists - against the entity mapping where there is one, against the
 * entrypoint DDL otherwise - and that the list below still matches the source.
 * Same approach as AccountStoreBlindnessTest.
 */
final class LibraryIdentityExporterSchemaTest extends TestCase
{
    /**
     * Columns the exporter reads, per table. The entity is null when the table
     * has no ORM mapping at all.
     *
     * @var array<string, array{0: class-string|null, 1: list<string>}>
     */
    private const SCANNED_COLUMNS = [
        'cached_catalogs' => [
            CachedCatalog::class,
            ['node_id', 'isbn_payload', 'catalog_payload', 'catalog_hash', 'updated_at', 'expires_at'],
        ],
        'registration_failures' => [
            RegistrationFailure::class,
            ['id', 'node_id', 'display_name', 'book_count', 'client_ip', 'app_version', 'created_at'],
        ],
        'hub_events' => [
            HubEvent::class,
            ['id', 'level', 'channel', 'message', 'context', 'created_at'],
        ],
        'library_view_cooldowns' => [
            null,
            ['profile_node_id', 'visitor_id', 'last_counted_at'],
        ],
    ];

    /**
     * The mapping is the schema for every table that has an entity: if a
     * property is renamed, Doctrine renames the column, and the exporter's
     * hand-written SQL silently stops matching.
     */
    public function testScannedColumnsExistInTheEntityMapping(): void
    {
        foreach (self::SCANNED_COLUMNS as $table => [$entityClass, $columns]) {
            if ($entityClass === null) {
                continue;
            }

            $mapped = self::mappedColumns($entityClass);
            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $mapped,
                    sprintf('%s.%s is read by the exporter but no longer mapped by %s', $table, $column, $entityClass),
                );
            }
        }
    }

    /** The entity-less table is pinned to the DDL the entrypoint creates. */
    public function testEntityLessTableColumnsExistInTheEntrypointDdl(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 3) . '/docker-entrypoint.sh');
        $this->assertNotFalse($entrypoint);

        foreach (self::SCANNED_COLUMNS as $table => [$entityClass, $columns]) {
            if ($entityClass !== null) {
                continue;
            }

            $ddl = self::createTableStatement($entrypoint, $table);
            $this->assertNotNull($ddl, "no CREATE TABLE for $table in docker-entrypoint.sh");

            foreach ($columns as $column) {
                $this->assertStringContainsString(
                    $column,
                    $ddl,
                    sprintf('%s.%s is read by the exporter but absent from the entrypoint DDL', $table, $column),
                );
            }
        }
    }

    /**
     * Keeps the list above honest: a column added to the exporter's SQL
     * without being listed here would otherwise be guarded by nothing.
     */
    public function testListedColumnsStillAppearInTheExporterSource(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/LibraryIdentityExporter.php');
        $this->assertNotFalse($source);

        foreach (self::SCANNED_COLUMNS as $table => [, $columns]) {
            $this->assertStringContainsString($table, $source, "$table is no longer read by the exporter");

            foreach ($columns as $column) {
                $this->assertStringContainsString(
                    $column,
                    $source,
                    sprintf('%s.%s is listed here but no longer named in the exporter', $table, $column),
                );
            }
        }
    }

    /**
     * Column names as Doctrine resolves them: the explicit name when the
     * attribute carries one, the underscored property name otherwise.
     *
     * @param class-string $entityClass
     *
     * @return list<string>
     */
    private static function mappedColumns(string $entityClass): array
    {
        $columns = [];

        foreach ((new \ReflectionClass($entityClass))->getProperties() as $property) {
            foreach ($property->getAttributes(ORM\Column::class) as $attribute) {
                $columns[] = $attribute->newInstance()->name ?? self::underscore($property->getName());
            }
            foreach ($property->getAttributes(ORM\JoinColumn::class) as $attribute) {
                $columns[] = $attribute->newInstance()->name ?? self::underscore($property->getName()) . '_id';
            }
        }

        return $columns;
    }

    /** Extracts the CREATE TABLE statement for one table out of a shell script. */
    private static function createTableStatement(string $haystack, string $table): ?string
    {
        $matched = preg_match(
            sprintf('/CREATE TABLE (?:IF NOT EXISTS )?%s \((.*?)\)"/s', preg_quote($table, '/')),
            $haystack,
            $matches,
        );

        return $matched === 1 ? $matches[1] : null;
    }

    private static function underscore(string $property): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
    }
}
