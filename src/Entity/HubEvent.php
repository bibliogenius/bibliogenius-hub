<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hub_events')]
#[ORM\Index(columns: ['created_at'], name: 'idx_hub_events_created')]
#[ORM\Index(columns: ['channel'], name: 'idx_hub_events_channel')]
class HubEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $level;

    #[ORM\Column(type: 'string', length: 30)]
    private string $channel;

    #[ORM\Column(type: 'string', length: 500)]
    private string $message;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $context = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLevel(): string { return $this->level; }
    public function getChannel(): string { return $this->channel; }
    public function getMessage(): string { return $this->message; }
    public function getContext(): ?string { return $this->context; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string
    {
        return sprintf('[%s] %s: %s', $this->level, $this->channel, $this->message);
    }
}
