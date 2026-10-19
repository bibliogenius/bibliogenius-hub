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
    /**
     * A profile seen within this window is considered active and keeps its
     * cached catalog even past the row's own expires_at (see
     * pruneExpiredCatalogs).
     *
     * One year, deliberately: personal libraries legitimately go dormant for
     * months (a demo box, a device in a drawer), and dropping the catalog is
     * what makes them unreachable for every peer (2026-08 incident: a device
     * silent for 30 days lost its served catalog while its owner's other
     * devices still wanted it). The hub cannot rebuild a dropped row; only a
     * new client push can. Storage is bounded (2 MB per row).
     */
    private const OWNER_INACTIVITY_DAYS = 365;

    /**
     * How long a catalog without audience (and its covers) survives its last
     * accepted push. Clients push whatever their audience, so a library that
     * pairs or gets followed must still find its copy on the hub: this has to
     * outlast the longest gap between two real pushes of an active client,
     * just under 6 days (4-day app keep-alive plus the 2-day local skip,
     * CATALOG_LOCAL_SKIP_MAX_AGE_DAYS in
     * bibliogenius/src/services/hub_directory_service.rs). Same value as the
     * catalog TTL (CachedCatalog::TTL_DAYS), the contract that gap was sized
     * against; only libraries silent for over a week lose their copy.
     */
    public const UNSHARED_CATALOG_GRACE_DAYS = 7;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryProfile::class);
    }

    /**
     * SQL condition: someone may legitimately read the catalog of the library
     * whose node id is $nodeIdColumn. True when the profile is published, or
     * when any follows row involves it, in either direction and whatever its
     * status: a pending request or a pairing materialised as a follow
     * (ADR-053) is a relationship.
     *
     * $nodeIdColumn is a column reference written in code, never user input.
     */
    public static function catalogAudienceCondition(string $nodeIdColumn): string
    {
        return sprintf(
            '(EXISTS (SELECT 1 FROM library_profiles audience_lp
                       WHERE audience_lp.node_id = %1$s AND audience_lp.is_listed = true)
              OR EXISTS (SELECT 1 FROM follows audience_f
                          WHERE audience_f.follower_node_id = %1$s
                             OR audience_f.followed_node_id = %1$s))',
            $nodeIdColumn,
        );
    }

    public function findByNodeId(string $nodeId): ?LibraryProfile
    {
        return $this->find($nodeId);
    }

    public function findByWriteToken(string $writeToken): ?LibraryProfile
    {
        return $this->findOneBy(['writeToken' => $writeToken]);
    }

    /** @return LibraryProfile[] */
    public function findByDeviceFingerprint(string $fingerprint): array
    {
        return $this->findBy(['deviceFingerprint' => $fingerprint]);
    }

    /**
     * Returns listed libraries, ordered by last_seen_at descending.
     * Supports optional country / city / search filters and pagination.
     *
     * @return LibraryProfile[]
     */
    public function findListed(
        int $limit = 50,
        int $offset = 0,
        ?string $country = null,
        ?string $search = null,
        ?int $cityId = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isListed = :listed')
            ->andWhere('p.bookCount > 0')
            ->setParameter('listed', true)
            ->orderBy('p.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($country !== null) {
            $qb->andWhere('p.locationCountry = :country')
               ->setParameter('country', $country);
        }

        if ($cityId !== null) {
            // ADR-035 Phase 2: optional city filter, combinable with country
            // and search. The pair (country, city_id) is what the picker
            // surfaces to users so the filter mirrors the input shape.
            $qb->andWhere('p.locationCityId = :cityId')
               ->setParameter('cityId', $cityId);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(p.displayName) LIKE LOWER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Deletes stale profiles: 0 books, dormant for 7+ days, no relay mailbox,
     * and no social follows. Cascades to follows, catalogs, borrow_requests,
     * and the relay mailbox + messages.
     *
     * @return int Number of profiles deleted
     */
    public function purgeStaleProfiles(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        // Stale = empty AND dormant >= 7 days AND created >= 7 days ago AND no
        // mailbox AND no follows. The mailbox guard spares installs that
        // completed onboarding (auto-setup creates one on cold start) even if
        // the user has not added a book yet.
        $staleIds = $conn->fetchFirstColumn(
            "SELECT p.node_id FROM library_profiles p
             WHERE p.book_count = 0
               AND (p.last_seen_at IS NULL OR p.last_seen_at < NOW() - INTERVAL '7 days')
               AND p.created_at < NOW() - INTERVAL '7 days'
               AND p.relay_mailbox_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM follows f WHERE f.follower_node_id = p.node_id OR f.followed_node_id = p.node_id)"
        );

        if (empty($staleIds)) {
            return 0;
        }

        // Build safe placeholder list
        $placeholders = implode(',', array_fill(0, count($staleIds), '?'));

        // Collect relay mailbox UUIDs before deleting profiles
        $mailboxIds = $conn->fetchFirstColumn(
            "SELECT relay_mailbox_id FROM library_profiles
             WHERE node_id IN ($placeholders) AND relay_mailbox_id IS NOT NULL",
            $staleIds
        );

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

        // Clean up relay mailboxes and their messages
        if (!empty($mailboxIds)) {
            $mbPlaceholders = implode(',', array_fill(0, count($mailboxIds), '?'));
            $conn->executeStatement(
                "DELETE FROM relay_messages WHERE mailbox_uuid IN ($mbPlaceholders)",
                $mailboxIds
            );
            $conn->executeStatement(
                "DELETE FROM relay_mailboxes WHERE uuid IN ($mbPlaceholders)",
                $mailboxIds
            );
        }

        // Delete the profiles
        $deleted = $conn->executeStatement(
            "DELETE FROM library_profiles WHERE node_id IN ($placeholders)",
            $staleIds
        );

        return $deleted;
    }

    /**
     * Prunes expired cached_catalogs rows, but only when the owning profile
     * is itself inactive (last_seen_at older than the inactivity window,
     * NULL, or profile gone). Clients up to 1.0.x skip the catalog re-push
     * when their library is unchanged, so an active device can sit behind an
     * expired row forever; deleting it would break the directory catalog
     * fallback for every peer while the owner is still around. The catalog
     * of an active profile is kept past expires_at and refreshed by the next
     * real push. Called probabilistically on writes and by the nightly prune
     * command.
     */
    public function pruneExpiredCatalogs(\DateTimeImmutable $now): int
    {
        $activeCutoff = $now->modify(sprintf('-%d days', self::OWNER_INACTIVITY_DAYS));

        return $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'DELETE FROM cached_catalogs
                  WHERE expires_at < :now
                    AND NOT EXISTS (
                        SELECT 1 FROM library_profiles lp
                         WHERE lp.node_id = cached_catalogs.node_id
                           AND lp.last_seen_at >= :activeCutoff
                    )',
                [
                    'now' => $now->format('Y-m-d H:i:s'),
                    'activeCutoff' => $activeCutoff->format('Y-m-d H:i:s'),
                ]
            );
    }

    /**
     * Drops the cached catalogs nobody may read (see catalogAudienceCondition)
     * once their last accepted push is older than UNSHARED_CATALOG_GRACE_DAYS.
     * The catalog is stored in clear and only serves listed libraries and
     * follows, so keeping it otherwise holds personal data for no reader.
     * Clients push on every launch whatever their audience, so an active
     * library keeps a fresh copy; this reaches the dormant and abandoned ones.
     *
     * updated_at is refreshed by every accepted push, 304 included
     * (CachedCatalog::refresh and ::touchTtl).
     */
    public function pruneUnsharedCatalogs(\DateTimeImmutable $now): int
    {
        $graceCutoff = $now->modify(sprintf('-%d days', self::UNSHARED_CATALOG_GRACE_DAYS));

        return $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'DELETE FROM cached_catalogs
                  WHERE updated_at < :graceCutoff
                    AND NOT ' . self::catalogAudienceCondition('cached_catalogs.node_id'),
                ['graceCutoff' => $graceCutoff->format('Y-m-d H:i:s')]
            );
    }

    /**
     * Node ids whose cover directory must be kept: those with a cached catalog
     * row (any copy still within its grace period, see pruneUnsharedCatalogs) or a catalog
     * audience. Candidates are every node id the hub knows of, so a follow
     * toward a profile deleted since still protects its covers.
     *
     * @return list<string>
     */
    public function findNodeIdsKeepingCovers(): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchFirstColumn(
                'SELECT n.node_id
                   FROM (SELECT node_id FROM library_profiles
                         UNION SELECT follower_node_id FROM follows
                         UNION SELECT followed_node_id FROM follows
                         UNION SELECT node_id FROM cached_catalogs) n
                  WHERE EXISTS (SELECT 1 FROM cached_catalogs kept_c WHERE kept_c.node_id = n.node_id)
                     OR ' . self::catalogAudienceCondition('n.node_id')
            );
    }
}
