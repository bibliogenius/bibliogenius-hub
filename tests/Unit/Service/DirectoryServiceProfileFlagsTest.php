<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

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
 * The three visibility flags (is_listed, requires_approval, allow_borrowing)
 * are the owner's settings. A client that re-registers for another reason
 * (relay credentials, rename, silent registration after a purge) leaves
 * them out of the body, and the hub must keep the stored values; the
 * response then tells the client what the hub holds, is_listed included,
 * so its local copy follows the hub rather than a default.
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceProfileFlagsTest extends TestCase
{
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

    private function publishedProfile(): LibraryProfile
    {
        $profile = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $profile->setIsListed(true);
        $profile->setRequiresApproval(false);
        $profile->setAllowBorrowing(true);

        return $profile;
    }

    public function testUpdateWithoutTheFlagsKeepsThePublishedValues(): void
    {
        $existing = $this->publishedProfile();
        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->upsertProfile([
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library (renamed)',
            'relay_url'    => 'https://hub.example.org',
            // is_listed, requires_approval and allow_borrowing absent
        ], $existing);

        self::assertTrue($existing->isListed());
        self::assertFalse($existing->isRequiresApproval());
        self::assertTrue($existing->isAllowBorrowing());
        self::assertSame('Test Library (renamed)', $result['profile']->getDisplayName());
    }

    public function testUpdateWithTheFlagsAppliesThem(): void
    {
        $existing = $this->publishedProfile();
        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'           => 'node-abc',
            'display_name'      => 'Test Library',
            'is_listed'         => false,
            'requires_approval' => true,
            'allow_borrowing'   => false,
        ], $existing);

        self::assertFalse($existing->isListed());
        self::assertTrue($existing->isRequiresApproval());
        self::assertFalse($existing->isAllowBorrowing());
    }

    public function testPublicArrayCarriesIsListed(): void
    {
        $published = $this->publishedProfile();
        self::assertTrue($published->toPublicArray()['is_listed']);

        $unlisted = new LibraryProfile('node-def', 'write-tok', 'Quiet Library');
        self::assertFalse($unlisted->toPublicArray()['is_listed']);
    }
}
