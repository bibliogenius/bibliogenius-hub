<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Account;
use App\Entity\AccountAuthChallenge;
use App\Entity\WrappedAccountKey;
use App\Repository\AccountDeviceRegistryRepository;
use App\Repository\AccountRepository;
use App\Repository\WrappedAccountKeyRepository;
use App\Service\AccountAuthService;
use App\Service\HubEventLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Account lifecycle + auth for the E2EE sync store (ADR-043). Never stores or
 * returns a decrypting secret: it persists public auth material, wrapped (opaque)
 * key bundles, and the opaque signed device registry. See the account sync hub
 * protocol doc for the wire contract.
 */
#[Route('/api/account', name: 'api_account_')]
class AccountController extends AbstractController
{
    use AccountApiTrait;

    // Body bounds (no global request_body limit in Caddy). signup carries the
    // registry + wrapped-key blobs, so it gets a larger budget than the tiny
    // auth bodies; the per-blob caps below bound what is actually stored.
    private const MAX_SIGNUP_BODY_BYTES = 1024 * 1024;
    private const MAX_AUTH_BODY_BYTES = 64 * 1024;
    private const MAX_REGISTRY_BLOB_BYTES = 64 * 1024;
    private const MAX_WRAPPED_KEY_BYTES = 8 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accounts,
        private readonly WrappedAccountKeyRepository $wrappedKeys,
        private readonly AccountDeviceRegistryRepository $deviceRegistry,
        private readonly AccountAuthService $auth,
        private readonly HubEventLogger $eventLogger,
        private readonly RateLimiterFactoryInterface $accountSignupAnonLimiter,
        private readonly RateLimiterFactoryInterface $accountBootstrapAnonLimiter,
        private readonly RateLimiterFactoryInterface $accountChallengeAnonLimiter,
        private readonly RateLimiterFactoryInterface $accountLoginAnonLimiter,
        private readonly RateLimiterFactoryInterface $accountKeybundleAnonLimiter,
    ) {
    }

    /**
     * POST /api/account/signup - create an account. No auth (rate-limited).
     * The passphrase policy and recovery kit are enforced client-side; the hub
     * stores only the resulting material.
     */
    #[Route('/signup', name: 'signup', methods: ['POST'])]
    public function signup(Request $request): JsonResponse
    {
        if (($limited = $this->enforce($this->accountSignupAnonLimiter, $request->getClientIp() ?? 'unknown')) !== null) {
            return $limited;
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_SIGNUP_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $email = self::stringField($data, 'email');
        $accountSalt = self::base64Field($data, 'account_salt', 32);
        $authPk = self::base64Field($data, 'account_auth_pk', 32);
        $descriptorSig = self::base64Field($data, 'descriptor_sig', 64);
        $authVerifierHash = self::stringField($data, 'auth_verifier_hash', 128);
        // Optional: clients older than the recovery marker (ADR-042 section 16.3)
        // do not send it, and the column stays null for those accounts.
        $recoveryVerifierHash = self::stringField($data, 'recovery_verifier_hash', 128);
        $authMethod = self::stringField($data, 'auth_method', 32);
        $aeadAlg = self::stringField($data, 'aead_alg', 32);
        $registryBlob = self::rawBase64Field($data, 'device_registry_blob', self::MAX_REGISTRY_BLOB_BYTES);
        $kdfParams = $data['kdf_params'] ?? null;
        $schemaVersion = $data['schema_version'] ?? null;

        // A recovery marker that is present but malformed is refused rather than
        // stored as null: null means "no marker was ever derived" and drives a
        // later retrofit count (section 16.5), so it must not absorb bad input.
        $recoveryVerifierMalformed = array_key_exists('recovery_verifier_hash', $data)
            && !self::isRecoveryVerifierHash($recoveryVerifierHash);

        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $accountSalt === null || $authPk === null || $descriptorSig === null
            || $authVerifierHash === null || $authMethod === null || $aeadAlg === null
            || $registryBlob === null || !is_array($kdfParams) || !is_int($schemaVersion)
            || $recoveryVerifierMalformed) {
            return $this->json(['error' => 'Missing or invalid account fields'], Response::HTTP_BAD_REQUEST);
        }

        $wrappedKeysIn = $this->parseWrappedKeys($data['wrapped_keys'] ?? null);
        if ($wrappedKeysIn === null) {
            return $this->json(['error' => 'Invalid wrapped_keys'], Response::HTTP_BAD_REQUEST);
        }

        $email = strtolower($email);
        if ($this->accounts->findOneByEmail($email) !== null) {
            return $this->json(['error' => 'Account already exists'], Response::HTTP_CONFLICT);
        }

        $accountId = $this->auth->generateAccountId();

        $account = new Account();
        $account->setAccountId($accountId)
            ->setEmail($email)
            ->setAccountSalt($data['account_salt'])
            ->setKdfParams(json_encode($kdfParams, JSON_UNESCAPED_SLASHES))
            ->setAccountAuthPk($data['account_auth_pk'])
            ->setAuthVerifierHash($authVerifierHash)
            ->setRecoveryVerifierHash($recoveryVerifierHash)
            ->setSchemaVersion($schemaVersion)
            ->setAuthMethod($authMethod)
            ->setAeadAlg($aeadAlg)
            ->setDescriptorSig($data['descriptor_sig']);

        try {
            // No ORM association is mapped (lanes key on plain account_id for
            // blindness), so Doctrine cannot order these inserts. Insert the
            // parent row first, then the FK children, atomically.
            $this->entityManager->wrapInTransaction(function () use ($account, $accountId, $wrappedKeysIn, $registryBlob): void {
                $this->entityManager->persist($account);
                $this->entityManager->flush();

                foreach ($wrappedKeysIn as $kind => $blob) {
                    $this->wrappedKeys->upsert($accountId, $kind, $blob);
                }
                $this->deviceRegistry->publish($accountId, $registryBlob);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Account already exists'], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            $this->eventLogger->error('account_sync', 'signup failed', ['reason' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to create account'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->eventLogger->info('account_sync', 'account created', ['count' => count($wrappedKeysIn)]);

        return $this->json(['account_id' => $accountId], Response::HTTP_CREATED);
    }

    /**
     * GET /api/account/bootstrap?email= - public KDF + descriptor material a
     * fresh device needs to derive MK and pin the descriptor (path A).
     */
    #[Route('/bootstrap', name: 'bootstrap', methods: ['GET'])]
    public function bootstrap(Request $request): JsonResponse
    {
        if (($limited = $this->enforce($this->accountBootstrapAnonLimiter, $request->getClientIp() ?? 'unknown')) !== null) {
            return $limited;
        }

        $email = (string) $request->query->get('email', '');
        $account = $email !== '' ? $this->accounts->findOneByEmail(strtolower($email)) : null;
        if ($account === null) {
            return $this->json(['error' => 'Account not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'account_salt' => $account->getAccountSalt(),
            'kdf_params' => json_decode($account->getKdfParams(), true),
            'schema_version' => $account->getSchemaVersion(),
            'auth_method' => $account->getAuthMethod(),
            'aead_alg' => $account->getAeadAlg(),
            'account_auth_pk' => $account->getAccountAuthPk(),
            'descriptor_sig' => $account->getDescriptorSig(),
        ]);
    }

    /**
     * POST /api/account/challenge - issue a one-time nonce for login/keybundle.
     */
    #[Route('/challenge', name: 'challenge', methods: ['POST'])]
    public function challenge(Request $request): JsonResponse
    {
        if (($limited = $this->enforce($this->accountChallengeAnonLimiter, $request->getClientIp() ?? 'unknown')) !== null) {
            return $limited;
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_AUTH_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        $email = $data !== null ? self::stringField($data, 'email') : null;
        $purpose = $data !== null ? self::stringField($data, 'purpose', 16) : null;
        if ($email === null || $purpose === null || !in_array($purpose, AccountAuthChallenge::PURPOSES, true)) {
            return $this->json(['error' => 'Invalid challenge request'], Response::HTTP_BAD_REQUEST);
        }

        $account = $this->accounts->findOneByEmail(strtolower($email));
        if ($account === null) {
            return $this->json(['error' => 'Account not found'], Response::HTTP_NOT_FOUND);
        }

        $issued = $this->auth->issueChallenge($account->getAccountId(), $purpose);

        return $this->json([
            'challenge' => $issued['challenge'],
            'expires_at' => $issued['expires_at']->format(\DateTimeInterface::RFC3339),
        ]);
    }

    /**
     * POST /api/account/login - Ed25519 challenge-response -> opaque token.
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        if (($limited = $this->enforce($this->accountLoginAnonLimiter, $request->getClientIp() ?? 'unknown')) !== null) {
            return $limited;
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_AUTH_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        $email = $data !== null ? self::stringField($data, 'email') : null;
        $challenge = $data !== null ? self::stringField($data, 'challenge') : null;
        $signature = $data !== null ? self::stringField($data, 'signature') : null;
        if ($email === null || $challenge === null || $signature === null) {
            return $this->json(['error' => 'Invalid login request'], Response::HTTP_BAD_REQUEST);
        }

        $account = $this->accounts->findOneByEmail(strtolower($email));
        if ($account === null) {
            return $this->json(['error' => 'Authentication failed'], Response::HTTP_UNAUTHORIZED);
        }

        // Consume first (atomic, replay-safe), then verify the signature.
        if (!$this->auth->consumeChallenge($account->getAccountId(), AccountAuthChallenge::PURPOSE_LOGIN, $challenge)
            || !$this->auth->verifyLoginSignature($account->getAccountAuthPk(), $challenge, $signature)) {
            return $this->json(['error' => 'Authentication failed'], Response::HTTP_UNAUTHORIZED);
        }

        $token = $this->auth->mintSessionToken($account->getAccountId());

        return $this->json([
            'token' => $token,
            'account_id' => $account->getAccountId(),
            'descriptor' => [
                'account_salt' => $account->getAccountSalt(),
                'kdf_params' => json_decode($account->getKdfParams(), true),
                'schema_version' => $account->getSchemaVersion(),
                'auth_method' => $account->getAuthMethod(),
                'aead_alg' => $account->getAeadAlg(),
                'account_auth_pk' => $account->getAccountAuthPk(),
                'descriptor_sig' => $account->getDescriptorSig(),
            ],
        ]);
    }

    /**
     * POST /api/account/keybundle - AuthVerifier HMAC challenge-response gate
     * for downloading the wrapped bundle (path A bootstrap / recovery).
     */
    #[Route('/keybundle', name: 'keybundle', methods: ['POST'])]
    public function keybundle(Request $request): JsonResponse
    {
        if (($limited = $this->enforce($this->accountKeybundleAnonLimiter, $request->getClientIp() ?? 'unknown')) !== null) {
            return $limited;
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_AUTH_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        $email = $data !== null ? self::stringField($data, 'email') : null;
        $challenge = $data !== null ? self::stringField($data, 'challenge') : null;
        $mac = $data !== null ? self::stringField($data, 'mac') : null;
        if ($email === null || $challenge === null || $mac === null) {
            return $this->json(['error' => 'Invalid keybundle request'], Response::HTTP_BAD_REQUEST);
        }

        $kinds = $this->parseKinds($data['kinds'] ?? [WrappedAccountKey::KIND_PASSPHRASE]);
        if ($kinds === null) {
            return $this->json(['error' => 'Invalid kinds'], Response::HTTP_BAD_REQUEST);
        }

        $account = $this->accounts->findOneByEmail(strtolower($email));
        if ($account === null) {
            return $this->json(['error' => 'Authentication failed'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->auth->consumeChallenge($account->getAccountId(), AccountAuthChallenge::PURPOSE_KEYBUNDLE, $challenge)
            || !$this->auth->verifyKeybundleMac($account->getAuthVerifierHash(), $challenge, $mac)) {
            return $this->json(['error' => 'Authentication failed'], Response::HTTP_UNAUTHORIZED);
        }

        $keys = $this->wrappedKeys->findByAccountAndKinds($account->getAccountId(), $kinds);
        $out = array_map(static fn (WrappedAccountKey $k): array => [
            'kind' => $k->getKind(),
            'blob' => base64_encode(self::blobToString($k->getBlob())),
        ], $keys);

        return $this->json(['wrapped_keys' => $out]);
    }

    /**
     * DELETE /api/account - RGPD purge (L4). Cascade-deletes lanes, wrapped
     * keys, registry, and challenges via the DB-level ON DELETE CASCADE.
     */
    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function deleteAccount(Request $request): JsonResponse
    {
        $accountId = $this->auth->authenticate($request);
        if ($accountId === null) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->accounts->purgeAccount($accountId);
        } catch (\Throwable $e) {
            $this->eventLogger->error('account_sync', 'account purge failed', ['reason' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to delete account'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $token = $this->auth->extractBearerToken($request);
        if ($token !== null) {
            $this->auth->revokeSessionToken($token);
        }
        $this->eventLogger->warning('account_sync', 'account deleted', []);

        return $this->json(['status' => 'deleted']);
    }

    // --- helpers (shared HTTP helpers live in AccountApiTrait) -----------

    /**
     * @return array<string,string>|null map kind => decoded blob, or null on invalid
     */
    private function parseWrappedKeys(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                return null;
            }
            $kind = $entry['kind'] ?? null;
            $blob = $entry['blob'] ?? null;
            if (!is_string($kind) || !in_array($kind, WrappedAccountKey::KINDS, true) || !is_string($blob)) {
                return null;
            }
            $decoded = base64_decode($blob, true);
            if ($decoded === false || $decoded === '' || strlen($decoded) > self::MAX_WRAPPED_KEY_BYTES) {
                return null;
            }
            $out[$kind] = $decoded;
        }
        // Path A bootstrap needs the passphrase copy at minimum.
        if (!isset($out[WrappedAccountKey::KIND_PASSPHRASE])) {
            return null;
        }

        return $out;
    }

    /**
     * @return string[]|null
     */
    private function parseKinds(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        foreach ($raw as $kind) {
            if (!is_string($kind) || !in_array($kind, WrappedAccountKey::KINDS, true)) {
                return null;
            }
        }

        return array_values($raw);
    }

    /**
     * Whether a value has the shape of a recovery marker: the hex SHA-256 of an
     * HKDF output, so exactly 64 hex characters (ADR-042 section 16.3).
     *
     * The shape is checked and not merely the presence, because a value of any
     * other shape can only come from a client deriving the marker wrongly, and
     * lot C explicitly adds implementations that must reproduce the derivation
     * (the web client). Stored as-is such a value would never match at recovery
     * AND would not be null, so it would escape the section 16.5 count: an
     * account neither working nor recensed, and one the hub is forbidden from
     * repairing since only its own user holds the phrase. Refusing at signup
     * turns that silent, distant failure into an immediate, local one.
     *
     * `ctype_xdigit` returns false on the empty string, which is the wanted
     * answer here; the length check makes it explicit rather than incidental.
     */
    private static function isRecoveryVerifierHash(?string $value): bool
    {
        return $value !== null && strlen($value) === 64 && ctype_xdigit($value);
    }

    private static function stringField(array $data, string $key, int $maxLen = 255): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLen) {
            return null;
        }

        return $value;
    }

    /**
     * Validate a base64url field decodes to exactly $expectedBytes bytes;
     * returns the decoded bytes (or null on mismatch).
     */
    private static function base64Field(array $data, string $key, int $expectedBytes): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== $expectedBytes) {
            return null;
        }

        return $decoded;
    }

    /**
     * Validate a base64 field decodes to non-empty bytes; returns the bytes.
     */
    private static function rawBase64Field(array $data, string $key, int $maxBytes = 0): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        if ($maxBytes > 0 && strlen($decoded) > $maxBytes) {
            return null;
        }

        return $decoded;
    }
}
