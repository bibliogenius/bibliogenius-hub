<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RelayMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelayMessage>
 */
class RelayMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelayMessage::class);
    }

    /**
     * @return RelayMessage[]
     */
    public function findByMailbox(string $uuid): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.mailboxUuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByMailbox(string $uuid): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.mailboxUuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
