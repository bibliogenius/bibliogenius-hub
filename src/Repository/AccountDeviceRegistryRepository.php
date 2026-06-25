<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountDeviceRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountDeviceRegistry>
 */
class AccountDeviceRegistryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountDeviceRegistry::class);
    }

    public function findOneByAccount(string $accountId): ?AccountDeviceRegistry
    {
        return $this->find($accountId);
    }

    /**
     * Store a new signed+encrypted registry blob and bump the server sequence
     * so the newest is served. The hub never parses the blob (H3). Flush left
     * to the caller. Returns the entity with its new registry_seq.
     */
    public function publish(string $accountId, string $blob): AccountDeviceRegistry
    {
        $entity = $this->find($accountId);
        if ($entity === null) {
            $entity = new AccountDeviceRegistry();
            $entity->setAccountId($accountId);
            $this->getEntityManager()->persist($entity);
        }
        $entity->setRegistrySeq($entity->getRegistrySeq() + 1)->setBlob($blob);

        return $entity;
    }
}
