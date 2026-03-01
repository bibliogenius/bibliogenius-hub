<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LibraryProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LibraryProfileRepository::class)]
#[ORM\Table(name: 'library_profiles')]
#[ORM\Index(columns: ['is_listed'], name: 'idx_library_profiles_listed')]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_library_profiles_last_seen')]
class LibraryProfile
{
    /** Cryptographic node identity (public key fingerprint). Immutable after creation. */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 128)]
    private string $nodeId;

    /** Shared secret returned once at registration. Required for all write operations. */
    #[ORM\Column(type: 'string', length: 64)]
    private string $writeToken;

    #[ORM\Column(type: 'string', length: 255)]
    private string $displayName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer')]
    private int $bookCount = 0;

    #[ORM\Column(type: 'string', length: 5, nullable: true)]
    private ?string $locationCountry = null;

    /**
     * When true: A's follow request stays pending until B manually approves it.
     * When false: follow requests are auto-approved and the catalog is served publicly.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $requiresApproval = true;

    /**
     * Limits who can send follow requests.
     * Allowed values: 'everyone' | 'individuals_only' | 'institutions_only'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $acceptFrom = 'everyone';

    /** Whether this library appears in the public directory. */
    #[ORM\Column(type: 'boolean')]
    private bool $isListed = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct(string $nodeId, string $writeToken, string $displayName)
    {
        $this->nodeId = $nodeId;
        $this->writeToken = $writeToken;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    public function getWriteToken(): string
    {
        return $this->writeToken;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getBookCount(): int
    {
        return $this->bookCount;
    }

    public function setBookCount(int $bookCount): static
    {
        $this->bookCount = $bookCount;
        return $this;
    }

    public function getLocationCountry(): ?string
    {
        return $this->locationCountry;
    }

    public function setLocationCountry(?string $locationCountry): static
    {
        $this->locationCountry = $locationCountry;
        return $this;
    }

    public function isRequiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function setRequiresApproval(bool $requiresApproval): static
    {
        $this->requiresApproval = $requiresApproval;
        return $this;
    }

    public function getAcceptFrom(): string
    {
        return $this->acceptFrom;
    }

    public function setAcceptFrom(string $acceptFrom): static
    {
        $this->acceptFrom = $acceptFrom;
        return $this;
    }

    public function isListed(): bool
    {
        return $this->isListed;
    }

    public function setIsListed(bool $isListed): static
    {
        $this->isListed = $isListed;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touchLastSeen(): static
    {
        $this->lastSeenAt = new \DateTimeImmutable();
        return $this;
    }

    public function toPublicArray(): array
    {
        return [
            'node_id'           => $this->nodeId,
            'display_name'      => $this->displayName,
            'description'       => $this->description,
            'book_count'        => $this->bookCount,
            'location_country'  => $this->locationCountry,
            'requires_approval' => $this->requiresApproval,
            'last_seen_at'      => $this->lastSeenAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
