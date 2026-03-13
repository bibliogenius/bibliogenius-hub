<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Follow;
use App\Repository\BorrowRequestRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Service\DirectoryService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public library directory API.
 *
 * Authentication: write_token via Authorization: Bearer header.
 * All validation and business logic is delegated to DirectoryService.
 *
 * Endpoints:
 *   POST   /api/directory/profile              - Register or update a library profile
 *   POST   /api/directory/catalog              - Push ISBN catalog cache
 *   GET    /api/directory                      - Browse the directory
 *   GET    /api/directory/{nodeId}             - Get a specific library profile
 *   GET    /api/directory/{nodeId}/catalog     - Get a library's catalog
 *   POST   /api/directory/follow/{nodeId}      - Follow or request to follow a library
 *   GET    /api/directory/follows/pending      - List incoming pending follow requests
 *   PATCH  /api/directory/follows/{id}         - Approve, reject, or block a follow request
 *   GET    /api/directory/follows              - List active follows (following + followers)
 *   DELETE /api/directory/follows/{nodeId}     - Unfollow a library
 *   DELETE /api/directory/profile              - Delete own library profile (and all follows)
 *   POST   /api/directory/borrow              - Create a borrow request (ADR-018)
 *   GET    /api/directory/borrow/incoming      - List incoming pending borrow requests
 *   GET    /api/directory/borrow/outgoing      - List outgoing borrow requests
 *   PATCH  /api/directory/borrow/{id}         - Accept or reject a borrow request
 *   DELETE /api/directory/borrow/{id}         - Cancel a borrow request (requester only)
 */
#[Route('/api/directory', name: 'api_directory_')]
class DirectoryController extends AbstractController
{
    private const VIEW_COOLDOWN_SECONDS = 900; // 15 minutes

    public function __construct(
        private readonly DirectoryService $directoryService,
        private readonly LibraryProfileRepository $profileRepository,
        private readonly FollowRepository $followRepository,
        private readonly Connection $connection,
    ) {}

    // -------------------------------------------------------------------------
    // Profile
    // -------------------------------------------------------------------------

    #[Route('/profile', name: 'profile_upsert', methods: ['POST'])]
    public function upsertProfile(Request $request): JsonResponse
    {
        $data = $this->parseJson($request);
        if ($data === null) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $nodeId = $data['node_id'] ?? null;
        if (!$this->isValidNodeId($nodeId)) {
            return $this->error('node_id is required and must be a non-empty string (max 128 chars).', Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['display_name'])) {
            return $this->error('display_name is required.', Response::HTTP_BAD_REQUEST);
        }

        // On update, the caller must authenticate with their write_token
        $token = $this->extractBearerToken($request);
        $authenticated = $token !== null ? $this->directoryService->authenticate($token) : null;

        $existing = $this->profileRepository->findByNodeId($nodeId);
        if ($existing !== null && $authenticated?->getNodeId() !== $nodeId) {
            return $this->error('Valid write_token required to update an existing profile.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->directoryService->upsertProfile($data, $authenticated);
        } catch (\LogicException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->error('upsertProfile failed: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = $result['profile']->toPublicArray();
        if ($result['write_token'] !== null) {
            // Returned once only - the library must store it
            $response['write_token'] = $result['write_token'];
        }

        $status = $existing === null ? Response::HTTP_CREATED : Response::HTTP_OK;

        return $this->json($response, $status);
    }

    #[Route('/profile', name: 'profile_delete', methods: ['DELETE'])]
    public function deleteProfile(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $this->directoryService->deleteProfile($profile);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Catalog
    // -------------------------------------------------------------------------

    #[Route('/catalog', name: 'catalog_push', methods: ['POST'])]
    public function pushCatalog(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $data = $this->parseJson($request);
        if ($data === null || !isset($data['isbn_payload']) || !is_string($data['isbn_payload'])) {
            return $this->error('isbn_payload (string) is required.', Response::HTTP_BAD_REQUEST);
        }

        // Basic sanity check on payload size (max 512 KB for isbn_payload)
        if (strlen($data['isbn_payload']) > 524288) {
            return $this->error('isbn_payload exceeds maximum allowed size.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        // Optional enriched catalog: [{isbn, title, author}, ...]
        $catalogPayload = null;
        if (isset($data['catalog_payload']) && is_string($data['catalog_payload'])) {
            $catalogPayload = $data['catalog_payload'];
        }

        try {
            $catalog = $this->directoryService->pushCatalog($profile, $data['isbn_payload'], $catalogPayload);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $this->json([
            'updated_at' => $catalog->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at' => $catalog->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{nodeId}/catalog', name: 'catalog_get', methods: ['GET'])]
    public function getCatalog(string $nodeId, Request $request): JsonResponse
    {
        if (!$this->isValidNodeId($nodeId)) {
            return $this->error('Invalid node_id.', Response::HTTP_BAD_REQUEST);
        }

        $profile = $this->profileRepository->findByNodeId($nodeId);
        if ($profile === null) {
            return $this->error('Library not found.', Response::HTTP_NOT_FOUND);
        }

        // Auth: always attempt (required for non-listed or approval-required libraries)
        $token = $this->extractBearerToken($request);
        $requester = $token !== null ? $this->directoryService->authenticate($token) : null;

        // Non-listed libraries are hidden from unauthenticated requests
        if (!$profile->isListed() && $requester === null) {
            return $this->error('Library not found.', Response::HTTP_NOT_FOUND);
        }

        $catalog = $this->directoryService->getCatalog($profile, $requester);

        if ($catalog !== null) {
            $visitorId = $requester?->getNodeId() ?? $request->getClientIp() ?? 'unknown';
            $this->recordView($profile, $visitorId);
        }

        if ($catalog === null) {
            return $this->error(
                $profile->isRequiresApproval()
                    ? 'Access requires an active follow relationship.'
                    : 'Catalog not available.',
                Response::HTTP_FORBIDDEN
            );
        }

        $response = [
            'node_id'      => $profile->getNodeId(),
            'isbn_payload' => $catalog->getIsbnPayload(),
            'updated_at'   => $catalog->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at'   => $catalog->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($catalog->getCatalogPayload() !== null) {
            $response['catalog_payload'] = $catalog->getCatalogPayload();
        }

        return $this->json($response);
    }

    // -------------------------------------------------------------------------
    // Directory listing
    // -------------------------------------------------------------------------

    #[Route('', name: 'list', methods: ['GET'])]
    public function listDirectory(Request $request): JsonResponse
    {
        $limit   = (int) ($request->query->get('limit', 50));
        $offset  = max(0, (int) $request->query->get('offset', 0));
        $country = $request->query->get('country');
        $search  = $request->query->get('search');

        try {
            $profiles = $this->directoryService->listDirectory($limit, $offset, $country ?: null, $search ?: null);
        } catch (\Throwable $e) {
            return $this->error('listDirectory failed: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'items'  => array_map(fn ($p) => $p->toPublicArray(), $profiles),
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    #[Route('/{nodeId}', name: 'profile_get', methods: ['GET'])]
    public function getProfile(string $nodeId, Request $request): JsonResponse
    {
        if (!$this->isValidNodeId($nodeId)) {
            return $this->error('Invalid node_id.', Response::HTTP_BAD_REQUEST);
        }

        $profile = $this->profileRepository->findByNodeId($nodeId);
        if ($profile === null) {
            return $this->error('Library not found.', Response::HTTP_NOT_FOUND);
        }

        // Non-listed profiles require authentication (same pattern as catalog)
        if (!$profile->isListed()) {
            $token = $this->extractBearerToken($request);
            $requester = $token !== null ? $this->directoryService->authenticate($token) : null;
            if ($requester === null) {
                return $this->error('Library not found.', Response::HTTP_NOT_FOUND);
            }
        }

        return $this->json($profile->toPublicArray());
    }

    // -------------------------------------------------------------------------
    // Follow lifecycle
    // -------------------------------------------------------------------------

    #[Route('/follow/{nodeId}', name: 'follow', methods: ['POST'])]
    public function follow(string $nodeId, Request $request): JsonResponse
    {
        $follower = $this->requireAuth($request);
        if ($follower instanceof JsonResponse) {
            return $follower;
        }

        if (!$this->isValidNodeId($nodeId)) {
            return $this->error('Invalid node_id.', Response::HTTP_BAD_REQUEST);
        }

        // Accept optional x25519 key so followers can receive encrypted contact
        $data = $this->parseJson($request);
        $x25519Key = $data['x25519_public_key'] ?? null;
        if ($x25519Key !== null && \is_string($x25519Key) && preg_match('/^[0-9a-f]{64}$/i', $x25519Key)) {
            $follower->setX25519PublicKey($x25519Key);
            $this->profileRepository->getEntityManager()->flush();
        }

        $followed = $this->profileRepository->findByNodeId($nodeId);
        if ($followed === null || !$followed->isListed()) {
            return $this->error('Library not found.', Response::HTTP_NOT_FOUND);
        }

        try {
            $follow = $this->directoryService->follow($follower, $followed);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        if ($follow === null) {
            // Silent rejection (blocked or accept_from mismatch) - return 200 to avoid enumeration
            return $this->json(['status' => 'pending']);
        }

        return $this->json($follow->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/follows/pending', name: 'follows_pending', methods: ['GET'])]
    public function pendingFollows(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $pending = $this->followRepository->findPendingFor($profile->getNodeId());

        // Enrich each follow with the follower's profile data.
        $items = array_map(function (Follow $f) {
            $data = $f->toArray();
            $followerProfile = $this->profileRepository->findByNodeId($f->getFollowerNodeId());
            $data['follower_display_name'] = $followerProfile?->getDisplayName();
            $data['follower_x25519_public_key'] = $followerProfile?->getX25519PublicKey();
            return $data;
        }, $pending);

        return $this->json([
            'items' => $items,
        ]);
    }

    #[Route('/follows/{id}', name: 'follows_resolve', methods: ['PATCH'])]
    public function resolveFollow(int $id, Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $data = $this->parseJson($request);
        $resolution = $data['resolution'] ?? null;

        if (!in_array($resolution, ['approve', 'reject', 'block'], true)) {
            return $this->error('resolution must be one of: approve, reject, block.', Response::HTTP_BAD_REQUEST);
        }

        // Optional E2EE contact blob (base64, max 8 KB) - attached when approving
        $encryptedContact = null;
        if (isset($data['encrypted_contact']) && is_string($data['encrypted_contact'])) {
            if (strlen($data['encrypted_contact']) > 8192) {
                return $this->error('encrypted_contact exceeds maximum size (8 KB).', Response::HTTP_BAD_REQUEST);
            }
            $encryptedContact = $data['encrypted_contact'];
        }

        try {
            $follow = $this->directoryService->resolveFollow($id, $resolution, $profile, $encryptedContact);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\LogicException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->json($follow->toArray());
    }

    #[Route('/follows', name: 'follows_list', methods: ['GET'], priority: 1)]
    public function listFollows(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $direction = $request->query->get('direction', 'following');

        $follows = match ($direction) {
            'following' => $this->followRepository->findActiveFollowing($profile->getNodeId()),
            'followers' => $this->followRepository->findActiveFollowers($profile->getNodeId()),
            default     => null,
        };

        if ($follows === null) {
            return $this->error('direction must be: following or followers.', Response::HTTP_BAD_REQUEST);
        }

        // Enrich with x25519 keys for E2EE contact exchange
        $items = array_map(function (Follow $f) use ($direction) {
            $data = $f->toArray();
            if ($direction === 'followers') {
                // Owner needs follower's key to encrypt contact for them
                $followerProfile = $this->profileRepository->findByNodeId($f->getFollowerNodeId());
                $data['follower_x25519_public_key'] = $followerProfile?->getX25519PublicKey();
                $data['follower_display_name'] = $followerProfile?->getDisplayName();
            }
            return $data;
        }, $follows);

        return $this->json([
            'items' => $items,
        ]);
    }

    #[Route('/follows/{nodeId}', name: 'unfollow', methods: ['DELETE'])]
    public function unfollow(string $nodeId, Request $request): JsonResponse
    {
        $follower = $this->requireAuth($request);
        if ($follower instanceof JsonResponse) {
            return $follower;
        }

        if (!$this->isValidNodeId($nodeId)) {
            return $this->error('Invalid node_id.', Response::HTTP_BAD_REQUEST);
        }

        $this->directoryService->unfollow($nodeId, $follower);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // E2EE contact sync
    // -------------------------------------------------------------------------

    /**
     * Batch-updates encrypted contact blobs for all active followers.
     * Called when the library owner changes their contact info.
     *
     * Body: { "contacts": [ { "follow_id": 42, "encrypted_contact": "base64..." }, ... ] }
     */
    #[Route('/contacts/sync', name: 'contacts_sync', methods: ['POST'], priority: 2)]
    public function syncContacts(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $data = $this->parseJson($request);
        $contacts = $data['contacts'] ?? null;

        if (!is_array($contacts)) {
            return $this->error('contacts array is required.', Response::HTTP_BAD_REQUEST);
        }

        if (count($contacts) > 500) {
            return $this->error('Too many contacts (max 500).', Response::HTTP_BAD_REQUEST);
        }

        try {
            $updated = $this->directoryService->syncFollowContacts($profile, $contacts);
        } catch (\Throwable $e) {
            return $this->error('syncContacts failed: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['updated' => $updated]);
    }

    // -------------------------------------------------------------------------
    // Borrow requests (ADR-018)
    // -------------------------------------------------------------------------

    #[Route('/borrow', name: 'borrow_create', methods: ['POST'], priority: 2)]
    public function createBorrowRequest(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $data = $this->parseJson($request);
        if ($data === null) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $lenderNodeId = $data['lender_node_id'] ?? null;
        if (!$this->isValidNodeId($lenderNodeId)) {
            return $this->error('lender_node_id is required (max 128 chars).', Response::HTTP_BAD_REQUEST);
        }

        $isbn = $data['isbn'] ?? null;
        if (!is_string($isbn) || $isbn === '' || strlen($isbn) > 20) {
            return $this->error('isbn is required (max 20 chars).', Response::HTTP_BAD_REQUEST);
        }

        $bookTitle = $data['book_title'] ?? '';

        try {
            $borrowRequest = $this->directoryService->createBorrowRequest(
                $profile,
                $lenderNodeId,
                $isbn,
                is_string($bookTitle) ? $bookTitle : '',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\LogicException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->error('createBorrowRequest failed: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($borrowRequest->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/borrow/incoming', name: 'borrow_incoming', methods: ['GET'], priority: 2)]
    public function incomingBorrowRequests(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        try {
            $items = $this->directoryService->getIncomingBorrowRequests($profile);
        } catch (\Throwable $e) {
            return $this->error('getIncomingBorrowRequests failed: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['items' => $items]);
    }

    #[Route('/borrow/outgoing', name: 'borrow_outgoing', methods: ['GET'], priority: 2)]
    public function outgoingBorrowRequests(Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        try {
            $items = $this->directoryService->getOutgoingBorrowRequests($profile);
        } catch (\Throwable $e) {
            return $this->error('getOutgoingBorrowRequests failed: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['items' => $items]);
    }

    #[Route('/borrow/{id}', name: 'borrow_resolve', methods: ['PATCH'], priority: 2)]
    public function resolveBorrowRequest(int $id, Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $data = $this->parseJson($request);
        $resolution = $data['resolution'] ?? null;

        if (!in_array($resolution, ['accept', 'reject'], true)) {
            return $this->error('resolution must be one of: accept, reject.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $borrowRequest = $this->directoryService->resolveBorrowRequest($id, $resolution, $profile);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\LogicException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->json($borrowRequest->toArray());
    }

    #[Route('/borrow/{id}', name: 'borrow_cancel', methods: ['DELETE'], priority: 2)]
    public function cancelBorrowRequest(int $id, Request $request): JsonResponse
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        try {
            $this->directoryService->cancelBorrowRequest($id, $profile);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\LogicException $e) {
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        return substr($header, 7);
    }

    /**
     * Authenticates the request and returns the LibraryProfile.
     * Returns a JsonResponse (401) if authentication fails.
     */
    private function requireAuth(Request $request): \App\Entity\LibraryProfile|JsonResponse
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->error('Authorization: Bearer <write_token> is required.', Response::HTTP_UNAUTHORIZED);
        }

        $profile = $this->directoryService->authenticate($token);
        if ($profile === null) {
            return $this->error('Invalid write_token.', Response::HTTP_UNAUTHORIZED);
        }

        return $profile;
    }

    private function parseJson(Request $request): ?array
    {
        if (empty($request->getContent())) {
            return [];
        }

        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : null;
    }

    private function isValidNodeId(mixed $nodeId): bool
    {
        return is_string($nodeId) && $nodeId !== '' && strlen($nodeId) <= 128;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    /**
     * Records a catalog view with a 15-minute cooldown per visitor.
     * Uses UPSERT to atomically check and update the cooldown timestamp.
     */
    private function recordView(\App\Entity\LibraryProfile $profile, string $visitorId): void
    {
        try {
            $nodeId = $profile->getNodeId();
            $now = new \DateTimeImmutable();
            $cutoff = $now->modify('-' . self::VIEW_COOLDOWN_SECONDS . ' seconds');

            // Check if this visitor has a recent cooldown entry
            $lastCounted = $this->connection->fetchOne(
                'SELECT last_counted_at FROM library_view_cooldowns WHERE profile_node_id = :node AND visitor_id = :visitor',
                ['node' => $nodeId, 'visitor' => substr($visitorId, 0, 128)]
            );

            if ($lastCounted !== false && new \DateTimeImmutable($lastCounted) > $cutoff) {
                return; // Still in cooldown
            }

            // Upsert cooldown entry
            $this->connection->executeStatement(
                'INSERT INTO library_view_cooldowns (profile_node_id, visitor_id, last_counted_at) '
                . 'VALUES (:node, :visitor, :now) '
                . 'ON CONFLICT (profile_node_id, visitor_id) DO UPDATE SET last_counted_at = :now',
                [
                    'node' => $nodeId,
                    'visitor' => substr($visitorId, 0, 128),
                    'now' => $now->format('Y-m-d H:i:s'),
                ]
            );

            // Increment view count atomically
            $this->connection->executeStatement(
                'UPDATE library_profiles SET view_count = view_count + 1 WHERE node_id = :node',
                ['node' => $nodeId]
            );

            // Probabilistic cleanup: ~1% chance to purge old cooldown entries (> 1 hour)
            if (random_int(1, 100) === 1) {
                $this->connection->executeStatement(
                    'DELETE FROM library_view_cooldowns WHERE last_counted_at < :cutoff',
                    ['cutoff' => $now->modify('-1 hour')->format('Y-m-d H:i:s')]
                );
            }
        } catch (\Throwable) {
            // View counting is best-effort, never fail the main request
        }
    }
}
