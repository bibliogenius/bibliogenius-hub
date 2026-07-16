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
 * Unit tests for the catalog access rules (canReadCatalog / getCatalog).
 *
 * Denial and absence are distinct outcomes: the API maps the former to
 * 'follow_required' and the latter to 'catalog_unavailable', so a paired
 * device blocked by requires_approval gets an actionable error instead of
 * being indistinguishable from an expired catalog.
 *
 * Verifies:
 *   - listed + requires_approval: denied without an active follow,
 *     allowed with one
 *   - non-listed or approval-free profiles: always readable (nodeId
 *     knowledge is the authorization)
 *   - getCatalog returns null on missing catalog even when access is
 *     allowed (the expired-catalog case)
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceCatalogAccessTest extends TestCase
{
    private EntityManagerInterface $em;
    private FollowRepository $followRepository;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->followRepository = $this->createMock(FollowRepository::class);

        $this->service = new DirectoryService(
            $this->em,
            $this->createStub(LibraryProfileRepository::class),
            $this->followRepository,
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: sys_get_temp_dir(),
        );
    }

    private static function profile(string $nodeId, bool $listed, bool $requiresApproval): LibraryProfile
    {
        $profile = new LibraryProfile($nodeId, 'write-tok-'.$nodeId, 'Lib '.$nodeId);
        $profile->setIsListed($listed);
        $profile->setRequiresApproval($requiresApproval);

        return $profile;
    }

    public function testListedApprovalGatedProfileDeniesWithoutFollow(): void
    {
        $owner = self::profile('owner', listed: true, requiresApproval: true);
        $requester = self::profile('requester', listed: false, requiresApproval: false);

        $this->followRepository->method('isActiveFollower')->willReturn(false);

        self::assertFalse($this->service->canReadCatalog($owner, $requester));
        self::assertNull($this->service->getCatalog($owner, $requester));
    }

    public function testListedApprovalGatedProfileDeniesUnauthenticated(): void
    {
        $owner = self::profile('owner', listed: true, requiresApproval: true);

        self::assertFalse($this->service->canReadCatalog($owner, null));
    }

    public function testListedApprovalGatedProfileAllowsActiveFollower(): void
    {
        $owner = self::profile('owner', listed: true, requiresApproval: true);
        $requester = self::profile('requester', listed: false, requiresApproval: false);

        $this->followRepository->method('isActiveFollower')
            ->with('requester', 'owner')
            ->willReturn(true);

        $catalog = new CachedCatalog($owner, '["9781234567890"]', null, null);
        $this->em->method('find')->willReturn($catalog);

        self::assertTrue($this->service->canReadCatalog($owner, $requester));
        self::assertSame($catalog, $this->service->getCatalog($owner, $requester));
    }

    public function testNonListedProfileIsReadableWithoutFollow(): void
    {
        $owner = self::profile('owner', listed: false, requiresApproval: true);

        self::assertTrue($this->service->canReadCatalog($owner, null));
    }

    public function testApprovalFreeProfileIsReadableWithoutFollow(): void
    {
        $owner = self::profile('owner', listed: true, requiresApproval: false);

        self::assertTrue($this->service->canReadCatalog($owner, null));
    }

    public function testAllowedAccessWithMissingCatalogReturnsNull(): void
    {
        // The expired/never-pushed catalog case: access is fine, data is gone.
        $owner = self::profile('owner', listed: false, requiresApproval: true);

        $this->em->method('find')->willReturn(null);

        self::assertTrue($this->service->canReadCatalog($owner, null));
        self::assertNull($this->service->getCatalog($owner, null));
    }
}
