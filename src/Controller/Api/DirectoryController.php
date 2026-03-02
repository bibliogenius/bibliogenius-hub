<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Follow;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Service\DirectoryService;
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
 */
#[Route('/api/directory', name: 'api_directory_')]
class DirectoryController extends AbstractController
{
    public function __construct(
        private readonly DirectoryService $directoryService,
        private readonly LibraryProfileRepository $profileRepository,
        private readonly FollowRepository $followRepository,
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
        if ($profile === null || !$profile->isListed()) {
            return $this->error('Library not found.', Response::HTTP_NOT_FOUND);
        }

        // Optional auth - required only for approval-required libraries
        $token = $this->extractBearerToken($request);
        $requester = $token !== null ? $this->directoryService->authenticate($token) : null;

        $catalog = $this->directoryService->getCatalog($profile, $requester);

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

        try {
            $profiles = $this->directoryService->listDirectory($limit, $offset, $country ?: null);
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
    public function getProfile(string $nodeId): JsonResponse
    {
        if (!$this->isValidNodeId($nodeId)) {
            return $this->error('Invalid node_id.', Response::HTTP_BAD_REQUEST);
        }

        $profile = $this->profileRepository->findByNodeId($nodeId);
        if ($profile === null || !$profile->isListed()) {
            return $this->error('Library not found.', Response::HTTP_NOT_FOUND);
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

        // Enrich each follow with the follower's display name from their profile.
        $items = array_map(function (Follow $f) {
            $data = $f->toArray();
            $followerProfile = $this->profileRepository->findByNodeId($f->getFollowerNodeId());
            $data['follower_display_name'] = $followerProfile?->getDisplayName();
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

        try {
            $follow = $this->directoryService->resolveFollow($id, $resolution, $profile);
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

        return $this->json([
            'items' => array_map(fn ($f) => $f->toArray(), $follows),
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
}
