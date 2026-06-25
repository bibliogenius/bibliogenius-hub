<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountDeviceRegistryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The signed + encrypted device registry, opaque to the hub (ADR-043 H3).
 * The hub stores and serves the latest blob and never parses it; device
 * authorization/revocation is enforced entirely client-side. `registrySeq`
 * is bumped on every publish so the newest registry is served.
 */
#[ORM\Entity(repositoryClass: AccountDeviceRegistryRepository::class)]
#[ORM\Table(name: 'account_device_registry')]
class AccountDeviceRegistry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $accountId;

    #[ORM\Column(type: 'blob')]
    private $blob;

    // bigint hydrates as string in Doctrine; getRegistrySeq() casts to int.
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private string $registrySeq = '0';

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

    public function getRegistrySeq(): int
    {
        return (int) $this->registrySeq;
    }

    public function setRegistrySeq(int $registrySeq): static
    {
        $this->registrySeq = (string) $registrySeq;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
