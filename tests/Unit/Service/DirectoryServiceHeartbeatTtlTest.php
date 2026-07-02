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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the catalog TTL refresh on the profile heartbeat path.
 *
 * An authenticated register_or_update call (upsertProfile with a valid
 * write_token) must extend the TTL of an existing cached catalog, because
 * clients predating the keep-alive fix never re-push an unchanged catalog
 * and would otherwise see it expire while the profile keeps heartbeating.
 *
 * Verifies:
 *   - Authenticated heartbeat bumps expires_at on an existing catalog
 *     without touching payload or hash
 *   - Heartbeat with no cached catalog does NOT create one
 *   - Unauthenticated / mismatched callers never reach the touch
 */
// The profileRepository mock is stub-configured (no expectations) in every
// test. Opt out of PHPUnit 12.5's no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceHeartbeatTtlTest extends TestCase
{
    private const HASH = 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd';

    private EntityManagerInterface $em;
    private LibraryProfileRepository $profileRepository;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->profileRepository = $this->createMock(LibraryProfileRepository::class);

        $this->service = new DirectoryService(
            $this->em,
            $this->profileRepository,
            $this->createStub(FollowRepository::class),
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: sys_get_temp_dir(),
        );
    }

    public function testAuthenticatedHeartbeatExtendsExistingCatalogTtl(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $catalog = new CachedCatalog(
            $existing,
            '["9781"]',
            '[{"isbn":"9781","title":"Old"}]',
            self::HASH,
        );

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())
            ->method('find')
            ->with(CachedCatalog::class, 'node-abc')
            ->willReturn($catalog);
        $this->em->expects(self::once())->method('flush');
        // The heartbeat must never persist a new entity.
        $this->em->expects(self::never())->method('persist');

        $originalExpires = $catalog->getExpiresAt();
        // Force a distinguishable delta so we can assert touchTtl bumped it.
        usleep(1500);

        $this->service->upsertProfile([
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library',
        ], $existing);

        self::assertGreaterThan($originalExpires, $catalog->getExpiresAt());
        // Payload and hash must be untouched: this is a TTL-only refresh.
        self::assertSame('[{"isbn":"9781","title":"Old"}]', $catalog->getCatalogPayload());
        self::assertSame('["9781"]', $catalog->getIsbnPayload());
        self::assertSame(self::HASH, $catalog->getCatalogHash());
    }

    public function testHeartbeatWithoutCachedCatalogCreatesNothing(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())
            ->method('find')
            ->with(CachedCatalog::class, 'node-abc')
            ->willReturn(null);
        $this->em->expects(self::once())->method('flush');
        $this->em->expects(self::never())->method('persist');

        $result = $this->service->upsertProfile([
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library',
        ], $existing);

        self::assertSame($existing, $result['profile']);
    }

    public function testUnauthenticatedUpdateNeverTouchesCatalog(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        // The auth check fires before any catalog lookup or write.
        $this->em->expects(self::never())->method('find');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\LogicException::class);

        $this->service->upsertProfile([
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library',
        ], null);
    }

    public function testMismatchedAuthenticationNeverTouchesCatalog(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $attacker = new LibraryProfile('node-evil', 'other-tok', 'Other Library');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::never())->method('find');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\LogicException::class);

        $this->service->upsertProfile([
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library',
        ], $attacker);
    }
}
