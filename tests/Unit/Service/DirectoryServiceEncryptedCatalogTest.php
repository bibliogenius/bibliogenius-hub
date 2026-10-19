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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Encrypted catalog push (ADR-072, format `e2ee-v1`).
 *
 * The hub stores the ciphertext as an opaque string and must never need to
 * read inside it: the ISBN list is empty by contract, the book count comes
 * from the client only, and the orphan cover GC is driven by the clear list
 * of book ids the push carries instead of the decoded entries. Everything
 * the hub validates is shape, never content.
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceEncryptedCatalogTest extends TestCase
{
    private const NODE_ID = 'node-e2ee-test';
    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    /** Looks like base64, decodes to nothing meaningful: the hub must not care. */
    private const CIPHERTEXT = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMA==';
    private const UUID_A = '0197f2a4-1111-7222-8333-444455556666';
    private const UUID_B = '0197f2a4-2222-7222-8333-444455556666';
    private const UUID_ORPHAN = '0197f2a4-9999-7999-8999-999999999999';

    private EntityManagerInterface $em;
    private Connection $connection;
    private string $coversDir;
    private string $nodeDir;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->coversDir = sys_get_temp_dir() . '/bg_e2ee_catalog_' . uniqid('', true);
        $this->nodeDir = $this->coversDir . '/' . self::NODE_ID;
        mkdir($this->nodeDir, 0755, true);

        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->connection = $this->createStub(Connection::class);
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

    private function putCover(string $basename): void
    {
        file_put_contents($this->nodeDir . '/' . $basename . '.jpg', 'jpeg');
    }

    private function buildProfile(int $bookCount = 0): LibraryProfile
    {
        $profile = new LibraryProfile(self::NODE_ID, 'write-tok', 'Test Library');
        $profile->setBookCount($bookCount);

        return $profile;
    }

    // -------------------------------------------------------------------
    // Storage: opaque, format persisted
    // -------------------------------------------------------------------

    public function testEncryptedPushIsStoredOpaqueWithItsFormat(): void
    {
        $profile = $this->buildProfile();
        $this->em->method('find')->willReturn(null);

        $persisted = null;
        $this->em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $result = $this->service->pushCatalog(
            $profile,
            '[]',
            self::CIPHERTEXT,
            bookCount: 42,
            catalogHash: self::HASH_A,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
            coverBookIds: [],
        );

        self::assertFalse($result->unchanged);
        self::assertInstanceOf(CachedCatalog::class, $persisted);
        self::assertSame(CachedCatalog::FORMAT_E2EE_V1, $persisted->getCatalogFormat());
        self::assertTrue($persisted->isEncrypted());
        self::assertSame(self::CIPHERTEXT, $persisted->getCatalogPayload());
        self::assertSame('[]', $persisted->getIsbnPayload());
        self::assertSame(self::HASH_A, $persisted->getCatalogHash());
        self::assertSame(42, $profile->getBookCount());
    }

    public function testClearPushAfterAnEncryptedOneClearsTheFormat(): void
    {
        // Mixed fleet (ADR-072 D8): an owner falls back to clear when a
        // reader cannot decrypt yet. The stored format must follow.
        $profile = $this->buildProfile();
        $existing = new CachedCatalog($profile, '[]', self::CIPHERTEXT, self::HASH_A, CachedCatalog::FORMAT_E2EE_V1);
        $this->em->method('find')->willReturn($existing);

        $this->service->pushCatalog(
            $profile,
            '["9781"]',
            '[{"isbn":"9781","title":"Clear again"}]',
            bookCount: 1,
            catalogHash: self::HASH_B,
        );

        self::assertNull($existing->getCatalogFormat());
        self::assertFalse($existing->isEncrypted());
        self::assertSame('[{"isbn":"9781","title":"Clear again"}]', $existing->getCatalogPayload());
    }

    public function testMatchingHashKeepsAnEncryptedRowUntouched(): void
    {
        $profile = $this->buildProfile(5);
        $existing = new CachedCatalog($profile, '[]', self::CIPHERTEXT, self::HASH_A, CachedCatalog::FORMAT_E2EE_V1);
        $this->em->method('find')->willReturn($existing);

        $result = $this->service->pushCatalog(
            $profile,
            '[]',
            'ZGlmZmVyZW50',
            bookCount: 7,
            catalogHash: self::HASH_A,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
            coverBookIds: [],
        );

        self::assertTrue($result->unchanged);
        self::assertSame(self::CIPHERTEXT, $existing->getCatalogPayload());
        self::assertSame(CachedCatalog::FORMAT_E2EE_V1, $existing->getCatalogFormat());
        // book_count may still drift on the fast path, as for clear catalogs.
        self::assertSame(7, $profile->getBookCount());
    }

    public function testEncryptedPushWithoutBookCountLeavesTheProfileCountAlone(): void
    {
        // The clear path falls back to the ISBN list length; for an
        // encrypted catalog that list is empty by contract and would zero
        // a public number the directory displays.
        $profile = $this->buildProfile(12);
        $this->em->method('find')->willReturn(null);

        $this->service->pushCatalog(
            $profile,
            '[]',
            self::CIPHERTEXT,
            bookCount: null,
            catalogHash: self::HASH_A,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
            coverBookIds: [],
        );

        self::assertSame(12, $profile->getBookCount());
    }

    // -------------------------------------------------------------------
    // Validation: shape only
    // -------------------------------------------------------------------

    public function testUnknownFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('catalog_format');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '[]',
            self::CIPHERTEXT,
            catalogFormat: 'e2ee-v9',
        );
    }

    public function testEncryptedPushRequiresAnEmptyIsbnList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('isbn_payload');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '["9781"]',
            self::CIPHERTEXT,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
        );
    }

    public function testTheEmptyIsbnListIsTheLiteralEmptyArray(): void
    {
        // "{}" also decodes to an empty PHP array; it is not the empty list
        // readers parse and must not be stored as one.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('isbn_payload');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '{}',
            self::CIPHERTEXT,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
        );
    }

    public function testEncryptedPushRequiresAPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('catalog_payload');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '[]',
            null,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
        );
    }

    public function testEncryptedPayloadMustBeBase64(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('catalog_payload');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '[]',
            '[{"isbn":"9781","title":"not a ciphertext"}]',
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
        );
    }

    public function testEncryptedPayloadMustUseTheStandardBase64Alphabet(): void
    {
        // Readers decode with the standard alphabet (padding, "+" and "/"),
        // the one the sealed blobs already use. A url-safe payload would be
        // accepted here and fail at every reader.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('catalog_payload');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '[]',
            'AAEC-wQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4_MA',
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
        );
    }

    public function testCoverBookIdsMustBeCoverKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cover_book_ids');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '[]',
            self::CIPHERTEXT,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
            coverBookIds: [self::UUID_A, '../escape'],
        );
    }

    public function testCoverBookIdsAreBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $this->service->pushCatalog(
            $this->buildProfile(),
            '[]',
            self::CIPHERTEXT,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
            coverBookIds: array_fill(0, DirectoryService::MAX_COVER_BOOK_IDS + 1, self::UUID_A),
        );
    }

    public function testCoverBookIdsAreIgnoredOnAClearPush(): void
    {
        // A clear catalog carries its own ids; the side list is not a second
        // source of truth for the GC.
        $profile = $this->buildProfile();
        $this->putCover(self::UUID_A);
        $this->putCover(self::UUID_B);
        $this->putCover(self::UUID_ORPHAN);
        $this->em->method('find')->willReturn(null);

        $this->service->pushCatalog(
            $profile,
            '["a","b","c"]',
            json_encode([
                ['isbn' => 'a', 'book_uuid' => self::UUID_A],
                ['isbn' => 'b', 'book_uuid' => self::UUID_B],
                ['isbn' => 'c', 'book_uuid' => self::UUID_ORPHAN],
            ]),
            bookCount: 3,
            catalogHash: self::HASH_A,
            coverBookIds: [self::UUID_A],
        );

        self::assertFileExists($this->nodeDir . '/' . self::UUID_ORPHAN . '.jpg');
    }

    // -------------------------------------------------------------------
    // Orphan cover GC driven by the clear id list
    // -------------------------------------------------------------------

    public function testCoverBookIdsDriveTheOrphanGcOnAnEncryptedPush(): void
    {
        $profile = $this->buildProfile();
        $this->putCover(self::UUID_A);
        $this->putCover(strtoupper(self::UUID_B)); // case-insensitive like the clear path
        $this->putCover(self::UUID_ORPHAN);
        $this->putCover('7'); // legacy integer id, still referenced
        $this->putCover('not-a-managed-name'); // never GC'd
        $this->em->method('find')->willReturn(null);

        $inserted = null;
        $this->connection->method('insert')->willReturnCallback(
            function (string $table, array $row) use (&$inserted): int {
                $inserted = $row;
                return 1;
            },
        );

        $this->service->pushCatalog(
            $profile,
            '[]',
            self::CIPHERTEXT,
            bookCount: 3,
            catalogHash: self::HASH_A,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
            coverBookIds: [self::UUID_A, self::UUID_B, 7],
        );

        self::assertFileExists($this->nodeDir . '/' . self::UUID_A . '.jpg');
        self::assertFileExists($this->nodeDir . '/' . strtoupper(self::UUID_B) . '.jpg');
        self::assertFileExists($this->nodeDir . '/7.jpg');
        self::assertFileExists($this->nodeDir . '/not-a-managed-name.jpg');
        self::assertFileDoesNotExist($this->nodeDir . '/' . self::UUID_ORPHAN . '.jpg');

        self::assertNotNull($inserted);
        self::assertSame('catalog_gc', $inserted['channel']);
        self::assertSame('deleted', $inserted['message']);
        $context = json_decode($inserted['context'], true);
        self::assertSame(1, $context['deleted_count']);
        self::assertSame('push', $context['trigger']);
    }

    public function testEncryptedPushWithoutCoverBookIdsNeverTouchesTheDisk(): void
    {
        // A client that sends no list cannot drive a safe GC: same rule as a
        // clear push without enriched entries.
        $profile = $this->buildProfile();
        $this->putCover(self::UUID_ORPHAN);
        $this->em->method('find')->willReturn(null);

        $this->service->pushCatalog(
            $profile,
            '[]',
            self::CIPHERTEXT,
            bookCount: 3,
            catalogHash: self::HASH_A,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
        );

        self::assertFileExists($this->nodeDir . '/' . self::UUID_ORPHAN . '.jpg');
    }

    public function testEmptyCoverBookIdsWithCoversOnDiskIsSkippedLikeAnEmptyCatalog(): void
    {
        $profile = $this->buildProfile();
        $this->putCover(self::UUID_A);
        $this->em->method('find')->willReturn(null);

        $inserted = null;
        $this->connection->method('insert')->willReturnCallback(
            function (string $table, array $row) use (&$inserted): int {
                $inserted = $row;
                return 1;
            },
        );

        $this->service->pushCatalog(
            $profile,
            '[]',
            self::CIPHERTEXT,
            bookCount: 0,
            catalogHash: self::HASH_A,
            catalogFormat: CachedCatalog::FORMAT_E2EE_V1,
            coverBookIds: [],
        );

        self::assertFileExists($this->nodeDir . '/' . self::UUID_A . '.jpg');
        self::assertNotNull($inserted);
        self::assertSame('skipped_empty_catalog', $inserted['message']);
    }

    public function testNightlySweepOnlyReadsClearCatalogs(): void
    {
        // The cron cannot decode a ciphertext; the push already ran the GC
        // with the clear id list, so the sweep leaves encrypted rows out.
        $capturedSql = null;
        $this->connection->method('iterateAssociative')->willReturnCallback(
            function (string $sql) use (&$capturedSql): \Iterator {
                $capturedSql = $sql;
                return new \ArrayIterator([]);
            },
        );

        self::assertSame(0, $this->service->pruneOrphanCoversForAllNodes());
        self::assertNotNull($capturedSql);
        self::assertStringContainsStringIgnoringCase('catalog_format IS NULL', $capturedSql);
    }
}
