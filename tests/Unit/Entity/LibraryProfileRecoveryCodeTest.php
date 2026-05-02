<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\LibraryProfile;
use PHPUnit\Framework\TestCase;

/**
 * Dual-read verification of recovery_code_hash (KAN-286).
 *
 * Covers:
 *   - new BCrypt hash verifies and is not flagged for rehash
 *   - legacy SHA-256 hex digest still verifies and IS flagged for rehash
 *   - invalid codes are rejected against both formats
 *   - missing hash never returns true
 */
final class LibraryProfileRecoveryCodeTest extends TestCase
{
    /** Cheap BCrypt cost so the entity tests stay fast. The entity does not care about the cost itself. */
    private const TEST_BCRYPT_COST = 4;

    private function newProfile(): LibraryProfile
    {
        return new LibraryProfile('node-abc', 'write-tok', 'Test Library');
    }

    public function testVerifyRecoveryCodeWithBcryptHash(): void
    {
        $profile = $this->newProfile();
        $code = 'ABCDEFGHJKMN';
        $profile->setRecoveryCodeHash(password_hash($code, PASSWORD_BCRYPT, ['cost' => self::TEST_BCRYPT_COST]));

        self::assertTrue($profile->verifyRecoveryCode($code));
        self::assertFalse($profile->recoveryCodeNeedsRehash());
    }

    public function testVerifyRecoveryCodeWithLegacySha256HashFlagsForRehash(): void
    {
        $profile = $this->newProfile();
        $code = 'ABCDEFGHJKMN';
        $profile->setRecoveryCodeHash(hash('sha256', $code));

        self::assertTrue($profile->verifyRecoveryCode($code));
        self::assertTrue(
            $profile->recoveryCodeNeedsRehash(),
            'Legacy SHA-256 hash must be flagged for upgrade to BCrypt.',
        );
    }

    public function testVerifyRecoveryCodeRejectsWrongCodeOnBcrypt(): void
    {
        $profile = $this->newProfile();
        $profile->setRecoveryCodeHash(
            password_hash('CORRECT-CODE', PASSWORD_BCRYPT, ['cost' => self::TEST_BCRYPT_COST]),
        );

        self::assertFalse($profile->verifyRecoveryCode('WRONG-CODE'));
    }

    public function testVerifyRecoveryCodeRejectsWrongCodeOnLegacySha256(): void
    {
        $profile = $this->newProfile();
        $profile->setRecoveryCodeHash(hash('sha256', 'CORRECT-CODE'));

        self::assertFalse($profile->verifyRecoveryCode('WRONG-CODE'));
    }

    public function testVerifyRecoveryCodeFalseWhenHashMissing(): void
    {
        $profile = $this->newProfile();
        // Default state: no recovery hash set.

        self::assertFalse($profile->verifyRecoveryCode('ANY-CODE'));
        self::assertFalse(
            $profile->recoveryCodeNeedsRehash(),
            'A null hash is not "legacy" — it just means no recovery code was issued yet.',
        );
    }

    /**
     * Defensive: a 64-hex-char value that is not the SHA-256 of the provided
     * code must NOT verify just because the format is the legacy shape.
     */
    public function testLegacyShapeDoesNotShortCircuitVerification(): void
    {
        $profile = $this->newProfile();
        $profile->setRecoveryCodeHash(str_repeat('a', 64));

        self::assertFalse($profile->verifyRecoveryCode('ABCDEFGHJKMN'));
        self::assertTrue($profile->recoveryCodeNeedsRehash());
    }
}
