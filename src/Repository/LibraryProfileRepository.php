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
    public function findListed(int $limit = 50, int $offset = 0, ?string $country = null, ?string $search = null): array
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

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(p.displayName) LIKE LOWER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Deletes stale profiles: book_count = 0 AND last_seen_at IS NULL.
     * Also cleans up associated follows and cached catalogs.
     *
     * @return int Number of profiles deleted
     */
    public function purgeStaleProfiles(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        // Find stale node IDs: 0 books, never updated, older than 24h,
        // AND no follow relationships (neither following nor followed by anyone).
        // This guarantees zero user impact.
        $staleIds = $conn->fetchFirstColumn(
            "SELECT p.node_id FROM library_profiles p
             WHERE p.book_count = 0
               AND p.last_seen_at IS NULL
               AND p.created_at < NOW() - INTERVAL '24 hours'
               AND NOT EXISTS (SELECT 1 FROM follows f WHERE f.follower_node_id = p.node_id OR f.followed_node_id = p.node_id)"
        );

        if (empty($staleIds)) {
            return 0;
        }

        // Build safe placeholder list
        $placeholders = implode(',', array_fill(0, count($staleIds), '?'));

        // Clean up related data first (follows, catalogs, borrow_requests)
        $conn->executeStatement(
            "DELETE FROM follows WHERE follower_node_id IN ($placeholders) OR followed_node_id IN ($placeholders)",
            array_merge($staleIds, $staleIds)
        );
        $conn->executeStatement(
            "DELETE FROM cached_catalogs WHERE node_id IN ($placeholders)",
            $staleIds
        );
        $conn->executeStatement(
            "DELETE FROM borrow_requests WHERE requester_node_id IN ($placeholders) OR lender_node_id IN ($placeholders)",
            array_merge($staleIds, $staleIds)
        );

        // Delete the profiles
        $deleted = $conn->executeStatement(
            "DELETE FROM library_profiles WHERE node_id IN ($placeholders)",
            $staleIds
        );

        return $deleted;
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
