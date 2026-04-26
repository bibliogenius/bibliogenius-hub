<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\BorrowRequestRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the directory listing filters (ADR-035 Phase 2).
 *
 * Asserts that listDirectory forwards the city / country / search filters
 * to LibraryProfileRepository::findListed in the right slots, and clamps
 * the limit. Repository SQL behaviour itself is exercised by the existing
 * Doctrine tests on findListed.
 */
final class DirectoryServiceListFilterTest extends TestCase
{
    private LibraryProfileRepository $profileRepository;
    private DirectoryService $service;

    protected function setUp(): void
    {
        $this->profileRepository = $this->createMock(LibraryProfileRepository::class);

        $this->service = new DirectoryService(
            $this->createStub(EntityManagerInterface::class),
            $this->profileRepository,
            $this->createStub(FollowRepository::class),
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: sys_get_temp_dir(),
        );
    }

    public function testListDirectoryForwardsCityIdToRepository(): void
    {
        $this->profileRepository
            ->expects(self::once())
            ->method('findListed')
            ->with(20, 0, 'FR', null, 2988507)
            ->willReturn([]);

        $this->service->listDirectory(20, 0, 'FR', null, 2988507);
    }

    public function testListDirectoryWithoutCityIdPassesNull(): void
    {
        // ADR-035 Phase 2: callers that do not need the city filter must
        // still produce the same result as before Phase 2 - null is the
        // explicit "no filter" sentinel both in the controller and here.
        $this->profileRepository
            ->expects(self::once())
            ->method('findListed')
            ->with(50, 0, null, null, null)
            ->willReturn([]);

        $this->service->listDirectory(50, 0, null);
    }

    public function testListDirectoryCombinesCityIdWithSearch(): void
    {
        $this->profileRepository
            ->expects(self::once())
            ->method('findListed')
            ->with(50, 10, 'FR', 'voltaire', 2988507)
            ->willReturn([]);

        $this->service->listDirectory(50, 10, 'FR', 'voltaire', 2988507);
    }

    public function testListDirectoryClampsLimitTo100(): void
    {
        // Existing invariant from Phase 1: a malicious or buggy client
        // cannot ask for an unbounded page just by passing limit=99999.
        $this->profileRepository
            ->expects(self::once())
            ->method('findListed')
            ->with(100, 0, null, null, null)
            ->willReturn([]);

        $this->service->listDirectory(99999, 0, null);
    }
}
