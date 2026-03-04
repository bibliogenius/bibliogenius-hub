<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FollowRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FollowRepository::class)]
#[ORM\Table(name: 'follows')]
#[ORM\UniqueConstraint(name: 'unique_follow', fields: ['followerNodeId', 'followedNodeId'])]
#[ORM\Index(columns: ['followed_node_id', 'status'], name: 'idx_follows_followed_status')]
#[ORM\Index(columns: ['follower_node_id', 'status'], name: 'idx_follows_follower_status')]
class Follow
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_BLOCKED  = 'blocked';

    private const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_REJECTED,
        self::STATUS_BLOCKED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 128)]
    private string $followerNodeId;

    #[ORM\Column(type: 'string', length: 128)]
    private string $followedNodeId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** E2EE sealed blob: followed library's contact info, encrypted for this specific follower. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $encryptedContact = null;

    public function __construct(string $followerNodeId, string $followedNodeId)
    {
        $this->followerNodeId = $followerNodeId;
        $this->followedNodeId = $followedNodeId;
        $this->status = self::STATUS_PENDING;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFollowerNodeId(): string
    {
        return $this->followerNodeId;
    }

    public function getFollowedNodeId(): string
    {
        return $this->followedNodeId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function approve(): static
    {
        $this->status = self::STATUS_ACTIVE;
        $this->resolvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function reject(): static
    {
        $this->status = self::STATUS_REJECTED;
        $this->resolvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function block(): static
    {
        $this->status = self::STATUS_BLOCKED;
        $this->resolvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getEncryptedContact(): ?string
    {
        return $this->encryptedContact;
    }

    public function setEncryptedContact(?string $encryptedContact): static
    {
        $this->encryptedContact = $encryptedContact;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'follower_node_id'  => $this->followerNodeId,
            'followed_node_id'  => $this->followedNodeId,
            'status'            => $this->status,
            'created_at'        => $this->createdAt->format(\DateTimeInterface::ATOM),
            'resolved_at'       => $this->resolvedAt?->format(\DateTimeInterface::ATOM),
            'encrypted_contact' => $this->encryptedContact,
        ];
    }
}
