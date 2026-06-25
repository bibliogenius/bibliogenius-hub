<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\AccountAuthChallengeRepository;
use App\Service\AccountAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Guards the account auth primitives (ADR-043, SECURITY_GUIDELINES PARTIE F):
 *
 *  - login = Ed25519 challenge-response verified against account_auth_pk,
 *    constant-time, never throwing on malformed input (uniform 401);
 *  - keybundle download = HMAC keyed by the stored auth_verifier_hash, so the
 *    AuthVerifier preimage never transits (M2);
 *  - the session bearer token is opaque random; only its hash is stored, so an
 *    attacker who reads the cache cannot recover a usable token, and an unknown
 *    token resolves to nothing.
 */
final class AccountAuthServiceTest extends TestCase
{
    private function service(): AccountAuthService
    {
        // A stub (not a mock): these tests exercise crypto/token logic, not the
        // challenge repository, so no interaction expectations are configured.
        return new AccountAuthService(
            $this->createStub(AccountAuthChallengeRepository::class),
            new ArrayAdapter(),
        );
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function testVerifyLoginSignatureAcceptsAValidSignature(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($keypair);
        $sk = sodium_crypto_sign_secretkey($keypair);

        $challengeRaw = random_bytes(32);
        $challenge = self::b64url($challengeRaw);
        $signature = self::b64url(sodium_crypto_sign_detached($challengeRaw, $sk));

        $this->assertTrue(
            $this->service()->verifyLoginSignature(self::b64url($pk), $challenge, $signature),
        );
    }

    public function testVerifyLoginSignatureRejectsATamperedSignature(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($keypair);
        $sk = sodium_crypto_sign_secretkey($keypair);

        $challengeRaw = random_bytes(32);
        $sig = sodium_crypto_sign_detached($challengeRaw, $sk);
        $sig[0] = $sig[0] === "\x00" ? "\x01" : "\x00"; // flip a byte

        $this->assertFalse(
            $this->service()->verifyLoginSignature(
                self::b64url($pk),
                self::b64url($challengeRaw),
                self::b64url($sig),
            ),
        );
    }

    public function testVerifyLoginSignatureRejectsWrongKeyAndMalformedInput(): void
    {
        $svc = $this->service();
        // Wrong-length public key.
        $this->assertFalse($svc->verifyLoginSignature(self::b64url('short'), self::b64url('msg'), self::b64url(str_repeat('x', 64))));
        // Non-base64 garbage never throws, just fails.
        $this->assertFalse($svc->verifyLoginSignature('!!!not-base64!!!', '###', '***'));
    }

    public function testVerifyKeybundleMacMatchesHmacKeyedByStoredHash(): void
    {
        $svc = $this->service();
        $authVerifierHash = bin2hex(random_bytes(32)); // what the hub stores
        $challenge = self::b64url(random_bytes(32));
        $mac = hash_hmac('sha256', $challenge, $authVerifierHash);

        $this->assertTrue($svc->verifyKeybundleMac($authVerifierHash, $challenge, $mac));
        $this->assertFalse($svc->verifyKeybundleMac($authVerifierHash, $challenge, 'deadbeef'));
        $this->assertFalse($svc->verifyKeybundleMac('other-key', $challenge, $mac));
    }

    public function testSessionTokenMintResolveRevoke(): void
    {
        $svc = $this->service();
        $token = $svc->mintSessionToken('account-123');

        $this->assertNotSame('', $token);
        $this->assertSame('account-123', $svc->resolveSessionToken($token));
        $this->assertNull($svc->resolveSessionToken('an-unknown-token'));
        $this->assertNull($svc->resolveSessionToken(''));

        $svc->revokeSessionToken($token);
        $this->assertNull($svc->resolveSessionToken($token));
    }

    public function testGenerateAccountIdIsOpaqueAndUnique(): void
    {
        $svc = $this->service();
        $a = $svc->generateAccountId();
        $b = $svc->generateAccountId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function testConsumeChallengeDelegatesToRepository(): void
    {
        $repo = $this->createMock(AccountAuthChallengeRepository::class);
        $repo->expects($this->once())
            ->method('consume')
            ->with('acc', 'login', 'nonce')
            ->willReturn(true);

        $svc = new AccountAuthService($repo, new ArrayAdapter());
        $this->assertTrue($svc->consumeChallenge('acc', 'login', 'nonce'));
    }
}
