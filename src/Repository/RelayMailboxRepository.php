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
     * The mailbox a node owns, which is not necessarily the one its profile
     * advertises: a mismatch between the two is the shape of the orphan and
     * shared mailbox anomalies the dashboard counts.
     */
    public function findByOwnerNodeId(string $nodeId): ?RelayMailbox
    {
        return $this->findOneBy(['ownerNodeId' => $nodeId]);
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
     * Count profiles referencing a mailbox UUID that no longer exists in
     * relay_mailboxes. Surfaced on the dashboard so admins can detect
     * broken references (stale local state, pruned mailbox, or manual
     * deletion) before peers accumulate "deposit to non-existent mailbox"
     * warnings.
     */
    public function countProfilesWithOrphanMailbox(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM library_profiles lp
             WHERE lp.relay_mailbox_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM relay_mailboxes rm
                   WHERE rm.uuid = lp.relay_mailbox_id
               )"
        );
    }

    /**
     * Return the profiles behind countProfilesWithOrphanMailbox(), with the
     * columns an admin needs to act: which profile (node_id, display_name),
     * which mailbox UUID is gone (relay_mailbox_id), and where to find it
     * (relay_url, last_seen_at, app_version). Powers the dashboard drill-down
     * so the orphan count is identifiable instead of an opaque number.
     *
     * @return array<array{node_id: string, display_name: string, relay_mailbox_id: string, relay_url: ?string, app_version: ?string, last_seen_at: ?string}>
     */
    public function findProfilesWithOrphanMailbox(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT lp.node_id, lp.display_name, lp.relay_mailbox_id,
                    lp.relay_url, lp.app_version, lp.last_seen_at
             FROM library_profiles lp
             WHERE lp.relay_mailbox_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM relay_mailboxes rm
                   WHERE rm.uuid = lp.relay_mailbox_id
               )
             ORDER BY lp.last_seen_at DESC NULLS LAST, lp.node_id ASC"
        );
    }

    /**
     * Return mailbox UUIDs referenced by more than one profile. This is a
     * hijack signal: under the current model, each profile should own its
     * own mailbox. A shared UUID indicates either a data migration artefact
     * or an attempt by a profile to redirect traffic to a mailbox it does
     * not own (OWASP A01).
     *
     * @return array<array{relay_mailbox_id: string, profile_count: int}>
     */
    public function findProfilesWithSharedMailbox(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT relay_mailbox_id, COUNT(*) AS profile_count
             FROM library_profiles
             WHERE relay_mailbox_id IS NOT NULL
             GROUP BY relay_mailbox_id
             HAVING COUNT(*) > 1
             ORDER BY profile_count DESC, relay_mailbox_id ASC"
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
     * Null out library_profiles.relay_mailbox_id references whose mailbox no
     * longer exists. The reference is soft (no FK), so the 90-day mailbox TTL
     * prune leaves it dangling: the profile then counts as an "orphan mailbox
     * reference" on the dashboard and, worse, dodges purgeStaleProfiles, whose
     * stale-profile guard requires relay_mailbox_id IS NULL. Clearing the
     * reference is safe for active clients: they recreate the mailbox on
     * collect 404 and republish the new UUID at the next keep-alive.
     *
     * @return int number of profiles whose dangling reference was cleared
     */
    public function clearDanglingProfileReferences(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->executeStatement(
            "UPDATE library_profiles SET relay_mailbox_id = NULL
             WHERE relay_mailbox_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM relay_mailboxes rm
                   WHERE rm.uuid = library_profiles.relay_mailbox_id
               )"
        );
    }

    /**
     * Delete a specific mailbox and its messages by UUID.
     */
    public function deleteWithMessages(string $uuid): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement('DELETE FROM relay_messages WHERE mailbox_uuid = ?', [$uuid]);
            $conn->executeStatement('DELETE FROM relay_mailboxes WHERE uuid = ?', [$uuid]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}
