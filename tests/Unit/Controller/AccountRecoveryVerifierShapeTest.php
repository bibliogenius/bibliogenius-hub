<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AccountController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the shape contract on the recovery marker (ADR-042 section 16.3).
 *
 * The marker is the hex SHA-256 of an HKDF output, so exactly 64 hex
 * characters. What makes the shape worth enforcing at signup rather than
 * shrugging at is section 16.5: null is the readable marker of "never
 * derived", and it is what a later count measures to size the population that
 * still needs retrofitting. A wrong-but-present value would never match at
 * recovery AND would not be null, so it would escape that count entirely, and
 * the hub is forbidden from repairing it since only the account's own user
 * holds the phrase. Refusing it here is the only place the mistake is cheap.
 *
 * The realistic source of a wrong value is not an attacker (there is nothing to
 * gain by sabotaging one's own account) but a second implementation: lot C
 * brings a web client that has to reproduce the derivation exactly.
 */
final class AccountRecoveryVerifierShapeTest extends TestCase
{
    /** A real marker: hex SHA-256 of 32 zero bytes, the value the Rust side locks too. */
    private const VALID = '66687aadf862bd776c8fc18b8e9f8e20089714856ee233b3902a591d0d5f2925';

    public function testTheHexSha256ShapeIsAccepted(): void
    {
        $this->assertSame(64, strlen(self::VALID));
        $this->assertTrue($this->isRecoveryVerifierHash(self::VALID));
    }

    public function testUppercaseHexIsAccepted(): void
    {
        // Nothing in the contract pins the case, and refusing it would break a
        // client for a reason that is not a mistake.
        $this->assertTrue($this->isRecoveryVerifierHash(strtoupper(self::VALID)));
    }

    #[DataProvider('malformedValues')]
    public function testAMalformedMarkerIsRefused(string $label, ?string $value): void
    {
        $this->assertFalse($this->isRecoveryVerifierHash($value), $label);
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function malformedValues(): array
    {
        return [
            'absent' => ['a null value carries no marker', null],
            'empty' => ['the empty string is not a marker', ''],
            'prose' => ['plain text must not pass', 'bonjour'],
            'too short' => ['a truncated hash', substr(self::VALID, 0, 63)],
            'too long' => ['one character too many', self::VALID . '0'],
            // The shape a client would produce by encoding the same digest the
            // wrong way: right bytes, wrong alphabet, plausible length.
            'base64' => ['base64 of the digest, not hex', base64_encode(hex2bin(self::VALID))],
            'non hex' => ['64 characters but not all hex', str_repeat('z', 64)],
            'padded' => ['a valid hash with surrounding space', ' ' . substr(self::VALID, 1)],
        ];
    }

    private function isRecoveryVerifierHash(?string $value): bool
    {
        // isRecoveryVerifierHash() is private static by design: it is an internal
        // detail of the signup endpoint, not a reusable helper. Reflection keeps
        // the visibility honest while still letting us assert on the contract,
        // the same way InviteControllerTest reaches generateToken().
        static $method = null;
        if ($method === null) {
            $method = new \ReflectionMethod(AccountController::class, 'isRecoveryVerifierHash');
        }

        return (bool) $method->invoke(null, $value);
    }
}
