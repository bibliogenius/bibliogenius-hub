<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BorrowRequest;
use App\Entity\CachedCatalog;
use App\Entity\Follow;
use App\Entity\LibraryProfile;
use App\Repository\BorrowRequestRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Business logic for the public library directory.
 *
 * Responsibilities:
 *   - Profile registration and updates
 *   - Catalog cache push and retrieval
 *   - Follow request lifecycle (create, approve, reject, block, unfollow)
 *   - Borrow request lifecycle (create, resolve, cancel)
 *   - Directory listing
 *
 * The controller delegates entirely to this service. No HTTP concerns here.
 */
class DirectoryService
{
    /** Probabilistic cleanup: 1 in N writes triggers expired catalog pruning. */
    private const CLEANUP_PROBABILITY = 50;

    private const VALID_ACCEPT_FROM = ['everyone', 'individuals_only', 'institutions_only'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LibraryProfileRepository $profileRepository,
        private readonly FollowRepository $followRepository,
        private readonly BorrowRequestRepository $borrowRequestRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Profile
    // -------------------------------------------------------------------------

    /**
     * Registers a new library profile or updates an existing one.
     *
     * On first registration: generates and returns a write_token.
     * On update: requires a valid write_token via $authenticatedProfile.
     *
     * @return array{profile: LibraryProfile, write_token: string|null}
     */
    public function upsertProfile(array $data, ?LibraryProfile $authenticatedProfile): array
    {
        $nodeId = $data['node_id'] ?? null;

        $existing = $this->profileRepository->findByNodeId($nodeId);

        if ($existing === null) {
            // New registration
            $writeToken = $this->generateToken();
            $profile = new LibraryProfile(
                $nodeId,
                $writeToken,
                $this->sanitizeString($data['display_name'] ?? '', 255)
            );
            $this->applyProfileData($profile, $data);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();

            return ['profile' => $profile, 'write_token' => $writeToken];
        }

        // Update - caller must be authenticated as this profile
        if ($authenticatedProfile?->getNodeId() !== $existing->getNodeId()) {
            throw new \LogicException('Authenticated profile does not match node_id.');
        }

        $this->applyProfileData($existing, $data);
        $existing->touchLastSeen();
        $this->entityManager->flush();

        return ['profile' => $existing, 'write_token' => null];
    }

    private function applyProfileData(LibraryProfile $profile, array $data): void
    {
        if (isset($data['display_name'])) {
            $profile->setDisplayName($this->sanitizeString($data['display_name'], 255));
        }
        if (array_key_exists('description', $data)) {
            $profile->setDescription(
                $data['description'] !== null
                    ? $this->sanitizeString($data['description'], 2000)
                    : null
            );
        }
        if (isset($data['book_count'])) {
            $profile->setBookCount(max(0, (int) $data['book_count']));
        }
        if (array_key_exists('location_country', $data)) {
            $profile->setLocationCountry(
                $data['location_country'] !== null
                    ? substr(preg_replace('/[^A-Z]/', '', strtoupper($data['location_country'])), 0, 5)
                    : null
            );
        }
        if (isset($data['requires_approval'])) {
            $profile->setRequiresApproval((bool) $data['requires_approval']);
        }
        if (isset($data['accept_from']) && in_array($data['accept_from'], self::VALID_ACCEPT_FROM, true)) {
            $profile->setAcceptFrom($data['accept_from']);
        }
        if (isset($data['allow_borrowing'])) {
            $profile->setAllowBorrowing((bool) $data['allow_borrowing']);
        }
        if (isset($data['is_listed'])) {
            $profile->setIsListed((bool) $data['is_listed']);
        }
    }

    // -------------------------------------------------------------------------
    // Catalog cache
    // -------------------------------------------------------------------------

    /**
     * Pushes or refreshes the catalog cache for a library.
     *
     * @param string      $isbnPayload    JSON array of ISBNs (legacy format)
     * @param string|null $catalogPayload JSON array of {isbn, title, author} objects (enriched format)
     */
    public function pushCatalog(LibraryProfile $profile, string $isbnPayload, ?string $catalogPayload = null): CachedCatalog
    {
        $this->probabilisticCleanup();

        // Validate catalog_payload size if provided (max 2 MB for enriched data)
        if ($catalogPayload !== null && strlen($catalogPayload) > 2097152) {
            throw new \InvalidArgumentException('catalog_payload exceeds maximum allowed size.');
        }

        $catalog = $this->entityManager->find(CachedCatalog::class, $profile->getNodeId());

        if ($catalog === null) {
            $catalog = new CachedCatalog($profile, $isbnPayload, $catalogPayload);
            $this->entityManager->persist($catalog);
        } else {
            $catalog->refresh($isbnPayload, $catalogPayload);
        }

        $profile->touchLastSeen();
        $this->entityManager->flush();

        return $catalog;
    }

    /**
     * Returns the cached catalog for a library if the requester is allowed to see it.
     *
     * Access rules:
     *   - requires_approval=false: public, no auth required (requesterProfile may be null)
     *   - requires_approval=true:  requester must have an active follow relationship
     */
    public function getCatalog(LibraryProfile $profile, ?LibraryProfile $requesterProfile): ?CachedCatalog
    {
        if (!$profile->isRequiresApproval()) {
            return $this->entityManager->find(CachedCatalog::class, $profile->getNodeId());
        }

        if ($requesterProfile === null) {
            return null;
        }

        if (!$this->followRepository->isActiveFollower($requesterProfile->getNodeId(), $profile->getNodeId())) {
            return null;
        }

        return $this->entityManager->find(CachedCatalog::class, $profile->getNodeId());
    }

    // -------------------------------------------------------------------------
    // Follow lifecycle
    // -------------------------------------------------------------------------

    /**
     * Sends a follow request from follower to followed.
     *
     * If followed.requires_approval=false: auto-approved.
     * If followed.accept_from restricts requester type: returns null (rejected silently).
     *
     * Returns null if the request is silently rejected (accept_from mismatch or already blocked).
     */
    public function follow(LibraryProfile $follower, LibraryProfile $followed): ?Follow
    {
        // Prevent self-follow
        if ($follower->getNodeId() === $followed->getNodeId()) {
            throw new \InvalidArgumentException('A library cannot follow itself.');
        }

        $existing = $this->followRepository->findExisting(
            $follower->getNodeId(),
            $followed->getNodeId()
        );

        // Already blocked: silent rejection
        if ($existing?->getStatus() === Follow::STATUS_BLOCKED) {
            return null;
        }

        // Already active or pending: return as-is
        if ($existing !== null && in_array($existing->getStatus(), [Follow::STATUS_ACTIVE, Follow::STATUS_PENDING], true)) {
            return $existing;
        }

        $follow = $existing ?? new Follow($follower->getNodeId(), $followed->getNodeId());

        if (!$followed->isRequiresApproval()) {
            $follow->approve();
        }

        if ($existing === null) {
            $this->entityManager->persist($follow);
        }

        $this->entityManager->flush();

        return $follow;
    }

    /**
     * Approves or rejects a pending follow request.
     * Only the followed library (authenticated) can resolve requests.
     */
    public function resolveFollow(int $followId, string $resolution, LibraryProfile $authenticated): Follow
    {
        $follow = $this->followRepository->find($followId);

        if ($follow === null) {
            throw new \InvalidArgumentException('Follow request not found.');
        }

        if ($follow->getFollowedNodeId() !== $authenticated->getNodeId()) {
            throw new \LogicException('Not authorized to resolve this follow request.');
        }

        if (!$follow->isPending()) {
            throw new \LogicException('Follow request is not pending.');
        }

        match ($resolution) {
            'approve' => $follow->approve(),
            'reject'  => $follow->reject(),
            'block'   => $follow->block(),
            default   => throw new \InvalidArgumentException('Invalid resolution. Use: approve, reject, block.'),
        };

        $this->entityManager->flush();

        return $follow;
    }

    /**
     * Completely removes a library profile and all associated data.
     * Deletes: the profile, all follows (as follower or followed), and cached catalogs (cascade).
     */
    public function deleteProfile(LibraryProfile $profile): void
    {
        $nodeId = $profile->getNodeId();

        // Delete all follows where this library is follower or followed
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'DELETE FROM follows WHERE follower_node_id = :nid OR followed_node_id = :nid',
            ['nid' => $nodeId]
        );

        // cached_catalogs has onDelete CASCADE on the FK to library_profiles,
        // so removing the profile entity will cascade.
        $this->entityManager->remove($profile);
        $this->entityManager->flush();
    }

    /**
     * Removes an active follow. Only the follower can unfollow.
     */
    public function unfollow(string $followedNodeId, LibraryProfile $follower): bool
    {
        $follow = $this->followRepository->findExisting($follower->getNodeId(), $followedNodeId);

        if ($follow === null) {
            return false;
        }

        $this->entityManager->remove($follow);
        $this->entityManager->flush();

        return true;
    }

    // -------------------------------------------------------------------------
    // Borrow requests (ADR-018)
    // -------------------------------------------------------------------------

    /**
     * Creates a borrow request from requester to lender.
     *
     * Validates: lender exists, active follow relationship, no pending duplicate.
     * Triggers probabilistic cleanup of expired requests (1 in 50 writes).
     */
    public function createBorrowRequest(
        LibraryProfile $requester,
        string $lenderNodeId,
        string $isbn,
        string $bookTitle,
    ): BorrowRequest {
        // Validate lender exists
        $lender = $this->profileRepository->findByNodeId($lenderNodeId);
        if ($lender === null) {
            throw new \InvalidArgumentException('Lender library not found.');
        }

        // Check if lender accepts borrow requests
        if (!$lender->isAllowBorrowing()) {
            throw new \LogicException('Borrowing is disabled for this library.');
        }

        // Validate active follow relationship
        if (!$this->followRepository->isActiveFollower($requester->getNodeId(), $lenderNodeId)) {
            throw new \LogicException('You must follow this library before requesting a loan.');
        }

        // Check for duplicate pending request
        $existing = $this->borrowRequestRepository->findPendingDuplicate(
            $requester->getNodeId(),
            $lenderNodeId,
            $isbn
        );
        if ($existing !== null) {
            throw new \LogicException('A pending request already exists for this book.');
        }

        $request = new BorrowRequest(
            $requester->getNodeId(),
            $lenderNodeId,
            substr(trim($isbn), 0, 20),
            substr(trim(strip_tags($bookTitle)), 0, 500),
        );

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        // Probabilistic cleanup of expired requests
        if (random_int(1, self::CLEANUP_PROBABILITY) === 1) {
            $this->borrowRequestRepository->pruneExpired();
        }

        return $request;
    }

    /**
     * Returns pending borrow requests for a lender, enriched with requester display names.
     *
     * @return array<array<string, mixed>>
     */
    public function getIncomingBorrowRequests(LibraryProfile $lender): array
    {
        $requests = $this->borrowRequestRepository->findPendingForLender($lender->getNodeId());

        return array_map(function (BorrowRequest $r) {
            $data = $r->toArray();
            $requesterProfile = $this->profileRepository->findByNodeId($r->getRequesterNodeId());
            $data['requester_display_name'] = $requesterProfile?->getDisplayName();
            return $data;
        }, $requests);
    }

    /**
     * Returns all borrow requests sent by a requester, enriched with lender display names.
     *
     * @return array<array<string, mixed>>
     */
    public function getOutgoingBorrowRequests(LibraryProfile $requester): array
    {
        $requests = $this->borrowRequestRepository->findByRequester($requester->getNodeId());

        return array_map(function (BorrowRequest $r) {
            $data = $r->toArray();
            $lenderProfile = $this->profileRepository->findByNodeId($r->getLenderNodeId());
            $data['lender_display_name'] = $lenderProfile?->getDisplayName();
            return $data;
        }, $requests);
    }

    /**
     * Resolves a borrow request (accept or reject). Only the lender can resolve.
     */
    public function resolveBorrowRequest(int $requestId, string $resolution, LibraryProfile $lender): BorrowRequest
    {
        $request = $this->borrowRequestRepository->find($requestId);

        if ($request === null) {
            throw new \InvalidArgumentException('Borrow request not found.');
        }

        if ($request->getLenderNodeId() !== $lender->getNodeId()) {
            throw new \LogicException('Not authorized to resolve this borrow request.');
        }

        if (!$request->isPending()) {
            throw new \LogicException('Borrow request is not pending.');
        }

        match ($resolution) {
            'accept' => $request->accept(),
            'reject' => $request->reject(),
            default  => throw new \InvalidArgumentException('Invalid resolution. Use: accept, reject.'),
        };

        $this->entityManager->flush();

        return $request;
    }

    /**
     * Cancels a borrow request. Only the requester can cancel.
     */
    public function cancelBorrowRequest(int $requestId, LibraryProfile $requester): BorrowRequest
    {
        $request = $this->borrowRequestRepository->find($requestId);

        if ($request === null) {
            throw new \InvalidArgumentException('Borrow request not found.');
        }

        if ($request->getRequesterNodeId() !== $requester->getNodeId()) {
            throw new \LogicException('Not authorized to cancel this borrow request.');
        }

        if (!$request->isPending()) {
            throw new \LogicException('Borrow request is not pending.');
        }

        $request->cancel();
        $this->entityManager->flush();

        return $request;
    }

    // -------------------------------------------------------------------------
    // Directory listing
    // -------------------------------------------------------------------------

    /** @return LibraryProfile[] */
    public function listDirectory(int $limit, int $offset, ?string $country): array
    {
        $limit = min(100, max(1, $limit));

        return $this->profileRepository->findListed($limit, $offset, $country);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Resolves a LibraryProfile from an Authorization: Bearer header value. Returns null if invalid. */
    public function authenticate(string $token): ?LibraryProfile
    {
        if ($token === '') {
            return null;
        }

        return $this->profileRepository->findByWriteToken($token);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function sanitizeString(string $value, int $maxLength): string
    {
        return substr(trim(strip_tags($value)), 0, $maxLength);
    }

    private function probabilisticCleanup(): void
    {
        if (random_int(1, self::CLEANUP_PROBABILITY) === 1) {
            $this->profileRepository->pruneExpiredCatalogs(new \DateTimeImmutable());
        }
    }
}
