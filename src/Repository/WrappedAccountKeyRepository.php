<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WrappedAccountKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WrappedAccountKey>
 */
class WrappedAccountKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WrappedAccountKey::class);
    }

    /**
     * @param string[] $kinds
     *
     * @return WrappedAccountKey[]
     */
    public function findByAccountAndKinds(string $accountId, array $kinds): array
    {
        if ($kinds === []) {
            return [];
        }

        return $this->createQueryBuilder('w')
            ->andWhere('w.accountId = :acc')->setParameter('acc', $accountId)
            ->andWhere('w.kind IN (:kinds)')->setParameter('kinds', $kinds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Insert or overwrite the wrapped bundle copy for a (account, kind).
     * Flush is left to the caller so signup can batch several kinds.
     */
    public function upsert(string $accountId, string $kind, string $blob): WrappedAccountKey
    {
        $entity = $this->find(['accountId' => $accountId, 'kind' => $kind]);
        if ($entity === null) {
            $entity = new WrappedAccountKey();
            $entity->setAccountId($accountId)->setKind($kind);
            $this->getEntityManager()->persist($entity);
        }
        $entity->setBlob($blob);

        return $entity;
    }
}
