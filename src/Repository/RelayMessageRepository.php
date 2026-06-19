<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RelayMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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

    /**
     * Drops the [$count] oldest messages of [$uuid] (FIFO eviction).
     * Used by the deposit path to enforce the per-mailbox cap with LRU
     * semantics: when the cap is reached, the oldest message is evicted
     * to make room for the incoming one. Returns rows actually deleted.
     */
    public function deleteOldest(string $uuid, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        // Subquery on PK (id ascending = oldest first) to bound the DELETE
        // strictly. The sub-SELECT runs in the same transaction so a
        // concurrent deposit cannot widen our victim set.
        $sql = 'DELETE FROM relay_messages WHERE id IN ('
             . 'SELECT id FROM relay_messages '
             . 'WHERE mailbox_uuid = :uuid '
             . 'ORDER BY id ASC LIMIT :limit'
             . ')';
        return (int) $this->getEntityManager()
            ->getConnection()
            ->executeStatement($sql, [
                'uuid' => $uuid,
                'limit' => $count,
            ], [
                // Doctrine DBAL 4 rejects raw \PDO::PARAM_* ints in the types
                // map (TypeError in ExpandArrayParameters). Use the enum.
                'limit' => ParameterType::INTEGER,
            ]);
    }
}
