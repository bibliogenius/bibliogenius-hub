<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscoveryCache;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\DiscoveryResolverService;
use App\Service\HubEventLogger;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Storage for the pooled discovery cache (ADR-060).
 *
 * The hot path is raw DBAL: reads return decoded rows without hydrating
 * entities, writes are a single Postgres upsert. The ORM mapping on
 * DiscoveryCache backs the prune and the tests.
 *
 * @extends ServiceEntityRepository<DiscoveryCache>
 */
class DiscoveryCacheRepository extends ServiceEntityRepository
{
    /**
     * Below this many samples in a window, a share is statistical noise
     * rather than a signal. Applied by every ratio in this section.
     */
    public const DRIFT_MIN_SAMPLES = 10;

    /**
     * Days the entity-quality tripwire looks back over, wider than the 24h
     * of every other figure on the section and deliberately so.
     *
     * Its population is the rarest one measured here: entities fetched
     * COLD, which is a handful a day on a young install and never the
     * dozens the sample floor asks for. A 24h window would leave the alarm
     * reading 'n/a' most days, that is, disarmed. Seven days multiplies the
     * samples without weakening what the tripwire promises: it exists so a
     * silent schema change surfaces before it becomes weeks of empty
     * external cards, and a week is still an order of magnitude short of
     * that. The other cards stay at 24h because their populations are
     * large enough and because 'what happened today' is what they answer.
     */
    public const QUALITY_WINDOW_DAYS = 7;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscoveryCache::class);
    }

    /**
     * Fresh (non-expired) cache row, or null on miss/expiry. Expired rows
     * are left in place: re-resolution overwrites them and the nightly
     * prune sweeps the rest.
     *
     * @return array{status: string, payload: mixed, source: ?string}|null
     */
    public function findFresh(string $kind, string $cacheKey): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT status, payload, source FROM discovery_cache
              WHERE kind = :kind AND cache_key = :key AND expires_at >= :now',
            [
                'kind' => $kind,
                'key' => $cacheKey,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
        if ($row === false) {
            return null;
        }

        return [
            'status' => (string) $row['status'],
            'payload' => is_string($row['payload']) ? json_decode($row['payload'], true) : null,
            'source' => $row['source'] !== null ? (string) $row['source'] : null,
        ];
    }

    /**
     * Upsert one cache row. created_at survives refreshes; updated_at and
     * expires_at are reset (updated_at feeds the drift monitoring window).
     */
    public function put(string $kind, string $cacheKey, string $status, ?array $payload, ?string $source): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO discovery_cache (kind, cache_key, status, payload, source, created_at, updated_at, expires_at)
             VALUES (:kind, :key, :status, :payload, :source, :now, :now, :expires)
             ON CONFLICT (kind, cache_key) DO UPDATE SET
               status = EXCLUDED.status,
               payload = EXCLUDED.payload,
               source = EXCLUDED.source,
               updated_at = EXCLUDED.updated_at,
               expires_at = EXCLUDED.expires_at',
            [
                'kind' => $kind,
                'key' => $cacheKey,
                'status' => $status,
                'payload' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                'source' => $source,
                'now' => $now,
                'expires' => DiscoveryCache::expiryFor($status)->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Secondary nightly cap, by BYTES rather than by row count: keep the
     * rows whose payloads fit in $maxPayloadBytes, freshest expiry first,
     * and drop the rest. Returns how many rows were dropped.
     *
     * The TTL sweep alone bounds nothing under pressure: the pool only
     * grows when resolutions succeed, and what limits resolutions is the
     * global outbound budget, so a sustained stream of checksum-valid
     * ISBNs pointing at distinct entities can write for thirty days before
     * the first row expires. Bytes are the right unit because the pool is
     * lopsided: an author payload measures tens of KB against a hundred
     * bytes for the '*_lookup' rows that map an anchor to its entity, so a
     * row cap would evict the cheap rows that save re-resolutions and
     * leave the expensive ones. Ordering by expires_at drops the negative
     * (7-day) rows and the least recently written entities first, which is
     * the order the TTL sweep would have used anyway.
     */
    public function pruneOverBudget(int $maxPayloadBytes): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM discovery_cache WHERE (kind, cache_key) IN (
               SELECT kind, cache_key FROM (
                 SELECT kind, cache_key,
                        SUM(COALESCE(octet_length(payload), 0))
                          OVER (ORDER BY expires_at DESC, kind, cache_key) AS running_bytes
                   FROM discovery_cache
               ) ranked
              WHERE running_bytes > :budget
            )',
            ['budget' => $maxPayloadBytes],
            ['budget' => ParameterType::INTEGER],
        );
    }

    /** Bytes of payload currently held, for the /admin pool card. */
    public function totalPayloadBytes(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(octet_length(payload)), 0) FROM discovery_cache',
        );
    }

    /** Nightly sweep of expired rows (both TTL classes share expires_at). */
    public function pruneExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM discovery_cache WHERE expires_at < :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Resolution quality at the ENTITY stage over $since..now: the
     * source-drift tripwire of ADR-060 section 3.5, rebased 2026-09-01.
     * Callers pass now minus QUALITY_WINDOW_DAYS, which explains why that
     * window is wider than every other figure of the section.
     *
     * Of the entities identified at the source and fetched cold, the share
     * that came back empty (REASON_NO_USABLE_MEMBERS,
     * REASON_NO_USABLE_WORKS) rather than with a usable payload. That is
     * the shape a silent SPARQL or API change takes, a healthy pipeline is
     * near zero on it, and an absolute threshold is therefore meaningful.
     *
     * The tripwire used to be the share of non-resolved cache WRITES, and
     * two structural facts made that number unreadable: one resolution
     * writes up to three '*_lookup' rows besides its payload, and an
     * anchor ISBN the sources never indexed writes an 'unknown' row
     * without anything having gone wrong. A pool whose series anchors
     * matched 18% of the time therefore sat permanently above any
     * threshold and the nightly warning fired every night on a healthy
     * pipeline: alert fatigue, and a tripwire nobody could read.
     *
     * Why this window and no other. A ratio is only honest when both of
     * its halves are counted on the same occasions, and here that means
     * COLD ones: an entity served warm returns from the pool before any
     * journalling, so an entity-stage failure is written exactly when a
     * resolved entity row would have been. The three anchor-stage verdicts
     * (disjoint_anchors, no_clear_winner, name_not_verified) do not have
     * that property: they are decided after lookups that may have been
     * served warm, so they re-journal on every repeat request while their
     * successful counterparts stay silent in the cache. A rate built on
     * them climbs with traffic rather than with breakage, which is the
     * very failure this rebase exists to remove, so they stay out of the
     * alarm and remain visible as raw counts in the /admin reason table.
     * Giving them a rate of their own would need the resolver to journal
     * whether a call was actually spent, which is a change on the serving
     * path and is not worth it until anchor-stage drift is a real problem.
     *
     * Source coverage (REASON_NO_ANCHOR_RESOLVED, measured by
     * anchorCoverage()) and source pressure ('*_unavailable', measured by
     * unavailableBreakdownSince()) are likewise out, each with its own
     * card: counting either would drown the signal in its own baseline.
     *
     * One last way this ratio could lie, guarded below: its two halves are
     * trimmed by different rules. Successes are cache rows, kept 30 days
     * and dropped last by the byte cap; failures are hub_events rows, which
     * the nightly prune also caps at a fixed number of rows, oldest first,
     * across every channel. Once the hub writes more events per week than
     * that cap, the far end of this window reads a journal that no longer
     * covers it: failures under-report, successes do not, and the alarm
     * quietly loses sensitivity exactly as traffic grows. So the window is
     * checked against the frontier the prune records whenever it cuts, and
     * an uncovered window yields no percentage at all rather than a
     * reassuring one.
     *
     * @return array{total: int, resolved: int, failed: int, reasons: array<string, int>, share_percent: ?int, truncated: bool}
     */
    public function entityQualitySince(\DateTimeImmutable $since): array
    {
        $watched = [
            DiscoveryResolverService::REASON_NO_USABLE_MEMBERS,
            DiscoveryResolverService::REASON_NO_USABLE_WORKS,
        ];

        $resolved = $this->countResolvedEntityRowsSince($since);

        $failed = 0;
        $reasons = [];
        foreach ($this->fetchOutcomeEventsSince($since) as $row) {
            $reason = $row['reason'] !== null ? (string) $row['reason'] : null;
            if ($reason === null || !in_array($reason, $watched, true)) {
                continue;
            }
            $count = (int) $row['count'];
            $failed += $count;
            $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
        }
        arsort($reasons);

        $total = $resolved + $failed;
        // Same statistical floor as everywhere else in this section: below
        // it a percentage is noise dressed up as a signal.
        $share = $total < self::DRIFT_MIN_SAMPLES ? null : (int) round(100 * $failed / $total);
        // Only worth asking when there would otherwise be a number to show.
        $truncated = $share !== null && !$this->journalCoversWindow($since);

        return [
            'total' => $total,
            'resolved' => $resolved,
            'failed' => $failed,
            'reasons' => $reasons,
            'share_percent' => $truncated ? null : $share,
            'truncated' => $truncated,
        ];
    }

    /**
     * Whether hub_events still holds everything it wrote since $since, or
     * whether the prune's row cap has already eaten into that window.
     *
     * Answered from positive evidence only. Both cutters of the journal,
     * the logger's own probabilistic cleanup and app:db:prune, record the
     * same marker (HubEventLogger::MARKER_HUB_EVENTS_CAPPED) whenever the
     * cap actually cuts, carrying the oldest ordinary event still standing
     * afterwards: that is the frontier, and everything older than it is
     * gone. No marker means the cap has never cut, so nothing was ever
     * removed.
     *
     * Deducing this from the journal's own contents instead, as the first
     * version of this guard did, is not possible: "no event survives from
     * before the window" has two other causes, a quiet period and a period
     * where every resolution succeeded, since only failures are journalled.
     * Both would have flagged a perfectly healthy hub as unreadable.
     *
     * Only the latest marker is read: each cut moves the frontier forward,
     * so the most recent one is the binding constraint.
     */
    private function journalCoversWindow(\DateTimeImmutable $since): bool
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT created_at, (context::jsonb)->>'cutoff' AS cutoff
               FROM hub_events
              WHERE channel = 'maintenance' AND message = :marker
              ORDER BY created_at DESC
              LIMIT 1",
            ['marker' => HubEventLogger::MARKER_HUB_EVENTS_CAPPED],
        );
        if (!is_array($row)) {
            return true;
        }

        // Parsed rather than compared as strings, like every other
        // timestamp read in this class: the driver is free to hand back
        // fractional seconds or an offset suffix. A null cutoff means the
        // cut left no ordinary event at all, so the cut's own timestamp is
        // the frontier.
        $raw = $row['cutoff'] ?? null;
        if (!is_string($raw) || $raw === '') {
            $raw = $row['created_at'] ?? null;
        }
        // A marker we cannot date says nothing: assume the window holds
        // rather than blind a working measure on a malformed row. Unlike
        // the other two date reads in this class, this one does not come
        // from a typed timestamp column but from a value extracted out of
        // the TEXT context, so an unparseable string is reachable without
        // the schema being violated, and it must not take /admin down: the
        // controller does not wrap these calls.
        if (!is_string($raw) || $raw === '') {
            return true;
        }
        try {
            $frontier = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return true;
        }

        return $frontier <= $since;
    }

    /**
     * How often the sources actually know the anchor ISBNs we look up,
     * over the last 24h and over the last 7 days as a baseline.
     *
     * This is the population the quality tripwire above refuses to judge:
     * an ISBN missing from Wikidata and Inventaire is a fact about their
     * indexes, not a defect of ours, and its normal value depends on the
     * catalogue (series anchors match far less often than author ones, and
     * French comics and manga are the thinnest of all). So it carries no
     * absolute threshold. What it carries is a baseline comparison, which
     * is the only way to read the one case an absolute threshold cannot
     * separate: the anchor query BREAKING looks exactly like a catalogue
     * the sources do not index, unless you notice that yesterday it worked.
     * Hence 'collapsed': both windows have enough samples, the last 24h
     * matched nothing at all, and the 7-day baseline did match. That is a
     * visual warning in /admin, never a hub_events alert, because a quiet
     * day is enough to make it true.
     *
     * Only COLD lookups are visible here: a cached anchor makes no source
     * call and writes no row, on purpose. One edge to know when reading
     * the 7-day figure: negative lookups expire after exactly 7 days, so
     * the far edge of that window has already lost some of its misses and
     * the baseline reads slightly better than it lived.
     *
     * @return array{last_24h: array{total: int, hits: int, share_percent: ?int}, last_7d: array{total: int, hits: int, share_percent: ?int}, collapsed: bool}
     */
    public function anchorCoverage(\DateTimeImmutable $now): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE updated_at >= :since24) AS total_24,
                    COUNT(*) FILTER (WHERE updated_at >= :since24 AND status = :resolved) AS hits_24,
                    COUNT(*) AS total_7d,
                    COUNT(*) FILTER (WHERE status = :resolved) AS hits_7d
               FROM discovery_cache
              WHERE kind IN (:series_lookup, :author_lookup) AND updated_at >= :since7',
            [
                'series_lookup' => DiscoveryCache::KIND_SERIES_LOOKUP,
                'author_lookup' => DiscoveryCache::KIND_AUTHOR_LOOKUP,
                'resolved' => DiscoveryCache::STATUS_RESOLVED,
                'since24' => $now->modify('-24 hours')->format('Y-m-d H:i:s'),
                'since7' => $now->modify('-7 days')->format('Y-m-d H:i:s'),
            ],
        );

        $window = static function (int $total, int $hits): array {
            return [
                'total' => $total,
                'hits' => $hits,
                'share_percent' => $total < self::DRIFT_MIN_SAMPLES ? null : (int) round(100 * $hits / $total),
            ];
        };

        $last24h = $window((int) ($row['total_24'] ?? 0), (int) ($row['hits_24'] ?? 0));
        $last7d = $window((int) ($row['total_7d'] ?? 0), (int) ($row['hits_7d'] ?? 0));

        return [
            'last_24h' => $last24h,
            'last_7d' => $last7d,
            'collapsed' => $last24h['total'] >= self::DRIFT_MIN_SAMPLES
                && $last7d['total'] >= self::DRIFT_MIN_SAMPLES
                && $last24h['hits'] === 0
                && $last7d['hits'] > 0,
        ];
    }

    /**
     * When the drift alert last fired (hub_events maintenance marker), for
     * the 24h dedup shared with the other prune-time checks.
     */
    public function lastDriftAlertAt(): ?\DateTimeImmutable
    {
        $raw = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT MAX(created_at) FROM hub_events
              WHERE channel = 'maintenance' AND message = 'discovery_drift_degraded'",
        );

        return is_string($raw) && $raw !== ''
            ? new \DateTimeImmutable($raw)
            : null;
    }

    // -----------------------------------------------------------------
    // /admin monitoring (ADR-060 section 3.5): read-only aggregates over
    // the pool and its hub_events trail. Kept here, not inline in the
    // controller, so the SELECT shapes are unit-tested.
    //
    // Clock basis, assumed by every window below: the bounds are built in
    // PHP and compared against timestamps the database wrote. hub_events
    // and discovery_cache both date rows with a naive TIMESTAMP, so
    // nothing converts anything on the way in or out, and the comparison
    // is only as sound as the two clocks agreeing.
    //
    // Verifiable here: the php image leaves date.timezone at UTC and no TZ
    // or PGTZ is set in the Dockerfiles or either compose file. NOT
    // verifiable here: the timezone of the managed Postgres, which is what
    // NOW() actually yields. Both are expected to be UTC. Setting TZ on
    // the application container alone, or landing on a database server
    // that is not UTC, would silently skew every figure in this section by
    // the offset, and the cap-frontier guard below, being a boundary
    // comparison, would be the first to misjudge.
    // -----------------------------------------------------------------

    /** Total rows currently held in the pool, all kinds and statuses. */
    public function countAll(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM discovery_cache',
        );
    }

    /**
     * Row counts grouped by kind and status, for the pool breakdown table.
     * Only combinations actually present in the pool are returned.
     *
     * @return list<array{kind: string, status: string, count: int}>
     */
    public function countByKindAndStatus(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT kind, status, COUNT(*) AS count
               FROM discovery_cache
              GROUP BY kind, status
              ORDER BY kind, status',
        );

        return array_map(
            static fn (array $row): array => [
                'kind' => (string) $row['kind'],
                'status' => (string) $row['status'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    /** Soonest expires_at still in the pool, or null when the pool is empty. */
    public function nextExpiryAt(): ?\DateTimeImmutable
    {
        $raw = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MIN(expires_at) FROM discovery_cache',
        );

        return is_string($raw) && $raw !== ''
            ? new \DateTimeImmutable($raw)
            : null;
    }

    /**
     * Rows expiring within the next $days days (already-expired rows
     * pending the nightly sweep are excluded: they are a prune-lag
     * artefact, not an upcoming-expiry signal).
     */
    public function countExpiringWithinDays(int $days, \DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM discovery_cache WHERE expires_at >= :now AND expires_at <= :cutoff',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'cutoff' => $now->modify(sprintf('+%d days', $days))->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Cache writes in the last 24h, all statuses and all kinds: the raw
     * volume of work the pool absorbed, exposed so the dashboard can say
     * how many samples its percentages rest on. No ratio is computed over
     * this population any more, and the docblock of
     * entityQualitySince() says why.
     */
    public function countResolutionsLast24h(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM discovery_cache WHERE updated_at >= :since',
            ['since' => $now->modify('-24 hours')->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Failure-reason breakdown over the last 24h, read from hub_events
     * (channel 'discovery'). Keys are whatever DiscoveryResolverService
     * wrote in the 'reason' context field (no_anchor_resolved,
     * disjoint_anchors, no_clear_winner, no_usable_members,
     * outbound_budget_exhausted, source-exception messages); the dashboard
     * only renders the four resolution-quality reasons, the budget one is
     * covered separately by countBudgetExhaustionsSince().
     *
     * @return array<string, int> reason => count
     */
    public function countFailureReasonsLast24h(\DateTimeImmutable $now): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT (context::jsonb)->>'reason' AS reason, COUNT(*) AS count
               FROM hub_events
              WHERE channel = 'discovery' AND created_at >= :since
              GROUP BY reason",
            ['since' => $now->modify('-24 hours')->format('Y-m-d H:i:s')],
        );

        $counts = [];
        foreach ($rows as $row) {
            if ($row['reason'] === null) {
                continue;
            }
            $counts[(string) $row['reason']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Outbound-budget refusals (hub_events channel 'discovery') since
     * $since, BOTH shapes counted together: the bucket emptying
     * mid-resolution and the resolution refused up front because the
     * bucket could not cover it. Called with a 24h and a 7d window for the
     * source-pressure cards.
     *
     * Both belong in one figure because the card answers "is the budget
     * the thing hurting readers", and both mean yes. Which of the two is
     * happening is a separate and useful question, answered by the reason
     * split of unavailableBreakdownSince().
     */
    public function countBudgetExhaustionsSince(\DateTimeImmutable $since): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM hub_events
              WHERE channel = 'discovery'
                AND (context::jsonb)->>'reason' IN (:exhausted, :insufficient)
                AND created_at >= :since",
            [
                'exhausted' => DiscoveryBudgetExhaustedException::REASON_EXHAUSTED,
                'insufficient' => DiscoveryBudgetExhaustedException::REASON_INSUFFICIENT,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Unavailable outcomes over a window, the total they are a share of,
     * and their cause split. The failure-RATE half of ADR-060 section 3.5,
     * which the existing cards cannot express.
     *
     * Why they cannot: an 'unavailable' outcome never writes a cache row
     * (DiscoveryResolverService keeps transport failures out of the pool
     * on purpose), so no ratio computed over the pool can see it. Three
     * author_unavailable in the journal could be three out of five or
     * three out of five hundred, and nothing stored says which.
     *
     * The denominator is derivable from what is already stored, without a
     * new table, a counter or a request log, because every outcome leaves
     * exactly one trace and never two:
     *
     *   - a cold resolution that succeeds writes one ENTITY row
     *     ('series' or 'author', status 'resolved');
     *   - every other outcome, unavailable included, writes one
     *     hub_events row on channel 'discovery'.
     *
     * Hence total = resolved entity rows + discovery events. Lookup kinds
     * are excluded because one resolution writes up to three of them
     * besides its payload, and non-resolved entity rows are excluded
     * because they are journalled too and would count twice.
     *
     * What this measures is OUTCOMES that reached a decision, not HTTP
     * requests: a fully warm request makes no source call and is invisible
     * here by design (the failure rate of the source lane is not a
     * question about requests that never touched a source), while a warm
     * NEGATIVE request is journalled and does count, at zero outbound
     * cost. Reasons come back keyed exactly as the resolver wrote them, so
     * a cause added later appears without touching this query.
     *
     * @return array{total: int, resolved: int, unavailable: int, reasons: array<string, int>, share_percent: ?int}
     */
    public function unavailableBreakdownSince(\DateTimeImmutable $since): array
    {
        $resolved = $this->countResolvedEntityRowsSince($since);
        $rows = $this->fetchOutcomeEventsSince($since);

        $events = 0;
        $unavailable = 0;
        $reasons = [];
        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $events += $count;
            // Both lanes journal '<lane>_unavailable' and nothing else does.
            if (!str_ends_with((string) $row['message'], '_' . DiscoveryResolverService::STATUS_UNAVAILABLE)) {
                continue;
            }
            $unavailable += $count;
            $reason = $row['reason'] !== null ? (string) $row['reason'] : 'unspecified';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
        }
        arsort($reasons);

        $total = $resolved + $events;

        return [
            'total' => $total,
            'resolved' => $resolved,
            'unavailable' => $unavailable,
            'reasons' => $reasons,
            // Same statistical floor as the drift share: below it the
            // percentage is noise dressed up as a signal.
            'share_percent' => $total < self::DRIFT_MIN_SAMPLES ? null : (int) round(100 * $unavailable / $total),
        ];
    }

    /**
     * The SUCCESS half of the outcome population: one row per cold
     * resolution that produced a payload. Lookup kinds are excluded (one
     * resolution writes up to three of them besides its payload) and so
     * are non-resolved entity rows (they are journalled too, and would be
     * counted twice). Shared by every ratio computed over outcomes, so
     * that they can never disagree about what an outcome is. Each caller
     * passes its own window: the quality tripwire looks back
     * QUALITY_WINDOW_DAYS days, the unavailable share 24h.
     */
    private function countResolvedEntityRowsSince(\DateTimeImmutable $since): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM discovery_cache
              WHERE kind IN (:series, :author) AND status = :resolved AND updated_at >= :since',
            [
                'series' => DiscoveryCache::KIND_SERIES,
                'author' => DiscoveryCache::KIND_AUTHOR,
                'resolved' => DiscoveryCache::STATUS_RESOLVED,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * The FAILURE half: every non-resolved outcome leaves exactly one
     * hub_events row on channel 'discovery', grouped here by message and
     * reason so each caller can keep the slice it is about.
     *
     * @return list<array{message: mixed, reason: mixed, count: mixed}>
     */
    private function fetchOutcomeEventsSince(\DateTimeImmutable $since): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT message, (context::jsonb)->>'reason' AS reason, COUNT(*) AS count
               FROM hub_events
              WHERE channel = 'discovery' AND created_at >= :since
              GROUP BY message, reason",
            ['since' => $since->format('Y-m-d H:i:s')],
        );
    }
}
