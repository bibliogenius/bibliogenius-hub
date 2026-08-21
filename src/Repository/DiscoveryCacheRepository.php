<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscoveryCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Below this many cache writes in the drift window, the non-resolved
     * share is statistical noise, not a source-drift signal.
     */
    public const DRIFT_MIN_SAMPLES = 10;

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

    /** Nightly sweep of expired rows (both TTL classes share expires_at). */
    public function pruneExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM discovery_cache WHERE expires_at < :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Share (percent, 0-100) of non-resolved outcomes among the cache rows
     * written in the last 24h, or null when there are fewer than
     * DRIFT_MIN_SAMPLES writes. The source-drift tripwire of ADR-060
     * section 3.5: SPARQL schemas and API shapes change silently, and a
     * quality regression must show up in /admin within a day instead of as
     * weeks of silently empty external cards.
     */
    public function nonResolvedSharePercentLast24h(\DateTimeImmutable $now): ?int
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status <> 'resolved') AS non_resolved
               FROM discovery_cache
              WHERE updated_at >= :since",
            ['since' => $now->modify('-24 hours')->format('Y-m-d H:i:s')],
        );
        $total = (int) ($row['total'] ?? 0);
        if ($total < self::DRIFT_MIN_SAMPLES) {
            return null;
        }

        return (int) round(100 * ((int) $row['non_resolved']) / $total);
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
}
