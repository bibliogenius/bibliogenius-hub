<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Follow;
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
 * Status a new follow starts in.
 *
 * An unlisted profile can now be followed (a paired library materializes its
 * pairing as a follow, ADR-053, whether or not either side is listed). Being
 * unlisted is no reason to be followed by anyone who learnt a node id, though:
 * an active follow hands over the catalog and the sealed contact card. So a
 * follow toward an unlisted profile always waits for its owner, and paired
 * peers are approved by the owner's client, never by the hub.
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceFollowTest extends TestCase
{
    private DirectoryService $service;

    protected function setUp(): void
    {
        $followRepository = $this->createStub(FollowRepository::class);
        $followRepository->method('findExisting')->willReturn(null);

        $this->service = new DirectoryService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LibraryProfileRepository::class),
            $followRepository,
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

    public function testUnlistedProfileWithoutApprovalStillStartsPending(): void
    {
        $follower = self::profile('follower', listed: false, requiresApproval: false);
        $followed = self::profile('followed', listed: false, requiresApproval: false);

        $follow = $this->service->follow($follower, $followed);

        self::assertNotNull($follow);
        self::assertSame(Follow::STATUS_PENDING, $follow->getStatus());
    }

    public function testUnlistedProfileWithApprovalStartsPending(): void
    {
        $follower = self::profile('follower', listed: false, requiresApproval: false);
        $followed = self::profile('followed', listed: false, requiresApproval: true);

        $follow = $this->service->follow($follower, $followed);

        self::assertSame(Follow::STATUS_PENDING, $follow?->getStatus());
    }

    public function testListedProfileWithoutApprovalIsStillActiveAtOnce(): void
    {
        $follower = self::profile('follower', listed: false, requiresApproval: false);
        $followed = self::profile('followed', listed: true, requiresApproval: false);

        $follow = $this->service->follow($follower, $followed);

        self::assertSame(Follow::STATUS_ACTIVE, $follow?->getStatus());
    }

    public function testListedProfileWithApprovalIsStillPending(): void
    {
        $follower = self::profile('follower', listed: false, requiresApproval: false);
        $followed = self::profile('followed', listed: true, requiresApproval: true);

        $follow = $this->service->follow($follower, $followed);

        self::assertSame(Follow::STATUS_PENDING, $follow?->getStatus());
    }
}
