<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\DirectoryHealthRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Guards the directory-health monitoring contract (ADR-027 keep-alive
 * invariants surfaced on the admin dashboard):
 *
 *  - coverage queries MUST treat "expired row" the same as "no row"
 *    (LEFT JOIN bounded by expires_at) and only look at alive libraries
 *    with books, all through bound parameters;
 *  - the placeholder counter MUST match the JSON context with an escaped
 *    underscore so `peer_X` matches but `peersomething` does not, and MUST
 *    stay bounded by created_at (indexed) for dashboard-render performance;
 *  - ghost detection MUST require repetition (>= 2 lookups), exclude
 *    placeholders, and exclude node ids present in library_profiles;
 *  - the coverage alert MUST fire only strictly above the threshold and
 *    at most once per 24h (deduplicated via the last emission instant).
 */
// Stub-only Connection collaborators in some tests; opt out of PHPUnit
// 12.5's no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class DirectoryHealthRepositoryTest extends TestCase
{
    private const NOW = '2026-07-02 12:00:00';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    // -------------------------------------------------------------------
    // Catalog coverage
    // -------------------------------------------------------------------

    public function testCountCatalogCoverageGapsShape(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '31';
            });

        $count = (new DirectoryHealthRepository($conn))->countCatalogCoverageGaps($this->now());

        $this->assertSame(31, $count);
        // Absent row and expired row must count the same: the expires_at
        // bound lives in the JOIN condition, not in the WHERE clause.
        $this->assertStringContainsStringIgnoringCase('LEFT JOIN cached_catalogs', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('c.expires_at >= ?', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('c.node_id IS NULL', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('p.book_count > 0', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('p.last_seen_at >= ?', $capturedSql);
        // Bound parameters only: now (TTL validity) then now-7d (liveness).
        $this->assertSame(['2026-07-02 12:00:00', '2026-06-25 12:00:00'], $capturedParams);
    }

    public function testFindCatalogCoverageGapsShape(): void
    {
        $capturedSql = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return [];
            });

        (new DirectoryHealthRepository($conn))->findCatalogCoverageGaps($this->now());

        // The drill-down carries the columns the admin needs to identify
        // pre-fix clients, biggest libraries first, and stays bounded.
        foreach (['node_id', 'display_name', 'book_count', 'last_seen_at', 'app_version'] as $column) {
            $this->assertStringContainsStringIgnoringCase($column, $capturedSql);
        }
        $this->assertStringContainsStringIgnoringCase('ORDER BY p.book_count DESC', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('LIMIT 10', $capturedSql);
    }

    // -------------------------------------------------------------------
    // Placeholder leaks
    // -------------------------------------------------------------------

    public function testCountPlaceholderLookupsShape(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '0';
            });

        $since = $this->now()->modify('-24 hours');
        $count = (new DirectoryHealthRepository($conn))->countPlaceholderLookups($since);

        $this->assertSame(0, $count);
        // Time-bounded scan (idx_hub_events_created) with the pattern bound
        // as a parameter, never interpolated.
        $this->assertStringContainsStringIgnoringCase('created_at >= ?', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('context LIKE ?', $capturedSql);
        $this->assertSame(
            [
                'profile lookup: not found',
                '2026-07-01 12:00:00',
                // Backslash-escaped underscore: `peer_` must not match `peerX`.
                '%"node_id":"peer\_%',
            ],
            $capturedParams,
        );
    }

    // -------------------------------------------------------------------
    // Ghost lookups
    // -------------------------------------------------------------------

    public function testFindGhostLookupsShape(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [['node_id' => 'gone-uuid', 'lookup_count' => 4]];
            });

        $since = $this->now()->modify('-7 days');
        $rows = (new DirectoryHealthRepository($conn))->findGhostLookups($since);

        $this->assertSame([['node_id' => 'gone-uuid', 'lookup_count' => 4]], $rows);
        // Repetition required, placeholders excluded (their own tile),
        // known profiles excluded, top-N bounded.
        $this->assertStringContainsStringIgnoringCase('HAVING COUNT(*) >= 2', $capturedSql);
        $this->assertStringContainsStringIgnoringCase("NOT LIKE 'peer\_%'", $capturedSql);
        $this->assertStringContainsStringIgnoringCase('NOT EXISTS', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('FROM library_profiles', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('created_at >= ?', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('LIMIT 5', $capturedSql);
        $this->assertSame(['profile lookup: not found', '2026-06-25 12:00:00'], $capturedParams);
    }

    // -------------------------------------------------------------------
    // Coverage alert: threshold + 24h dedup
    // -------------------------------------------------------------------

    public function testAlertNotEmittedAtOrBelowThreshold(): void
    {
        $now = $this->now();

        // Strictly-above semantics: the baseline itself must not alert.
        $this->assertFalse(DirectoryHealthRepository::shouldEmitCoverageAlert(0, 40, null, $now));
        $this->assertFalse(DirectoryHealthRepository::shouldEmitCoverageAlert(31, 40, null, $now));
        $this->assertFalse(DirectoryHealthRepository::shouldEmitCoverageAlert(40, 40, null, $now));
    }

    public function testAlertEmittedAboveThresholdWhenNeverEmitted(): void
    {
        $this->assertTrue(
            DirectoryHealthRepository::shouldEmitCoverageAlert(41, 40, null, $this->now()),
        );
    }

    public function testAlertDeduplicatedWithin24Hours(): void
    {
        $now = $this->now();

        $oneHourAgo = $now->modify('-1 hour');
        $this->assertFalse(
            DirectoryHealthRepository::shouldEmitCoverageAlert(50, 40, $oneHourAgo, $now),
        );

        $twentyThreeHoursAgo = $now->modify('-23 hours');
        $this->assertFalse(
            DirectoryHealthRepository::shouldEmitCoverageAlert(50, 40, $twentyThreeHoursAgo, $now),
        );
    }

    public function testAlertReEmittedAfter24Hours(): void
    {
        $now = $this->now();

        $exactly24hAgo = $now->modify('-24 hours');
        $this->assertTrue(
            DirectoryHealthRepository::shouldEmitCoverageAlert(50, 40, $exactly24hAgo, $now),
        );

        $twoDaysAgo = $now->modify('-2 days');
        $this->assertTrue(
            DirectoryHealthRepository::shouldEmitCoverageAlert(50, 40, $twoDaysAgo, $now),
        );
    }

    public function testLastCoverageAlertAtParsesTimestampAndHandlesEmpty(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('2026-07-01 09:30:00');
        $at = (new DirectoryHealthRepository($conn))->lastCoverageAlertAt();
        $this->assertInstanceOf(\DateTimeImmutable::class, $at);
        $this->assertSame('2026-07-01 09:30:00', $at->format('Y-m-d H:i:s'));

        // MAX() over zero rows yields NULL: must map to "never emitted".
        $empty = $this->createMock(Connection::class);
        $empty->method('fetchOne')->willReturn(null);
        $this->assertNull((new DirectoryHealthRepository($empty))->lastCoverageAlertAt());
    }
}
