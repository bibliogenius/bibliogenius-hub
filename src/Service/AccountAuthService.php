<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountAuthChallengeRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Account auth primitives for the E2EE sync store (ADR-043).
 *
 * The hub holds no decrypting secret:
 *  - login = Ed25519 challenge-response verified against account_auth_pk
 *    (libsodium, constant-time);
 *  - keybundle download = HMAC challenge-response keyed by the stored
 *    auth_verifier_hash, so the AuthVerifier preimage never transits (M2);
 *  - the session bearer token is a random 256-bit opaque string; only its
 *    SHA-256 hash is stored, in a dedicated short-TTL cache pool. No JWT.
 */
class AccountAuthService
{
    public const CHALLENGE_TTL_SECONDS = 120;
    public const SESSION_TOKEN_TTL_SECONDS = 1800; // 30 min

    private const SESSION_CACHE_PREFIX = 'acct_sess_';

    public function __construct(
        private readonly AccountAuthChallengeRepository $challenges,
        private readonly CacheItemPoolInterface $sessionCache,
    ) {
    }

    /**
     * Generate an opaque, non-sequential account id (256-bit random hex).
     */
    public function generateAccountId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Issue and persist a one-time challenge nonce for the given purpose.
     *
     * @return array{challenge: string, expires_at: \DateTimeImmutable}
     */
    public function issueChallenge(string $accountId, string $purpose): array
    {
        $challenge = self::base64UrlEncode(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::CHALLENGE_TTL_SECONDS . ' seconds');
        $this->challenges->issue($accountId, $purpose, $challenge, $expiresAt);

        return ['challenge' => $challenge, 'expires_at' => $expiresAt];
    }

    /**
     * Consume a one-time challenge (atomic, replay-safe). Returns true exactly
     * once per issued nonce, and only before it expires.
     */
    public function consumeChallenge(string $accountId, string $purpose, string $challenge): bool
    {
        return $this->challenges->consume($accountId, $purpose, $challenge);
    }

    /**
     * Verify an Ed25519 login signature over the raw challenge bytes. All
     * inputs are base64url; malformed or wrong-length material returns false
     * (never throws) so callers reply with a uniform 401.
     */
    public function verifyLoginSignature(string $accountAuthPk, string $challenge, string $signature): bool
    {
        $pk = self::base64UrlDecode($accountAuthPk);
        $sig = self::base64UrlDecode($signature);
        $msg = self::base64UrlDecode($challenge);

        if ($pk === null || $sig === null || $msg === null) {
            return false;
        }
        if (strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Verify the keybundle-download MAC. The HMAC key is exactly the stored
     * auth_verifier_hash (= SHA-256 of the client's AuthVerifier); the message
     * is the challenge. Constant-time compare. The AuthVerifier itself is never
     * transmitted (M2); this gate limits enumeration, not confidentiality.
     */
    public function verifyKeybundleMac(string $authVerifierHash, string $challenge, string $mac): bool
    {
        $expected = hash_hmac('sha256', $challenge, $authVerifierHash);

        return hash_equals($expected, $mac);
    }

    /**
     * Mint a session bearer token. Returns the plaintext token once; only its
     * SHA-256 hash is stored (cache pool, short TTL).
     */
    public function mintSessionToken(string $accountId): string
    {
        $token = self::base64UrlEncode(random_bytes(32));
        $item = $this->sessionCache->getItem($this->sessionKey($token));
        $item->set($accountId);
        $item->expiresAfter(self::SESSION_TOKEN_TTL_SECONDS);
        $this->sessionCache->save($item);

        return $token;
    }

    /**
     * Resolve the account id behind a bearer token, or null if unknown/expired.
     * Lookup is by the token's hash (the cache key), so no plaintext token is
     * ever stored or compared.
     */
    public function resolveSessionToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        $item = $this->sessionCache->getItem($this->sessionKey($token));
        if (!$item->isHit()) {
            return null;
        }
        $accountId = $item->get();

        return is_string($accountId) ? $accountId : null;
    }

    public function revokeSessionToken(string $token): void
    {
        if ($token !== '') {
            $this->sessionCache->deleteItem($this->sessionKey($token));
        }
    }

    /**
     * Resolve the authenticated account from the request's Bearer token.
     * Returns the account id or null (caller replies 401).
     */
    public function authenticate(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return $this->resolveSessionToken(substr($header, 7));
    }

    public function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }

    private function sessionKey(string $token): string
    {
        return self::SESSION_CACHE_PREFIX . hash('sha256', $token);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
