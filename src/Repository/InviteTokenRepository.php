<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InviteToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InviteToken>
 */
class InviteTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InviteToken::class);
    }

    public function findByToken(string $token): ?InviteToken
    {
        return $this->find($token);
    }

    /**
     * Delete tokens older than the given TTL.
     *
     * @return int Number of deleted rows
     */
    public function deleteExpired(int $ttlDays): int
    {
        $conn = $this->getEntityManager()->getConnection();

        // Constants only (no user input) - safe to interpolate.
        // SQLite datetime() modifiers don't work with bound parameters.
        return (int) $conn->executeStatement(
            sprintf("DELETE FROM invite_tokens WHERE created_at < datetime('now', '-%d days')", $ttlDays),
        );
    }
}
