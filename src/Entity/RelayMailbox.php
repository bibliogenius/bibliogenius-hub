<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RelayMailboxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RelayMailboxRepository::class)]
#[ORM\Table(name: 'relay_mailboxes')]
class RelayMailbox
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 64)]
    private string $readToken;

    #[ORM\Column(type: 'string', length: 64)]
    private string $writeToken;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAccessed = null;

    /**
     * Node identity that owns this mailbox. Set on first reference from
     * DirectoryService::applyProfileData (claim-on-first-reference). See
     * ADR-031 for the threat model and ownership semantics. NULL means
     * the mailbox has not yet been claimed.
     */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $ownerNodeId = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getReadToken(): string
    {
        return $this->readToken;
    }

    public function setReadToken(string $readToken): static
    {
        $this->readToken = $readToken;

        return $this;
    }

    public function getWriteToken(): string
    {
        return $this->writeToken;
    }

    public function setWriteToken(string $writeToken): static
    {
        $this->writeToken = $writeToken;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getLastAccessed(): ?\DateTimeImmutable
    {
        return $this->lastAccessed;
    }

    public function setLastAccessed(?\DateTimeImmutable $lastAccessed): static
    {
        $this->lastAccessed = $lastAccessed;

        return $this;
    }

    public function getOwnerNodeId(): ?string
    {
        return $this->ownerNodeId;
    }

    public function setOwnerNodeId(?string $ownerNodeId): static
    {
        $this->ownerNodeId = $ownerNodeId;

        return $this;
    }
}
