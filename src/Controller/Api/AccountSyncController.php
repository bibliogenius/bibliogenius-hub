<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AccountEntity;
use App\Repository\AccountAuthChallengeRepository;
use App\Repository\AccountDeviceRegistryRepository;
use App\Repository\AccountEntityRepository;
use App\Service\AccountAuthService;
use App\Service\HubEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Blind sync endpoints (ADR-043). push overwrites lanes in place without ever
 * reading the blob; pull returns a single-cursor delta of OTHER devices' lanes;
 * registry is an opaque signed blob the hub serves but never parses (H3); the
 * lanes-delete is client-driven orphan GC. All require a bearer session token.
 */
#[Route('/api/account', name: 'api_account_sync_')]
class AccountSyncController extends AbstractController
{
    use AccountApiTrait;

    private const MAX_BLOB_SIZE = 64 * 1024;
    private const MAX_LANES_PER_PUSH = 500;
    // Early body bound so a push/registry call cannot read an oversized body
    // into memory before the per-lane caps apply. A push carrying many max-size
    // blobs that exceeds this must be chunked into several calls.
    private const MAX_PUSH_BODY_BYTES = 16 * 1024 * 1024;
    private const MAX_REGISTRY_BODY_BYTES = 256 * 1024;
    private const PULL_PAGE_LIMIT = 200;
    private const TOMBSTONE_BLOB_TTL_DAYS = 7;
    private const TOMBSTONE_ROW_TTL_DAYS = 90;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountEntityRepository $lanes,
        private readonly AccountDeviceRegistryRepository $deviceRegistry,
        private readonly AccountAuthChallengeRepository $challenges,
        private readonly AccountAuthService $auth,
        private readonly HubEventLogger $eventLogger,
        private readonly RateLimiterFactoryInterface $accountPushLimiter,
        private readonly RateLimiterFactoryInterface $accountPullLimiter,
    ) {
    }

    /**
     * POST /api/account/push - blind overwrite-in-place of the caller's lanes.
     */
    #[Route('/push', name: 'push', methods: ['POST'])]
    public function push(Request $request): JsonResponse
    {
        $accountId = $this->auth->authenticate($request);
        if ($accountId === null) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (($limited = $this->enforce($this->accountPushLimiter, $accountId)) !== null) {
            return $limited;
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_PUSH_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        $deviceId = $data !== null ? self::idField($data, 'device_id') : null;
        $rawLanes = $data['lanes'] ?? null;
        if ($deviceId === null || !is_array($rawLanes)) {
            return $this->json(['error' => 'Invalid push request'], Response::HTTP_BAD_REQUEST);
        }
        if (count($rawLanes) > self::MAX_LANES_PER_PUSH) {
            return $this->json(
                ['error' => sprintf('Too many lanes (max %d per push)', self::MAX_LANES_PER_PUSH)],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $lanes = [];
        foreach ($rawLanes as $lane) {
            $parsed = $this->parseLane($lane);
            if ($parsed === null) {
                return $this->json(['error' => 'Invalid lane'], Response::HTTP_BAD_REQUEST);
            }
            if ($parsed['blob'] !== null && strlen($parsed['blob']) > self::MAX_BLOB_SIZE) {
                return $this->json(
                    ['error' => sprintf('Blob exceeds maximum size of %d bytes', self::MAX_BLOB_SIZE)],
                    Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                );
            }
            $lanes[] = $parsed;
        }

        try {
            $high = $this->lanes->pushLanes($accountId, $deviceId, $lanes);
        } catch (\Throwable $e) {
            $this->eventLogger->error('account_sync', 'push failed', ['reason' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to store lanes'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->maybeMaintain($accountId);
        $this->eventLogger->info('account_sync', 'lanes pushed', ['count' => count($lanes)]);

        return $this->json(['accepted' => count($lanes), 'high_change_seq' => $high]);
    }

    /**
     * GET /api/account/pull?cursor=&limit=&device_id= - delta of OTHER devices'
     * lanes after the cursor, tombstones included. cursor=0 = full bootstrap.
     */
    #[Route('/pull', name: 'pull', methods: ['GET'])]
    public function pull(Request $request): JsonResponse
    {
        $accountId = $this->auth->authenticate($request);
        if ($accountId === null) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (($limited = $this->enforce($this->accountPullLimiter, $accountId)) !== null) {
            return $limited;
        }

        $cursor = max(0, (int) $request->query->get('cursor', '0'));
        $limit = (int) $request->query->get('limit', (string) self::PULL_PAGE_LIMIT);
        $limit = max(1, min($limit, self::PULL_PAGE_LIMIT));
        // The caller's own device, so the hub does not ship back its own lanes.
        // Absent = exclude nothing. Enforcement of the signed registry is client-side.
        $excludeDevice = (string) $request->query->get('device_id', '');

        $rows = $this->lanes->pullSince($accountId, $excludeDevice, $cursor, $limit);

        $nextCursor = $cursor;
        $out = [];
        foreach ($rows as $row) {
            /** @var AccountEntity $row */
            $nextCursor = max($nextCursor, $row->getChangeSeq());
            $blob = $row->getBlob();
            $out[] = [
                'opaque_id' => $row->getOpaqueId(),
                'device_id' => $row->getDeviceId(),
                'change_seq' => $row->getChangeSeq(),
                'deleted' => $row->isDeleted(),
                'size_bucket' => $row->getSizeBucket(),
                'blob' => $blob === null ? null : base64_encode(self::blobToString($blob)),
            ];
        }

        return $this->json(['lanes' => $out, 'next_cursor' => $nextCursor]);
    }

    /**
     * GET /api/account/registry - the opaque signed device registry.
     */
    #[Route('/registry', name: 'registry_get', methods: ['GET'])]
    public function getRegistry(Request $request): JsonResponse
    {
        $accountId = $this->auth->authenticate($request);
        if ($accountId === null) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $registry = $this->deviceRegistry->findOneByAccount($accountId);
        if ($registry === null) {
            return $this->json(['blob' => null, 'registry_seq' => 0]);
        }

        return $this->json([
            'blob' => base64_encode(self::blobToString($registry->getBlob())),
            'registry_seq' => $registry->getRegistrySeq(),
        ]);
    }

    /**
     * POST /api/account/registry - publish a new signed registry (opaque to hub).
     */
    #[Route('/registry', name: 'registry_post', methods: ['POST'])]
    public function postRegistry(Request $request): JsonResponse
    {
        $accountId = $this->auth->authenticate($request);
        if ($accountId === null) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_REGISTRY_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        $blobB64 = $data !== null ? ($data['blob'] ?? null) : null;
        if (!is_string($blobB64) || $blobB64 === '') {
            return $this->json(['error' => 'Invalid registry blob'], Response::HTTP_BAD_REQUEST);
        }
        $blob = base64_decode($blobB64, true);
        if ($blob === false || $blob === '' || strlen($blob) > self::MAX_BLOB_SIZE) {
            return $this->json(['error' => 'Invalid registry blob'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $registry = $this->deviceRegistry->publish($accountId, $blob);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->eventLogger->error('account_sync', 'registry publish failed', ['reason' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to publish registry'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['registry_seq' => $registry->getRegistrySeq()]);
    }

    /**
     * DELETE /api/account/lanes?device_id= - client-driven orphan-lane GC.
     */
    #[Route('/lanes', name: 'delete_lanes', methods: ['DELETE'])]
    public function deleteLanes(Request $request): JsonResponse
    {
        $accountId = $this->auth->authenticate($request);
        if ($accountId === null) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $deviceId = $request->query->get('device_id', '');
        if (!is_string($deviceId) || !self::isValidId($deviceId)) {
            return $this->json(['error' => 'Invalid device_id'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $deleted = $this->lanes->deleteByDevice($accountId, $deviceId);
        } catch (\Throwable $e) {
            $this->eventLogger->error('account_sync', 'lane delete failed', ['reason' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to delete lanes'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->eventLogger->info('account_sync', 'device lanes deleted', ['count' => $deleted]);

        return $this->json(['deleted' => $deleted]);
    }

    // --- helpers (shared HTTP helpers live in AccountApiTrait) -----------

    /**
     * Probabilistic maintenance (~1% on write), like the relay. Best-effort:
     * tombstone GC, expired-challenge GC, and the per-account quota recompute.
     * The quota counter is a non-enforced hook (ST-06/ST-08), so refreshing it
     * here rather than on every push avoids a full per-account blob scan on the
     * hot path.
     */
    private function maybeMaintain(string $accountId): void
    {
        if (random_int(0, 99) === 0) {
            $this->lanes->gcTombstones(self::TOMBSTONE_BLOB_TTL_DAYS, self::TOMBSTONE_ROW_TTL_DAYS);
            $this->challenges->gcExpired();
            $this->lanes->recomputeQuotaBytes($accountId);
        }
    }

    /**
     * @return array{opaque_id: string, deleted: bool, size_bucket: int, blob: ?string}|null
     */
    private function parseLane(mixed $lane): ?array
    {
        if (!is_array($lane)) {
            return null;
        }
        $opaqueId = $lane['opaque_id'] ?? null;
        $deleted = $lane['deleted'] ?? null;
        $sizeBucket = $lane['size_bucket'] ?? null;
        $blobB64 = $lane['blob'] ?? null;

        if (!is_string($opaqueId) || !self::isValidId($opaqueId)
            || !is_bool($deleted)
            || !is_int($sizeBucket) || $sizeBucket < 0 || $sizeBucket > self::MAX_BLOB_SIZE) {
            return null;
        }

        $blob = null;
        if ($blobB64 !== null) {
            if (!is_string($blobB64)) {
                return null;
            }
            $decoded = base64_decode($blobB64, true);
            if ($decoded === false) {
                return null;
            }
            $blob = $decoded;
        }
        // A non-tombstone lane must carry a blob.
        if (!$deleted && ($blob === null || $blob === '')) {
            return null;
        }

        return [
            'opaque_id' => $opaqueId,
            'deleted' => $deleted,
            'size_bucket' => $sizeBucket,
            'blob' => $blob,
        ];
    }

    private static function idField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && self::isValidId($value) ? $value : null;
    }

    /** Opaque ids (opaque_id, device_id) are base64url tokens, 1..64 chars. */
    private static function isValidId(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value);
    }
}
