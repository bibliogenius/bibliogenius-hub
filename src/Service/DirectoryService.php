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
use App\Repository\RelayMailboxRepository;
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

    /** Alphabet for recovery codes: uppercase alphanumeric, no ambiguous chars (0/O, 1/I/L). */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const RECOVERY_CODE_LENGTH = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LibraryProfileRepository $profileRepository,
        private readonly FollowRepository $followRepository,
        private readonly BorrowRequestRepository $borrowRequestRepository,
        private readonly RelayMailboxRepository $relayMailboxRepository,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%covers_directory%')]
        private readonly string $coversDirectory,
    ) {}

    // -------------------------------------------------------------------------
    // Profile
    // -------------------------------------------------------------------------

    /**
     * Registers a new library profile or updates an existing one.
     *
     * On first registration: generates and returns a write_token + recovery_code.
     * On update: requires a valid write_token via $authenticatedProfile.
     *
     * @return array{profile: LibraryProfile, write_token: string|null, recovery_code: string|null}
     */
    public function upsertProfile(array $data, ?LibraryProfile $authenticatedProfile): array
    {
        $nodeId = $data['node_id'] ?? null;

        $existing = $this->profileRepository->findByNodeId($nodeId);

        if ($existing === null) {
            // Deduplicate by device_fingerprint: if another profile from the
            // same physical device exists (different node_id after UUID
            // regeneration or reinstall), remove it to prevent ghost profiles.
            $fp = $data['device_fingerprint'] ?? null;
            if ($fp !== null && is_string($fp) && preg_match('/^[0-9a-f]{1,128}$/i', $fp)) {
                foreach ($this->profileRepository->findByDeviceFingerprint(strtolower($fp)) as $old) {
                    if ($old->getNodeId() !== $nodeId) {
                        $this->deleteProfile($old);
                    }
                }
            }

            // New registration
            $writeToken = $this->generateToken();
            $recoveryCode = $this->generateRecoveryCode();
            $profile = new LibraryProfile(
                $nodeId,
                $writeToken,
                $this->sanitizeString($data['display_name'] ?? '', 255)
            );
            $profile->setRecoveryCodeHash(hash('sha256', $recoveryCode));
            $this->applyProfileData($profile, $data);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();

            return ['profile' => $profile, 'write_token' => $writeToken, 'recovery_code' => $recoveryCode];
        }

        // Update - caller must be authenticated as this profile
        if ($authenticatedProfile?->getNodeId() !== $existing->getNodeId()) {
            throw new \LogicException('Authenticated profile does not match node_id.');
        }

        $this->applyProfileData($existing, $data);
        $existing->touchLastSeen();
        $this->entityManager->flush();

        return ['profile' => $existing, 'write_token' => null, 'recovery_code' => null];
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
        if (isset($data['x25519_public_key'])) {
            $key = $data['x25519_public_key'];
            // Validate hex-encoded 32-byte key (64 hex chars)
            if (is_string($key) && preg_match('/^[0-9a-f]{64}$/i', $key)) {
                $profile->setX25519PublicKey(strtolower($key));
            }
        }
        if (array_key_exists('website', $data)) {
            $profile->setWebsite(
                $data['website'] !== null
                    ? $this->sanitizeUrl($data['website'], 255)
                    : null
            );
        }
        if (array_key_exists('device_model', $data)) {
            $profile->setDeviceModel(
                $data['device_model'] !== null
                    ? $this->sanitizeString($data['device_model'], 255)
                    : null
            );
        }
        if (array_key_exists('device_fingerprint', $data)) {
            $fp = $data['device_fingerprint'];
            // Validate hex-only string, max 128 chars
            if ($fp !== null && is_string($fp) && preg_match('/^[0-9a-f]{1,128}$/i', $fp)) {
                $profile->setDeviceFingerprint(strtolower($fp));
            } elseif ($fp === null) {
                $profile->setDeviceFingerprint(null);
            }
        }
        // Relay credentials: allow peers to refresh stale relay info
        if (array_key_exists('relay_url', $data)) {
            $profile->setRelayUrl(
                $data['relay_url'] !== null
                    ? $this->sanitizeUrl($data['relay_url'], 255)
                    : null
            );
        }
        if (array_key_exists('relay_mailbox_id', $data)) {
            $mid = $data['relay_mailbox_id'];
            // Validate UUID format (36 chars with dashes)
            if ($mid !== null && is_string($mid) && preg_match('/^[0-9a-f\-]{36}$/i', $mid)) {
                $profile->setRelayMailboxId(strtolower($mid));
            } elseif ($mid === null) {
                $profile->setRelayMailboxId(null);
            }
        }
        if (array_key_exists('relay_write_token', $data)) {
            $wt = $data['relay_write_token'];
            // Validate hex-only string, max 128 chars
            if ($wt !== null && is_string($wt) && preg_match('/^[0-9a-f]{1,128}$/i', $wt)) {
                $profile->setRelayWriteToken(strtolower($wt));
            } elseif ($wt === null) {
                $profile->setRelayWriteToken(null);
            }
        }
        // Avatar config: stored as opaque JSON string (max 2 KB).
        // Hub does not interpret it — just passes it through to peers.
        if (array_key_exists('avatar_config', $data)) {
            $ac = $data['avatar_config'];
            if ($ac === null) {
                $profile->setAvatarConfig(null);
            } elseif (is_array($ac) || is_object($ac)) {
                $json = json_encode($ac);
                if ($json !== false && strlen($json) <= 2048) {
                    $profile->setAvatarConfig($json);
                }
            } elseif (is_string($ac) && strlen($ac) <= 2048) {
                // Validate it's valid JSON before storing
                if (json_decode($ac) !== null) {
                    $profile->setAvatarConfig($ac);
                }
            }
        }
    }

    /**
     * Recovers a profile using a one-time recovery code.
     *
     * On success: regenerates write_token + recovery_code, returns both.
     * On failure: returns null (invalid code or no profile).
     *
     * @return array{profile: LibraryProfile, write_token: string, recovery_code: string}|null
     */
    public function recoverProfile(string $nodeId, string $recoveryCode): ?array
    {
        $profile = $this->profileRepository->findByNodeId($nodeId);
        if ($profile === null) {
            return null;
        }

        if (!$profile->verifyRecoveryCode($recoveryCode)) {
            return null;
        }

        // Recovery succeeded: rotate both write_token and recovery_code.
        $newWriteToken = $profile->resetWriteToken();
        $newRecoveryCode = $this->generateRecoveryCode();
        $profile->setRecoveryCodeHash(hash('sha256', $newRecoveryCode));
        $profile->touchLastSeen();
        $this->entityManager->flush();

        return [
            'profile' => $profile,
            'write_token' => $newWriteToken,
            'recovery_code' => $newRecoveryCode,
        ];
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
    public function pushCatalog(LibraryProfile $profile, string $isbnPayload, ?string $catalogPayload = null, ?int $bookCount = null): CachedCatalog
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

        // Update book_count: prefer the total count sent by the client
        // (includes books without ISBNs), fall back to ISBN count.
        if ($bookCount !== null) {
            $profile->setBookCount($bookCount);
        } else {
            $isbns = json_decode($isbnPayload, true);
            if (is_array($isbns)) {
                $profile->setBookCount(count($isbns));
            }
        }

        $profile->touchLastSeen();
        $this->entityManager->flush();

        return $catalog;
    }

    /**
     * Returns the cached catalog for a library if the requester is allowed to see it.
     *
     * Access rules:
     *   - requires_approval=false OR is_listed=false: accessible to any authenticated user
     *     (non-listed libraries use nodeId knowledge as implicit authorization)
     *   - requires_approval=true AND is_listed=true: requester must have an active follow
     */
    public function getCatalog(LibraryProfile $profile, ?LibraryProfile $requesterProfile): ?CachedCatalog
    {
        // Non-listed libraries: nodeId knowledge is sufficient (not discoverable in directory)
        if (!$profile->isRequiresApproval() || !$profile->isListed()) {
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
    public function resolveFollow(int $followId, string $resolution, LibraryProfile $authenticated, ?string $encryptedContact = null): Follow
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

        // Attach encrypted contact blob when approving (E2EE, opaque to hub)
        if ($resolution === 'approve' && $encryptedContact !== null) {
            $follow->setEncryptedContact($encryptedContact);
        }

        $this->entityManager->flush();

        return $follow;
    }

    /**
     * Completely removes a library profile and all associated data.
     * Deletes: the profile, all follows, borrow requests, relay mailbox+messages, and cached catalogs (cascade).
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

        // Delete orphan borrow requests (no FK constraint on this table)
        $conn->executeStatement(
            'DELETE FROM borrow_requests WHERE requester_node_id = :nid OR lender_node_id = :nid',
            ['nid' => $nodeId]
        );

        // Delete relay mailbox and its messages if the profile had one
        $mailboxId = $profile->getRelayMailboxId();
        if ($mailboxId !== null) {
            $this->relayMailboxRepository->deleteWithMessages($mailboxId);
        }

        // Delete cover thumbnails from filesystem
        $coverDir = $this->coversDirectory . '/' . $nodeId;
        if (is_dir($coverDir)) {
            array_map('unlink', glob($coverDir . '/*.jpg') ?: []);
            @rmdir($coverDir);
        }

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

    /**
     * Batch-updates encrypted contact blobs for active followers.
     *
     * Called when the library owner changes their contact info and needs to
     * re-encrypt for every approved follower.
     *
     * @param array<array{follow_id: int, encrypted_contact: string}> $contacts
     */
    public function syncFollowContacts(LibraryProfile $authenticated, array $contacts): int
    {
        $updated = 0;
        foreach ($contacts as $entry) {
            $followId = (int) ($entry['follow_id'] ?? 0);
            $blob = $entry['encrypted_contact'] ?? null;

            if ($followId <= 0 || !is_string($blob) || strlen($blob) > 8192) {
                continue;
            }

            $follow = $this->followRepository->find($followId);
            if ($follow === null) {
                continue;
            }
            // Only the followed library can update contact on their followers
            if ($follow->getFollowedNodeId() !== $authenticated->getNodeId()) {
                continue;
            }
            if (!$follow->isActive()) {
                continue;
            }

            $follow->setEncryptedContact($blob);
            $updated++;
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }

        return $updated;
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
    public function listDirectory(int $limit, int $offset, ?string $country, ?string $search = null): array
    {
        $limit = min(100, max(1, $limit));

        return $this->profileRepository->findListed($limit, $offset, $country, $search);
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

    /** Generates a human-readable recovery code (e.g. ABCD-EFGH-JKLM). */
    private function generateRecoveryCode(): string
    {
        $alphabet = self::RECOVERY_ALPHABET;
        $len = self::RECOVERY_CODE_LENGTH;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    private function sanitizeString(string $value, int $maxLength): string
    {
        return substr(trim(strip_tags($value)), 0, $maxLength);
    }

    private function sanitizeUrl(string $url, int $maxLength): string
    {
        $url = trim($url);
        // Basic URL sanity: must start with http:// or https://
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return substr($url, 0, $maxLength);
    }

    private function probabilisticCleanup(): void
    {
        if (random_int(1, self::CLEANUP_PROBABILITY) === 1) {
            $this->profileRepository->pruneExpiredCatalogs(new \DateTimeImmutable());
        }
    }
}
