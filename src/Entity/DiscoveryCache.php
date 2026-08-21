<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DiscoveryCacheRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pooled bibliographic resolution cache (ADR-060).
 *
 * Keyed by bibliographic identity only, never by requester: all users share
 * the same rows, which is both the politeness mechanism toward the sources
 * and the privacy contract (no requester-to-query association exists).
 *
 * Two levels on purpose:
 *   - '*_lookup' rows map one ISBN-13 to entity ids (tiny, one per anchor
 *     ever seen; payload = {"series_uris": [...]}).
 *   - entity rows ('series', later 'author') hold the full resolved
 *     payload, language-neutral: editions are stored for every language and
 *     filtered per request at serve time.
 *
 * TTLs: 30 days for resolved rows (a newly published volume becomes visible
 * within a month), 7 days for negative rows ('ambiguous', 'unknown').
 * Transport errors are never cached. Expired rows are re-resolved on demand
 * and swept by the nightly app:db:prune.
 *
 * The hot read/write path uses raw DBAL on the repository (upsert); this
 * mapping backs the prune, the tests and any future BO visibility.
 */
#[ORM\Entity(repositoryClass: DiscoveryCacheRepository::class)]
#[ORM\Table(name: 'discovery_cache')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_discovery_cache_expires')]
class DiscoveryCache
{
    public const TTL_RESOLVED_DAYS = 30;
    public const TTL_NEGATIVE_DAYS = 7;

    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_UNKNOWN = 'unknown';

    public const KIND_SERIES_LOOKUP = 'series_lookup';
    public const KIND_SERIES = 'series';
    public const KIND_AUTHOR_LOOKUP = 'author_lookup';
    public const KIND_AUTHOR = 'author';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 16)]
    private string $kind;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    private string $cacheKey;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    /** JSON payload; NULL for negative entries. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $kind, string $cacheKey, string $status, ?string $payload, ?string $source)
    {
        $this->kind = $kind;
        $this->cacheKey = $cacheKey;
        $this->status = $status;
        $this->payload = $payload;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = self::expiryFor($status);
    }

    /** Resolved rows live 30 days, negative rows 7 (ADR-060 section 3.3). */
    public static function expiryFor(string $status): \DateTimeImmutable
    {
        $days = $status === self::STATUS_RESOLVED ? self::TTL_RESOLVED_DAYS : self::TTL_NEGATIVE_DAYS;

        return new \DateTimeImmutable(sprintf('+%d days', $days));
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
