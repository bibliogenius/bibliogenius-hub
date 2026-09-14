<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DashboardAlerts;
use PHPUnit\Framework\TestCase;

final class DashboardAlertsTest extends TestCase
{
    /** A hub where every tile is green. @return array<string, mixed> */
    private function quietStats(): array
    {
        return [
            'recent_errors' => 0,
            'recent_warnings' => 2,
            'prune_stale' => false,
            'last_prune_at' => '2026-09-14 03:00:00',
            'orphan_profile_refs' => 0,
            'shared_mailbox_refs' => [],
            'hijack_attempts_24h' => 0,
            'active_mailboxes' => 3,
            'total_mailboxes' => 10,
            'stale_messages' => 4,
            'deposit_404s' => 0,
            'catalog_coverage_gaps' => 0,
            'placeholder_leaks_24h' => 0,
            'duplicate_libraries' => 0,
            'ghost_lookups_7d' => [],
            'discovery_quality' => ['share_percent' => 12, 'truncated' => false, 'failed' => 3, 'total' => 25],
            'discovery_drift_alert_threshold' => 50,
            'discovery_anchor_coverage' => ['collapsed' => false],
            'discovery_unavailable_24h' => ['share_percent' => 0, 'unavailable' => 0, 'total' => 40],
            'discovery_budget_exhausted_24h' => 0,
            'active_profiles' => 5,
            'total_profiles' => 20,
            'invite_token_count' => 12,
            'registration_failure_count' => 3,
        ];
    }

    public function testQuietHubHasNoAlert(): void
    {
        $alerts = DashboardAlerts::build($this->quietStats());

        self::assertSame([], $alerts['items']);
        self::assertSame(0, $alerts['errors']);
        self::assertSame(0, $alerts['warnings']);
    }

    public function testErrorsComeFirstThenWarningsInPageOrder(): void
    {
        $stats = $this->quietStats();
        $stats['recent_warnings'] = 9;          // warning, health
        $stats['orphan_profile_refs'] = 2;      // error, relay-orphans
        $stats['duplicate_libraries'] = 1;      // warning, directory-health
        $stats['placeholder_leaks_24h'] = 1;    // error, directory-health

        $alerts = DashboardAlerts::build($stats);

        self::assertSame(2, $alerts['errors']);
        self::assertSame(2, $alerts['warnings']);
        self::assertSame(
            ['relay-orphans', 'directory-health', 'health', 'directory-health'],
            array_column($alerts['items'], 'anchor'),
        );
        self::assertSame('2 profiles reference a gone mailbox', $alerts['items'][0]['text']);
        self::assertSame('1 placeholder node id reached the hub in the last 24h', $alerts['items'][1]['text']);
    }

    public function testClientSideSignalsAreWarningsNotErrors(): void
    {
        $stats = $this->quietStats();
        $stats['deposit_404s'] = 7;

        $alerts = DashboardAlerts::build($stats);

        self::assertSame(0, $alerts['errors']);
        self::assertSame(1, $alerts['warnings']);
        self::assertSame('relay', $alerts['items'][0]['anchor']);
    }

    public function testPruneNeverRanIsWordedDifferentlyFromLate(): void
    {
        $never = $this->quietStats();
        $never['prune_stale'] = true;
        $never['last_prune_at'] = null;
        self::assertSame('nightly prune never ran', DashboardAlerts::build($never)['items'][0]['text']);

        $late = $this->quietStats();
        $late['prune_stale'] = true;
        self::assertSame('nightly prune has not run for over 48h', DashboardAlerts::build($late)['items'][0]['text']);
    }

    public function testDriftPastThresholdIsAnError(): void
    {
        $stats = $this->quietStats();
        $stats['discovery_quality']['share_percent'] = 50;

        $alerts = DashboardAlerts::build($stats);

        self::assertSame(1, $alerts['errors']);
        self::assertSame('discovery', $alerts['items'][0]['anchor']);
    }

    public function testEveryAnchorExistsInTheTemplate(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../templates/admin/dashboard_stats.html.twig');

        // Light every condition at once so every anchor the service can emit is checked.
        $stats = [
            'recent_errors' => 1, 'recent_warnings' => 9, 'prune_stale' => true, 'last_prune_at' => null,
            'orphan_profile_refs' => 1, 'shared_mailbox_refs' => [[]], 'hijack_attempts_24h' => 1,
            'active_mailboxes' => 0, 'stale_messages' => 11, 'deposit_404s' => 1,
            'catalog_coverage_gaps' => 1, 'placeholder_leaks_24h' => 1, 'duplicate_libraries' => 1, 'ghost_lookups_7d' => [[]],
            'discovery_quality' => ['share_percent' => 99, 'truncated' => true],
            'discovery_anchor_coverage' => ['collapsed' => true],
            'discovery_unavailable_24h' => ['share_percent' => 5],
            'discovery_budget_exhausted_24h' => 1,
            'active_profiles' => 0, 'invite_token_count' => 201, 'registration_failure_count' => 101,
        ] + $this->quietStats();

        $alerts = DashboardAlerts::build($stats);
        self::assertCount(21, $alerts['items']);

        foreach (array_unique(array_column($alerts['items'], 'anchor')) as $anchor) {
            self::assertStringContainsString(sprintf('id="%s"', $anchor), $template, "anchor #$anchor has no target in the template");
        }
    }
}
