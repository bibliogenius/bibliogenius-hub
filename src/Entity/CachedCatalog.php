<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cached_catalogs')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_cached_catalogs_expires')]
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
     * JSON-encoded ISBN array.
     * Plaintext for open libraries (requires_approval=false).
     * AES-GCM encrypted payload for approval-required libraries.
     */
    #[ORM\Column(type: 'text')]
    private string $isbnPayload;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** TTL: 7 days from last push. Expired entries are pruned on write or by scheduled cleanup. */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    private const TTL_DAYS = 7;

    public function __construct(LibraryProfile $libraryProfile, string $isbnPayload)
    {
        $this->libraryProfile = $libraryProfile;
        $this->isbnPayload = $isbnPayload;
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

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    /** Replaces the payload and resets the TTL. */
    public function refresh(string $isbnPayload): static
    {
        $this->isbnPayload = $isbnPayload;
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
