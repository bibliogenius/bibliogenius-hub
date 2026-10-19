<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\BorrowRequestRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Cover directories of libraries nobody may read (no catalog row left, no
 * listing, no follow) are deleted by the nightly prune. Uploaded covers are
 * often photos taken by the owner and are served without authentication,
 * so they must not outlive the catalog they illustrate.
 *
 * The safety rails matter more than the deletion: an active library must
 * never lose its covers, an upload racing the prune must survive, and a
 * suspicious empty keep-list must delete nothing.
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceUnsharedCoversTest extends TestCase
{
    private const NOW = '2026-10-19 03:00:00';

    /** Parent of the covers directory, what a node id ".." would reach. */
    private string $baseDir;
    private string $coversDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/bg_unshared_covers_' . uniqid('', true);
        $this->coversDir = $this->baseDir . '/covers';
        mkdir($this->coversDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
    }

    public function testDeletesOnlyStaleDirectoriesOfNodesWithoutCatalogOrAudience(): void
    {
        $old = strtotime('2026-10-01 00:00:00');
        $kept = $this->putNodeDir('kept-node', $old);
        $stale = $this->putNodeDir('stale-node', $old);
        $recent = $this->putNodeDir('recent-node', strtotime('2026-10-18 22:00:00'));

        $service = $this->buildService(['kept-node']);
        $deleted = $service->pruneUnsharedCovers(new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $deleted);
        self::assertDirectoryExists($kept, 'a node with a catalog or an audience keeps its covers');
        self::assertDirectoryDoesNotExist($stale);
        self::assertDirectoryExists(
            $recent,
            'covers uploaded within the grace period survive: the catalog push follows them',
        );
    }

    public function testARecentlyRewrittenCoverProtectsItsDirectory(): void
    {
        $old = strtotime('2026-10-01 00:00:00');
        $dir = $this->putNodeDir('rewritten-node', $old);
        // Overwriting an existing file does not touch the directory mtime.
        touch($dir . '/book-1.jpg', strtotime('2026-10-18 23:00:00'));
        touch($dir, $old);

        $deleted = $this->buildService(['other-node'])
            ->pruneUnsharedCovers(new \DateTimeImmutable(self::NOW));

        self::assertSame(0, $deleted);
        self::assertDirectoryExists($dir);
    }

    public function testAnEmptyKeepListDeletesNothing(): void
    {
        // Directories on disk while the hub knows of no node at all means a
        // wrong or empty database, not a fleet without readers.
        $dir = $this->putNodeDir('any-node', strtotime('2026-10-01 00:00:00'));

        $deleted = $this->buildService([])->pruneUnsharedCovers(new \DateTimeImmutable(self::NOW));

        self::assertSame(0, $deleted);
        self::assertDirectoryExists($dir);
    }

    public function testAFailingKeepListQueryDeletesNothing(): void
    {
        $dir = $this->putNodeDir('any-node', strtotime('2026-10-01 00:00:00'));

        $repository = $this->createStub(LibraryProfileRepository::class);
        $repository->method('findNodeIdsKeepingCovers')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        try {
            $this->buildServiceWith($repository)->pruneUnsharedCovers(new \DateTimeImmutable(self::NOW));
            self::fail('the query failure must surface to the prune command');
        } catch (\RuntimeException) {
        }

        self::assertDirectoryExists($dir);
    }

    public function testLeavesSymlinksAndLooseFilesAlone(): void
    {
        $old = strtotime('2026-10-01 00:00:00');
        $target = $this->putNodeDir('target-node', $old);
        symlink($target, $this->coversDir . '/link-node');
        file_put_contents($this->coversDir . '/loose.jpg', 'jpeg');
        touch($this->coversDir . '/loose.jpg', $old);

        // target-node is kept, the link and the loose file are not node dirs.
        $deleted = $this->buildService(['target-node'])
            ->pruneUnsharedCovers(new \DateTimeImmutable(self::NOW));

        self::assertSame(0, $deleted);
        self::assertTrue(is_link($this->coversDir . '/link-node'));
        self::assertFileExists($this->coversDir . '/loose.jpg');
        self::assertFileExists($target . '/book-1.jpg');
    }

    public function testAWildcardNodeIdOnlyReachesItsOwnDirectory(): void
    {
        // Node ids are any string without a slash, so "*" is a valid one. A
        // glob() built from it would match every other node's covers.
        $old = strtotime('2026-10-01 00:00:00');
        $wildcard = $this->putNodeDir('*', $old);
        $victimA = $this->putNodeDir('victim-a', $old);
        $victimB = $this->putNodeDir('victim-b', $old);

        $deleted = $this->buildService(['victim-a', 'victim-b'])
            ->pruneUnsharedCovers(new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $deleted);
        self::assertDirectoryDoesNotExist($wildcard);
        self::assertFileExists($victimA . '/book-1.jpg');
        self::assertFileExists($victimB . '/book-1.jpg');
    }

    public function testOrphanSweepOfAWildcardNodeLeavesOtherNodesAlone(): void
    {
        $wildcard = $this->putNodeDir('*', time());
        foreach (['1', '2', '3'] as $bookId) {
            file_put_contents($wildcard . "/$bookId.jpg", 'jpeg');
        }
        unlink($wildcard . '/book-1.jpg');
        $victim = $this->putNodeDir('victim', time());
        file_put_contents($victim . '/9.jpg', 'jpeg');

        $catalog = json_encode([['book_id' => 1], ['book_id' => 2], ['book_id' => 3]]);
        $conn = $this->createStub(Connection::class);
        $conn->method('iterateAssociative')
            ->willReturn(new \ArrayIterator([['node_id' => '*', 'catalog_payload' => $catalog]]));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $deleted = $this->buildServiceWith($this->createStub(LibraryProfileRepository::class), $em)
            ->pruneOrphanCoversForAllNodes();

        self::assertSame(0, $deleted);
        self::assertFileExists($victim . '/9.jpg', "another node's cover is not an orphan of \"*\"");
    }

    public function testADotDotNodeIdNeverReachesOutsideTheCoversDirectory(): void
    {
        // Registration accepts ".." as a node id; joined onto the covers
        // directory it names the parent. Files there are not covers.
        foreach (['1', '2', '3', '9'] as $name) {
            file_put_contents($this->baseDir . "/$name.jpg", 'jpeg');
        }
        $catalog = json_encode([['book_id' => 1], ['book_id' => 2], ['book_id' => 3]]);
        $conn = $this->createStub(Connection::class);
        $conn->method('iterateAssociative')
            ->willReturn(new \ArrayIterator([['node_id' => '..', 'catalog_payload' => $catalog]]));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $deleted = $this->buildServiceWith($this->createStub(LibraryProfileRepository::class), $em)
            ->pruneOrphanCoversForAllNodes();

        self::assertSame(0, $deleted);
        self::assertFileExists($this->baseDir . '/9.jpg');
    }

    public function testANodeIdWithASeparatorNeverReachesOutsideTheCoversDirectory(): void
    {
        // Registration only bounds the node id's length, so "../escape" is a
        // valid one and reaches the sweep through the database, not scandir.
        $escape = $this->baseDir . '/escape';
        mkdir($escape);
        foreach (['1', '2', '3', '9'] as $name) {
            file_put_contents($escape . "/$name.jpg", 'jpeg');
        }
        $catalog = json_encode([['book_id' => 1], ['book_id' => 2], ['book_id' => 3]]);
        $conn = $this->createStub(Connection::class);
        $conn->method('iterateAssociative')
            ->willReturn(new \ArrayIterator([['node_id' => '../escape', 'catalog_payload' => $catalog]]));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $deleted = $this->buildServiceWith($this->createStub(LibraryProfileRepository::class), $em)
            ->pruneOrphanCoversForAllNodes();

        self::assertSame(0, $deleted);
        self::assertFileExists($escape . '/9.jpg');
    }

    public function testMissingCoversDirectoryIsANoOp(): void
    {
        $this->rrmdir($this->coversDir);

        self::assertSame(0, $this->buildService(['x'])->pruneUnsharedCovers(new \DateTimeImmutable(self::NOW)));
    }

    /** @param list<string> $keep */
    private function buildService(array $keep): DirectoryService
    {
        $repository = $this->createStub(LibraryProfileRepository::class);
        $repository->method('findNodeIdsKeepingCovers')->willReturn($keep);

        return $this->buildServiceWith($repository);
    }

    private function buildServiceWith(
        LibraryProfileRepository $repository,
        ?EntityManagerInterface $em = null,
    ): DirectoryService {
        return new DirectoryService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $repository,
            $this->createStub(FollowRepository::class),
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->createStub(HubEventLogger::class),
            coversDirectory: $this->coversDir,
        );
    }

    private function putNodeDir(string $nodeId, int $mtime): string
    {
        $dir = $this->coversDir . '/' . $nodeId;
        mkdir($dir);
        file_put_contents($dir . '/book-1.jpg', 'jpeg');
        touch($dir . '/book-1.jpg', $mtime);
        touch($dir, $mtime);

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            (is_dir($path) && !is_link($path)) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
