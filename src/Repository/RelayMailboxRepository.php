<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RelayMailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelayMailbox>
 */
class RelayMailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelayMailbox::class);
    }

    public function findByUuid(string $uuid): ?RelayMailbox
    {
        return $this->find($uuid);
    }
}
