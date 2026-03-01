<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LibraryProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LibraryProfile>
 */
class LibraryProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryProfile::class);
    }

    public function findByNodeId(string $nodeId): ?LibraryProfile
    {
        return $this->find($nodeId);
    }

    public function findByWriteToken(string $writeToken): ?LibraryProfile
    {
        return $this->findOneBy(['writeToken' => $writeToken]);
    }

    /**
     * Returns listed libraries, ordered by last_seen_at descending.
     * Supports optional country filter and pagination.
     *
     * @return LibraryProfile[]
     */
    public function findListed(int $limit = 50, int $offset = 0, ?string $country = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isListed = :listed')
            ->setParameter('listed', true)
            ->orderBy('p.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($country !== null) {
            $qb->andWhere('p.locationCountry = :country')
               ->setParameter('country', $country);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Prunes expired cached_catalogs rows. Called probabilistically on writes
     * to avoid a dedicated cron dependency on the hub.
     */
    public function pruneExpiredCatalogs(\DateTimeImmutable $now): int
    {
        return $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'DELETE FROM cached_catalogs WHERE expires_at < :now',
                ['now' => $now->format('Y-m-d H:i:s')]
            );
    }
}
