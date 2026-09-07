<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\AccountController;
use App\Entity\Account;
use App\Entity\AccountAuthChallenge;
use App\Entity\WrappedAccountKey;
use App\Repository\AccountDeviceRegistryRepository;
use App\Repository\AccountRepository;
use App\Repository\WrappedAccountKeyRepository;
use App\Service\AccountAuthService;
use App\Service\HubEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Guards the passphrase rotation gate and its blast radius (ADR-042 section 7
 * and 16.2, lot B):
 *
 *  - the gate is the bearer session PLUS a fresh `rotate` challenge signed by
 *    the account key, never the old AuthVerifier (the user may have lost it);
 *  - a good session with a signature the account key does not verify changes
 *    nothing (a stolen token alone is not enough);
 *  - a rotation replaces exactly salt, kdf params, verifier hash, descriptor
 *    signature and the kind=passphrase copy; the recovery marker, the account
 *    key and every other wrapped kind are out of reach;
 *  - the writes happen inside one transaction.
 */
final class AccountControllerPassphraseRotationTest extends TestCase
{
    private const ACCOUNT_ID = 'acct-rotation-test';
    private const CHALLENGE = 'bm9uY2U';
    private const OLD_SALT = 'b2xkLXNhbHQtb2xkLXNhbHQtb2xkLXNhbHQtb2xkLXM';
    private const OLD_VERIFIER = 'old-verifier-hash';
    private const OLD_SIG = 'b2xkLXNpZw';
    private const RECOVERY_MARKER = '66687aadf862bd776c8fc18b8e9f8e20089714856ee233b3902a591d0d5f2925';

    public function testRotationWithoutASessionIsUnauthorized(): void
    {
        $wrapped = $this->createMock(WrappedAccountKeyRepository::class);
        $wrapped->expects($this->never())->method('upsert');

        $auth = $this->createStub(AccountAuthService::class);
        $auth->method('authenticate')->willReturn(null);

        $controller = $this->buildController(auth: $auth, wrappedKeys: $wrapped);
        $response = $controller->rotatePassphrase($this->rotationRequest());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testASessionWithoutAValidStepUpSignatureChangesNothing(): void
    {
        $account = $this->account();
        $wrapped = $this->createMock(WrappedAccountKeyRepository::class);
        $wrapped->expects($this->never())->method('upsert');

        // The challenge is consumed (one-time) but the signature is not the
        // account key's: a stolen bearer token alone must not rotate anything.
        $auth = $this->authStub(consume: true, signatureValid: false);

        $controller = $this->buildController(auth: $auth, wrappedKeys: $wrapped, account: $account);
        $response = $controller->rotatePassphrase($this->rotationRequest());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertSame(self::OLD_SALT, $account->getAccountSalt());
        $this->assertSame(self::OLD_VERIFIER, $account->getAuthVerifierHash());
        $this->assertSame(self::OLD_SIG, $account->getDescriptorSig());
    }

    public function testAnUnknownOrReplayedChallengeIsRefused(): void
    {
        $account = $this->account();
        $wrapped = $this->createMock(WrappedAccountKeyRepository::class);
        $wrapped->expects($this->never())->method('upsert');

        $auth = $this->authStub(consume: false, signatureValid: true);

        $controller = $this->buildController(auth: $auth, wrappedKeys: $wrapped, account: $account);
        $response = $controller->rotatePassphrase($this->rotationRequest());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertSame(self::OLD_SALT, $account->getAccountSalt());
    }

    public function testTheStepUpChallengeMustCarryTheRotatePurpose(): void
    {
        $auth = $this->createMock(AccountAuthService::class);
        $auth->method('authenticate')->willReturn(self::ACCOUNT_ID);
        // A login nonce must never authorize a rotation: the purpose is pinned.
        $auth->expects($this->once())
            ->method('consumeChallenge')
            ->with(self::ACCOUNT_ID, AccountAuthChallenge::PURPOSE_ROTATE, self::CHALLENGE)
            ->willReturn(true);
        $auth->method('verifyLoginSignature')->willReturn(true);

        $controller = $this->buildController(auth: $auth, account: $this->account());
        $response = $controller->rotatePassphrase($this->rotationRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAMalformedBodyIsRefusedBeforeTheStepUp(): void
    {
        $auth = $this->createMock(AccountAuthService::class);
        $auth->method('authenticate')->willReturn(self::ACCOUNT_ID);
        $auth->expects($this->never())->method('consumeChallenge');

        $controller = $this->buildController(auth: $auth, account: $this->account());
        // A 16-byte salt where 32 are required.
        $response = $controller->rotatePassphrase($this->rotationRequest([
            'account_salt' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * A joining device refuses a KDF profile below the floor (validate_profile
     * on the client), so storing one would close path A for the account with
     * no error anywhere. Same for a verifier that is not a 64-hex digest: it
     * would never match the keybundle MAC. Both are refused before the step-up.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedMaterial')]
    public function testMaterialAJoiningDeviceWouldRefuseIsRejected(string $label, array $override): void
    {
        $auth = $this->createMock(AccountAuthService::class);
        $auth->method('authenticate')->willReturn(self::ACCOUNT_ID);
        $auth->expects($this->never())->method('consumeChallenge');

        $controller = $this->buildController(auth: $auth, account: $this->account());
        $response = $controller->rotatePassphrase($this->rotationRequest($override));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $label);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function rejectedMaterial(): array
    {
        $kdf = ['algo' => 'argon2id', 'version' => 19, 'm' => 65536, 't' => 3, 'p' => 1];

        return [
            'weak memory' => ['m below the 64 MiB floor', ['kdf_params' => ['m' => 256] + $kdf]],
            'weak passes' => ['t below 3', ['kdf_params' => ['t' => 1] + $kdf]],
            'parallelism' => ['p must be pinned to 1', ['kdf_params' => ['p' => 4] + $kdf]],
            'other kdf' => ['pbkdf2 is not derivable by any client', ['kdf_params' => ['algo' => 'pbkdf2'] + $kdf]],
            'old version' => ['Argon2 version other than 0x13', ['kdf_params' => ['version' => 16] + $kdf]],
            'string cost' => ['costs must be integers', ['kdf_params' => ['m' => '65536'] + $kdf]],
            'not an object' => ['kdf_params must be an object', ['kdf_params' => 'argon2id']],
            'verifier not hex' => ['auth_verifier_hash must be 64 hex chars', ['auth_verifier_hash' => str_repeat('z', 64)]],
            'verifier short' => ['a truncated digest', ['auth_verifier_hash' => str_repeat('a', 63)]],
        ];
    }

    public function testExtraKdfKeysAreTolerated(): void
    {
        // The smoke client sends `out: 32`; only the pinned fields are checked.
        $controller = $this->buildController(
            auth: $this->authStub(consume: true, signatureValid: true),
            account: $this->account(),
        );
        $response = $controller->rotatePassphrase($this->rotationRequest([
            'kdf_params' => ['algo' => 'argon2id', 'version' => 19, 'm' => 65536, 't' => 3, 'p' => 1, 'out' => 32],
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testARotationReplacesThePassphraseMaterialAndNothingElse(): void
    {
        $account = $this->account();
        $before = $account->getUpdatedAt();

        $wrapped = $this->createMock(WrappedAccountKeyRepository::class);
        $wrapped->expects($this->once())
            ->method('upsert')
            ->with(self::ACCOUNT_ID, WrappedAccountKey::KIND_PASSPHRASE, 'new-wrapped-copy')
            ->willReturn(new WrappedAccountKey());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work) => $work());

        $controller = $this->buildController(
            auth: $this->authStub(consume: true, signatureValid: true),
            wrappedKeys: $wrapped,
            account: $account,
            entityManager: $em,
        );
        $request = $this->rotationRequest();
        $body = json_decode((string) $request->getContent(), true);

        $response = $controller->rotatePassphrase($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('rotated', json_decode((string) $response->getContent(), true)['status']);

        // Replaced: what the passphrase derives.
        $this->assertSame($body['account_salt'], $account->getAccountSalt());
        $this->assertSame($body['auth_verifier_hash'], $account->getAuthVerifierHash());
        $this->assertSame($body['descriptor_sig'], $account->getDescriptorSig());
        $this->assertSame($body['kdf_params'], json_decode($account->getKdfParams(), true));
        $this->assertGreaterThanOrEqual($before, $account->getUpdatedAt());

        // Untouched: the recovery marker and the account key (the trousseau itself).
        $this->assertSame(self::RECOVERY_MARKER, $account->getRecoveryVerifierHash());
        $this->assertSame('account-pk', $account->getAccountAuthPk());
        $this->assertSame('passphrase', $account->getAuthMethod());
    }

    public function testASessionForAPurgedAccountIsUnauthorized(): void
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('find')->willReturn(null);

        $auth = $this->createMock(AccountAuthService::class);
        $auth->method('authenticate')->willReturn(self::ACCOUNT_ID);
        $auth->expects($this->never())->method('consumeChallenge');

        $controller = $this->buildController(auth: $auth, accounts: $accounts);
        $response = $controller->rotatePassphrase($this->rotationRequest());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Harness (same pattern as AccountSyncControllerQuotaTest)
    // ------------------------------------------------------------------

    private function account(): Account
    {
        return (new Account())
            ->setAccountId(self::ACCOUNT_ID)
            ->setEmail('reader@example.org')
            ->setAccountSalt(self::OLD_SALT)
            ->setKdfParams('{"algo":"argon2id","version":19,"m":65536,"t":3,"p":1}')
            ->setAccountAuthPk('account-pk')
            ->setAuthVerifierHash(self::OLD_VERIFIER)
            ->setRecoveryVerifierHash(self::RECOVERY_MARKER)
            ->setSchemaVersion(1)
            ->setAuthMethod('passphrase')
            ->setAeadAlg('AES-256-GCM')
            ->setDescriptorSig(self::OLD_SIG);
    }

    private function authStub(bool $consume, bool $signatureValid): AccountAuthService
    {
        $auth = $this->createStub(AccountAuthService::class);
        $auth->method('authenticate')->willReturn(self::ACCOUNT_ID);
        $auth->method('consumeChallenge')->willReturn($consume);
        $auth->method('verifyLoginSignature')->willReturn($signatureValid);

        return $auth;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function rotationRequest(array $overrides = []): Request
    {
        $body = array_merge([
            'challenge' => self::CHALLENGE,
            'signature' => rtrim(strtr(base64_encode(str_repeat("\x11", 64)), '+/', '-_'), '='),
            'account_salt' => rtrim(strtr(base64_encode(str_repeat("\x22", 32)), '+/', '-_'), '='),
            'kdf_params' => ['algo' => 'argon2id', 'version' => 19, 'm' => 65536, 't' => 3, 'p' => 1],
            'auth_verifier_hash' => str_repeat('a', 64),
            'descriptor_sig' => rtrim(strtr(base64_encode(str_repeat("\x33", 64)), '+/', '-_'), '='),
            'wrapped_key' => base64_encode('new-wrapped-copy'),
        ], $overrides);

        $request = new Request(content: (string) json_encode($body));
        $request->headers->set('Authorization', 'Bearer dummy-token');

        return $request;
    }

    private function acceptingLimiter(): RateLimiterFactoryInterface
    {
        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    private function buildController(
        AccountAuthService $auth,
        ?WrappedAccountKeyRepository $wrappedKeys = null,
        ?Account $account = null,
        ?AccountRepository $accounts = null,
        ?EntityManagerInterface $entityManager = null,
    ): AccountController {
        if ($accounts === null) {
            $accounts = $this->createStub(AccountRepository::class);
            $accounts->method('find')->willReturn($account);
        }
        if ($entityManager === null) {
            $entityManager = $this->createStub(EntityManagerInterface::class);
            $entityManager->method('wrapInTransaction')
                ->willReturnCallback(static fn (callable $work) => $work());
        }

        $controller = new AccountController(
            $entityManager,
            $accounts,
            $wrappedKeys ?? $this->createStub(WrappedAccountKeyRepository::class),
            $this->createStub(AccountDeviceRegistryRepository::class),
            $auth,
            $this->createStub(HubEventLogger::class),
            $this->acceptingLimiter(),
            $this->acceptingLimiter(),
            $this->acceptingLimiter(),
            $this->acceptingLimiter(),
            $this->acceptingLimiter(),
            $this->acceptingLimiter(),
        );
        $controller->setContainer(new Container());

        return $controller;
    }
}
