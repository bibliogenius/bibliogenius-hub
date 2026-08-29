<?php

declare(strict_types=1);

/**
 * Behavioral smoke for the account E2EE sync store (ADR-043), run against a
 * live hub. This is the account-sync counterpart of `make test-relay`: it
 * exercises the real HTTP endpoints end to end (with real Ed25519 auth), then
 * deletes its throwaway account so it leaves no residue (safe against staging).
 *
 * Run it in the dev container (needs ext-sodium + the live hub):
 *   make test-account
 *   # or: docker compose -f docker-compose.dev.yml exec hub php tests/Smoke/account_sync_smoke.php
 *
 * Target another hub: ACCOUNT_HUB_URL=https://hub-dev.bibliogenius.org php tests/Smoke/account_sync_smoke.php
 *
 * Exit code 0 = all checks passed, 1 = a check failed. NOT part of the phpunit
 * suite (that stays pure-unit, DB-free); this needs a running hub + database.
 */

$base = getenv('ACCOUNT_HUB_URL') ?: ($argv[1] ?? 'http://127.0.0.1:80');
$base = rtrim($base, '/');
$fail = 0;

function http(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }
    $opts = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 10,
    ]];
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }
    $resp = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return [$code, json_decode((string) $resp, true)];
}
function b64url(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function check(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $fail++; }
}

echo "Account sync smoke against $base\n";

$kp = sodium_crypto_sign_keypair();
$pk = sodium_crypto_sign_publickey($kp);
$sk = sodium_crypto_sign_secretkey($kp);
$email = 'smoke_' . bin2hex(random_bytes(4)) . '@example.org';

// 1. signup
[$c, $j] = http('POST', "$base/api/account/signup", [
    'email' => $email,
    'account_salt' => base64_encode(random_bytes(32)),
    'kdf_params' => ['algo' => 'argon2id', 'version' => 19, 'm' => 65536, 't' => 3, 'p' => 1, 'out' => 32],
    'account_auth_pk' => b64url($pk),
    'auth_verifier_hash' => hash('sha256', 'smoke'),
    'recovery_verifier_hash' => hash('sha256', 'smoke-recovery'),
    'schema_version' => 1,
    'auth_method' => 'ed25519-cr-v1',
    'aead_alg' => 'aes-256-gcm',
    'descriptor_sig' => b64url(random_bytes(64)),
    'wrapped_keys' => [['kind' => 'passphrase', 'blob' => base64_encode(random_bytes(120))]],
    'device_registry_blob' => base64_encode(random_bytes(80)),
]);
check('signup -> 201 + account_id', $c === 201 && !empty($j['account_id']));

// 2. challenge + Ed25519 login
[$c, $j] = http('POST', "$base/api/account/challenge", ['email' => $email, 'purpose' => 'login']);
check('challenge -> 200 + nonce', $c === 200 && !empty($j['challenge']));
$challenge = $j['challenge'] ?? '';
$sig = b64url(sodium_crypto_sign_detached(base64_decode(strtr($challenge, '-_', '+/')), $sk));
[$c, $j] = http('POST', "$base/api/account/login", ['email' => $email, 'challenge' => $challenge, 'signature' => $sig]);
check('login (valid Ed25519) -> 200 + token', $c === 200 && !empty($j['token']));
$token = $j['token'] ?? '';

// 2b. replayed challenge must now fail (one-time)
[$c2] = http('POST', "$base/api/account/login", ['email' => $email, 'challenge' => $challenge, 'signature' => $sig]);
check('login challenge replay -> 401', $c2 === 401);

// 3. push from device A
$opaque = b64url(random_bytes(32));
$blobA = base64_encode('CIPHERTEXT-A-' . random_bytes(16));
[$c, $j] = http('POST', "$base/api/account/push", [
    'device_id' => 'devA',
    'lanes' => [['opaque_id' => $opaque, 'deleted' => false, 'size_bucket' => 2048, 'blob' => $blobA]],
], $token);
check('push devA -> 200 accepted=1', $c === 200 && ($j['accepted'] ?? 0) === 1);

// 4. pull as device B restores device A's lane
[$c, $j] = http('GET', "$base/api/account/pull?cursor=0&device_id=devB", null, $token);
$lane = $j['lanes'][0] ?? null;
check('pull from devB -> sees devA lane, blob restored', $c === 200
    && count($j['lanes'] ?? []) === 1
    && $lane['opaque_id'] === $opaque
    && $lane['device_id'] === 'devA'
    && $lane['blob'] === $blobA
    && $lane['deleted'] === false);

// 5. tombstone + overwrite-in-place
[$c] = http('POST', "$base/api/account/push", [
    'device_id' => 'devA',
    'lanes' => [['opaque_id' => $opaque, 'deleted' => true, 'size_bucket' => 256, 'blob' => base64_encode('TOMB')]],
], $token);
[$c, $j] = http('GET', "$base/api/account/pull?cursor=0&device_id=devB", null, $token);
check('pull after tombstone -> still ONE lane (overwrite in place), deleted=true',
    $c === 200 && count($j['lanes'] ?? []) === 1 && ($j['lanes'][0]['deleted'] ?? null) === true);

// 6. device A excludes its own lane
[$c, $j] = http('GET', "$base/api/account/pull?cursor=0&device_id=devA", null, $token);
check('pull from devA -> excludes own lane (0 lanes)', $c === 200 && count($j['lanes'] ?? []) === 0);

// 7. registry round-trip (opaque blob served back verbatim)
$registry = base64_encode('SIGNED-REGISTRY-' . random_bytes(24));
[$c] = http('POST', "$base/api/account/registry", ['blob' => $registry], $token);
[$c, $j] = http('GET', "$base/api/account/registry", null, $token);
check('registry publish + fetch -> blob round-trips', $c === 200 && ($j['blob'] ?? null) === $registry);

// 8. unauthorized push rejected
[$c] = http('POST', "$base/api/account/push", ['device_id' => 'devA', 'lanes' => []], 'bogus-token');
check('push with bad token -> 401', $c === 401);

// 9. cleanup: delete the throwaway account (cascades), leaving no residue
[$c] = http('DELETE', "$base/api/account", null, $token);
check('account delete (cleanup) -> 200', $c === 200);

echo ($fail === 0 ? "ALL SMOKE CHECKS PASSED\n" : "$fail SMOKE CHECK(S) FAILED\n");
exit($fail === 0 ? 0 : 1);
