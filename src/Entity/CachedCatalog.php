<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cached_catalogs')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_cached_catalogs_expires')]
#[ORM\Index(columns: ['catalog_hash'], name: 'idx_cached_catalogs_hash')]
class CachedCatalog
{
    /**
     * Shared primary key with LibraryProfile (1:1, FK = PK).
     */
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: LibraryProfile::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'node_id', onDelete: 'CASCADE')]
    private LibraryProfile $libraryProfile;

    /**
     * JSON-encoded ISBN array (legacy format: ["isbn1", "isbn2"]).
     * Kept for backward compatibility.
     */
    #[ORM\Column(type: 'text')]
    private string $isbnPayload;

    /**
     * JSON-encoded enriched catalog: [{"isbn":"...", "title":"...", "author":"..."}, ...].
     * Null for libraries that have not yet pushed enriched data.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $catalogPayload = null;

    /**
     * SHA-256 hex digest of the client-canonical catalog payload (ADR-027).
     * Null for legacy catalogs pushed before diff-based sync was rolled out.
     * Clients compare this hash to their own to short-circuit re-pushes.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $catalogHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** TTL: 7 days from last push. Expired entries are pruned on write or by scheduled cleanup. */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    private const TTL_DAYS = 7;

    public function __construct(
        LibraryProfile $libraryProfile,
        string $isbnPayload,
        ?string $catalogPayload = null,
        ?string $catalogHash = null,
    ) {
        $this->libraryProfile = $libraryProfile;
        $this->isbnPayload = $isbnPayload;
        $this->catalogPayload = $catalogPayload;
        $this->catalogHash = $catalogHash;
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable(sprintf('+%d days', self::TTL_DAYS));
    }

    public function getNodeId(): string
    {
        return $this->libraryProfile->getNodeId();
    }

    public function getLibraryProfile(): LibraryProfile
    {
        return $this->libraryProfile;
    }

    public function getIsbnPayload(): string
    {
        return $this->isbnPayload;
    }

    public function getCatalogPayload(): ?string
    {
        return $this->catalogPayload;
    }

    public function getCatalogHash(): ?string
    {
        return $this->catalogHash;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    /** Replaces the payload and resets the TTL. */
    public function refresh(string $isbnPayload, ?string $catalogPayload = null, ?string $catalogHash = null): static
    {
        $this->isbnPayload = $isbnPayload;
        $this->catalogPayload = $catalogPayload;
        $this->catalogHash = $catalogHash;
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable(sprintf('+%d days', self::TTL_DAYS));
        return $this;
    }

    /**
     * Bumps the TTL and updated_at without touching the stored payload.
     * Used by the diff-based push path when the client signals that the
     * catalog is unchanged (same hash) — keeps the cache alive without
     * a redundant rewrite (ADR-027).
     */
    public function touchTtl(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable(sprintf('+%d days', self::TTL_DAYS));
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
