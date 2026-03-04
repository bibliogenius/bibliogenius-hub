<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BorrowRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BorrowRequest>
 */
class BorrowRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BorrowRequest::class);
    }

    /**
     * Pending borrow requests for a given lender (not expired).
     *
     * @return BorrowRequest[]
     */
    public function findPendingForLender(string $lenderNodeId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.lenderNodeId = :lender')
            ->andWhere('b.status = :status')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('lender', $lenderNodeId)
            ->setParameter('status', BorrowRequest::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All borrow requests sent by a given requester (most recent first, capped at 50).
     *
     * @return BorrowRequest[]
     */
    public function findByRequester(string $requesterNodeId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.requesterNodeId = :requester')
            ->setParameter('requester', $requesterNodeId)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /**
     * Checks if a pending (non-expired) request already exists for this requester+lender+isbn.
     */
    public function findPendingDuplicate(string $requesterNodeId, string $lenderNodeId, string $isbn): ?BorrowRequest
    {
        return $this->createQueryBuilder('b')
            ->where('b.requesterNodeId = :requester')
            ->andWhere('b.lenderNodeId = :lender')
            ->andWhere('b.isbn = :isbn')
            ->andWhere('b.status = :status')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('requester', $requesterNodeId)
            ->setParameter('lender', $lenderNodeId)
            ->setParameter('isbn', $isbn)
            ->setParameter('status', BorrowRequest::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Prunes expired borrow requests.
     *
     * @return int Number of deleted rows
     */
    public function pruneExpired(): int
    {
        return $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'DELETE FROM borrow_requests WHERE expires_at < :now',
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
            );
    }
}
