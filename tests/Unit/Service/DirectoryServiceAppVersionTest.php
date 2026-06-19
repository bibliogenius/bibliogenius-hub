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
 * Unit tests for the app_version field on library_profiles.
 *
 * Verifies:
 *   - Value persists through upsertProfile on registration and update
 *   - Omitting the key preserves the stored value (array_key_exists pattern)
 *   - Explicit null clears the value
 *   - Malformed values are rejected silently (prior value kept)
 *   - toPublicArray surfaces the field alongside device_model
 */
// Shared EM/repository doubles: some tests set expectations (flush), others
// use them as pure stubs. Opt out of PHPUnit 12.5's no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceAppVersionTest extends TestCase
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

    public function testRegisterPersistsAppVersion(): void
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
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library',
            'app_version'  => '0.9.0-alpha.1+422',
        ], null);

        self::assertInstanceOf(LibraryProfile::class, $persisted);
        self::assertSame('0.9.0-alpha.1+422', $persisted->getAppVersion());
    }

    public function testUpdateWithoutAppVersionKeepsExistingValue(): void
    {
        // Critical backward-compat invariant: older clients that do NOT send
        // app_version must not wipe a previously-stored value.
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $existing->setAppVersion('0.8.5+300');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'      => 'node-abc',
            'display_name' => 'Test Library (renamed)',
            // app_version intentionally absent
        ], $existing);

        self::assertSame('0.8.5+300', $existing->getAppVersion());
    }

    public function testUpdateWithNullAppVersionClearsValue(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $existing->setAppVersion('0.9.0+422');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->upsertProfile([
            'node_id'     => 'node-abc',
            'app_version' => null,
        ], $existing);

        self::assertNull($existing->getAppVersion());
    }

    public function testInvalidAppVersionIsRejectedAndKeepsPrevious(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $existing->setAppVersion('0.9.0+422');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        // <script> tag attempts, oversize strings, and forbidden chars must
        // silently fail (log candidate but do not overwrite).
        $this->service->upsertProfile([
            'node_id'     => 'node-abc',
            'app_version' => '<script>alert(1)</script>',
        ], $existing);

        self::assertSame('0.9.0+422', $existing->getAppVersion());
    }

    public function testAppVersionIsTruncatedToMaxLength(): void
    {
        $existing = new LibraryProfile('node-abc', 'write-tok', 'Test Library');

        $this->profileRepository->method('findByNodeId')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        // 33 valid chars — should be rejected OR truncated to 32.
        // Contract: truncate to 32 to keep maximum info without overflow.
        $long = str_repeat('a', 33);
        $this->service->upsertProfile([
            'node_id'     => 'node-abc',
            'app_version' => $long,
        ], $existing);

        $stored = $existing->getAppVersion();
        self::assertNotNull($stored);
        self::assertLessThanOrEqual(32, strlen($stored));
    }

    public function testToPublicArrayIncludesAppVersion(): void
    {
        $profile = new LibraryProfile('node-abc', 'write-tok', 'Test Library');
        $profile->setAppVersion('0.9.0+422');

        $public = $profile->toPublicArray();

        self::assertArrayHasKey('app_version', $public);
        self::assertSame('0.9.0+422', $public['app_version']);
    }

    public function testToPublicArrayReturnsNullWhenUnset(): void
    {
        $profile = new LibraryProfile('node-abc', 'write-tok', 'Test Library');

        $public = $profile->toPublicArray();

        self::assertArrayHasKey('app_version', $public);
        self::assertNull($public['app_version']);
    }
}
