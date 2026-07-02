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
final class DirectoryHealthRepository
{
    /**
     * LIKE pattern matching a JSON context whose node_id is a client-side
     * placeholder. The backslash escapes the underscore (PostgreSQL's
     * default LIKE escape character), so `peer_X` matches but `peersomething`
     * does not.
     */
    private const PLACEHOLDER_CONTEXT_PATTERN = '%"node_id":"peer\_%';

    private const LOOKUP_NOT_FOUND_MESSAGE = 'profile lookup: not found';

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

    /**
     * Instant of the most recent catalog_coverage_degraded alert, used to
     * deduplicate emissions (max one per 24h). Reads the same maintenance
     * marker pattern as the dashboard's last_prune_at.
     */
    public function lastCoverageAlertAt(): ?\DateTimeImmutable
    {
        $raw = $this->connection->fetchOne(
            "SELECT MAX(created_at) FROM hub_events
             WHERE channel = 'maintenance' AND message = 'catalog_coverage_degraded'",
        );

        return is_string($raw) && $raw !== ''
            ? new \DateTimeImmutable($raw)
            : null;
    }

    /**
     * Pure alert decision: emit when the gap count strictly exceeds the
     * threshold AND no alert was emitted in the last 24 hours. Keeping it
     * static and side-effect free freezes the semantics under unit test.
     */
    public static function shouldEmitCoverageAlert(
        int $gapCount,
        int $threshold,
        ?\DateTimeImmutable $lastAlertAt,
        \DateTimeImmutable $now,
    ): bool {
        if ($gapCount <= $threshold) {
            return false;
        }

        return $lastAlertAt === null || $lastAlertAt <= $now->modify('-24 hours');
    }
}
