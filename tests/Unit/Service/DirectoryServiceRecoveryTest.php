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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Service-level tests for recovery_code_hash BCrypt migration (KAN-286).
 *
 * Covers:
 *   - upsertProfile (new registration) writes a BCrypt-formatted hash
 *   - recoverProfile accepts a code that was stored with the legacy SHA-256
 *     algorithm and rotates the persisted hash to BCrypt
 *   - recoverProfile accepts a code stored with BCrypt and rotates again
 *   - recoverProfile rejects a wrong code without rotating anything
 *   - recoverProfile returns null when the profile is unknown
 */
// Shared EM/repository doubles: some tests set expectations, others use them
// as pure stubs. Opt out of PHPUnit 12.5's no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceRecoveryTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LibraryProfileRepository&MockObject $profileRepository;
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

    public function testNewRegistrationStoresBcryptRecoveryHash(): void
    {
        $this->profileRepository->method('findByNodeId')->willReturn(null);
        $this->profileRepository->method('findByDeviceFingerprint')->willReturn([]);

        $captured = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (LibraryProfile $p) use (&$captured): bool {
                $captured = $p;
                return true;
            }));
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->upsertProfile([
            'node_id'      => 'node-new',
            'display_name' => 'New Library',
        ], null);

        self::assertNotNull($result['recovery_code']);
        self::assertNotNull($captured);
        $hash = $captured->getRecoveryCodeHash();
        self::assertNotNull($hash);
        self::assertStringStartsWith(
            '$2y$',
            $hash,
            'Newly registered profiles must store a BCrypt $2y$ hash, not SHA-256.',
        );
        // Sanity: the returned plaintext code verifies against the persisted hash.
        self::assertTrue(password_verify($result['recovery_code'], $hash));
    }

    public function testRecoverProfileWithLegacySha256CodeUpgradesToBcrypt(): void
    {
        $code = 'ABCDEFGHJKMN';
        $profile = new LibraryProfile('node-legacy', 'write-tok', 'Legacy Library');
        $profile->setRecoveryCodeHash(hash('sha256', $code));

        $this->profileRepository
            ->expects(self::once())
            ->method('findByNodeId')
            ->with('node-legacy')
            ->willReturn($profile);

        $this->em->expects(self::once())->method('flush');

        $result = $this->service->recoverProfile('node-legacy', $code);

        self::assertNotNull($result, 'Legacy SHA-256 hash must still verify after migration.');
        self::assertSame($profile, $result['profile']);
        self::assertNotEmpty($result['write_token']);
        self::assertNotEmpty($result['recovery_code']);

        $newHash = $profile->getRecoveryCodeHash();
        self::assertNotNull($newHash);
        self::assertStringStartsWith(
            '$2y$',
            $newHash,
            'After recovery, the persisted hash must be upgraded to BCrypt.',
        );
        self::assertTrue(password_verify($result['recovery_code'], $newHash));
        self::assertFalse(
            $profile->recoveryCodeNeedsRehash(),
            'Post-recovery hash must no longer be flagged as legacy.',
        );
    }

    public function testRecoverProfileWithBcryptCodeRotatesHash(): void
    {
        $oldCode = 'ABCDEFGHJKMN';
        $profile = new LibraryProfile('node-modern', 'write-tok', 'Modern Library');
        // Cost 4 keeps the test fast — the service does not care about the cost on read.
        $profile->setRecoveryCodeHash(password_hash($oldCode, PASSWORD_BCRYPT, ['cost' => 4]));
        $oldHash = $profile->getRecoveryCodeHash();

        $this->profileRepository
            ->expects(self::once())
            ->method('findByNodeId')
            ->willReturn($profile);

        $this->em->expects(self::once())->method('flush');

        $result = $this->service->recoverProfile('node-modern', $oldCode);

        self::assertNotNull($result);
        self::assertNotSame(
            $oldHash,
            $profile->getRecoveryCodeHash(),
            'Recovery must always rotate the hash, even when input was already BCrypt.',
        );
        self::assertStringStartsWith('$2y$', (string) $profile->getRecoveryCodeHash());
    }

    public function testRecoverProfileRejectsInvalidCode(): void
    {
        $profile = new LibraryProfile('node-x', 'write-tok', 'X');
        $profile->setRecoveryCodeHash(hash('sha256', 'CORRECT-CODE'));
        $originalHash = $profile->getRecoveryCodeHash();
        $originalToken = $profile->getWriteToken();

        $this->profileRepository->method('findByNodeId')->willReturn($profile);

        // No flush on a failed verification — nothing to persist.
        $this->em->expects(self::never())->method('flush');

        $result = $this->service->recoverProfile('node-x', 'WRONG-CODE');

        self::assertNull($result);
        self::assertSame($originalHash, $profile->getRecoveryCodeHash());
        self::assertSame($originalToken, $profile->getWriteToken());
    }

    public function testRecoverProfileReturnsNullWhenProfileMissing(): void
    {
        $this->profileRepository->method('findByNodeId')->willReturn(null);
        $this->em->expects(self::never())->method('flush');

        self::assertNull($this->service->recoverProfile('node-unknown', 'ABCDEFGHJKMN'));
    }
}
