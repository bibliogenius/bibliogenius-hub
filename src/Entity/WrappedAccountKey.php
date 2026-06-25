<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WrappedAccountKeyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A wrapped AccountKeyBundle copy, opaque to the hub (ADR-042 section 8).
 * `kind` is one of passphrase | recovery | escrow (double/triple-wrap). The
 * hub stores and serves the bytes; it can never unwrap them.
 */
#[ORM\Entity(repositoryClass: WrappedAccountKeyRepository::class)]
#[ORM\Table(name: 'wrapped_account_keys')]
class WrappedAccountKey
{
    public const KIND_PASSPHRASE = 'passphrase';
    public const KIND_RECOVERY = 'recovery';
    public const KIND_ESCROW = 'escrow';
    public const KINDS = [self::KIND_PASSPHRASE, self::KIND_RECOVERY, self::KIND_ESCROW];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $accountId;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 16)]
    private string $kind;

    #[ORM\Column(type: 'blob')]
    private $blob;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getBlob()
    {
        return $this->blob;
    }

    public function setBlob($blob): static
    {
        $this->blob = $blob;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
