<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountEntityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One lane of the blind per-account store (ADR-043). The composite primary
 * key (account_id, opaque_id, device_id) IS the lane, so an upsert overwrites
 * in place and no history accumulates. The hub never reads `blob`; there is no
 * entity_type, no HLC, and no authorization column - by design (H1-H5/M1).
 *
 * The hot push/pull paths use raw DBAL on the repository for performance; this
 * mapping backs account CRUD, cascade deletes, and tests.
 */
#[ORM\Entity(repositoryClass: AccountEntityRepository::class)]
#[ORM\Table(name: 'account_entities')]
#[ORM\Index(columns: ['account_id', 'change_seq'], name: 'idx_account_entities_cursor')]
#[ORM\Index(columns: ['tombstoned_at'], name: 'idx_account_entities_tomb')]
class AccountEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $accountId;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $opaqueId;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $deviceId;

    // bigint hydrates as string in Doctrine; getChangeSeq() casts to int.
    #[ORM\Column(type: 'bigint')]
    private string $changeSeq = '0';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $deleted = false;

    #[ORM\Column(type: 'integer')]
    private int $sizeBucket;

    #[ORM\Column(type: 'blob', nullable: true)]
    private $blob;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $tombstonedAt = null;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
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

    public function getOpaqueId(): string
    {
        return $this->opaqueId;
    }

    public function setOpaqueId(string $opaqueId): static
    {
        $this->opaqueId = $opaqueId;

        return $this;
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    public function setDeviceId(string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function getChangeSeq(): int
    {
        return (int) $this->changeSeq;
    }

    public function setChangeSeq(int $changeSeq): static
    {
        $this->changeSeq = (string) $changeSeq;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): static
    {
        $this->deleted = $deleted;

        return $this;
    }

    public function getSizeBucket(): int
    {
        return $this->sizeBucket;
    }

    public function setSizeBucket(int $sizeBucket): static
    {
        $this->sizeBucket = $sizeBucket;

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

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getTombstonedAt(): ?\DateTimeImmutable
    {
        return $this->tombstonedAt;
    }

    public function setTombstonedAt(?\DateTimeImmutable $tombstonedAt): static
    {
        $this->tombstonedAt = $tombstonedAt;

        return $this;
    }
}
