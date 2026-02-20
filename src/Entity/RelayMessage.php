<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RelayMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RelayMessageRepository::class)]
#[ORM\Table(name: 'relay_messages')]
#[ORM\Index(columns: ['mailbox_uuid'], name: 'idx_relay_messages_mailbox')]
#[ORM\Index(columns: ['created_at'], name: 'idx_relay_messages_created')]
class RelayMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36)]
    private string $mailboxUuid;

    #[ORM\Column(type: 'blob')]
    private $blob;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMailboxUuid(): string
    {
        return $this->mailboxUuid;
    }

    public function setMailboxUuid(string $mailboxUuid): static
    {
        $this->mailboxUuid = $mailboxUuid;

        return $this;
    }

    public function getBlob()
    {
        return $this->blob;
    }

    public function setBlob($blob): static
    {
        $this->blob = $blob;

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
}
