<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RelayMessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for RelayMessageRepository::deleteOldest(), the FIFO
 * eviction used by the deposit path when a mailbox reaches its cap.
 *
 * Prod 500 (2026-06-18): every deposit to a capped mailbox crashed with
 * a Doctrine DBAL 4 TypeError because the LIMIT parameter type was passed
 * as the raw \PDO::PARAM_INT int (1) instead of a ParameterType enum.
 * ExpandArrayParameters::appendTypedParameter() requires
 * string|ParameterType|Type, so the int blew up the whole request.
 * These tests freeze the type contract so it cannot regress to a raw int.
 */
// The repository is a partial double: only getEntityManager() is doubled so
// the real deleteOldest() runs. PHPUnit 12.5 would otherwise flag it (and the
// EntityManager stub) as a mock without expectations; this opts that check out.
#[AllowMockObjectsWithoutExpectations]
final class RelayMessageRepositoryDeleteOldestTest extends TestCase
{
    private const UUID = '20dae332-6948-4bb4-b9ca-4573b1c182c2';

    public function testDeleteOldestPassesParameterTypeEnumNotRawInt(): void
    {
        $capturedTypes = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$capturedTypes): int {
                $capturedTypes = $types;

                return 3;
            });

        $deleted = $this->buildRepository($conn)->deleteOldest(self::UUID, 3);

        self::assertSame(3, $deleted);
        self::assertArrayHasKey('limit', $capturedTypes);
        self::assertInstanceOf(
            ParameterType::class,
            $capturedTypes['limit'],
            'LIMIT type must be a ParameterType enum; a raw \PDO::PARAM_* int crashes DBAL 4',
        );
        self::assertSame(ParameterType::INTEGER, $capturedTypes['limit']);
    }

    public function testDeleteOldestShortCircuitsOnNonPositiveCount(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method('executeStatement');

        self::assertSame(0, $this->buildRepository($conn)->deleteOldest(self::UUID, 0));
        self::assertSame(0, $this->buildRepository($conn)->deleteOldest(self::UUID, -5));
    }

    private function buildRepository(Connection $conn): RelayMessageRepository
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $repository = $this->getMockBuilder(RelayMessageRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityManager'])
            ->getMock();
        $repository->method('getEntityManager')->willReturn($em);

        return $repository;
    }
}
