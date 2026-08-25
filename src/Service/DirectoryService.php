<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BorrowRequest;
use App\Entity\CachedCatalog;
use App\Entity\Follow;
use App\Entity\LibraryProfile;
use App\Exception\MailboxOwnershipConflictException;
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

    /**
     * BCrypt cost factor for recovery_code_hash (KAN-286).
     * Recovery codes carry ~2^59 entropy so 2^12 ≈ 4096 iterations already
     * blow well past the GPU brute-force budget while staying under ~250ms
     * per hash on production hardware (registration + recovery only).
     */
    private const RECOVERY_HASH_BCRYPT_COST = 12;

    /**
     * Safety guard for orphan-cover GC (ADR-033): skip if removing the
     * orphans would wipe >= this fraction of files on disk for the node.
     * Protects against mass-deletion if a client pushes a corrupted catalog.
     */
    private const COVER_GC_DEFAULT_MAX_DELETE_RATIO = 0.5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LibraryProfileRepository $profileRepository,
        private readonly FollowRepository $followRepository,
        private readonly BorrowRequestRepository $borrowRequestRepository,
        private readonly RelayMailboxRepository $relayMailboxRepository,
        private readonly HubEventLogger $eventLogger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%covers_directory%')]
        private readonly string $coversDirectory,
        private readonly bool $mailboxOwnershipEnforced = false,
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
            $profile->setRecoveryCodeHash($this->hashRecoveryCode($recoveryCode));
            $this->applyProfileData($profile, $data, callerNodeId: $nodeId);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();

            return ['profile' => $profile, 'write_token' => $writeToken, 'recovery_code' => $recoveryCode];
        }

        // Update - caller must be authenticated as this profile
        if ($authenticatedProfile?->getNodeId() !== $existing->getNodeId()) {
            throw new \LogicException('Authenticated profile does not match node_id.');
        }

        $this->applyProfileData($existing, $data, callerNodeId: $existing->getNodeId());
        $existing->touchLastSeen();

        // An authenticated profile heartbeat proves the library is alive, so
        // extend the TTL of its cached catalog in the same flush. Clients
        // predating the keep-alive fix skip the unchanged-catalog push
        // (ADR-027 fast path), leaving this heartbeat as the hub's only
        // liveness signal: without the touch their catalogs expire while the
        // profile stays listed. Touch only an existing row: creation belongs
        // to the catalog push path, and public lookups must never bump TTLs.
        $catalog = $this->entityManager->find(CachedCatalog::class, $existing->getNodeId());
        if ($catalog !== null) {
            $catalog->touchTtl();
        }

        $this->entityManager->flush();

        return ['profile' => $existing, 'write_token' => null, 'recovery_code' => null];
    }

    private function applyProfileData(LibraryProfile $profile, array $data, string $callerNodeId): void
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
        if (array_key_exists('location_city_id', $data)) {
            $cityId = $data['location_city_id'];
            $profile->setLocationCityId(
                $cityId !== null && (int) $cityId > 0 ? (int) $cityId : null
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
        if (array_key_exists('app_version', $data)) {
            $v = $data['app_version'];
            if ($v === null) {
                $profile->setAppVersion(null);
            } elseif (is_string($v)) {
                // Truncate then validate semver-ish charset. Silent reject on
                // malformed input so an injection attempt does not overwrite
                // a previously valid stored value.
                $trimmed = substr(trim(strip_tags($v)), 0, 32);
                if ($trimmed !== '' && preg_match('/^[A-Za-z0-9._+\-]{1,32}$/', $trimmed)) {
                    $profile->setAppVersion($trimmed);
                }
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
                $normalized = strtolower($mid);
                // ADR-031: claim-on-first-reference ownership check. Runs
                // only when the mailbox id passed format validation and is
                // a non-null value. Explicit null (clear-own-mailbox) and
                // malformed values do not trigger it, since they do not
                // touch relay_mailboxes rows owned by anyone.
                $this->checkAndClaimMailboxOwnership($normalized, $callerNodeId);
                $profile->setRelayMailboxId($normalized);
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
        // Hub does not interpret it - just passes it through to peers.
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
     * Claim-on-first-reference ownership check on relay_mailboxes. See ADR-031.
     *
     * Runs inside a dedicated short transaction with SELECT ... FOR UPDATE
     * to serialize concurrent claimants on the same fresh mailbox.
     *
     * Three branches:
     *   - row absent: trust-on-first-use, no claim recorded here (the
     *     relay service will assign ownership at mailbox creation time,
     *     out of scope for this ADR).
     *   - owner_node_id NULL: claim it for the caller.
     *   - owner_node_id == caller: legitimate owner refreshing creds, no-op.
     *   - owner_node_id != caller: log + bump counter. In enforced mode,
     *     throws MailboxOwnershipConflictException (caller-facing 403).
     */
    private function checkAndClaimMailboxOwnership(string $mailboxId, string $callerNodeId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $row = $conn->fetchAssociative(
                'SELECT owner_node_id FROM relay_mailboxes WHERE uuid = :uuid FOR UPDATE',
                ['uuid' => $mailboxId],
            );

            if ($row === false) {
                $conn->commit();
                return;
            }

            $owner = $row['owner_node_id'] ?? null;

            if ($owner === null) {
                $conn->update(
                    'relay_mailboxes',
                    ['owner_node_id' => $callerNodeId],
                    ['uuid' => $mailboxId],
                );
                $conn->commit();
                $this->eventLogger->info('directory', 'mailbox claimed on first reference', [
                    'mailbox' => $mailboxId,
                    'node_id' => substr($callerNodeId, 0, 12),
                ]);
                return;
            }

            if ($owner === $callerNodeId) {
                $conn->commit();
                return;
            }

            // Hijack attempt. Commit the read-only tx before logging so the
            // row lock is released; we do not record any mutation.
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        // Outside the TX: log and bump the cumulative counter on the caller.
        // Both paths are best-effort; their failure must not mask the hijack.
        $mode = $this->mailboxOwnershipEnforced ? 'enforced' : 'shadow';
        $this->eventLogger->warning('directory', 'hijack_attempt', [
            'mailbox' => $mailboxId,
            'node_id' => substr($callerNodeId, 0, 12),
            'reason'  => $mode,
        ]);

        try {
            $conn->executeStatement(
                'UPDATE library_profiles SET hijack_attempts_total = hijack_attempts_total + 1 WHERE node_id = :node_id',
                ['node_id' => $callerNodeId],
            );
        } catch (\Throwable) {
            // Best-effort counter bump. The authoritative audit trail is the
            // hub_events warning above; the counter is a dashboard convenience.
        }

        if ($this->mailboxOwnershipEnforced) {
            // Opaque message: do not reveal which field failed, whether the
            // mailbox exists, or who owns it. Controller maps this to 403.
            throw new MailboxOwnershipConflictException('Credentials conflict.');
        }
        // Shadow mode: fall through, upsert proceeds.
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
        // The new BCrypt hash written below subsumes the legacy-SHA-256
        // upgrade flagged by recoveryCodeNeedsRehash() (KAN-286), so no
        // separate migration step is needed on this code path.
        $newWriteToken = $profile->resetWriteToken();
        $newRecoveryCode = $this->generateRecoveryCode();
        $profile->setRecoveryCodeHash($this->hashRecoveryCode($newRecoveryCode));
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
     * If $catalogHash is provided and matches the hash of the currently
     * stored catalog, the payload is not rewritten; only the TTL and
     * `last_seen_at` are bumped. The returned result carries
     * `unchanged=true`, which the controller translates into a
     * 304 Not Modified response (ADR-027).
     *
     * @param string      $isbnPayload    JSON array of ISBNs (legacy format)
     * @param string|null $catalogPayload JSON array of {isbn, title, author} objects (enriched format)
     * @param string|null $catalogHash    SHA-256 hex digest (64 chars) of the client-canonical payload
     */
    public function pushCatalog(
        LibraryProfile $profile,
        string $isbnPayload,
        ?string $catalogPayload = null,
        ?int $bookCount = null,
        ?string $catalogHash = null,
    ): PushCatalogResult {
        $this->probabilisticCleanup();

        // Validate catalog_payload size if provided (max 2 MB for enriched data)
        if ($catalogPayload !== null && strlen($catalogPayload) > 2097152) {
            throw new \InvalidArgumentException('catalog_payload exceeds maximum allowed size.');
        }

        // catalog_hash is always a lowercase 64-hex digest when present; reject
        // anything else so we never persist an attacker-controlled value.
        if ($catalogHash !== null && !preg_match('/^[0-9a-f]{64}$/', $catalogHash)) {
            throw new \InvalidArgumentException('catalog_hash must be a 64-char lowercase hex digest.');
        }

        $catalog = $this->entityManager->find(CachedCatalog::class, $profile->getNodeId());

        // Fast path: same hash as what we already have → no payload rewrite.
        if (
            $catalog !== null
            && $catalogHash !== null
            && $catalog->getCatalogHash() === $catalogHash
        ) {
            $catalog->touchTtl();
            $profile->touchLastSeen();
            // book_count may still have drifted, update it without rewriting the payload.
            $this->applyBookCount($profile, $isbnPayload, $bookCount);
            $this->entityManager->flush();
            return new PushCatalogResult($catalog, unchanged: true);
        }

        if ($catalog === null) {
            $catalog = new CachedCatalog($profile, $isbnPayload, $catalogPayload, $catalogHash);
            $this->entityManager->persist($catalog);
        } else {
            $catalog->refresh($isbnPayload, $catalogPayload, $catalogHash);
        }

        $this->applyBookCount($profile, $isbnPayload, $bookCount);
        $profile->touchLastSeen();
        $this->entityManager->flush();

        // Catalog-driven orphan cover GC (ADR-033). Runs only on the rewrite
        // path (not the hash-unchanged fast path) and only when the client
        // sent enriched entries: legacy catalogs without book_id cannot
        // drive safe GC. Best-effort: any failure is logged, not raised.
        if ($catalogPayload !== null) {
            try {
                $this->pruneOrphanCoversForNode($profile->getNodeId(), $catalogPayload, 'push');
            } catch (\Throwable $e) {
                $this->logCoverGcEvent('error', 'error', $profile->getNodeId(), [
                    'trigger' => 'push',
                    'error' => substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return new PushCatalogResult($catalog, unchanged: false);
    }

    /**
     * Scans every cached catalog with enriched data and prunes orphan covers
     * (ADR-033 Option 3). Called from the nightly PruneCommand as a safety
     * net that catches silent nodes that rarely push catalogs.
     *
     * Returns the total number of cover files deleted across all nodes.
     * Per-node failures are logged and swallowed so one bad node cannot
     * abort the cron.
     */
    public function pruneOrphanCoversForAllNodes(): int
    {
        $total = 0;
        $rows = $this->entityManager->getConnection()->iterateAssociative(
            'SELECT node_id, catalog_payload FROM cached_catalogs WHERE catalog_payload IS NOT NULL',
        );
        foreach ($rows as $row) {
            $nodeId = (string) ($row['node_id'] ?? '');
            $payload = (string) ($row['catalog_payload'] ?? '');
            if ($nodeId === '' || $payload === '') {
                continue;
            }
            try {
                $total += $this->pruneOrphanCoversForNode($nodeId, $payload, 'cron');
            } catch (\Throwable $e) {
                $this->logCoverGcEvent('error', 'error', $nodeId, [
                    'trigger' => 'cron',
                    'error' => substr($e->getMessage(), 0, 200),
                ]);
            }
        }
        return $total;
    }

    /**
     * Compares the catalog's book_ids against files on disk under
     * covers/{nodeId}/ and deletes the orphans. Skips if:
     *   - the catalog decodes to an empty book_id set (suspicious)
     *   - the delete would exceed COVER_GC_MAX_DELETE_RATIO of disk files
     *     (threshold guard, ADR-033)
     * Returns the number of files deleted (0 when skipped or when nothing
     * to delete).
     */
    private function pruneOrphanCoversForNode(string $nodeId, string $catalogPayload, string $trigger): int
    {
        $dir = $this->coversDirectory . '/' . $nodeId;
        if (!is_dir($dir)) {
            return 0;
        }

        $entries = json_decode($catalogPayload, true);
        if (!is_array($entries)) {
            return 0;
        }

        $keep = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // Current clients key covers by the owner's book uuid, sent as
            // `book_uuid`; catalogs from pre-uuid clients carried an integer
            // `book_id`. Both generations coexist in cached_catalogs, and
            // covers on disk are named by whichever id uploaded them.
            $bookId = $entry['book_uuid'] ?? $entry['book_id'] ?? null;
            if (is_int($bookId) && $bookId > 0) {
                $keep[(string) $bookId] = true;
            } elseif (is_string($bookId) && self::isUuidBookId($bookId)) {
                $keep[strtolower($bookId)] = true;
            }
        }

        $files = glob($dir . '/*.jpg') ?: [];
        $diskCount = count($files);
        if ($diskCount === 0) {
            return 0;
        }

        if ($keep === []) {
            $this->logCoverGcEvent('warning', 'skipped_empty_catalog', $nodeId, [
                'trigger' => $trigger,
                'disk_count' => $diskCount,
            ]);
            return 0;
        }

        $orphans = [];
        foreach ($files as $file) {
            $basename = basename($file, '.jpg');
            if (!ctype_digit($basename) && !self::isUuidBookId($basename)) {
                continue; // neither an integer nor a uuid cover name, not ours
            }
            if (!isset($keep[strtolower($basename)])) {
                $orphans[] = $file;
            }
        }

        $orphanCount = count($orphans);
        if ($orphanCount === 0) {
            return 0;
        }

        if ($orphanCount / $diskCount >= $this->coverGcMaxDeleteRatio()) {
            $this->logCoverGcEvent('warning', 'skipped_threshold', $nodeId, [
                'trigger' => $trigger,
                'orphan_count' => $orphanCount,
                'disk_count' => $diskCount,
            ]);
            return 0;
        }

        $deleted = 0;
        foreach ($orphans as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->logCoverGcEvent('info', 'deleted', $nodeId, [
                'trigger' => $trigger,
                'deleted_count' => $deleted,
                'disk_count' => $diskCount,
            ]);
        }
        return $deleted;
    }

    /**
     * True for a book id the cover routes may join onto the covers directory:
     * the integer row ids pre-uuid clients uploaded under, and the uuids
     * current clients key covers by. Both shapes are traversal-safe by
     * construction, which is what makes them usable as a path component.
     *
     * The two generations coexist on disk and in cached_catalogs, so both must
     * be accepted for as long as pre-uuid uploads survive the GC.
     */
    public static function isCoverBookId(string $value): bool
    {
        return ctype_digit($value) || self::isUuidBookId($value);
    }

    /**
     * True for a canonical 36-char hyphenated uuid (the shape clients use to
     * key cover files since the uuid primary-key migration). Deliberately
     * strict so the GC never claims arbitrary filenames as its own.
     */
    public static function isUuidBookId(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }

    /**
     * Reads the runtime threshold from the COVER_GC_MAX_DELETE_RATIO env var
     * with safe fallbacks: any malformed or out-of-range value falls back
     * to the default. Inclusive of 1.0 (would disable GC) and 0.0 (no guard).
     */
    private function coverGcMaxDeleteRatio(): float
    {
        $raw = getenv('COVER_GC_MAX_DELETE_RATIO');
        if (!is_string($raw) || $raw === '' || !is_numeric($raw)) {
            return self::COVER_GC_DEFAULT_MAX_DELETE_RATIO;
        }
        $value = (float) $raw;
        if ($value < 0.0 || $value > 1.0) {
            return self::COVER_GC_DEFAULT_MAX_DELETE_RATIO;
        }
        return $value;
    }

    /**
     * Direct INSERT to hub_events for cover GC observability. Bypasses the
     * HubEventLogger allowlist so we can keep structured fields like
     * deleted_count / disk_count / trigger without renaming them to the
     * generic "count" / "size" / "reason" slots.
     */
    private function logCoverGcEvent(string $level, string $message, string $nodeId, array $context): void
    {
        try {
            $context['node_id'] = substr($nodeId, 0, 12);
            $this->entityManager->getConnection()->insert('hub_events', [
                'level' => $level,
                'channel' => 'catalog_gc',
                'message' => $message,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // Best-effort: observability must never break the caller.
        }
    }

    /**
     * DEBUG instrumentation: append a row to hub_events so catalog-push
     * activity is observable in DB backups. Needed because Symfony's
     * fingers_crossed handler only emits 5xx requests and Caddy access
     * logging is disabled, so successful / 401 catalog pushes are otherwise
     * invisible. Best-effort: never throws into the caller.
     */
    public function logCatalogEvent(string $channel, string $level, string $message, array $context): void
    {
        try {
            $this->entityManager->getConnection()->insert('hub_events', [
                'level' => $level,
                'channel' => $channel,
                'message' => $message,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // Observability must never break the caller.
        }
    }

    private function applyBookCount(LibraryProfile $profile, string $isbnPayload, ?int $bookCount): void
    {
        // Prefer the total count sent by the client (includes books without
        // ISBNs); fall back to the length of the legacy ISBN array.
        if ($bookCount !== null) {
            $profile->setBookCount($bookCount);
            return;
        }
        $isbns = json_decode($isbnPayload, true);
        if (is_array($isbns)) {
            $profile->setBookCount(count($isbns));
        }
    }

    /**
     * Whether the requester is allowed to read this library's catalog.
     *
     * Access rules:
     *   - requires_approval=false OR is_listed=false: accessible to any requester
     *     (non-listed libraries use nodeId knowledge as implicit authorization)
     *   - requires_approval=true AND is_listed=true: requester must have an active follow
     */
    public function canReadCatalog(LibraryProfile $profile, ?LibraryProfile $requesterProfile): bool
    {
        // Non-listed libraries: nodeId knowledge is sufficient (not discoverable in directory)
        if (!$profile->isRequiresApproval() || !$profile->isListed()) {
            return true;
        }

        if ($requesterProfile === null) {
            return false;
        }

        return $this->followRepository->isActiveFollower($requesterProfile->getNodeId(), $profile->getNodeId());
    }

    /**
     * Returns the cached catalog for a library if the requester is allowed to see it.
     *
     * Returns null both when access is denied and when no catalog is stored
     * (expired or never pushed); callers needing to distinguish the two use
     * [canReadCatalog] first.
     */
    public function getCatalog(LibraryProfile $profile, ?LibraryProfile $requesterProfile): ?CachedCatalog
    {
        if (!$this->canReadCatalog($profile, $requesterProfile)) {
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

        // Already blocked: silent rejection (privacy-preserving, no leak)
        if ($existing?->getStatus() === Follow::STATUS_BLOCKED) {
            return null;
        }

        // Already active or pending: idempotent, return as-is
        if ($existing !== null && in_array($existing->getStatus(), [Follow::STATUS_ACTIVE, Follow::STATUS_PENDING], true)) {
            return $existing;
        }

        // Previously rejected: re-open as pending so the owner gets a fresh
        // notification instead of seeing the silently-unchanged record. This
        // matches the UX the requester expects when they tap "Follow" again.
        if ($existing !== null && $existing->getStatus() === Follow::STATUS_REJECTED) {
            $existing->resetPending();
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
    public function listDirectory(
        int $limit,
        int $offset,
        ?string $country,
        ?string $search = null,
        ?int $cityId = null,
    ): array {
        $limit = min(100, max(1, $limit));

        return $this->profileRepository->findListed($limit, $offset, $country, $search, $cityId);
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

    private function hashRecoveryCode(string $code): string
    {
        return password_hash($code, PASSWORD_BCRYPT, ['cost' => self::RECOVERY_HASH_BCRYPT_COST]);
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
