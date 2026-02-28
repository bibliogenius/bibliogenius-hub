<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\InviteTokenRepository;
use App\Repository\PeerRepository;
use App\Repository\RelayMailboxRepository;
use App\Repository\RelayMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health and monitoring endpoint for the hub.
 *
 * GET /api/health  - Public: basic liveness check
 * GET /api/health/detailed - Protected: full metrics (requires HEALTH_TOKEN)
 */
#[Route('/api/health', name: 'api_health_')]
class HealthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RelayMailboxRepository $mailboxRepository,
        private readonly RelayMessageRepository $messageRepository,
        private readonly PeerRepository $peerRepository,
        private readonly InviteTokenRepository $inviteTokenRepository,
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * GET /api/health - Public liveness check.
     * Returns basic status for uptime monitors (UptimeRobot, BetterStack).
     */
    #[Route('', name: 'liveness', methods: ['GET'])]
    public function liveness(): JsonResponse
    {
        // Quick DB check: run a trivial query to verify the connection is alive
        try {
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }

        $status = $dbOk ? 'ok' : 'degraded';
        $httpCode = $dbOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return $this->json([
            'status' => $status,
            'version' => $_ENV['APP_VERSION'] ?? 'dev',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ], $httpCode);
    }

    /**
     * GET /api/health/detailed - Protected metrics endpoint.
     * Requires: Authorization: Bearer {HEALTH_TOKEN} (env variable).
     *
     * Returns mailbox stats, message counts, peer count, disk usage.
     * Designed for monitoring dashboards and alerting.
     */
    #[Route('/detailed', name: 'detailed', methods: ['GET'])]
    public function detailed(Request $request): JsonResponse
    {
        // Verify health token
        $expectedToken = $_ENV['HEALTH_TOKEN'] ?? $_SERVER['HEALTH_TOKEN'] ?? null;
        if ($expectedToken === null || $expectedToken === '') {
            return $this->json(
                ['error' => 'HEALTH_TOKEN not configured on server'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $providedToken = $this->extractBearerToken($request);
        if ($providedToken === null || !hash_equals($expectedToken, $providedToken)) {
            return $this->json(
                ['error' => 'Invalid or missing health token'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $conn = $this->entityManager->getConnection();

        // Mailbox stats
        $mailboxCount = (int) $conn->executeQuery('SELECT COUNT(*) FROM relay_mailboxes')->fetchOne();
        $messageCount = (int) $conn->executeQuery('SELECT COUNT(*) FROM relay_messages')->fetchOne();

        // Mailboxes with pending messages (non-empty)
        $activeMailboxes = (int) $conn->executeQuery(
            'SELECT COUNT(DISTINCT mailbox_uuid) FROM relay_messages'
        )->fetchOne();

        // Average and max messages per mailbox (among non-empty ones)
        $mailboxDepth = $conn->executeQuery(
            'SELECT AVG(cnt) as avg_depth, MAX(cnt) as max_depth FROM (SELECT COUNT(*) as cnt FROM relay_messages GROUP BY mailbox_uuid)'
        )->fetchAssociative();

        // Peer count
        $peerCount = (int) $conn->executeQuery('SELECT COUNT(*) FROM peers')->fetchOne();

        // Invite token count
        $inviteCount = (int) $conn->executeQuery('SELECT COUNT(*) FROM invite_tokens')->fetchOne();

        // Database file size
        $dbPath = $this->kernel->getProjectDir() . '/var/data.db';
        $dbSizeBytes = file_exists($dbPath) ? filesize($dbPath) : null;

        // Disk free space
        $diskFree = disk_free_space($this->kernel->getProjectDir());

        // Oldest unread message (staleness indicator)
        $oldestMessage = $conn->executeQuery(
            'SELECT MIN(created_at) FROM relay_messages'
        )->fetchOne();

        return $this->json([
            'status' => 'ok',
            'version' => $_ENV['APP_VERSION'] ?? 'dev',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            'relay' => [
                'mailboxes_total' => $mailboxCount,
                'mailboxes_with_messages' => $activeMailboxes,
                'messages_total' => $messageCount,
                'avg_messages_per_mailbox' => round((float) ($mailboxDepth['avg_depth'] ?? 0), 1),
                'max_messages_in_mailbox' => (int) ($mailboxDepth['max_depth'] ?? 0),
                'oldest_pending_message' => $oldestMessage ?: null,
            ],
            'peers' => [
                'total' => $peerCount,
            ],
            'invites' => [
                'total' => $inviteCount,
            ],
            'storage' => [
                'db_size_bytes' => $dbSizeBytes,
                'db_size_mb' => $dbSizeBytes !== null ? round($dbSizeBytes / 1048576, 2) : null,
                'disk_free_mb' => $diskFree !== false ? round($diskFree / 1048576, 0) : null,
            ],
        ]);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }
}
