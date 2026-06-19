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
 * Unit tests for the location_city_id field on library_profiles (ADR-035).
 *
 * Verifies:
 *   - Value persists through upsertProfile on registration and update
 *   - Omitting the key preserves the stored value (array_key_exists pattern)
 *   - Explicit null clears the value
 *   - Non-positive integers are normalized to null (no FK validation by design)
 *   - toPublicArray surfaces the field
 */
// Shared EM/repository doubles: some tests set expectations (flush), others
// use them as pure stubs. Opt out of PHPUnit 12.5's no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceLocationCityIdTest extends TestCase
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

    public function testRegisterPersistsLocationCityId(): void
    {
        $this->profileRepository->method('findByNodeId')->willReturn(null);

        $persisted = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function ($entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'          => 'node-abc',
            'display_name'     => 'Test Library',
            'location_city_id' => 2988507, // GeoNames ID for Paris
        ], null);

        self::assertInstanceOf(LibraryProfile::class, $persisted);
        self::assertSame(2988507, $persisted->getLocationCityId());
    }

    public function testUpdateWithoutLocationCityIdKeepsExistingValue(): void
    {
        // Backward-compat invariant: older clients that do NOT send
        // location_city_id must not wipe a previously-stored value.
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $existing->setLocationCityId(2988507);

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library (renamed)',
            // location_city_id intentionally absent
        ], $existing);

        self::assertSame(2988507, $existing->getLocationCityId());
    }

    public function testUpdateWithNullLocationCityIdClearsValue(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $existing->setLocationCityId(2988507);

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'          => 'node-abc',
            'location_city_id' => null,
        ], $existing);

        self::assertNull($existing->getLocationCityId());
    }

    public function testNonPositiveIdIsNormalizedToNull(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'          => 'node-abc',
            'location_city_id' => 0,
        ], $existing);

        self::assertNull($existing->getLocationCityId());
    }

    public function testNegativeIdIsNormalizedToNull(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'          => 'node-abc',
            'location_city_id' => -42,
        ], $existing);

        self::assertNull($existing->getLocationCityId());
    }

    public function testStringNumericIdIsAccepted(): void
    {
        // JSON bodies sometimes ship integers as strings depending on client
        // serialization quirks; the (int) cast must accept "2988507" as 2988507.
        $this->profileRepository->method('findByNodeId')->willReturn(null);

        $persisted = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function ($entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'          => 'node-abc',
            'display_name'     => 'Test Library',
            'location_city_id' => '2988507',
        ], null);

        self::assertInstanceOf(LibraryProfile::class, $persisted);
        self::assertSame(2988507, $persisted->getLocationCityId());
    }

    public function testToPublicArrayIncludesLocationCityId(): void
    {
        $profile = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $profile->setLocationCityId(2988507);

        $public = $profile->toPublicArray();

        self::assertArrayHasKey('location_city_id', $public);
        self::assertSame(2988507, $public['location_city_id']);
    }

    public function testToPublicArrayReturnsNullWhenUnset(): void
    {
        $profile = new LibraryProfile('node-abc', 'write-tok', 'Test Library');

        $public = $profile->toPublicArray();

        self::assertArrayHasKey('location_city_id', $public);
        self::assertNull($public['location_city_id']);
    }
}
