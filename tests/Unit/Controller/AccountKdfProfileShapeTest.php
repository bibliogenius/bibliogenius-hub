<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AccountController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the KDF profile contract the hub enforces at signup AND at passphrase
 * rotation (ADR-042 section 3 and 14/H4). Both endpoints share one helper, so
 * this pins the helper itself rather than each caller: a joining device refuses
 * a profile outside these bounds (validate_profile on the client), so storing
 * one would create an account nobody can join through path A, with no error
 * anywhere. The bounds mirror the client floor exactly; the ceiling is
 * robustness (a profile a phone cannot execute closes path A just as surely).
 */
final class AccountKdfProfileShapeTest extends TestCase
{
    /** The single mandatory v1 profile every shipped client sends. */
    private const BASELINE = ['algo' => 'argon2id', 'version' => 19, 'm' => 65536, 't' => 3, 'p' => 1];

    public function testTheBaselineProfileIsAccepted(): void
    {
        $this->assertTrue($this->isAcceptedKdfProfile(self::BASELINE));
    }

    public function testExtraKeysAreTolerated(): void
    {
        // The smoke client sends `out: 32`; only the pinned fields are checked.
        $this->assertTrue($this->isAcceptedKdfProfile(self::BASELINE + ['out' => 32]));
    }

    public function testAStrongerProfileWithinTheCeilingIsAccepted(): void
    {
        // A future cost upgrade (ADR-042 section 3) must not be refused by the hub.
        $this->assertTrue($this->isAcceptedKdfProfile(['m' => 262144, 't' => 4] + self::BASELINE));
    }

    #[DataProvider('refusedProfiles')]
    public function testAProfileAJoiningDeviceWouldRefuseIsRefused(string $label, mixed $profile): void
    {
        $this->assertFalse($this->isAcceptedKdfProfile($profile), $label);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function refusedProfiles(): array
    {
        $b = self::BASELINE;

        return [
            'not an object' => ['a bare string is not a profile', 'argon2id'],
            'null' => ['absent', null],
            'empty' => ['no fields at all', []],
            'other kdf' => ['no client derives pbkdf2', ['algo' => 'pbkdf2'] + $b],
            'old version' => ['Argon2 version other than 0x13', ['version' => 16] + $b],
            'memory below floor' => ['m < 64 MiB makes brute force cheap', ['m' => 19456] + $b],
            'memory above ceiling' => ['m > 1 GiB is not executable on a phone', ['m' => 2097152] + $b],
            'passes below floor' => ['t < 3', ['t' => 2] + $b],
            'passes above ceiling' => ['t > 10', ['t' => 50] + $b],
            'parallelism' => ['p must be 1 for WASM parity', ['p' => 4] + $b],
            'string cost' => ['costs must be JSON integers', ['m' => '65536'] + $b],
            'float cost' => ['a float is not an integer', ['m' => 65536.0] + $b],
            'missing p' => ['every pinned field is required', array_diff_key($b, ['p' => 0])],
        ];
    }

    private function isAcceptedKdfProfile(mixed $profile): bool
    {
        // Private static by design: an internal detail of the two endpoints, not
        // a reusable helper. Reflection keeps the visibility honest, the same
        // way AccountRecoveryVerifierShapeTest reaches isRecoveryVerifierHash().
        static $method = null;
        if ($method === null) {
            $method = new \ReflectionMethod(AccountController::class, 'isAcceptedKdfProfile');
        }

        return (bool) $method->invoke(null, $profile);
    }
}
