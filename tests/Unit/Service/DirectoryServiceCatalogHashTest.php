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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the diff-based push path added in ADR-027.
 *
 * The service is exercised in isolation by mocking Doctrine. We only
 * care about the branching around `catalog_hash`: format validation,
 * short-circuit when the hash matches, and rewrite otherwise.
 */
final class DirectoryServiceCatalogHashTest extends TestCase
{
    private const VALID_HASH = 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd';
    private const OTHER_HASH = '0000000000000000000000000000000000000000000000000000000000000001';

    private EntityManagerInterface $em;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);

        // Use createStub() for unused dependencies: PHPUnit 12 emits
        // notices when a full mock has no configured expectations.
        $this->service = new DirectoryService(
            $this->em,
            $this->createStub(LibraryProfileRepository::class),
            $this->createStub(FollowRepository::class),
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: sys_get_temp_dir(),
        );
    }

    private function buildProfile(string $nodeId = 'node-abc'): LibraryProfile
    {
        return new LibraryProfile($nodeId, 'write-tok', 'Test Library');
    }

    public function testMatchingHashShortCircuitsWithoutPayloadRewrite(): void
    {
        $profile = $this->buildProfile();
        $existing = new CachedCatalog(
            $profile,
            '["9781"]',
            '[{"isbn":"9781","title":"Old"}]',
            self::VALID_HASH,
        );

        $this->em->expects(self::once())
            ->method('find')
            ->with(CachedCatalog::class, $profile->getNodeId())
            ->willReturn($existing);
        $this->em->expects(self::once())->method('flush');
        // A matching-hash push must not persist a new entity.
        $this->em->expects(self::never())->method('persist');

        $originalExpires = $existing->getExpiresAt();
        // Force a distinguishable delta so we can assert touchTtl bumped it.
        usleep(1500);

        $result = $this->service->pushCatalog(
            $profile,
            '["9781","9782"]', // different payload content...
            '[{"isbn":"9781","title":"Completely Different"}]',
            bookCount: 7,
            catalogHash: self::VALID_HASH, // ...but hash says "unchanged"
        );

        self::assertTrue($result->unchanged);
        self::assertSame($existing, $result->catalog);
        // Stored payload MUST NOT be rewritten: the hash is the source of truth.
        self::assertSame('[{"isbn":"9781","title":"Old"}]', $existing->getCatalogPayload());
        self::assertSame('["9781"]', $existing->getIsbnPayload());
        self::assertSame(self::VALID_HASH, $existing->getCatalogHash());
        self::assertGreaterThan($originalExpires, $existing->getExpiresAt());
        // book_count is cheap to update and reflects live state.
        self::assertSame(7, $profile->getBookCount());
    }

    public function testDifferentHashTriggersFullRefresh(): void
    {
        $profile = $this->buildProfile();
        $existing = new CachedCatalog(
            $profile,
            '["9781"]',
            '[{"isbn":"9781","title":"Old"}]',
            self::VALID_HASH,
        );

        $this->em->expects(self::once())
            ->method('find')
            ->willReturn($existing);
        $this->em->expects(self::once())->method('flush');
        $this->em->expects(self::never())->method('persist');

        $result = $this->service->pushCatalog(
            $profile,
            '["9781","9782"]',
            '[{"isbn":"9781","title":"New"},{"isbn":"9782","title":"Fresh"}]',
            bookCount: 2,
            catalogHash: self::OTHER_HASH,
        );

        self::assertFalse($result->unchanged);
        self::assertSame(self::OTHER_HASH, $existing->getCatalogHash());
        self::assertSame(
            '[{"isbn":"9781","title":"New"},{"isbn":"9782","title":"Fresh"}]',
            $existing->getCatalogPayload(),
        );
    }

    public function testFirstPushCreatesNewCatalogWithHash(): void
    {
        $profile = $this->buildProfile();

        $this->em->method('find')->willReturn(null);
        $persisted = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function ($entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->pushCatalog(
            $profile,
            '["9781"]',
            '[{"isbn":"9781","title":"Title"}]',
            bookCount: 1,
            catalogHash: self::VALID_HASH,
        );

        self::assertFalse($result->unchanged);
        self::assertInstanceOf(CachedCatalog::class, $persisted);
        self::assertSame(self::VALID_HASH, $persisted->getCatalogHash());
    }

    public function testInvalidHashFormatIsRejected(): void
    {
        $profile = $this->buildProfile();

        $this->em->expects(self::never())->method('find');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/catalog_hash/');

        $this->service->pushCatalog(
            $profile,
            '["9781"]',
            '[]',
            bookCount: 0,
            catalogHash: 'UPPERCASE_OR_TOO_SHORT',
        );
    }

    public function testMissingHashIsAllowedForBackwardCompat(): void
    {
        $profile = $this->buildProfile();

        $this->em->method('find')->willReturn(null);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->pushCatalog(
            $profile,
            '["9781"]',
            '[]',
            bookCount: 0,
            catalogHash: null,
        );

        self::assertFalse($result->unchanged);
        self::assertNull($result->catalog->getCatalogHash());
    }

    public function testExistingCatalogWithNullHashNeverShortCircuits(): void
    {
        // Legacy catalog stored before ADR-027: hash is null. A client
        // that now computes a hash must still trigger a rewrite so the
        // hub picks up the new hash for future diff checks.
        $profile = $this->buildProfile();
        $existing = new CachedCatalog($profile, '["9781"]', '[]', null);

        $this->em->method('find')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->pushCatalog(
            $profile,
            '["9781"]',
            '[]',
            bookCount: 1,
            catalogHash: self::VALID_HASH,
        );

        self::assertFalse($result->unchanged);
        self::assertSame(self::VALID_HASH, $existing->getCatalogHash());
    }
}
