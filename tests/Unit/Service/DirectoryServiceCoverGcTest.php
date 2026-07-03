<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CachedCatalog;
use App\Entity\LibraryProfile;
use App\Repository\BorrowRequestRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for catalog-driven orphan cover GC (ADR-033).
 *
 * Exercises the safety rails around filesystem deletion:
 *   - null / empty catalog payload must never trigger a wipe
 *   - the threshold guard blocks suspiciously large batches
 *   - the hash-unchanged fast path never touches the filesystem
 *   - a normal push under the threshold deletes orphans and logs the action
 */
final class DirectoryServiceCoverGcTest extends TestCase
{
    private const NODE_ID = 'node-gc-test';
    private const VALID_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EntityManagerInterface $em;
    private Connection $connection;
    private string $coversDir;
    private string $nodeDir;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->coversDir = sys_get_temp_dir() . '/bg_cover_gc_' . uniqid('', true);
        $this->nodeDir = $this->coversDir . '/' . self::NODE_ID;
        mkdir($this->nodeDir, 0755, true);

        // Stub for the EM (no call-count assertions on it); mock for the
        // Connection because the tests assert the hub_events insert contract.
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->em->method('getConnection')->willReturn($this->connection);

        $this->service = new DirectoryService(
            $this->em,
            $this->createStub(LibraryProfileRepository::class),
            $this->createStub(FollowRepository::class),
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: $this->coversDir,
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->coversDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function putCover(int $bookId): string
    {
        $path = $this->nodeDir . '/' . $bookId . '.jpg';
        file_put_contents($path, 'jpeg');
        return $path;
    }

    private function putNamedCover(string $basename): string
    {
        $path = $this->nodeDir . '/' . $basename . '.jpg';
        file_put_contents($path, 'jpeg');
        return $path;
    }

    /**
     * Catalog payload as pushed by post-uuid clients: the owner book id is a
     * uuid string carried under `book_uuid` (never `book_id`, which pre-uuid
     * followers declare as int and would fail their whole decode on).
     */
    private function uuidCatalogPayload(array $bookUuids): string
    {
        return json_encode(array_map(
            fn(string $uuid) => ['isbn' => "isbn-$uuid", 'book_uuid' => $uuid, 'title' => "T$uuid"],
            $bookUuids,
        ), JSON_UNESCAPED_UNICODE);
    }

    private function buildProfile(): LibraryProfile
    {
        return new LibraryProfile(self::NODE_ID, 'write-tok', 'Test Library');
    }

    private function catalogPayload(array $bookIds): string
    {
        return json_encode(array_map(
            fn(int $id) => ['isbn' => "isbn-$id", 'book_id' => $id, 'title' => "T$id"],
            $bookIds,
        ), JSON_UNESCAPED_UNICODE);
    }

    public function testDeletesOrphansWhenCatalogIsFreshAndUnderThreshold(): void
    {
        $profile = $this->buildProfile();
        $this->putCover(1);
        $this->putCover(2);
        $this->putCover(3);
        $this->putCover(99); // orphan

        // First push: no existing catalog.
        $this->em->method('find')->willReturn(null);

        // Expect a single hub_events insert describing the deletion.
        $inserted = null;
        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('hub_events'),
                $this->callback(function (array $row) use (&$inserted): bool {
                    $inserted = $row;
                    return true;
                }),
            );

        $this->service->pushCatalog(
            $profile,
            '["isbn-1","isbn-2","isbn-3"]',
            $this->catalogPayload([1, 2, 3]),
            bookCount: 3,
            catalogHash: self::VALID_HASH,
        );

        self::assertFileExists($this->nodeDir . '/1.jpg');
        self::assertFileExists($this->nodeDir . '/2.jpg');
        self::assertFileExists($this->nodeDir . '/3.jpg');
        self::assertFileDoesNotExist($this->nodeDir . '/99.jpg');

        self::assertNotNull($inserted);
        self::assertSame('info', $inserted['level']);
        self::assertSame('catalog_gc', $inserted['channel']);
        self::assertSame('deleted', $inserted['message']);
        $context = json_decode($inserted['context'], true);
        self::assertSame(1, $context['deleted_count']);
        self::assertSame(4, $context['disk_count']);
        self::assertSame('push', $context['trigger']);
    }

    public function testUuidKeyedCatalogDrivesGcAndIgnoresForeignFiles(): void
    {
        $profile = $this->buildProfile();
        $keptA = '0197f2a4-1111-7222-8333-444455556666';
        $keptUpper = '0197F2A4-AAAA-7BBB-8CCC-DDDDEEEEFFFF'; // uppercase file, lowercase catalog
        $orphanUuid = '0197f2a4-9999-7999-8999-999999999999';
        $this->putNamedCover($keptA);
        $this->putNamedCover($keptUpper);
        $this->putNamedCover($orphanUuid);
        $this->putCover(7); // legacy numeric cover no longer referenced
        $this->putNamedCover('not-a-managed-name'); // never GC'd
        $this->putCover(3); // still referenced by a legacy int entry

        $this->em->method('find')->willReturn(null);
        $this->connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $row): int {
                return 1;
            });

        // Mixed-generation payload: two uuid entries + one legacy int entry.
        $entries = array_merge(
            json_decode($this->uuidCatalogPayload([$keptA, strtolower($keptUpper)]), true),
            json_decode($this->catalogPayload([3]), true),
        );

        $this->service->pushCatalog(
            $profile,
            '["isbn-a","isbn-b","isbn-3"]',
            json_encode($entries, JSON_UNESCAPED_UNICODE),
            bookCount: 3,
            catalogHash: self::VALID_HASH,
        );

        // 6 files, 2 orphans (33% < 50% threshold): only the orphans go.
        self::assertFileExists($this->nodeDir . '/' . $keptA . '.jpg');
        self::assertFileExists($this->nodeDir . '/' . $keptUpper . '.jpg');
        self::assertFileExists($this->nodeDir . '/3.jpg');
        self::assertFileExists($this->nodeDir . '/not-a-managed-name.jpg');
        self::assertFileDoesNotExist($this->nodeDir . '/' . $orphanUuid . '.jpg');
        self::assertFileDoesNotExist($this->nodeDir . '/7.jpg');
    }

    public function testSkipsWhenCatalogPayloadIsNull(): void
    {
        $profile = $this->buildProfile();
        $this->putCover(1);
        $this->putCover(99);

        $this->em->method('find')->willReturn(null);

        // No deletion, no hub_events insert when the catalog has no enriched
        // data — legacy catalogs without book_id cannot drive GC safely.
        $this->connection->expects($this->never())->method('insert');

        $this->service->pushCatalog(
            $profile,
            '["isbn-1","isbn-99"]',
            catalogPayload: null,
            bookCount: 2,
            catalogHash: self::VALID_HASH,
        );

        self::assertFileExists($this->nodeDir . '/1.jpg');
        self::assertFileExists($this->nodeDir . '/99.jpg');
    }

    public function testSkipsWhenCatalogDecodesToEmptyArray(): void
    {
        $profile = $this->buildProfile();
        $this->putCover(1);
        $this->putCover(2);

        $this->em->method('find')->willReturn(null);

        $inserted = null;
        $this->connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
                $inserted = $row;
                return 1;
            });

        $this->service->pushCatalog(
            $profile,
            '[]',
            catalogPayload: '[]',
            bookCount: 0,
            catalogHash: self::VALID_HASH,
        );

        // Covers untouched: an empty catalog is treated as suspicious.
        self::assertFileExists($this->nodeDir . '/1.jpg');
        self::assertFileExists($this->nodeDir . '/2.jpg');

        self::assertNotNull($inserted);
        self::assertSame('warning', $inserted['level']);
        self::assertSame('skipped_empty_catalog', $inserted['message']);
    }

    public function testSkipsWhenDeleteRatioExceedsThreshold(): void
    {
        $profile = $this->buildProfile();
        for ($i = 1; $i <= 10; $i++) {
            $this->putCover($i);
        }

        $this->em->method('find')->willReturn(null);

        $inserted = null;
        $this->connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
                $inserted = $row;
                return 1;
            });

        // Catalog only references book_id=1 → 9 of 10 would be deleted (90%).
        $this->service->pushCatalog(
            $profile,
            '["isbn-1"]',
            $this->catalogPayload([1]),
            bookCount: 1,
            catalogHash: self::VALID_HASH,
        );

        // All files preserved: threshold guard prevents mass deletion.
        for ($i = 1; $i <= 10; $i++) {
            self::assertFileExists($this->nodeDir . '/' . $i . '.jpg');
        }

        self::assertNotNull($inserted);
        self::assertSame('warning', $inserted['level']);
        self::assertSame('skipped_threshold', $inserted['message']);
        $context = json_decode($inserted['context'], true);
        self::assertSame(9, $context['orphan_count']);
        self::assertSame(10, $context['disk_count']);
    }

    public function testHashUnchangedFastPathNeverTriggersGc(): void
    {
        $profile = $this->buildProfile();
        $this->putCover(1);
        $this->putCover(99); // would be orphan if GC ran

        $existing = new CachedCatalog(
            $profile,
            '["isbn-1"]',
            $this->catalogPayload([1]),
            self::VALID_HASH,
        );
        $this->em->method('find')->willReturn($existing);

        // Fast path: same hash means "unchanged". GC must not fire.
        $this->connection->expects($this->never())->method('insert');

        $this->service->pushCatalog(
            $profile,
            '["isbn-1"]',
            $this->catalogPayload([1]),
            bookCount: 1,
            catalogHash: self::VALID_HASH,
        );

        // Both files still there: the fast path does not scan the filesystem.
        self::assertFileExists($this->nodeDir . '/1.jpg');
        self::assertFileExists($this->nodeDir . '/99.jpg');
    }
}
