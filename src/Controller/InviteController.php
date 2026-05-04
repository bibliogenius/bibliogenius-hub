<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\InviteToken;
use App\Repository\InviteTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InviteController extends AbstractController
{
    private const TOKEN_LENGTH = 12;
    private const TOKEN_TTL_DAYS = 30;
    private const MAX_PAYLOAD_SIZE = 4096;

    // Domain separator for key derivation. Not a secret: its only purpose
    // is to bind the derived key to this specific use case so the same
    // token string could not accidentally decrypt data from another context.
    private const INVITE_SALT = 'bibliogenius-invite-v1';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InviteTokenRepository $tokenRepository,
    ) {
    }

    /**
     * POST /api/invite - Store an invite payload and return a short token.
     *
     * Body: { "payload": "{\"v\":4,...}" }
     * Response: { "token": "abc123def789", "url": "https://hub.../i/abc123def789" }
     */
    #[Route('/api/invite', name: 'api_invite_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !isset($body['payload']) || !is_string($body['payload'])) {
            return $this->json(['error' => 'Missing or invalid "payload" field'], Response::HTTP_BAD_REQUEST);
        }

        $payload = $body['payload'];
        if (strlen($payload) > self::MAX_PAYLOAD_SIZE) {
            return $this->json(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        // Validate that the payload is valid JSON
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return $this->json(['error' => 'Payload must be valid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Generate a unique 12-char alphanumeric token
        $token = self::generateToken();

        // Encrypt the payload: key = SHA-256(token + INVITE_SALT), AES-256-GCM
        $encryptedBlob = self::encryptPayload($token, $payload);

        $entity = new InviteToken();
        $entity->setToken($token);
        $entity->setEncryptedPayload($encryptedBlob);

        try {
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to store invite'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Probabilistic cleanup (~1% chance), same pattern as RelayController
        if (random_int(0, 99) === 0) {
            $this->cleanup();
        }

        // Build the short URL using Symfony's trusted proxy mechanism.
        // framework.yaml configures REMOTE_ADDR as trusted proxy so that
        // X-Forwarded-Proto from the reverse proxy (Scaleway/Caddy) is respected.
        $baseUrl = $request->getSchemeAndHttpHost();
        $url = "$baseUrl/i/$token";

        return $this->json([
            'token' => $token,
            'url' => $url,
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /i/{token} - Show the invite landing page.
     *
     * Decrypts the stored payload using the token, extracts the library name,
     * and renders the landing page with a deep link button.
     */
    #[Route('/i/{token}', name: 'invite_short', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9]{12}'])]
    public function show(string $token): Response
    {
        $entity = $this->tokenRepository->findByToken($token);

        if ($entity === null) {
            return $this->render('invite/index.html.twig', [
                'data' => '',
                'library_name' => null,
            ]);
        }

        // Check TTL
        $age = (new \DateTimeImmutable())->getTimestamp() - $entity->getCreatedAt()->getTimestamp();
        if ($age > self::TOKEN_TTL_DAYS * 86400) {
            // Expired: clean up and show error
            try {
                $this->entityManager->remove($entity);
                $this->entityManager->flush();
            } catch (\Throwable) {
            }

            return $this->render('invite/index.html.twig', [
                'data' => '',
                'library_name' => null,
            ]);
        }

        // Decrypt the payload
        $payload = self::decryptPayload($token, $entity->getEncryptedPayload());
        if ($payload === null) {
            return $this->render('invite/index.html.twig', [
                'data' => '',
                'library_name' => null,
            ]);
        }

        // Extract library name for display
        $libraryName = null;
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $libraryName = $decoded['n'] ?? $decoded['name'] ?? null;
        }

        // Encode payload as base64url for the deep link (same format as the old ?d= parameter)
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $this->render('invite/index.html.twig', [
            'data' => $b64,
            'library_name' => $libraryName,
        ]);
    }

    /**
     * GET /invite?d=... - Legacy invite link handler (kept for backward compatibility).
     */
    #[Route('/invite', name: 'invite_legacy', methods: ['GET'])]
    public function legacy(Request $request): Response
    {
        $data = $request->query->get('d', '');

        $libraryName = null;
        if ($data !== '') {
            try {
                $b64 = strtr($data, '-_', '+/');
                $b64 = str_pad($b64, (int) (ceil(strlen($b64) / 4) * 4), '=');
                $json = base64_decode($b64, true);
                if ($json !== false) {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $libraryName = $decoded['n'] ?? $decoded['name'] ?? null;
                    }
                }
            } catch (\Throwable) {
                // Invalid payload - template will show error state
            }
        }

        return $this->render('invite/index.html.twig', [
            'data' => $data,
            'library_name' => $libraryName,
        ]);
    }

    /**
     * Generate a 12-char alphanumeric token from random bytes.
     *
     * Uses rejection sampling against the largest multiple of 62 below 256
     * (= 248) so each char of the alphabet is equiprobable. A naive `% 62`
     * over uniform 0..255 bytes would give indexes 0..7 a 5/256 chance vs
     * 4/256 for the rest, costing ~0.8 bits of entropy.
     */
    private static function generateToken(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $alphabetLen = strlen($chars);
        $cutoff = intdiv(256, $alphabetLen) * $alphabetLen;

        $token = '';
        while (strlen($token) < self::TOKEN_LENGTH) {
            $byte = ord(random_bytes(1));
            if ($byte < $cutoff) {
                $token .= $chars[$byte % $alphabetLen];
            }
        }

        return $token;
    }

    /**
     * Encrypt payload with AES-256-GCM.
     *
     * Key = SHA-256(token + INVITE_SALT)
     * Stored format: base64(nonce_12 || tag_16 || ciphertext)
     */
    private static function encryptPayload(string $token, string $payload): string
    {
        $key = hash('sha256', $token . self::INVITE_SALT, true);
        $nonce = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $payload,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed');
        }

        // Format: nonce (12) || tag (16) || ciphertext
        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt payload with AES-256-GCM.
     *
     * Returns null on any failure (bad token, corrupted data, etc.).
     */
    private static function decryptPayload(string $token, string $encryptedBlob): ?string
    {
        $raw = base64_decode($encryptedBlob, true);
        if ($raw === false || strlen($raw) < 28) {
            // 12 (nonce) + 16 (tag) = 28 minimum
            return null;
        }

        $key = hash('sha256', $token . self::INVITE_SALT, true);
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * TTL cleanup: delete expired invite tokens.
     */
    private function cleanup(): void
    {
        try {
            $this->tokenRepository->deleteExpired(self::TOKEN_TTL_DAYS);
        } catch (\Throwable) {
            // Cleanup is best-effort
        }
    }
}
