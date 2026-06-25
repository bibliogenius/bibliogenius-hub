<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findOneByEmail(string $email): ?Account
    {
        return $this->findOneBy(['email' => strtolower($email)]);
    }

    /**
     * RGPD account deletion (ADR-043 section 9 / ADR-042 L4). A raw DELETE on
     * accounts relies on the DB-level ON DELETE CASCADE to purge every child
     * table (lanes, wrapped keys, registry, challenges) atomically, so no
     * plaintext, wrapped key, or registry blob survives.
     */
    public function purgeAccount(string $accountId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM accounts WHERE account_id = :a',
            ['a' => $accountId],
        );
    }
}
