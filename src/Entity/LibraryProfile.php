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

    /** Whether this library accepts borrow requests from followers via the hub. */
    #[ORM\Column(type: 'boolean')]
    private bool $allowBorrowing = true;

    /** Whether this library appears in the public directory. */
    #[ORM\Column(type: 'boolean')]
    private bool $isListed = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    /** X25519 public key (hex-encoded, 64 chars). Required for E2EE contact sharing. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $x25519PublicKey = null;

    /** Public website URL. Visible to all directory visitors. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $website = null;

    /** Hardware model name (e.g. "SM-A405FN", "iPhone14,2"). Informational only. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $deviceModel = null;

    /** SHA-256 hash of a platform-specific device identifier. Helps detect duplicate profiles after reinstall. */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $deviceFingerprint = null;

    /** Relay hub URL (e.g. "https://hub.bibliogenius.org"). Enables credential refresh for relay-only peers. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $relayUrl = null;

    /** Relay mailbox UUID. Peers deposit encrypted messages here. */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $relayMailboxId = null;

    /** Relay mailbox write token. Allows peers to deposit messages (NOT to read them). */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $relayWriteToken = null;

    /** JSON avatar configuration (DiceBear style + seed + customisation). Opaque to hub. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $avatarConfig = null;

    /** Total number of catalog views (incremented with 15-min cooldown per visitor). */
    #[ORM\Column(type: 'integer')]
    private int $viewCount = 0;

    public function __construct(string $nodeId, string $writeToken, string $displayName)
    {
        $this->nodeId = $nodeId;
        $this->writeToken = $writeToken;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->displayName, substr($this->nodeId, 0, 8));
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

    public function isAllowBorrowing(): bool
    {
        return $this->allowBorrowing;
    }

    public function setAllowBorrowing(bool $allowBorrowing): static
    {
        $this->allowBorrowing = $allowBorrowing;
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

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function incrementViewCount(): static
    {
        $this->viewCount++;
        return $this;
    }

    public function getX25519PublicKey(): ?string
    {
        return $this->x25519PublicKey;
    }

    public function setX25519PublicKey(?string $x25519PublicKey): static
    {
        $this->x25519PublicKey = $x25519PublicKey;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;
        return $this;
    }

    public function getDeviceModel(): ?string
    {
        return $this->deviceModel;
    }

    public function setDeviceModel(?string $deviceModel): static
    {
        $this->deviceModel = $deviceModel;
        return $this;
    }

    public function getDeviceFingerprint(): ?string
    {
        return $this->deviceFingerprint;
    }

    public function setDeviceFingerprint(?string $deviceFingerprint): static
    {
        $this->deviceFingerprint = $deviceFingerprint;
        return $this;
    }

    public function getRelayUrl(): ?string
    {
        return $this->relayUrl;
    }

    public function setRelayUrl(?string $relayUrl): static
    {
        $this->relayUrl = $relayUrl;
        return $this;
    }

    public function getRelayMailboxId(): ?string
    {
        return $this->relayMailboxId;
    }

    public function setRelayMailboxId(?string $relayMailboxId): static
    {
        $this->relayMailboxId = $relayMailboxId;
        return $this;
    }

    public function getRelayWriteToken(): ?string
    {
        return $this->relayWriteToken;
    }

    public function setRelayWriteToken(?string $relayWriteToken): static
    {
        $this->relayWriteToken = $relayWriteToken;
        return $this;
    }

    public function getAvatarConfig(): ?string
    {
        return $this->avatarConfig;
    }

    public function setAvatarConfig(?string $avatarConfig): static
    {
        $this->avatarConfig = $avatarConfig;
        return $this;
    }

    /** Public profile data. Relay credentials are intentionally excluded (OWASP A01). */
    public function toPublicArray(): array
    {
        return [
            'node_id'           => $this->nodeId,
            'display_name'      => $this->displayName,
            'description'       => $this->description,
            'book_count'        => $this->bookCount,
            'location_country'  => $this->locationCountry,
            'requires_approval' => $this->requiresApproval,
            'allow_borrowing'   => $this->allowBorrowing,
            'last_seen_at'      => $this->lastSeenAt?->format(\DateTimeInterface::ATOM),
            'view_count'        => $this->viewCount,
            'x25519_public_key' => $this->x25519PublicKey,
            'website'           => $this->website,
            'device_model'      => $this->deviceModel,
            'device_fingerprint' => $this->deviceFingerprint,
            'avatar_config'     => $this->avatarConfig,
        ];
    }
}
