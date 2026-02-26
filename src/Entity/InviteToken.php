<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InviteTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InviteTokenRepository::class)]
#[ORM\Table(name: 'invite_tokens')]
class InviteToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 12)]
    private string $token;

    #[ORM\Column(type: 'text')]
    private string $encryptedPayload;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getEncryptedPayload(): string
    {
        return $this->encryptedPayload;
    }

    public function setEncryptedPayload(string $encryptedPayload): static
    {
        $this->encryptedPayload = $encryptedPayload;

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
