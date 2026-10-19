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
 * Transport of the wrapped catalog key on the follow row (ADR-072 D3).
 *
 * The followed library seals its catalog key for each reader and hands the
 * wrap to the hub through the same two paths as the sealed contact card:
 * the approval of a follow and the re-broadcast batch. The hub stores the
 * wrap as an opaque string next to the contact blob and returns it to the
 * follower with the rest of the follow. Nothing here is decoded.
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceCatalogKeyTest extends TestCase
{
    private const OWNER = 'owner-node';
    private const READER = 'reader-node';
    private const WRAP = 'd2FwcGVkLWNhdGFsb2cta2V5LWZvci1yZWFkZXI=';
    private const OTHER_WRAP = 'bmV3LXdyYXAtYWZ0ZXItcm90YXRpb24=';

    private FollowRepository $followRepository;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->followRepository = $this->createStub(FollowRepository::class);

        $this->service = new DirectoryService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LibraryProfileRepository::class),
            $this->followRepository,
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: sys_get_temp_dir(),
        );
    }

    private static function owner(): LibraryProfile
    {
        return new LibraryProfile(self::OWNER, 'write-tok-owner', 'Owner');
    }

    private function pendingFollow(): Follow
    {
        $follow = new Follow(self::READER, self::OWNER);
        $this->followRepository->method('find')->willReturn($follow);

        return $follow;
    }

    private function activeFollow(): Follow
    {
        return $this->pendingFollow()->approve();
    }

    // -------------------------------------------------------------------
    // Follow row
    // -------------------------------------------------------------------

    public function testTheWrapTravelsWithTheFollow(): void
    {
        $follow = (new Follow(self::READER, self::OWNER))->approve();

        self::assertArrayHasKey('encrypted_catalog_key', $follow->toArray());
        self::assertNull($follow->toArray()['encrypted_catalog_key']);

        $follow->setEncryptedCatalogKey(self::WRAP);

        self::assertSame(self::WRAP, $follow->toArray()['encrypted_catalog_key']);
    }

    // -------------------------------------------------------------------
    // Approval path
    // -------------------------------------------------------------------

    public function testApprovingAFollowStoresTheWrap(): void
    {
        $follow = $this->pendingFollow();

        $this->service->resolveFollow(1, 'approve', self::owner(), null, self::WRAP);

        self::assertTrue($follow->isActive());
        self::assertSame(self::WRAP, $follow->getEncryptedCatalogKey());
        self::assertNull($follow->getEncryptedContact(), 'the contact card is a separate audience');
    }

    public function testApprovingAFollowIgnoresAnOversizedWrap(): void
    {
        // The controller bounds the key first; the service bounds it again so
        // the rule holds for every caller. The follow is still approved: the
        // owner re-wraps through the batch once it notices the missing key.
        $follow = $this->pendingFollow();

        $this->service->resolveFollow(
            1,
            'approve',
            self::owner(),
            null,
            str_repeat('A', DirectoryService::MAX_CATALOG_KEY_LENGTH + 1),
        );

        self::assertTrue($follow->isActive());
        self::assertNull($follow->getEncryptedCatalogKey());
    }

    public function testRejectingAFollowNeverStoresAWrap(): void
    {
        $follow = $this->pendingFollow();

        $this->service->resolveFollow(1, 'reject', self::owner(), null, self::WRAP);

        self::assertNull($follow->getEncryptedCatalogKey());
    }

    // -------------------------------------------------------------------
    // Re-broadcast path (rotation, re-wrap after a reader key change)
    // -------------------------------------------------------------------

    public function testTheBatchCanCarryTheWrapAlone(): void
    {
        $follow = $this->activeFollow();
        $follow->setEncryptedContact('existing-contact');

        $updated = $this->service->syncFollowContacts(self::owner(), [
            ['follow_id' => 1, 'encrypted_catalog_key' => self::OTHER_WRAP],
        ]);

        self::assertSame(1, $updated);
        self::assertSame(self::OTHER_WRAP, $follow->getEncryptedCatalogKey());
        self::assertSame('existing-contact', $follow->getEncryptedContact(), 'the card is left as it was');
    }

    public function testTheBatchCanCarryBothBlobs(): void
    {
        $follow = $this->activeFollow();

        $updated = $this->service->syncFollowContacts(self::owner(), [
            ['follow_id' => 1, 'encrypted_contact' => 'new-contact', 'encrypted_catalog_key' => self::WRAP],
        ]);

        self::assertSame(1, $updated);
        self::assertSame('new-contact', $follow->getEncryptedContact());
        self::assertSame(self::WRAP, $follow->getEncryptedCatalogKey());
    }

    public function testAnExplicitNullWithdrawsTheWrap(): void
    {
        // Rotation (D4): the ex-reader simply never gets the new key, but the
        // owner may also clear the stale wrap so the reader fails fast.
        $follow = $this->activeFollow();
        $follow->setEncryptedCatalogKey(self::WRAP);

        $updated = $this->service->syncFollowContacts(self::owner(), [
            ['follow_id' => 1, 'encrypted_catalog_key' => null],
        ]);

        self::assertSame(1, $updated);
        self::assertNull($follow->getEncryptedCatalogKey());
    }

    public function testAnEntryWithoutAnyBlobIsIgnored(): void
    {
        $follow = $this->activeFollow();
        $follow->setEncryptedCatalogKey(self::WRAP);

        $updated = $this->service->syncFollowContacts(self::owner(), [
            ['follow_id' => 1],
        ]);

        self::assertSame(0, $updated);
        self::assertSame(self::WRAP, $follow->getEncryptedCatalogKey());
    }

    public function testAnOversizedWrapIsIgnored(): void
    {
        $follow = $this->activeFollow();

        $updated = $this->service->syncFollowContacts(self::owner(), [
            ['follow_id' => 1, 'encrypted_catalog_key' => str_repeat('A', DirectoryService::MAX_CATALOG_KEY_LENGTH + 1)],
        ]);

        self::assertSame(0, $updated);
        self::assertNull($follow->getEncryptedCatalogKey());
    }

    public function testOnlyTheFollowedLibraryWritesTheWrap(): void
    {
        $follow = $this->activeFollow();
        $stranger = new LibraryProfile('someone-else', 'write-tok-x', 'Stranger');

        $updated = $this->service->syncFollowContacts($stranger, [
            ['follow_id' => 1, 'encrypted_catalog_key' => self::WRAP],
        ]);

        self::assertSame(0, $updated);
        self::assertNull($follow->getEncryptedCatalogKey());
    }

    public function testAPendingFollowGetsNoWrapFromTheBatch(): void
    {
        $follow = $this->pendingFollow();

        $updated = $this->service->syncFollowContacts(self::owner(), [
            ['follow_id' => 1, 'encrypted_catalog_key' => self::WRAP],
        ]);

        self::assertSame(0, $updated);
        self::assertNull($follow->getEncryptedCatalogKey());
    }
}
