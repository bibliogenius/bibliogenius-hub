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
     * Active libraries that the given node is following.
     *
     * @return Follow[]
     */
    public function findActiveFollowing(string $followerNodeId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.followerNodeId = :follower')
            ->andWhere('f.status = :status')
            ->setParameter('follower', $followerNodeId)
            ->setParameter('status', Follow::STATUS_ACTIVE)
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
}
