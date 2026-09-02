<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Follow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Follow>
 */
class FollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Follow::class);
    }

    public function findExisting(string $followerNodeId, string $followedNodeId): ?Follow
    {
        return $this->findOneBy([
            'followerNodeId' => $followerNodeId,
            'followedNodeId' => $followedNodeId,
        ]);
    }

    /**
     * Pending incoming follow requests for a given library (it must approve or reject them).
     *
     * @return Follow[]
     */
    public function findPendingFor(string $followedNodeId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.followedNodeId = :followed')
            ->andWhere('f.status = :status')
            ->setParameter('followed', $followedNodeId)
            ->setParameter('status', Follow::STATUS_PENDING)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Libraries that the given node is following, including pending requests.
     *
     * Pending follows are returned so the requester's UI can reflect that
     * a request was sent (e.g. show "awaiting approval" instead of the
     * regular Follow button). Rejected and blocked entries are filtered out
     * because the requester has no actionable state there.
     *
     * @return Follow[]
     */
    public function findFollowing(string $followerNodeId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.followerNodeId = :follower')
            ->andWhere('f.status IN (:statuses)')
            ->setParameter('follower', $followerNodeId)
            ->setParameter('statuses', [Follow::STATUS_PENDING, Follow::STATUS_ACTIVE])
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active libraries that follow the given node.
     *
     * @return Follow[]
     */
    public function findActiveFollowers(string $followedNodeId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.followedNodeId = :followed')
            ->andWhere('f.status = :status')
            ->setParameter('followed', $followedNodeId)
            ->setParameter('status', Follow::STATUS_ACTIVE)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function isActiveFollower(string $followerNodeId, string $followedNodeId): bool
    {
        return $this->findOneBy([
            'followerNodeId' => $followerNodeId,
            'followedNodeId' => $followedNodeId,
            'status'         => Follow::STATUS_ACTIVE,
        ]) !== null;
    }

    /**
     * Network-shape counters for the /admin dashboard.
     *
     * A follows row is directed, so counting rows answers "how many follow
     * gestures happened", never "how many libraries are connected to each
     * other". A hundred rows can be a hundred readers following one showcase
     * library with no mutual relationship anywhere. Reciprocity is the figure
     * an exchange, group or peer-suggestion feature actually rests on, so it
     * gets its own count instead of being inferred from the total.
     *
     * Hub follows are not the whole truth: a direct P2P pairing is device
     * local by design (ADR-044) and never reaches the hub. These counters are
     * a floor for the real relationship graph, not a census of it.
     *
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     reciprocal_pairs: int,
     *     libraries_with_active_edge: int
     * }
     */
    public function relationshipStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $byStatus = [
            Follow::STATUS_PENDING  => 0,
            Follow::STATUS_ACTIVE   => 0,
            Follow::STATUS_REJECTED => 0,
            Follow::STATUS_BLOCKED  => 0,
        ];
        foreach ($conn->fetchAllAssociative('SELECT status, COUNT(*) AS count FROM follows GROUP BY status') as $row) {
            $byStatus[(string) $row['status']] = (int) $row['count'];
        }

        // Each mutual pair is counted once by keeping only the edge whose
        // follower sorts before its followed. The unique_follow constraint
        // already rules out a duplicate edge, so a COUNT(*) / 2 would agree
        // here; the ordering guard is preferred because nothing forbids a node
        // following itself, which the join would match against itself and the
        // division would then round away silently.
        $reciprocalPairs = (int) $conn->fetchOne(
            'SELECT COUNT(*)
             FROM follows a
             JOIN follows b
               ON a.follower_node_id = b.followed_node_id
              AND a.followed_node_id = b.follower_node_id
             WHERE a.status = ?
               AND b.status = ?
               AND a.follower_node_id < a.followed_node_id',
            [Follow::STATUS_ACTIVE, Follow::STATUS_ACTIVE],
        );

        // How many distinct libraries appear in at least one active edge. Read
        // next to the pair count it separates a hub-and-spoke shape (many
        // libraries, no pairs) from a connected one.
        $librariesWithActiveEdge = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM (
                 SELECT follower_node_id AS node_id FROM follows WHERE status = ?
                 UNION
                 SELECT followed_node_id FROM follows WHERE status = ?
             ) AS involved',
            [Follow::STATUS_ACTIVE, Follow::STATUS_ACTIVE],
        );

        return [
            'total'                      => array_sum($byStatus),
            'by_status'                  => $byStatus,
            'reciprocal_pairs'           => $reciprocalPairs,
            'libraries_with_active_edge' => $librariesWithActiveEdge,
        ];
    }

    /**
     * Every follow edge touching a node, in either direction and whatever the
     * status. Unlike findFollowing() and findActiveFollowers(), which serve the
     * UI and therefore hide rejected and blocked rows, this is for diagnosis:
     * a rejection is often the answer to "why does this peer see nothing".
     *
     * @return Follow[]
     */
    public function findAllInvolving(string $nodeId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.followerNodeId = :node OR f.followedNodeId = :node')
            ->setParameter('node', $nodeId)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
