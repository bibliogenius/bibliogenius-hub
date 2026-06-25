<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountAuthChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * One-time, short-lived auth nonces (ADR-043). Issued per login/keybundle
 * attempt, consumed atomically on verification, GC'd on expiry.
 *
 * @extends ServiceEntityRepository<AccountAuthChallenge>
 */
class AccountAuthChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountAuthChallenge::class);
    }

    public function issue(string $accountId, string $purpose, string $challenge, \DateTimeImmutable $expiresAt): void
    {
        $entity = new AccountAuthChallenge();
        $entity->setAccountId($accountId)
            ->setPurpose($purpose)
            ->setChallenge($challenge)
            ->setExpiresAt($expiresAt);

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    /**
     * Atomically consume a matching, unexpired challenge. Returns true exactly
     * once per issued nonce (DELETE ... RETURNING is the single-statement guard
     * against replay and concurrent reuse).
     */
    public function consume(string $accountId, string $purpose, string $challenge): bool
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'DELETE FROM account_auth_challenges
             WHERE account_id = :a AND purpose = :p AND challenge = :c AND expires_at > NOW()
             RETURNING id',
            ['a' => $accountId, 'p' => $purpose, 'c' => $challenge],
        );

        return $id !== false;
    }

    /** Best-effort GC of expired nonces. */
    public function gcExpired(): void
    {
        try {
            $this->getEntityManager()->getConnection()->executeStatement(
                'DELETE FROM account_auth_challenges WHERE expires_at < NOW()',
            );
        } catch (\Throwable) {
            // Best-effort
        }
    }
}
