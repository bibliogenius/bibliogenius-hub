<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BorrowRequest;
use App\Entity\Follow;
use App\Entity\LibraryProfile;
use App\Repository\BorrowRequestRepository;
use App\Repository\Deposit404LogRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Repository\RelayMessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Collects everything the hub knows about one library into a single
 * analysis bundle, for the admin backoffice.
 *
 * Why this exists: diagnosing one library used to mean downloading a full
 * pg_dump, which carries every profile's credentials and every account's
 * ciphertext onto a laptop. This narrows that to one node, read-only, with
 * credentials reduced to a present/absent marker.
 *
 * Scope is the P2P lane only (node_id). The account-sync lane is a blind
 * store by design (ADR-043) and is deliberately absent: adding it would
 * change what a hub admin can observe of an encrypted account, which is a
 * decision for an ADR rather than a helper.
 *
 * Secrets never appear in the output. Relay credentials in particular are
 * bound by the hub security rules: the write token is disclosed only to
 * authenticated requesters (S1) and the read token must never leave the
 * device that owns the mailbox (S2).
 */
final class LibraryIdentityExporter
{
    /** Cap on every free-form scan, so one noisy node cannot pull an unbounded set. */
    public const MAX_ROWS = 200;

    /** Cap on the per-entry catalog samples embedded in the bundle. */
    public const MAX_SAMPLES = 20;

    /** Mirrors the node id contract enforced on the directory endpoints. */
    private const MAX_NODE_ID_LENGTH = 128;

    /** Catalog entry fields whose absence degrades a book on a peer screen. */
    private const REQUIRED_ENTRY_FIELDS = ['title', 'author', 'cover_url'];

    /**
     * The subset that makes a book unidentifiable rather than merely plain: a
     * cover-less book still reads, a title-less one renders as a blank tile.
     * Samples are ordered on this so a library with many cover-less books
     * cannot push the real defects out of a capped list.
     */
    private const CRITICAL_ENTRY_FIELDS = ['title', 'author'];

    public function __construct(
        private readonly LibraryProfileRepository $profiles,
        private readonly FollowRepository $follows,
        private readonly BorrowRequestRepository $borrowRequests,
        private readonly RelayMailboxRepository $mailboxes,
        private readonly RelayMessageRepository $relayMessages,
        private readonly Deposit404LogRepository $deposit404Log,
        private readonly Connection $connection,
    ) {}

    /**
     * @return array<string, mixed>|null null when the node id is malformed or unknown
     */
    public function export(string $nodeId): ?array
    {
        if ($nodeId === '' || strlen($nodeId) > self::MAX_NODE_ID_LENGTH) {
            return null;
        }

        $profile = $this->profiles->findByNodeId($nodeId);
        if ($profile === null) {
            return null;
        }

        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'node_id' => $nodeId,
            'profile' => $this->profileSection($profile),
            'catalog' => $this->catalogSection($nodeId),
            'follows' => $this->followSection($nodeId),
            'borrow_requests' => $this->borrowSection($nodeId),
            'relay' => $this->relaySection($profile),
            'views' => $this->viewSection($nodeId),
            'registration_failures' => $this->registrationFailureSection($nodeId),
            'hub_events' => $this->hubEventSection($nodeId),
        ];
    }

    /** @return array<string, mixed> */
    private function profileSection(LibraryProfile $profile): array
    {
        return [
            'display_name' => $profile->getDisplayName(),
            'description' => $profile->getDescription(),
            'book_count' => $profile->getBookCount(),
            'location_country' => $profile->getLocationCountry(),
            'location_city_id' => $profile->getLocationCityId(),
            'is_listed' => $profile->isListed(),
            'requires_approval' => $profile->isRequiresApproval(),
            'accept_from' => $profile->getAcceptFrom(),
            'allow_borrowing' => $profile->isAllowBorrowing(),
            'view_count' => $profile->getViewCount(),
            'hijack_attempts_total' => $profile->getHijackAttemptsTotal(),
            'website' => $profile->getWebsite(),
            'device_model' => $profile->getDeviceModel(),
            'device_fingerprint' => $profile->getDeviceFingerprint(),
            'app_version' => $profile->getAppVersion(),
            'avatar_config' => $profile->getAvatarConfig(),
            'created_at' => $profile->getCreatedAt()->format(\DATE_ATOM),
            'last_seen_at' => $profile->getLastSeenAt()?->format(\DATE_ATOM),
            'write_token' => self::secret($profile->getWriteToken()),
            'recovery_code_hash' => self::secret($profile->getRecoveryCodeHash()),
            'x25519_public_key' => self::secret($profile->getX25519PublicKey()),
        ];
    }

    /**
     * Catalog health, which is where "the peer shows a book it cannot render"
     * becomes visible: an entry carrying an ISBN but no title renders as a
     * blank tile, and an ISBN present in the legacy payload but absent from
     * the enriched one renders as nothing at all.
     *
     * @return array<string, mixed>|null null when the library never pushed a catalog
     */
    private function catalogSection(string $nodeId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT isbn_payload, catalog_payload, catalog_hash, updated_at, expires_at
             FROM cached_catalogs WHERE node_id = ?',
            [$nodeId],
        );

        if ($row === false) {
            return null;
        }

        $isbns = self::decodeList($row['isbn_payload'] ?? null);
        $entries = self::decodeList($row['catalog_payload'] ?? null);

        $withoutTitle = 0;
        $withoutAuthor = 0;
        $withoutCover = 0;
        $criticalSamples = [];
        $minorSamples = [];
        $catalogIsbns = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $isbn = is_string($entry['isbn'] ?? null) ? $entry['isbn'] : null;
            if ($isbn !== null && $isbn !== '') {
                $catalogIsbns[$isbn] = true;
            }

            $missing = [];
            foreach (self::REQUIRED_ENTRY_FIELDS as $field) {
                $value = $entry[$field] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    $missing[] = $field;
                }
            }

            $withoutTitle += in_array('title', $missing, true) ? 1 : 0;
            $withoutAuthor += in_array('author', $missing, true) ? 1 : 0;
            $withoutCover += in_array('cover_url', $missing, true) ? 1 : 0;

            if ($missing === []) {
                continue;
            }

            $sample = ['isbn' => $isbn, 'missing' => $missing];
            $isCritical = array_intersect(self::CRITICAL_ENTRY_FIELDS, $missing) !== [];

            if ($isCritical && count($criticalSamples) < self::MAX_SAMPLES) {
                $criticalSamples[] = $sample;
            } elseif (!$isCritical && count($minorSamples) < self::MAX_SAMPLES) {
                $minorSamples[] = $sample;
            }
        }

        $samples = array_slice([...$criticalSamples, ...$minorSamples], 0, self::MAX_SAMPLES);

        $orphanIsbns = [];
        foreach ($isbns as $isbn) {
            if (is_string($isbn) && !isset($catalogIsbns[$isbn])) {
                $orphanIsbns[] = $isbn;
            }
        }

        return [
            'hash' => $row['catalog_hash'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'isbn_payload_bytes' => strlen((string) ($row['isbn_payload'] ?? '')),
            'catalog_payload_bytes' => strlen((string) ($row['catalog_payload'] ?? '')),
            'isbn_count' => count($isbns),
            'entry_count' => count($entries),
            'entries_without_title' => $withoutTitle,
            'entries_without_author' => $withoutAuthor,
            'entries_without_cover' => $withoutCover,
            'isbns_missing_from_catalog' => count($orphanIsbns),
            'isbns_missing_from_catalog_samples' => array_slice($orphanIsbns, 0, self::MAX_SAMPLES),
            'incomplete_samples' => $samples,
        ];
    }

    /** @return array<string, mixed> */
    private function followSection(string $nodeId): array
    {
        $outgoing = [];
        $incoming = [];

        foreach ($this->follows->findAllInvolving($nodeId) as $follow) {
            $row = [
                'id' => $follow->getId(),
                'status' => $follow->getStatus(),
                'created_at' => $follow->getCreatedAt()->format(\DATE_ATOM),
                'resolved_at' => $follow->getResolvedAt()?->format(\DATE_ATOM),
                'encrypted_contact' => self::secret($follow->getEncryptedContact()),
            ];

            if ($follow->getFollowerNodeId() === $nodeId) {
                $outgoing[] = $row + ['followed_node_id' => $follow->getFollowedNodeId()];
            } else {
                $incoming[] = $row + ['follower_node_id' => $follow->getFollowerNodeId()];
            }
        }

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
    }

    /** @return array<string, mixed> */
    private function borrowSection(string $nodeId): array
    {
        $asRequester = [];
        $asLender = [];

        foreach ($this->borrowRequests->findAllInvolving($nodeId) as $request) {
            $row = [
                'id' => $request->getId(),
                'isbn' => $request->getIsbn(),
                'book_title' => $request->getBookTitle(),
                'status' => $request->getStatus(),
                'created_at' => $request->getCreatedAt()->format(\DATE_ATOM),
                'resolved_at' => $request->getResolvedAt()?->format(\DATE_ATOM),
                'expires_at' => $request->getExpiresAt()->format(\DATE_ATOM),
            ];

            if ($request->getRequesterNodeId() === $nodeId) {
                $asRequester[] = $row + ['lender_node_id' => $request->getLenderNodeId()];
            } else {
                $asLender[] = $row + ['requester_node_id' => $request->getRequesterNodeId()];
            }
        }

        return ['as_requester' => $asRequester, 'as_lender' => $asLender];
    }

    /** @return array<string, mixed> */
    private function relaySection(LibraryProfile $profile): array
    {
        $mailbox = $this->mailboxes->findByOwnerNodeId($profile->getNodeId());

        $section = [
            'profile_relay_url' => $profile->getRelayUrl(),
            'profile_mailbox_id' => $profile->getRelayMailboxId(),
            'profile_relay_write_token' => self::secret($profile->getRelayWriteToken()),
            'mailbox' => null,
            // True when the profile advertises a mailbox that has no row, the
            // shape behind the orphan-mailbox alerts.
            'profile_points_at_missing_mailbox' => $profile->getRelayMailboxId() !== null && $mailbox === null,
        ];

        if ($mailbox === null) {
            return $section;
        }

        $section['mailbox'] = [
            'uuid' => $mailbox->getUuid(),
            'created_at' => $mailbox->getCreatedAt()->format(\DATE_ATOM),
            'last_accessed' => $mailbox->getLastAccessed()?->format(\DATE_ATOM),
            'matches_profile' => $mailbox->getUuid() === $profile->getRelayMailboxId(),
            'message_count' => $this->relayMessages->countByMailbox($mailbox->getUuid()),
            'deposit_404_hits' => $this->deposit404Log->countByMailbox($mailbox->getUuid()),
        ];
        $section['read_token'] = self::secret($mailbox->getReadToken());
        $section['write_token'] = self::secret($mailbox->getWriteToken());

        return $section;
    }

    /**
     * Who looked this profile up, and whom it looked up. This is the only
     * lookup trace the hub keeps: successful profile reads write nothing to
     * hub_events and there are no HTTP access logs.
     *
     * @return array<string, mixed>
     */
    private function viewSection(string $nodeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT profile_node_id, visitor_id, last_counted_at
             FROM library_view_cooldowns
             WHERE profile_node_id = ? OR visitor_id = ?
             ORDER BY last_counted_at DESC
             LIMIT ?',
            [$nodeId, $nodeId, self::MAX_ROWS],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );

        $received = [];
        $made = [];
        foreach ($rows as $row) {
            if (($row['profile_node_id'] ?? null) === $nodeId) {
                $received[] = ['visitor_id' => $row['visitor_id'], 'at' => $row['last_counted_at']];
            } else {
                $made[] = ['profile_node_id' => $row['profile_node_id'], 'at' => $row['last_counted_at']];
            }
        }

        return [
            'received' => $received,
            'made' => $made,
            'truncated' => count($rows) >= self::MAX_ROWS,
        ];
    }

    /** @return array<string, mixed> */
    private function registrationFailureSection(string $nodeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_name, book_count, client_ip, app_version, created_at
             FROM registration_failures
             WHERE node_id = ?
             ORDER BY created_at DESC
             LIMIT ?',
            [$nodeId, self::MAX_ROWS],
            [ParameterType::STRING, ParameterType::INTEGER],
        );

        return ['rows' => $rows, 'truncated' => count($rows) >= self::MAX_ROWS];
    }

    /**
     * hub_events has no node_id column, so this is a substring match over the
     * message and context. Treat it as a lead, not as proof: the directory
     * channel only records failed profile lookups.
     *
     * @return array<string, mixed>
     */
    private function hubEventSection(string $nodeId): array
    {
        $needle = '%' . addcslashes($nodeId, '\\%_') . '%';

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, level, channel, message, context, created_at
             FROM hub_events
             WHERE message LIKE ? OR context LIKE ?
             ORDER BY created_at DESC
             LIMIT ?',
            [$needle, $needle, self::MAX_ROWS],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );

        return [
            'matched' => $rows,
            'truncated' => count($rows) >= self::MAX_ROWS,
            'note' => 'substring match; the directory channel logs failed profile lookups only',
        ];
    }

    /**
     * Reports that a credential is set without disclosing it.
     *
     * @return array<string, mixed>
     */
    private static function secret(?string $value): array
    {
        return $value === null || $value === ''
            ? ['present' => false]
            : ['present' => true, 'length' => strlen($value)];
    }

    /** @return list<mixed> */
    private static function decodeList(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
