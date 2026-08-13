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
