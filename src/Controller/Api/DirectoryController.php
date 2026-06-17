<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Follow;
use App\Entity\RegistrationFailure;
use App\Repository\BorrowRequestRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Service\DirectoryService;
use App\Service\SidecarNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\HubEventLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly HubEventLogger $eventLogger,
        private readonly SidecarNotifier $sidecarNotifier,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%covers_directory%')]
        private readonly string $coversDirectory,
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

        $appVersion = $this->sanitizeAppVersion($data['app_version'] ?? null);

        $existing = $this->profileRepository->findByNodeId($nodeId);
        if ($existing !== null && $authenticated?->getNodeId() !== $nodeId) {
            // Log the failed attempt so admins can unblock manually via BO
            try {
                $failure = new RegistrationFailure(
                    $nodeId,
                    substr(strip_tags($data['display_name'] ?? ''), 0, 255),
                    max(0, (int) ($data['book_count'] ?? 0)),
                    $request->getClientIp(),
                    $appVersion,
                );
                $this->entityManager->persist($failure);
                $this->entityManager->flush();
            } catch (\Throwable) {
                // Best-effort logging, never fail the main response
            }
            $this->eventLogger->warning('directory', 'registration rejected (missing write_token)', [
                'node_id' => substr($nodeId, 0, 12),
                'name' => substr(strip_tags($data['display_name'] ?? ''), 0, 50),
                'app_version' => $appVersion,
            ]);
            return $this->error('Valid write_token required to update an existing profile.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->directoryService->upsertProfile($data, $authenticated);
        } catch (\LogicException $e) {
            $this->eventLogger->warning('directory', 'upsert forbidden', []);
            return $this->error($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent insert for the same node_id won the race.
            // Return 409 so the client retries; next attempt will find
            // the existing profile and authenticate normally.
            return $this->error('Concurrent registration. Please retry.', Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            // Doctrine may wrap the DBAL exception in an ORMException.
            if ($e->getPrevious() instanceof UniqueConstraintViolationException) {
                return $this->error('Concurrent registration. Please retry.', Response::HTTP_CONFLICT);
            }
            $this->eventLogger->error('directory', 'upsert failed', ['reason' => $e->getMessage()]);
            return $this->error('upsertProfile failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = $result['profile']->toPublicArray();
        if ($result['write_token'] !== null) {
            // Returned once only - the library must store it
            $response['write_token'] = $result['write_token'];
        }
        if ($result['recovery_code'] !== null) {
            // Returned once only - the library must show it to the user
            $response['recovery_code'] = $result['recovery_code'];
        }

        $status = $existing === null ? Response::HTTP_CREATED : Response::HTTP_OK;

        if ($existing === null) {
            $this->eventLogger->info('directory', 'profile registered', [
                'node_id' => substr($nodeId, 0, 12),
                'name' => substr(strip_tags($data['display_name'] ?? ''), 0, 50),
                'app_version' => $appVersion,
            ]);
        }

        return $this->json($response, $status);
    }

    /**
     * Recover access to a profile using a one-time recovery code.
     * Returns a new write_token + a new recovery_code (the old one is invalidated).
     *
     * Rate-limited: max 5 failed attempts per node_id per hour (logged, not hard-blocked in beta).
     */
    #[Route('/recover', name: 'recover', methods: ['POST'])]
    public function recoverProfile(Request $request): JsonResponse
    {
        $data = $this->parseJson($request);
        if ($data === null) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $nodeId = $data['node_id'] ?? null;
        if (!$this->isValidNodeId($nodeId)) {
            return $this->error('node_id is required.', Response::HTTP_BAD_REQUEST);
        }

        $code = $data['recovery_code'] ?? null;
        if (!is_string($code) || strlen($code) < 8 || strlen($code) > 20) {
            return $this->error('recovery_code is required (8-20 characters).', Response::HTTP_BAD_REQUEST);
        }

        // Normalize: strip dashes/spaces, uppercase
        $code = strtoupper(preg_replace('/[\s\-]/', '', $code));

        $result = $this->directoryService->recoverProfile($nodeId, $code);

        if ($result === null) {
            $this->eventLogger->warning('directory', 'recovery failed (invalid code)', [
                'node_id' => substr($nodeId, 0, 12),
            ]);
            // Generic error to avoid leaking whether the node_id exists
            return $this->error('Invalid recovery code.', Response::HTTP_UNAUTHORIZED);
        }

        $this->eventLogger->warning('directory', 'profile recovered via recovery code', [
            'node_id' => substr($nodeId, 0, 12),
            'name' => substr($result['profile']->getDisplayName(), 0, 50),
        ]);

        $response = $result['profile']->toPublicArray();
        $response['write_token'] = $result['write_token'];
        $response['recovery_code'] = $result['recovery_code'];

        return $this->json($response, Response::HTTP_OK);
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
    public function pushCatalog(Request $request): JsonResponse|Response
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            // DEBUG (catalog desync investigation): a catalog push that fails
            // auth is otherwise invisible (fingers_crossed only logs 5xx). Log
            // a token fingerprint (never the token) to correlate a library.
            $tok = $this->extractBearerToken($request);
            $this->directoryService->logCatalogEvent('catalog_push_debug', 'warning', 'auth_failed', [
                'status' => $profile->getStatusCode(),
                'token_fp' => $tok !== null ? substr(hash('sha256', $tok), 0, 12) : null,
            ]);
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

        $bookCount = isset($data['book_count']) && is_numeric($data['book_count'])
            ? max(0, (int) $data['book_count'])
            : null;

        // Optional client-computed SHA-256 of the canonical payload (ADR-027).
        // When present and matching the stored hash, the hub returns 304 and
        // skips rewriting the payload. Format is validated by the service.
        $catalogHash = null;
        if (isset($data['catalog_hash']) && is_string($data['catalog_hash'])) {
            $catalogHash = $data['catalog_hash'];
        }

        try {
            $result = $this->directoryService->pushCatalog(
                $profile,
                $data['isbn_payload'],
                $catalogPayload,
                $bookCount,
                $catalogHash,
            );
        } catch (\InvalidArgumentException $e) {
            // Size limit vs. hash format: both raise InvalidArgumentException.
            // Map format errors to 400, size errors to 413.
            $status = str_contains($e->getMessage(), 'catalog_hash')
                ? Response::HTTP_BAD_REQUEST
                : Response::HTTP_REQUEST_ENTITY_TOO_LARGE;
            $this->directoryService->logCatalogEvent('catalog_push_debug', 'warning', 'rejected', [
                'node_id' => substr($profile->getNodeId(), 0, 12),
                'status' => $status,
                'reason' => substr($e->getMessage(), 0, 120),
            ]);
            return $this->error($e->getMessage(), $status);
        }

        if ($result->unchanged) {
            // 304 Not Modified: the client already has the same catalog.
            // Per RFC 7232, the body must be empty; callers read the ETag
            // header to confirm which version matched.
            $response = new Response(null, Response::HTTP_NOT_MODIFIED);
            if ($result->catalog->getCatalogHash() !== null) {
                $response->headers->set('ETag', '"'.$result->catalog->getCatalogHash().'"');
            }
            return $response;
        }

        $json = $this->json([
            'updated_at' => $result->catalog->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at' => $result->catalog->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);
        if ($result->catalog->getCatalogHash() !== null) {
            $json->headers->set('ETag', '"'.$result->catalog->getCatalogHash().'"');
        }
        return $json;
    }

    /**
     * DEBUG (catalog desync investigation): client-side beacon. When the
     * device's catalog sync fails before/at the push (e.g. a local DB error),
     * the request never reaches `pushCatalog`, so it is invisible server-side.
     * This lets the client self-report (node_id + phase + error) into
     * hub_events so the failure surfaces in DB backups without the device log.
     * Auth-gated by write_token like the push itself; never mutates state.
     */
    #[Route('/catalog/diag', name: 'catalog_diag', methods: ['POST'])]
    public function pushCatalogDiag(Request $request): JsonResponse|Response
    {
        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $data = $this->parseJson($request) ?? [];
        $phase = is_string($data['phase'] ?? null) ? substr($data['phase'], 0, 40) : 'unknown';
        $ok = (bool) ($data['ok'] ?? false);
        $detail = is_string($data['detail'] ?? null) ? substr($data['detail'], 0, 300) : null;

        $this->directoryService->logCatalogEvent(
            'catalog_sync_diag',
            $ok ? 'info' : 'warning',
            $ok ? 'sync_ok' : 'sync_failed',
            [
                'node_id' => substr($profile->getNodeId(), 0, 12),
                'phase' => $phase,
                'detail' => $detail,
            ],
        );

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{nodeId}/catalog', name: 'catalog_get', methods: ['GET', 'HEAD'])]
    public function getCatalog(string $nodeId, Request $request): JsonResponse|Response
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

        if ($catalog === null) {
            return $this->error(
                $profile->isRequiresApproval()
                    ? 'Access requires an active follow relationship.'
                    : 'Catalog not available.',
                Response::HTTP_FORBIDDEN
            );
        }

        // Conditional GET: if the client already has the current hash, return
        // 304 and skip the view-count bump (ADR-027).
        $etag = $catalog->getCatalogHash() !== null
            ? '"'.$catalog->getCatalogHash().'"'
            : null;
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($etag !== null && $ifNoneMatch !== null && $this->ifNoneMatchMatches($ifNoneMatch, $etag)) {
            $notModified = new Response(null, Response::HTTP_NOT_MODIFIED);
            $notModified->headers->set('ETag', $etag);
            return $notModified;
        }

        $visitorId = $requester?->getNodeId() ?? $request->getClientIp() ?? 'unknown';
        $this->recordView($profile, $visitorId);

        $response = [
            'node_id'      => $profile->getNodeId(),
            'isbn_payload' => $catalog->getIsbnPayload(),
            'updated_at'   => $catalog->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at'   => $catalog->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($catalog->getCatalogPayload() !== null) {
            $response['catalog_payload'] = $catalog->getCatalogPayload();
        }

        $json = $this->json($response);
        if ($etag !== null) {
            $json->headers->set('ETag', $etag);
        }
        return $json;
    }

    /**
     * Matches an `If-None-Match` header against a strong ETag.
     *
     * Accepts the RFC 7232 forms: wildcard `*`, comma-separated list,
     * and weak `W/"..."` equivalents (opaque comparison).
     */
    private function ifNoneMatchMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed === '*') {
                return true;
            }
            $normalized = str_starts_with($trimmed, 'W/') ? substr($trimmed, 2) : $trimmed;
            if ($normalized === $etag) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Cover thumbnails
    // -------------------------------------------------------------------------

    #[Route('/{nodeId}/covers/{bookId}', name: 'cover_upload', methods: ['POST'], priority: 2)]
    public function uploadCover(string $nodeId, string $bookId, Request $request): JsonResponse|Response
    {
        if (!$this->isValidNodeId($nodeId) || !ctype_digit($bookId)) {
            return $this->error('Invalid parameters.', Response::HTTP_BAD_REQUEST);
        }

        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        // Ensure the authenticated profile matches the nodeId
        if ($profile->getNodeId() !== $nodeId) {
            return $this->error('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $body = $request->getContent();

        if (strlen($body) > 102400) { // 100 KB
            return $this->error('Cover too large (max 100 KB).', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if (strlen($body) < 3 || $body[0] !== "\xFF" || $body[1] !== "\xD8" || $body[2] !== "\xFF") {
            return $this->error('Invalid image (JPEG required).', Response::HTTP_BAD_REQUEST);
        }

        $dir = $this->coversDirectory . '/' . $nodeId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $bookId . '.jpg', $body);

        return $this->json(['status' => 'ok'], Response::HTTP_CREATED);
    }

    #[Route('/{nodeId}/covers/{bookId}', name: 'cover_get', methods: ['GET'], priority: 2)]
    public function getCover(string $nodeId, string $bookId): BinaryFileResponse|JsonResponse
    {
        if (!$this->isValidNodeId($nodeId) || !ctype_digit($bookId)) {
            return $this->error('Invalid parameters.', Response::HTTP_BAD_REQUEST);
        }

        $path = $this->coversDirectory . '/' . $nodeId . '/' . $bookId . '.jpg';

        if (!is_file($path)) {
            return $this->error('Cover not found.', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $bookId . '.jpg');

        return $response;
    }

    #[Route('/{nodeId}/covers/{bookId}', name: 'cover_delete', methods: ['DELETE'], priority: 2)]
    public function deleteCover(string $nodeId, string $bookId, Request $request): JsonResponse
    {
        if (!$this->isValidNodeId($nodeId) || !ctype_digit($bookId)) {
            return $this->error('Invalid parameters.', Response::HTTP_BAD_REQUEST);
        }

        $profile = $this->requireAuth($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        if ($profile->getNodeId() !== $nodeId) {
            return $this->error('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $path = $this->coversDirectory . '/' . $nodeId . '/' . $bookId . '.jpg';
        if (is_file($path)) {
            unlink($path);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
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
        // ADR-035 Phase 2: optional city filter. Reject anything that is not
        // a positive integer so a malformed query never reaches the SQL
        // layer (defense in depth, the QB also binds via parameter).
        $cityIdRaw = $request->query->get('city_id');
        $cityId = null;
        if ($cityIdRaw !== null && $cityIdRaw !== '') {
            if (!ctype_digit((string) $cityIdRaw)) {
                return $this->error('city_id must be a positive integer.', Response::HTTP_BAD_REQUEST);
            }
            $cityId = (int) $cityIdRaw;
            if ($cityId <= 0) {
                return $this->error('city_id must be a positive integer.', Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $profiles = $this->directoryService->listDirectory(
                $limit,
                $offset,
                $country ?: null,
                $search ?: null,
                $cityId,
            );
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
            $this->eventLogger->warning('directory', 'profile lookup: not found', [
                'node_id' => $nodeId,
            ]);
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

        $data = $profile->toPublicArray();

        // Relay credentials are only returned to authenticated requesters
        // to limit mailbox spam surface (OWASP A01).
        $token = $this->extractBearerToken($request);
        $requester = $token !== null ? $this->directoryService->authenticate($token) : null;
        if ($requester !== null) {
            $data['relay_url'] = $profile->getRelayUrl();
            $data['relay_mailbox_id'] = $profile->getRelayMailboxId();
            $data['relay_write_token'] = $profile->getRelayWriteToken();
            $this->eventLogger->info('directory', 'profile lookup with relay creds', [
                'node_id' => $nodeId,
                'name' => $profile->getDisplayName(),
                'mailbox' => $profile->getRelayMailboxId() ?? 'none',
            ]);
        }

        return $this->json($data);
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

        // Notify the followed library (new follower or pending request).
        $this->nudgeProfile($followed->getRelayMailboxId());

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

        // Notify the follower that their request was approved, rejected, or blocked.
        $followerProfile = $this->profileRepository->findByNodeId($follow->getFollowerNodeId());
        $this->nudgeProfile($followerProfile?->getRelayMailboxId());

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
            'following' => $this->followRepository->findFollowing($profile->getNodeId()),
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

        // Notify the lender of the incoming borrow request.
        $lenderProfile = $this->profileRepository->findByNodeId($lenderNodeId);
        $this->nudgeProfile($lenderProfile?->getRelayMailboxId());

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

        // Notify the requester that their borrow request was accepted or rejected.
        $requesterProfile = $this->profileRepository->findByNodeId($borrowRequest->getRequesterNodeId());
        $this->nudgeProfile($requesterProfile?->getRelayMailboxId());

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

    /**
     * Fire-and-forget nudge to a relay mailbox. No-op if mailboxId is null
     * (profile not yet registered on the relay) or if the sidecar is down.
     */
    private function nudgeProfile(?string $mailboxId): void
    {
        if ($mailboxId !== null) {
            $this->sidecarNotifier->nudge($mailboxId);
        }
    }

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

    /**
     * Normalizes an incoming app_version value for logging/persistence.
     * Mirrors the stricter validation in DirectoryService::applyProfileData -
     * returns null on anything unrecognized to avoid leaking injection attempts
     * into hub_events.context or registration_failures.app_version.
     */
    private function sanitizeAppVersion(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = substr(trim(strip_tags($raw)), 0, 32);
        return $trimmed !== '' && preg_match('/^[A-Za-z0-9._+\-]{1,32}$/', $trimmed)
            ? $trimmed
            : null;
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
