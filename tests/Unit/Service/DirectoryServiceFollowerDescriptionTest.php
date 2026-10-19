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
 * What an owner learns about each follower from the followers and pending
 * lists: the sealing key for the contact card and the catalog key, the name,
 * and the app version (ADR-072 D8: the owner encrypts its catalog only when
 * every reader runs a build that can decrypt it). All three are already
 * public on the follower's profile; the list only spares one profile lookup
 * per follower.
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceFollowerDescriptionTest extends TestCase
{
    private const X25519 = 'ab'.'cd'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab';

    private LibraryProfileRepository $profiles;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->profiles = $this->createStub(LibraryProfileRepository::class);

        $this->service = new DirectoryService(
            $this->createStub(EntityManagerInterface::class),
            $this->profiles,
            $this->createStub(FollowRepository::class),
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: sys_get_temp_dir(),
        );
    }

    public function testDescribesTheFollowerFromItsProfile(): void
    {
        $follower = new LibraryProfile('reader', 'tok', 'Reader');
        $follower->setX25519PublicKey(self::X25519)->setAppVersion('1.2.3+500');
        $this->profiles->method('findByNodeId')->willReturn($follower);

        $data = $this->service->describeFollowerForOwner((new Follow('reader', 'owner'))->approve());

        self::assertSame('reader', $data['follower_node_id']);
        self::assertSame('Reader', $data['follower_display_name']);
        self::assertSame(self::X25519, $data['follower_x25519_public_key']);
        self::assertSame('1.2.3+500', $data['follower_app_version']);
        self::assertArrayHasKey('encrypted_catalog_key', $data);
    }

    public function testAVanishedFollowerProfileYieldsNulls(): void
    {
        $this->profiles->method('findByNodeId')->willReturn(null);

        $data = $this->service->describeFollowerForOwner(new Follow('gone', 'owner'));

        self::assertNull($data['follower_display_name']);
        self::assertNull($data['follower_x25519_public_key']);
        self::assertNull($data['follower_app_version']);
    }
}
