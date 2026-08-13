<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Directory-health monitoring queries for the admin dashboard.
 *
 * Watches the invariants behind the cached-catalog keep-alive chain
 * (client re-push on stale confirmed-push timestamp + hub-side TTL touch
 * on authenticated profile heartbeat, see ADR-027):
 *
 *  - catalog coverage: an alive library with books must always have a
 *    non-expired cached_catalogs row; a gap means its client never
 *    refreshes the TTL (pre-fix build) or the touch path regressed;
 *  - placeholder leaks: locally fabricated `peer_<row id>` node ids must
 *    never reach the hub profile lookup (the client guards them);
 *  - ghost lookups: unknown node ids repeatedly queried against the
 *    directory point at stale references circulating between peers.
 *
 * Performance contract: every hub_events query is bounded by created_at
 * (idx_hub_events_created) and the table is capped at 1000 rows by
 * HubEventLogger, so all aggregates stay trivial at dashboard-render time.
 * Follows the plain-Connection pattern of Deposit404LogRepository.
 */
class DirectoryHealthRepository
{
    /**
     * LIKE pattern matching a JSON context whose node_id is a client-side
     * placeholder. The backslash escapes the underscore (PostgreSQL's
     * default LIKE escape character), so `peer_X` matches but `peersomething`
     * does not.
     */
    private const PLACEHOLDER_CONTEXT_PATTERN = '%"node_id":"peer\_%';

    private const LOOKUP_NOT_FOUND_MESSAGE = 'profile lookup: not found';

    /**
     * A profile is "alive" when it was seen within this window. It is the
     * criterion that separates a legitimate migration from a real duplicate:
     * restoring a backup on a second device mints a new node id by design
     * (two devices sharing one would fight over the same relay mailbox and
     * profile), so the old node lingering in the directory is normal. Only
     * when BOTH keep checking in is the same library live twice.
     */
    private const ALIVE_DAYS = 7;

    private const DUPLICATE_ALERT_MESSAGE = 'duplicate_library_detected';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Counts alive libraries (seen within 7 days, book_count > 0) that have
     * no valid cached catalog: the row is either absent or already expired.
     * Expected to converge to 0 as the fleet picks up the keep-alive fix.
     */
    public function countCatalogCoverageGaps(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM library_profiles p
             LEFT JOIN cached_catalogs c
                    ON c.node_id = p.node_id AND c.expires_at >= ?
             WHERE p.book_count > 0
               AND p.last_seen_at >= ?
               AND c.node_id IS NULL',
            [
                $now->format('Y-m-d H:i:s'),
                $now->modify('-7 days')->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Drill-down for countCatalogCoverageGaps: the biggest uncovered
     * libraries first. app_version identifies clients still running a
     * build that predates the keep-alive fix.
     *
     * @return array<array<string, mixed>>
     */
    public function findCatalogCoverageGaps(\DateTimeImmutable $now, int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT p.node_id, p.display_name, p.book_count, p.last_seen_at, p.app_version
                 FROM library_profiles p
                 LEFT JOIN cached_catalogs c
                        ON c.node_id = p.node_id AND c.expires_at >= ?
                 WHERE p.book_count > 0
                   AND p.last_seen_at >= ?
                   AND c.node_id IS NULL
                 ORDER BY p.book_count DESC, p.last_seen_at DESC
                 LIMIT %d',
                max(1, $limit),
            ),
            [
                $now->format('Y-m-d H:i:s'),
                $now->modify('-7 days')->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Counts directory lookups that hit the hub with a locally fabricated
     * `peer_<row id>` placeholder node_id since $since. The client guards
     * these before any network call, so the expected value is 0; anything
     * above means a build without the guard is still in the wild.
     */
    public function countPlaceholderLookups(\DateTimeImmutable $since): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM hub_events
             WHERE message = ?
               AND created_at >= ?
               AND context LIKE ?',
            [
                self::LOOKUP_NOT_FOUND_MESSAGE,
                $since->format('Y-m-d H:i:s'),
                self::PLACEHOLDER_CONTEXT_PATTERN,
            ],
        );
    }

    /**
     * Node ids unknown to library_profiles that were looked up more than
     * once since $since, most-queried first. Placeholders are excluded
     * (they have their own counter) so this surfaces real ghosts: stale
     * follows or invite links pointing at deleted/regenerated profiles.
     *
     * Note: HubEventLogger truncates context node_ids to 100 chars; real
     * node ids (UUIDs) are far shorter, so the JSON extraction is safe.
     *
     * @return array<array{node_id: string, lookup_count: int}>
     */
    public function findGhostLookups(\DateTimeImmutable $since, int $limit = 5): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT g.node_id, g.lookup_count
                 FROM (
                     SELECT (context::jsonb)->>'node_id' AS node_id,
                            COUNT(*) AS lookup_count
                     FROM hub_events
                     WHERE message = ?
                       AND created_at >= ?
                       AND context IS NOT NULL
                     GROUP BY 1
                     HAVING COUNT(*) >= 2
                 ) g
                 WHERE g.node_id IS NOT NULL
                   AND g.node_id NOT LIKE 'peer\_%%'
                   AND NOT EXISTS (
                       SELECT 1 FROM library_profiles p WHERE p.node_id = g.node_id
                   )
                 ORDER BY g.lookup_count DESC
                 LIMIT %d",
                max(1, $limit),
            ),
            [
                self::LOOKUP_NOT_FOUND_MESSAGE,
                $since->format('Y-m-d H:i:s'),
            ],
        );
    }

    // -------------------------------------------------------------------
    // Duplicate live libraries
    // -------------------------------------------------------------------

    /**
     * Number of catalogs held by more than one live node id.
     *
     * `cached_catalogs.catalog_hash` is the client-computed digest of the
     * whole catalog payload plus its book count (ADR-027), so equality is
     * exact rather than heuristic: two nodes sharing a hash hold the same
     * library, book for book. That makes the signal essentially free, and
     * indexed by `idx_cached_catalogs_hash`.
     *
     * Two guards keep it honest:
     *  - `book_count > 0`, because an empty library hashes like every other
     *    empty library; without it any two fresh installs would be reported;
     *  - both profiles alive within {@see ALIVE_DAYS}, so replacing a lost
     *    phone (old node stops reporting) is never flagged.
     */
    public function countDuplicateLiveLibraries(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (
                 SELECT c.catalog_hash
                 FROM cached_catalogs c
                 JOIN library_profiles p ON p.node_id = c.node_id
                 WHERE c.catalog_hash IS NOT NULL
                   AND p.book_count > 0
                   AND p.last_seen_at >= ?
                 GROUP BY c.catalog_hash
                 HAVING COUNT(*) >= 2
             ) d',
            [$this->aliveSince($now)],
        );
    }

    /**
     * Drill-down for {@see countDuplicateLiveLibraries}: one row per node of
     * every duplicated catalog, grouped by hash and oldest node first.
     *
     * The oldest node is the one peers already follow, so the ordering shows
     * at a glance which identity the second device would need to adopt (or,
     * from phase 2 on, which one to point the user's account sync at).
     *
     * @return array<array<string, mixed>>
     */
    public function findDuplicateLiveLibraries(\DateTimeImmutable $now, int $limit = 20): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT c.catalog_hash, p.node_id, p.display_name, p.book_count,
                        p.created_at, p.last_seen_at, p.app_version
                 FROM cached_catalogs c
                 JOIN library_profiles p ON p.node_id = c.node_id
                 WHERE c.catalog_hash IS NOT NULL
                   AND p.book_count > 0
                   AND p.last_seen_at >= ?
                   AND c.catalog_hash IN (
                       SELECT c2.catalog_hash
                       FROM cached_catalogs c2
                       JOIN library_profiles p2 ON p2.node_id = c2.node_id
                       WHERE c2.catalog_hash IS NOT NULL
                         AND p2.book_count > 0
                         AND p2.last_seen_at >= ?
                       GROUP BY c2.catalog_hash
                       HAVING COUNT(*) >= 2
                   )
                 ORDER BY c.catalog_hash, p.created_at ASC
                 LIMIT %d',
                max(1, $limit),
            ),
            [$this->aliveSince($now), $this->aliveSince($now)],
        );
    }

    /**
     * Instant of the most recent duplicate_library_detected alert, for the
     * same 24h deduplication as the coverage alert.
     */
    public function lastDuplicateLibraryAlertAt(): ?\DateTimeImmutable
    {
        return $this->lastMaintenanceAlertAt(self::DUPLICATE_ALERT_MESSAGE);
    }

    /**
     * Start of the liveness window, formatted for a bound parameter.
     */
    private function aliveSince(\DateTimeImmutable $now): string
    {
        return $now->modify(sprintf('-%d days', self::ALIVE_DAYS))->format('Y-m-d H:i:s');
    }

    /**
     * Instant of the most recent catalog_coverage_degraded alert, used to
     * deduplicate emissions (max one per 24h). Reads the same maintenance
     * marker pattern as the dashboard's last_prune_at.
     */
    public function lastCoverageAlertAt(): ?\DateTimeImmutable
    {
        return $this->lastMaintenanceAlertAt('catalog_coverage_degraded');
    }

    /**
     * Instant of the most recent maintenance marker carrying $message, or
     * null when it was never emitted (MAX() over zero rows yields NULL).
     */
    private function lastMaintenanceAlertAt(string $message): ?\DateTimeImmutable
    {
        $raw = $this->connection->fetchOne(
            "SELECT MAX(created_at) FROM hub_events
             WHERE channel = 'maintenance' AND message = ?",
            [$message],
        );

        return is_string($raw) && $raw !== ''
            ? new \DateTimeImmutable($raw)
            : null;
    }

    /**
     * Pure alert decision: emit when the count strictly exceeds the threshold
     * AND no alert was emitted in the last 24 hours. Keeping it static and
     * side-effect free freezes the semantics under unit test.
     *
     * Shared by every dashboard alert so they cannot drift apart on the
     * dedup window. Pass a threshold of 0 for a signal where a single
     * occurrence already deserves an event.
     */
    public static function shouldEmitAlert(
        int $count,
        int $threshold,
        ?\DateTimeImmutable $lastAlertAt,
        \DateTimeImmutable $now,
    ): bool {
        if ($count <= $threshold) {
            return false;
        }

        return $lastAlertAt === null || $lastAlertAt <= $now->modify('-24 hours');
    }

    /**
     * Coverage-specific alias of {@see shouldEmitAlert}, kept so the call
     * sites read as what they watch.
     */
    public static function shouldEmitCoverageAlert(
        int $gapCount,
        int $threshold,
        ?\DateTimeImmutable $lastAlertAt,
        \DateTimeImmutable $now,
    ): bool {
        return self::shouldEmitAlert($gapCount, $threshold, $lastAlertAt, $now);
    }
}
