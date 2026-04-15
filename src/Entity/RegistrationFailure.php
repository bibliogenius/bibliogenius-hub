<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Logs failed hub directory registrations (401 - write_token mismatch).
 * Allows admins to identify stuck libraries and manually unblock them
 * by deleting the stale profile from the BO.
 */
#[ORM\Entity]
#[ORM\Table(name: 'registration_failures')]
#[ORM\Index(columns: ['node_id'], name: 'idx_reg_failures_node')]
#[ORM\Index(columns: ['created_at'], name: 'idx_reg_failures_created')]
class RegistrationFailure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 128)]
    private string $nodeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $displayName;

    #[ORM\Column(type: 'integer')]
    private int $bookCount;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $clientIp;

    /** Client app version reported in the rejected payload (informational). */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->displayName, substr($this->nodeId, 0, 8));
    }

    public function __construct(
        string $nodeId,
        string $displayName,
        int $bookCount,
        ?string $clientIp,
        ?string $appVersion = null,
    ) {
        $this->nodeId = $nodeId;
        $this->displayName = $displayName;
        $this->bookCount = $bookCount;
        $this->clientIp = $clientIp;
        $this->appVersion = $appVersion;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getBookCount(): int
    {
        return $this->bookCount;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
