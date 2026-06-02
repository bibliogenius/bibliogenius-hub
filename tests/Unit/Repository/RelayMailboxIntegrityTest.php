<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RelayMailboxRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the data-integrity counters surfaced on the hub
 * dashboard. These are regression guards: the SQL shape is frozen
 * because the counters feed an admin alert. A silent change of WHERE
 * clause would mask orphan references or hijack signals.
 */
final class RelayMailboxIntegrityTest extends TestCase
{
    public function testCountProfilesWithOrphanMailboxReturnsIntFromFetchOne(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with($this->callback(function (string $sql): bool {
                // The query must constrain to profiles that reference
                // a mailbox not present in relay_mailboxes.
                return str_contains($sql, 'library_profiles')
                    && str_contains($sql, 'relay_mailbox_id IS NOT NULL')
                    && str_contains($sql, 'NOT EXISTS')
                    && str_contains($sql, 'relay_mailboxes');
            }))
            ->willReturn('3');

        $result = $this->buildRepository($conn)->countProfilesWithOrphanMailbox();

        $this->assertSame(3, $result);
    }

    public function testCountProfilesWithOrphanMailboxReturnsZeroWhenNone(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('0');

        $this->assertSame(0, $this->buildRepository($conn)->countProfilesWithOrphanMailbox());
    }

    public function testFindProfilesWithSharedMailboxReturnsGroupsWithMoreThanOne(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->callback(function (string $sql): bool {
                // HAVING COUNT(*) > 1 is the signal for "same mailbox
                // referenced by >1 profile" (hijack attempt indicator).
                return str_contains($sql, 'GROUP BY')
                    && str_contains($sql, 'relay_mailbox_id')
                    && str_contains($sql, 'HAVING')
                    && str_contains($sql, '> 1');
            }))
            ->willReturn([
                ['relay_mailbox_id' => 'uuid-a', 'profile_count' => 2],
            ]);

        $result = $this->buildRepository($conn)->findProfilesWithSharedMailbox();

        $this->assertCount(1, $result);
        $this->assertSame('uuid-a', $result[0]['relay_mailbox_id']);
        $this->assertSame(2, (int) $result[0]['profile_count']);
    }

    public function testFindProfilesWithSharedMailboxReturnsEmptyWhenNoneShared(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);

        $this->assertSame([], $this->buildRepository($conn)->findProfilesWithSharedMailbox());
    }

    public function testFindProfilesWithOrphanMailboxSelectsIdentifyingColumns(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->callback(function (string $sql): bool {
                // Must select the columns that let an admin identify the
                // exact profile and the gone mailbox UUID, scoped to the
                // same orphan condition as the counter.
                return str_contains($sql, 'node_id')
                    && str_contains($sql, 'display_name')
                    && str_contains($sql, 'relay_mailbox_id')
                    && str_contains($sql, 'relay_mailbox_id IS NOT NULL')
                    && str_contains($sql, 'NOT EXISTS')
                    && str_contains($sql, 'relay_mailboxes');
            }))
            ->willReturn([
                [
                    'node_id' => 'node-a',
                    'display_name' => 'Alice',
                    'relay_mailbox_id' => 'gone-uuid',
                    'relay_url' => 'https://hub.example',
                    'app_version' => '0.9.0',
                    'last_seen_at' => '2026-06-01 12:00:00',
                ],
            ]);

        $result = $this->buildRepository($conn)->findProfilesWithOrphanMailbox();

        $this->assertCount(1, $result);
        $this->assertSame('gone-uuid', $result[0]['relay_mailbox_id']);
        $this->assertSame('Alice', $result[0]['display_name']);
    }

    public function testFindProfilesWithOrphanMailboxReturnsEmptyWhenNone(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);

        $this->assertSame([], $this->buildRepository($conn)->findProfilesWithOrphanMailbox());
    }

    /**
     * Callers (DashboardController) depend on these exact method names.
     * If either is renamed, the dashboard stats silently break.
     */
    public function testIntegrityMethodsExist(): void
    {
        $this->assertTrue(
            method_exists(RelayMailboxRepository::class, 'countProfilesWithOrphanMailbox'),
            'DashboardController depends on this method name',
        );
        $this->assertTrue(
            method_exists(RelayMailboxRepository::class, 'findProfilesWithOrphanMailbox'),
            'DashboardController depends on this method name',
        );
        $this->assertTrue(
            method_exists(RelayMailboxRepository::class, 'findProfilesWithSharedMailbox'),
            'DashboardController depends on this method name',
        );
    }

    private function buildRepository(Connection $conn): RelayMailboxRepository
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $repository = $this->getMockBuilder(RelayMailboxRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityManager'])
            ->getMock();
        $repository->method('getEntityManager')->willReturn($em);

        return $repository;
    }
}
