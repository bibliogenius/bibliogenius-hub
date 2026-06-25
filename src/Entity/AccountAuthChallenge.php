<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountAuthChallengeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A one-time, short-lived nonce for account auth challenge-response (ADR-043).
 * `purpose` is `login` (Ed25519) or `keybundle` (AuthVerifier HMAC). The row
 * is consumed (deleted) on successful verification; expired rows are GC'd.
 */
#[ORM\Entity(repositoryClass: AccountAuthChallengeRepository::class)]
#[ORM\Table(name: 'account_auth_challenges')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_account_auth_chal_expires')]
class AccountAuthChallenge
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_KEYBUNDLE = 'keybundle';
    public const PURPOSES = [self::PURPOSE_LOGIN, self::PURPOSE_KEYBUNDLE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $accountId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $challenge;

    #[ORM\Column(type: 'string', length: 16)]
    private string $purpose;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function setAccountId(string $accountId): static
    {
        $this->accountId = $accountId;

        return $this;
    }

    public function getChallenge(): string
    {
        return $this->challenge;
    }

    public function setChallenge(string $challenge): static
    {
        $this->challenge = $challenge;

        return $this;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
