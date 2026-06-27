<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The blind lane store (ADR-043). Push is a blind overwrite-in-place keyed by
 * (account_id, opaque_id, device_id); pull is a single-cursor delta over a
 * per-account monotonic change_seq. The hub never inspects blob contents.
 *
 * Push/pull go through the ORM so BYTEA binding is handled the same proven way
 * as the relay (RelayMessage). change_seq values come from the Postgres
 * sequence in one block; delete/GC/quota use raw DBAL (no blob binding).
 *
 * @extends ServiceEntityRepository<AccountEntity>
 */
class AccountEntityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountEntity::class);
    }

    /**
     * Blind overwrite-in-place of a batch of the caller's own lanes.
     *
     * @param list<array{opaque_id: string, deleted: bool, size_bucket: int, blob: ?string}> $lanes
     *
     * @return int the highest change_seq assigned in this batch (0 if empty)
     */
    public function pushLanes(string $accountId, string $deviceId, array $lanes): int
    {
        if ($lanes === []) {
            return 0;
        }

        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        // One round-trip for all order values: the per-account-global monotonic
        // sequence is the only ordering signal the hub exposes (HLC stays in the
        // ciphertext). Gaps are irrelevant to a cursor.
        $seqs = $conn->fetchFirstColumn(
            "SELECT nextval('account_entities_change_seq') FROM generate_series(1, :n)",
            ['n' => count($lanes)],
            ['n' => ParameterType::INTEGER],
        );

        $now = new \DateTimeImmutable();
        $high = 0;

        foreach (array_values($lanes) as $i => $lane) {
            $seq = (int) $seqs[$i];
            $high = max($high, $seq);

            $entity = $this->find([
                'accountId' => $accountId,
                'opaqueId' => $lane['opaque_id'],
                'deviceId' => $deviceId,
            ]);
            if ($entity === null) {
                $entity = new AccountEntity();
                $entity->setAccountId($accountId)
                    ->setOpaqueId($lane['opaque_id'])
                    ->setDeviceId($deviceId);
                $em->persist($entity);
            }

            $entity->setChangeSeq($seq)
                ->setDeleted($lane['deleted'])
                ->setSizeBucket($lane['size_bucket'])
                ->setBlob($lane['blob'])
                ->setReceivedAt($now)
                ->setTombstonedAt($lane['deleted'] ? $now : null);
        }

        $em->flush();

        return $high;
    }

    /**
     * Delta pull: lanes of OTHER devices changed after the cursor, tombstones
     * included. cursor=0 returns the full state (latest blob per lane, no
     * history). Bounded by $limit; the caller pages on the returned cursor.
     *
     * @return AccountEntity[]
     */
    public function pullSince(string $accountId, string $excludeDeviceId, int $cursor, int $limit): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.accountId = :acc')->setParameter('acc', $accountId)
            ->andWhere('e.changeSeq > :cur')->setParameter('cur', $cursor)
            ->andWhere('e.deviceId <> :dev')->setParameter('dev', $excludeDeviceId)
            ->orderBy('e.changeSeq', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Client-driven orphan-lane GC: delete every lane of one device under the
     * account (issued after the device is removed from the signed registry).
     * The hub executes blindly; it never reads the registry (H3).
     */
    public function deleteByDevice(string $accountId, string $deviceId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM account_entities WHERE account_id = :a AND device_id = :d',
            ['a' => $accountId, 'd' => $deviceId],
        );
    }

    /**
     * Recompute the account's stored-bytes quota counter from the live blobs.
     * Accurate (one aggregate query) rather than a drifting running total; the
     * quota is a hook reserved for future quota enforcement, not enforced here.
     */
    public function recomputeQuotaBytes(string $accountId): int
    {
        $bytes = (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(LENGTH(blob)), 0) FROM account_entities WHERE account_id = :a',
            ['a' => $accountId],
        );

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE accounts SET quota_bytes_used = :b, updated_at = NOW() WHERE account_id = :a',
            ['b' => $bytes, 'a' => $accountId],
            ['b' => ParameterType::INTEGER],
        );

        return $bytes;
    }

    /**
     * Tombstone retention GC (ADR-033 pattern). Blobs of old tombstones are
     * purged first (in-flight readers keep a short window), then very old
     * tombstone rows are hard-deleted to bound storage. Integer day counts are
     * the only interpolated values - no user input. Best-effort.
     */
    public function gcTombstones(int $blobTtlDays, int $rowTtlDays): void
    {
        $conn = $this->getEntityManager()->getConnection();

        try {
            $conn->executeStatement(sprintf(
                "UPDATE account_entities SET blob = NULL WHERE deleted = TRUE AND blob IS NOT NULL AND tombstoned_at < NOW() - INTERVAL '%d days'",
                $blobTtlDays,
            ));
            $conn->executeStatement(sprintf(
                "DELETE FROM account_entities WHERE deleted = TRUE AND tombstoned_at < NOW() - INTERVAL '%d days'",
                $rowTtlDays,
            ));
        } catch (\Throwable) {
            // Best-effort, like the relay cleanup.
        }
    }
}
