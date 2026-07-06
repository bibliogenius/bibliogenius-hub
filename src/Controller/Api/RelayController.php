<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RelayMailbox;
use App\Entity\RelayMessage;
use App\Repository\Deposit404LogRepository;
use App\Repository\RelayMailboxRepository;
use App\Repository\RelayMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\HubEventLogger;
use App\Service\SidecarNotifier;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/relay', name: 'api_relay_')]
class RelayController extends AbstractController
{
    private const MAX_BLOB_SIZE = 64 * 1024; // 64 KB
    private const MAX_MESSAGES_PER_MAILBOX = 100;
    private const MESSAGE_TTL_DAYS = 7;
    private const MAILBOX_TTL_DAYS = 90;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RelayMailboxRepository $mailboxRepository,
        private readonly RelayMessageRepository $messageRepository,
        private readonly HubEventLogger $eventLogger,
        private readonly Deposit404LogRepository $deposit404Log,
        private readonly RateLimiterFactoryInterface $depositAnonLimiter,
        private readonly RateLimiterFactoryInterface $mailboxCreateAnonLimiter,
        private readonly RateLimiterFactoryInterface $collectAnonLimiter,
        private readonly ?SidecarNotifier $sidecarNotifier = null,
    ) {
    }

    /**
     * POST /api/relay/mailbox — Create a new mailbox.
     * No authentication required.
     */
    #[Route('/mailbox', name: 'create_mailbox', methods: ['POST'])]
    public function createMailbox(Request $request): JsonResponse
    {
        $rateLimit = $this->mailboxCreateAnonLimiter->create($this->clientIpKey($request))->consume();
        if (!$rateLimit->isAccepted()) {
            return $this->rateLimitedResponse($rateLimit);
        }

        $uuid = self::generateUuidV4();
        $readToken = bin2hex(random_bytes(32));
        $writeToken = bin2hex(random_bytes(32));

        $mailbox = new RelayMailbox();
        $mailbox->setUuid($uuid);
        $mailbox->setReadToken($readToken);
        $mailbox->setWriteToken($writeToken);

        try {
            $this->entityManager->persist($mailbox);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->eventLogger->error('relay', 'mailbox creation failed', ['reason' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to create mailbox'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->eventLogger->info('relay', 'mailbox created', ['uuid' => $uuid]);

        return $this->json([
            'uuid' => $uuid,
            'read_token' => $readToken,
            'write_token' => $writeToken,
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/relay/mailbox/{uuid}/messages — Deposit an encrypted blob.
     * Requires: Authorization: Bearer {write_token}
     */
    #[Route('/mailbox/{uuid}/messages', name: 'deposit_message', methods: ['POST'])]
    public function depositMessage(string $uuid, Request $request): JsonResponse
    {
        if (!self::isValidUuid($uuid)) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        // Rate limit BEFORE the DB lookup so spam with random valid UUIDs
        // does not waste a SELECT and a deposit_404_log upsert per request.
        $rateLimit = $this->depositAnonLimiter->create($this->clientIpKey($request))->consume();
        if (!$rateLimit->isAccepted()) {
            return $this->rateLimitedResponse($rateLimit);
        }

        // 1. Extract bearer token
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->json(['error' => 'Missing Authorization header'], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Early reject oversized bodies (before reading into memory)
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && (int) $contentLength > self::MAX_BLOB_SIZE) {
            return $this->json(
                ['error' => sprintf('Blob exceeds maximum size of %d bytes', self::MAX_BLOB_SIZE)],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        // 3. Find mailbox and verify write token
        $mailbox = $this->mailboxRepository->findByUuid($uuid);
        if ($mailbox === null) {
            // Aggregated counter (deposit_404_log) instead of a per-event hub_events row.
            // Peers holding a stale write_token retry in a loop, and at the former rate
            // (~80% of hub_events) they evicted legitimate warnings from the 1000-row cap.
            $this->deposit404Log->recordHit($uuid);
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        if (!hash_equals($mailbox->getWriteToken(), $token)) {
            $this->eventLogger->warning('relay', 'invalid write token', [
                'uuid' => $uuid,
            ]);
            return $this->json(['error' => 'Invalid write token'], Response::HTTP_UNAUTHORIZED);
        }

        // 4. Check blob size (definitive check after reading body)
        $blob = $request->getContent();
        if (strlen($blob) > self::MAX_BLOB_SIZE) {
            return $this->json(
                ['error' => sprintf('Blob exceeds maximum size of %d bytes', self::MAX_BLOB_SIZE)],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        // 5. Enforce the per-mailbox cap with FIFO eviction (LRU semantics).
        //    Rationale: rejecting in 429 stranded depositors when an owner
        //    stopped polling, while the queue contained increasingly stale
        //    messages. Evicting the oldest keeps the cap stable and
        //    guarantees the owner sees the most recent messages on return.
        $count = $this->messageRepository->countByMailbox($uuid);
        if ($count >= self::MAX_MESSAGES_PER_MAILBOX) {
            $toEvict = $count - self::MAX_MESSAGES_PER_MAILBOX + 1;
            $evicted = $this->messageRepository->deleteOldest($uuid, $toEvict);
            $this->eventLogger->info('relay', 'oldest messages evicted to enforce cap', [
                'mailbox' => $uuid,
                'evicted' => $evicted,
            ]);
        }

        // 6. Store blob
        $message = new RelayMessage();
        $message->setMailboxUuid($uuid);
        $message->setBlob($blob);

        try {
            $this->entityManager->persist($message);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->eventLogger->error('relay', 'deposit failed', ['uuid' => $uuid, 'reason' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to store message'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->eventLogger->info('relay', 'message deposited', [
            'mailbox' => $uuid,
            'size' => strlen($blob),
            'msg_id' => $message->getId(),
        ]);

        // Nudge the WebSocket sidecar (fire-and-forget, ADR-017).
        $this->sidecarNotifier?->nudge($uuid);

        // Probabilistic cleanup (~1% chance) — also triggered on write so that
        // mailboxes that are never polled don't accumulate indefinitely.
        if (random_int(0, 99) === 0) {
            $this->cleanup();
        }

        return $this->json(['id' => $message->getId()], Response::HTTP_CREATED);
    }

    /**
     * GET /api/relay/mailbox/{uuid}/messages — Collect pending messages.
     * Requires: Authorization: Bearer {read_token}
     */
    #[Route('/mailbox/{uuid}/messages', name: 'collect_messages', methods: ['GET'])]
    public function collectMessages(string $uuid, Request $request): JsonResponse
    {
        if (!self::isValidUuid($uuid)) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        $rateLimit = $this->collectAnonLimiter->create($this->clientIpKey($request))->consume();
        if (!$rateLimit->isAccepted()) {
            return $this->rateLimitedResponse($rateLimit);
        }

        // 1. Extract bearer token
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->json(['error' => 'Missing Authorization header'], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Find mailbox and verify read token
        $mailbox = $this->mailboxRepository->findByUuid($uuid);
        if ($mailbox === null) {
            $this->eventLogger->warning('relay', 'collect from non-existent mailbox', [
                'uuid' => $uuid,
            ]);
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        if (!hash_equals($mailbox->getReadToken(), $token)) {
            return $this->json(['error' => 'Invalid read token'], Response::HTTP_UNAUTHORIZED);
        }

        // 3. Update last_accessed
        $mailbox->setLastAccessed(new \DateTimeImmutable());
        $this->entityManager->flush();

        // 4. Fetch all pending messages
        $messages = $this->messageRepository->findByMailbox($uuid);

        $items = array_map(function (RelayMessage $msg) {
            $blob = $msg->getBlob();
            // Doctrine may return a resource (stream) for BLOB columns
            if (is_resource($blob)) {
                $blob = stream_get_contents($blob);
            }

            return [
                'id' => $msg->getId(),
                'blob' => base64_encode($blob),
                'created_at' => $msg->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            ];
        }, $messages);

        // 5. Probabilistic cleanup (~1% chance)
        if (random_int(0, 99) === 0) {
            $this->cleanup();
        }

        if (count($items) > 0) {
            $this->eventLogger->info('relay', 'messages collected', [
                'mailbox' => $uuid,
                'count' => count($items),
            ]);
        }

        return $this->json(['messages' => $items]);
    }

    /**
     * GET /api/relay/mailbox/{uuid}/verify — Verify read_token ownership.
     * Lightweight endpoint used by the WebSocket sidecar at handshake time.
     * Returns 200 if the token is valid, 401 otherwise. No DB side effects.
     */
    #[Route('/mailbox/{uuid}/verify', name: 'verify_token', methods: ['GET'])]
    public function verifyToken(string $uuid, Request $request): JsonResponse
    {
        if (!self::isValidUuid($uuid)) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->json(['error' => 'Missing Authorization header'], Response::HTTP_UNAUTHORIZED);
        }

        $mailbox = $this->mailboxRepository->findByUuid($uuid);
        if ($mailbox === null) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        if (!hash_equals($mailbox->getReadToken(), $token)) {
            return $this->json(['error' => 'Invalid read token'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['status' => 'ok']);
    }

    /**
     * DELETE /api/relay/mailbox/{uuid}/messages/{id} — Acknowledge and delete a message.
     * Requires: Authorization: Bearer {read_token}
     */
    #[Route('/mailbox/{uuid}/messages/{id}', name: 'ack_message', methods: ['DELETE'])]
    public function ackMessage(string $uuid, int $id, Request $request): JsonResponse
    {
        if (!self::isValidUuid($uuid)) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        // 1. Extract bearer token
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->json(['error' => 'Missing Authorization header'], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Find mailbox and verify read token
        $mailbox = $this->mailboxRepository->findByUuid($uuid);
        if ($mailbox === null) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        if (!hash_equals($mailbox->getReadToken(), $token)) {
            return $this->json(['error' => 'Invalid read token'], Response::HTTP_UNAUTHORIZED);
        }

        // 3. Find message (must belong to this mailbox)
        $message = $this->messageRepository->findOneBy([
            'id' => $id,
            'mailboxUuid' => $uuid,
        ]);

        if ($message === null) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->entityManager->remove($message);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to delete message'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['message' => 'Deleted']);
    }

    /**
     * DELETE /api/relay/mailbox/{uuid} - Delete a mailbox and all its messages.
     * Requires: Authorization: Bearer {read_token}
     * Only the mailbox owner (who knows the read_token) can delete it.
     */
    #[Route('/mailbox/{uuid}', name: 'delete_mailbox', methods: ['DELETE'])]
    public function deleteMailbox(string $uuid, Request $request): JsonResponse
    {
        if (!self::isValidUuid($uuid)) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->json(['error' => 'Missing Authorization header'], Response::HTTP_UNAUTHORIZED);
        }

        $mailbox = $this->mailboxRepository->findByUuid($uuid);
        if ($mailbox === null) {
            return $this->json(['error' => 'Mailbox not found'], Response::HTTP_NOT_FOUND);
        }

        if (!hash_equals($mailbox->getReadToken(), $token)) {
            return $this->json(['error' => 'Invalid read token'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->mailboxRepository->deleteWithMessages($uuid);
            $this->eventLogger->info('relay', 'mailbox deleted by owner', [
                'uuid' => $uuid,
            ]);
        } catch (\Throwable $e) {
            $this->eventLogger->error('relay', 'mailbox delete failed', [
                'uuid' => $uuid,
                'reason' => $e->getMessage(),
            ]);
            return $this->json(['error' => 'Failed to delete mailbox'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['message' => 'Mailbox deleted']);
    }

    /**
     * Per-IP key used by every public limiter on this controller.
     * `getClientIp()` honours `framework.trusted_proxies` and returns the
     * real client behind Caddy. Falls back to a string sentinel so a missing
     * IP collapses every anonymous caller into one shared bucket rather
     * than blowing up `consume()` with a null key.
     */
    private function clientIpKey(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }

    /**
     * 429 with `Retry-After` (seconds) derived from the limiter's own clock,
     * not a hardcoded constant - matches the actual time the bucket needs
     * to refill one token.
     */
    private function rateLimitedResponse(RateLimit $rateLimit): JsonResponse
    {
        $retryAfter = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());

        return $this->json(
            ['error' => 'Rate limit exceeded'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }

    /**
     * Extract bearer token from Authorization header.
     */
    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }

    /**
     * TTL cleanup: delete old messages and inactive mailboxes.
     */
    private function cleanup(): void
    {
        $conn = $this->entityManager->getConnection();

        try {
            // Integer constants only — safe to interpolate (no user input).
            $conn->executeStatement(
                sprintf("DELETE FROM relay_messages WHERE created_at < NOW() - INTERVAL '%d days'", self::MESSAGE_TTL_DAYS),
            );

            $conn->executeStatement(
                sprintf("DELETE FROM relay_mailboxes WHERE last_accessed IS NOT NULL AND last_accessed < NOW() - INTERVAL '%d days'", self::MAILBOX_TTL_DAYS),
            );

            $conn->executeStatement(
                sprintf("DELETE FROM relay_mailboxes WHERE last_accessed IS NULL AND created_at < NOW() - INTERVAL '%d days'", self::MAILBOX_TTL_DAYS),
            );

            // Mailbox deletion above strands library_profiles.relay_mailbox_id
            // (soft reference, no FK): clear the dangling references so the
            // profiles neither surface as orphan references on the dashboard
            // nor dodge the stale-profile purge.
            $this->mailboxRepository->clearDanglingProfileReferences();
        } catch (\Throwable) {
            // Cleanup is best-effort
        }
    }

    /**
     * Validate UUID v4 format (avoids useless DB queries on garbage input).
     */
    private static function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
    }

    /**
     * Generate a UUID v4 from random bytes (no external dependency).
     */
    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // version 4
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
