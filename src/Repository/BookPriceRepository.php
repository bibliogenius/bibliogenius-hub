<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookPrice>
 */
class BookPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookPrice::class);
    }

    public function findByIsbn(string $isbn): ?BookPrice
    {
        return $this->find($isbn);
    }

    /**
     * @param string[] $isbns
     * @return BookPrice[]
     */
    public function findByIsbns(array $isbns): array
    {
        if (empty($isbns)) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->where('p.isbn IN (:isbns)')
            ->setParameter('isbns', $isbns)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes entries not updated in the given number of days.
     */
    public function pruneStale(int $days = 90): int
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d days', $days));

        return $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'DELETE FROM book_prices WHERE updated_at < :threshold',
                ['threshold' => $threshold->format('Y-m-d H:i:s')]
            );
    }
}
