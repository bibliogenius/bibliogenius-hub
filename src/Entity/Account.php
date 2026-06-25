<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Account identity, auth material, KDF params, and the pinned version triple
 * for E2EE multi-device sync (ADR-043, ADR-042). The hub holds no decrypting
 * secret here: account_auth_pk verifies logins, auth_verifier_hash gates the
 * keybundle download, none of them decrypts content.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\UniqueConstraint(name: 'uniq_accounts_email', columns: ['email'])]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $accountId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 64)]
    private string $accountSalt;

    #[ORM\Column(type: 'text')]
    private string $kdfParams;

    #[ORM\Column(type: 'string', length: 64)]
    private string $accountAuthPk;

    #[ORM\Column(type: 'string', length: 128)]
    private string $authVerifierHash;

    #[ORM\Column(type: 'integer')]
    private int $schemaVersion;

    #[ORM\Column(type: 'string', length: 32)]
    private string $authMethod;

    #[ORM\Column(type: 'string', length: 32)]
    private string $aeadAlg;

    #[ORM\Column(type: 'string', length: 128)]
    private string $descriptorSig;

    // bigint columns hydrate as strings in Doctrine (no 64-bit overflow on
    // 32-bit PHP); accessors cast to int at the edge.
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private string $changeCounter = '0';

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private string $quotaBytesUsed = '0';

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $quotaBytesLimit = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getAccountSalt(): string
    {
        return $this->accountSalt;
    }

    public function setAccountSalt(string $accountSalt): static
    {
        $this->accountSalt = $accountSalt;

        return $this;
    }

    public function getKdfParams(): string
    {
        return $this->kdfParams;
    }

    public function setKdfParams(string $kdfParams): static
    {
        $this->kdfParams = $kdfParams;

        return $this;
    }

    public function getAccountAuthPk(): string
    {
        return $this->accountAuthPk;
    }

    public function setAccountAuthPk(string $accountAuthPk): static
    {
        $this->accountAuthPk = $accountAuthPk;

        return $this;
    }

    public function getAuthVerifierHash(): string
    {
        return $this->authVerifierHash;
    }

    public function setAuthVerifierHash(string $authVerifierHash): static
    {
        $this->authVerifierHash = $authVerifierHash;

        return $this;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function setSchemaVersion(int $schemaVersion): static
    {
        $this->schemaVersion = $schemaVersion;

        return $this;
    }

    public function getAuthMethod(): string
    {
        return $this->authMethod;
    }

    public function setAuthMethod(string $authMethod): static
    {
        $this->authMethod = $authMethod;

        return $this;
    }

    public function getAeadAlg(): string
    {
        return $this->aeadAlg;
    }

    public function setAeadAlg(string $aeadAlg): static
    {
        $this->aeadAlg = $aeadAlg;

        return $this;
    }

    public function getDescriptorSig(): string
    {
        return $this->descriptorSig;
    }

    public function setDescriptorSig(string $descriptorSig): static
    {
        $this->descriptorSig = $descriptorSig;

        return $this;
    }

    public function getQuotaBytesUsed(): int
    {
        return (int) $this->quotaBytesUsed;
    }

    public function setQuotaBytesUsed(int $quotaBytesUsed): static
    {
        $this->quotaBytesUsed = (string) $quotaBytesUsed;

        return $this;
    }

    public function getQuotaBytesLimit(): ?int
    {
        return $this->quotaBytesLimit === null ? null : (int) $this->quotaBytesLimit;
    }

    public function setQuotaBytesLimit(?int $quotaBytesLimit): static
    {
        $this->quotaBytesLimit = $quotaBytesLimit === null ? null : (string) $quotaBytesLimit;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
