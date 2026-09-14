<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The status strip at the top of the admin dashboard.
 *
 * Every tile that turns red or amber further down the page must also land
 * here: the strip is what makes the state of the hub readable without
 * scrolling. The conditions therefore mirror the tile classes in
 * templates/admin/dashboard_stats.html.twig, one entry per tile, and a
 * tile without an entry here is a tile the operator can miss.
 *
 * Severity vocabulary: 'error' = an operator action is required (fix);
 * 'warning' = worth a look, no action yet (watch). Signals that are
 * client-side by nature (stale invitations behind deposit 404s, pending
 * follow requests) are never errors: red is reserved for what the hub
 * operator can actually fix.
 */
final class DashboardAlerts
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';

    /**
     * @param array<string, mixed> $stats the template variables of the dashboard
     *
     * @return array{items: list<array{level: string, text: string, anchor: string}>, errors: int, warnings: int}
     */
    public static function build(array $stats): array
    {
        $items = [];
        $add = static function (bool $when, string $level, string $text, string $anchor) use (&$items): void {
            if ($when) {
                $items[] = ['level' => $level, 'text' => $text, 'anchor' => $anchor];
            }
        };
        $n = static fn (string $key): int => (int) ($stats[$key] ?? 0);
        $rows = static fn (string $key): int => is_countable($stats[$key] ?? null) ? count($stats[$key]) : 0;
        $plural = static fn (int $count, string $one, string $many): string => sprintf('%d %s', $count, $count === 1 ? $one : $many);

        // Health: the journal and the nightly cron.
        $add($n('recent_errors') > 0, self::LEVEL_ERROR,
            $plural($n('recent_errors'), 'error logged in the last 24h', 'errors logged in the last 24h'), 'health');
        $add($n('recent_warnings') > 5, self::LEVEL_WARNING,
            sprintf('%d warnings logged in the last 24h', $n('recent_warnings')), 'health');
        $add(($stats['prune_stale'] ?? false) === true, self::LEVEL_WARNING,
            ($stats['last_prune_at'] ?? null) === null ? 'nightly prune never ran' : 'nightly prune has not run for over 48h', 'health');

        // Relay: integrity first (each one is an ADR-031 signal), then reachability.
        $add($n('orphan_profile_refs') > 0, self::LEVEL_ERROR,
            $plural($n('orphan_profile_refs'), 'profile references a gone mailbox', 'profiles reference a gone mailbox'), 'relay-orphans');
        $add($rows('shared_mailbox_refs') > 0, self::LEVEL_ERROR,
            $plural($rows('shared_mailbox_refs'), 'mailbox is referenced by several profiles', 'mailboxes are referenced by several profiles'), 'relay-shared');
        $add($n('hijack_attempts_24h') > 0, self::LEVEL_ERROR,
            $plural($n('hijack_attempts_24h'), 'mailbox hijack attempt in the last 24h', 'mailbox hijack attempts in the last 24h'), 'relay-hijack');
        $add($n('active_mailboxes') === 0 && $n('total_mailboxes') > 0, self::LEVEL_WARNING,
            'no mailbox polled in the last 24h, relay may be unreachable', 'relay');
        $add($n('stale_messages') > 10, self::LEVEL_WARNING,
            sprintf('%d relay messages pending for over 24h', $n('stale_messages')), 'relay');
        $add($n('deposit_404s') > 0, self::LEVEL_WARNING,
            $plural($n('deposit_404s'), 'deposit to a gone mailbox in the last 24h (stale invitation, client side)', 'deposits to gone mailboxes in the last 24h (stale invitations, client side)'), 'relay');

        // Directory health: keep-alive invariants (ADR-027, ADR-055).
        $add($n('catalog_coverage_gaps') > 0, self::LEVEL_ERROR,
            $plural($n('catalog_coverage_gaps'), 'alive library without a cached catalog', 'alive libraries without a cached catalog'), 'directory-health');
        $add($n('placeholder_leaks_24h') > 0, self::LEVEL_ERROR,
            $plural($n('placeholder_leaks_24h'), 'placeholder node id reached the hub in the last 24h', 'placeholder node ids reached the hub in the last 24h'), 'directory-health');
        $add($n('duplicate_libraries') > 0, self::LEVEL_WARNING,
            $plural($n('duplicate_libraries'), 'catalog is published under several live node ids', 'catalogs are published under several live node ids'), 'directory-health');
        $add($rows('ghost_lookups_7d') > 0, self::LEVEL_WARNING,
            $plural($rows('ghost_lookups_7d'), 'unknown node id looked up repeatedly this week', 'unknown node ids looked up repeatedly this week'), 'directory-health');

        // Discovery resolver (ADR-060).
        $quality = $stats['discovery_quality'] ?? [];
        $qualityShare = $quality['share_percent'] ?? null;
        $add(($quality['truncated'] ?? false) === true, self::LEVEL_WARNING,
            'resolution quality window is trimmed by the event cap', 'discovery');
        $add($qualityShare !== null && $qualityShare >= (int) ($stats['discovery_drift_alert_threshold'] ?? PHP_INT_MAX), self::LEVEL_ERROR,
            sprintf('%d%% of resolved entities came back empty, source drift likely', $qualityShare), 'discovery');
        $add((($stats['discovery_anchor_coverage'] ?? [])['collapsed'] ?? false) === true, self::LEVEL_WARNING,
            'anchor coverage collapsed against its 7-day baseline', 'discovery');
        $unavailableShare = (($stats['discovery_unavailable_24h'] ?? [])['share_percent'] ?? null);
        $add($unavailableShare !== null && $unavailableShare > 0, self::LEVEL_WARNING,
            sprintf('%d%% of resolutions were unavailable in the last 24h', $unavailableShare), 'discovery');
        $add($n('discovery_budget_exhausted_24h') > 0, self::LEVEL_WARNING,
            $plural($n('discovery_budget_exhausted_24h'), 'resolution hit the outbound budget in the last 24h', 'resolutions hit the outbound budget in the last 24h'), 'discovery');

        // Directory: reachability and cleanup.
        $add($n('active_profiles') === 0 && $n('total_profiles') > 0, self::LEVEL_WARNING,
            'no profile contacted the hub in the last 24h, check reachability', 'directory');
        $add($n('invite_token_count') > 200, self::LEVEL_WARNING,
            sprintf('%d invite tokens stored, cleanup may not be running', $n('invite_token_count')), 'directory');
        $add($n('registration_failure_count') > 100, self::LEVEL_WARNING,
            sprintf('%d registration failures stored, check for repeated attempts', $n('registration_failure_count')), 'directory');

        // Errors first, then warnings, each group in page order.
        usort($items, static fn (array $a, array $b): int => ($a['level'] === $b['level']) ? 0 : ($a['level'] === self::LEVEL_ERROR ? -1 : 1));

        $errors = count(array_filter($items, static fn (array $i): bool => $i['level'] === self::LEVEL_ERROR));

        return [
            'items' => $items,
            'errors' => $errors,
            'warnings' => count($items) - $errors,
        ];
    }
}
