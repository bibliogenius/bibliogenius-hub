<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RelayMailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelayMailbox>
 */
class RelayMailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelayMailbox::class);
    }

    public function findByUuid(string $uuid): ?RelayMailbox
    {
        return $this->find($uuid);
    }

    /**
     * Count mailboxes not referenced by any library profile.
     */
    public function countOrphans(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM relay_mailboxes rm
             WHERE NOT EXISTS (
                 SELECT 1 FROM library_profiles lp
                 WHERE lp.relay_mailbox_id = rm.uuid
             )"
        );
    }

    /**
     * Count total messages sitting in orphan mailboxes.
     */
    public function countOrphanMessages(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM relay_messages msg
             WHERE NOT EXISTS (
                 SELECT 1 FROM library_profiles lp
                 WHERE lp.relay_mailbox_id = msg.mailbox_uuid
             )"
        );
    }

    /**
     * Delete orphan mailboxes (not referenced by any profile) and their messages.
     *
     * @return array{mailboxes: int, messages: int}
     */
    public function purgeOrphans(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Delete messages in orphan mailboxes first
        $messages = $conn->executeStatement(
            "DELETE FROM relay_messages WHERE mailbox_uuid IN (
                 SELECT rm.uuid FROM relay_mailboxes rm
                 WHERE NOT EXISTS (
                     SELECT 1 FROM library_profiles lp
                     WHERE lp.relay_mailbox_id = rm.uuid
                 )
             )"
        );

        // Then delete the orphan mailboxes
        $mailboxes = $conn->executeStatement(
            "DELETE FROM relay_mailboxes WHERE NOT EXISTS (
                 SELECT 1 FROM library_profiles lp
                 WHERE lp.relay_mailbox_id = relay_mailboxes.uuid
             )"
        );

        return ['mailboxes' => $mailboxes, 'messages' => $messages];
    }

    /**
     * Delete a specific mailbox and its messages by UUID.
     */
    public function deleteWithMessages(string $uuid): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('DELETE FROM relay_messages WHERE mailbox_uuid = ?', [$uuid]);
        $conn->executeStatement('DELETE FROM relay_mailboxes WHERE uuid = ?', [$uuid]);
    }
}
