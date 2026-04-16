<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RelayMailboxRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the atomic delete path added after a prod 500
 * was traced to RelayController calling a non-existent deleteByUuid()
 * on this repository. Guarantees that deleteWithMessages() exists and
 * wraps the two DELETEs in a transaction that rolls back on failure.
 */
final class RelayMailboxRepositoryDeleteTest extends TestCase
{
    private const UUID = '5048d99b-cd0d-4fea-9fb0-6e5db1e9e848';

    public function testDeleteWithMessagesCommitsOnSuccess(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturn(1);
        $conn->expects($this->once())->method('commit');
        $conn->expects($this->never())->method('rollBack');

        $this->buildRepository($conn)->deleteWithMessages(self::UUID);
    }

    public function testDeleteWithMessagesRollsBackWhenSecondStatementFails(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(
                1,
                $this->throwException(new \RuntimeException('FK violation')),
            );
        $conn->expects($this->never())->method('commit');
        $conn->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->buildRepository($conn)->deleteWithMessages(self::UUID);
    }

    /**
     * Callers rely on this exact method name. The prod 500 happened
     * because the controller called a name that did not exist; this
     * freezes the public contract.
     */
    public function testDeleteWithMessagesMethodExists(): void
    {
        $this->assertTrue(
            method_exists(RelayMailboxRepository::class, 'deleteWithMessages'),
            'RelayController::deleteMailbox depends on this method name',
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
