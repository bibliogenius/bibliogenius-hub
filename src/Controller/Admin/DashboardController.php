<?php

namespace App\Controller\Admin;

use App\Repository\Deposit404LogRepository;
use App\Repository\DirectoryHealthRepository;
use App\Repository\RelayMailboxRepository;
use App\Service\HubEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RelayMailboxRepository $mailboxRepository,
        private readonly HubEventLogger $eventLogger,
        private readonly Deposit404LogRepository $deposit404Log,
        private readonly DirectoryHealthRepository $directoryHealth,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(int:default:catalog_coverage_alert_threshold_default:CATALOG_COVERAGE_ALERT_THRESHOLD)%')]
        private readonly int $catalogCoverageAlertThreshold = 40,
    ) {
    }

    public function index(): Response
    {
        $conn = $this->em->getConnection();
        $now = new \DateTimeImmutable();
        $yesterday = $now->modify('-24 hours')->format('Y-m-d H:i:s');

        // Profile stats
        $totalProfiles = (int) $conn->fetchOne('SELECT COUNT(*) FROM library_profiles');
        $activeProfiles = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM library_profiles WHERE last_seen_at >= ?',
            [$yesterday],
        );
        $profilesWithRelay = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM library_profiles WHERE relay_mailbox_id IS NOT NULL',
        );

        // Mailbox stats
        $totalMailboxes = (int) $conn->fetchOne('SELECT COUNT(*) FROM relay_mailboxes');
        $activeMailboxes = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM relay_mailboxes WHERE last_accessed >= ?',
            [$yesterday],
        );

        // Message stats
        $pendingMessages = (int) $conn->fetchOne('SELECT COUNT(*) FROM relay_messages');
        $staleMessages = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM relay_messages WHERE created_at < ?",
            [$yesterday],
        );

        // Integrity signals: orphan references + hijack candidates.
        // Kept out of the SQL block above because they rely on repository
        // methods that are unit-tested (shape of the SELECT is frozen).
        $orphanProfileRefs = $this->mailboxRepository->countProfilesWithOrphanMailbox();
        $orphanMailboxRefs = $this->mailboxRepository->findProfilesWithOrphanMailbox();
        $sharedMailboxRefs = $this->mailboxRepository->findProfilesWithSharedMailbox();

        // Mailbox hijack attempts (ADR-031). 24h count comes from hub_events
        // so the figure is truly rolling; the monotonic per-profile total on
        // library_profiles.hijack_attempts_total is used for the offenders
        // drill-down below, and stays accurate even when hub_events is
        // purged by TTL.
        $hijackAttempts24h = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM hub_events WHERE channel = 'directory' AND message = 'hijack_attempt' AND created_at >= ?",
            [$yesterday],
        );
        $hijackOffenders = $conn->fetchAllAssociative(
            'SELECT node_id, display_name, hijack_attempts_total, last_seen_at
             FROM library_profiles
             WHERE hijack_attempts_total > 0
             ORDER BY hijack_attempts_total DESC, last_seen_at DESC
             LIMIT 10',
        );

        // Directory health (keep-alive invariants, ADR-027). Repository
        // methods are unit-tested so the shape of each SELECT is frozen;
        // all hub_events scans are bounded by created_at (indexed).
        $catalogCoverageGaps = $this->directoryHealth->countCatalogCoverageGaps($now);
        $catalogCoverageGapRows = $catalogCoverageGaps > 0
            ? $this->directoryHealth->findCatalogCoverageGaps($now)
            : [];
        $placeholderLeaks24h = $this->directoryHealth->countPlaceholderLookups($now->modify('-24 hours'));

        // One library published under several live node ids (ADR-055). The
        // count is over hashes, the drill-down over the nodes behind them.
        $duplicateLibraries = $this->directoryHealth->countDuplicateLiveLibraries($now);
        $duplicateLibraryRows = $duplicateLibraries > 0
            ? $this->directoryHealth->findDuplicateLiveLibraries($now)
            : [];
        $ghostLookups7d = $this->directoryHealth->findGhostLookups($now->modify('-7 days'));

        // Escalate a coverage regression into the errors tile and the
        // log-based cron alerting, at most once per 24h. Also evaluated
        // nightly by app:db:prune so the alert fires even when nobody
        // opens the dashboard; the 24h dedup makes both paths idempotent.
        if (DirectoryHealthRepository::shouldEmitCoverageAlert(
            $catalogCoverageGaps,
            $this->catalogCoverageAlertThreshold,
            $this->directoryHealth->lastCoverageAlertAt(),
            $now,
        )) {
            $this->eventLogger->critical('maintenance', 'catalog_coverage_degraded', [
                'count' => $catalogCoverageGaps,
            ]);
        }

        // Audit trail for the duplicate case, at most once per 24h. Phase 1 is
        // observation only: the event is what tells us, over the coming weeks,
        // whether a single occurrence was an accident or a pattern worth a
        // user-facing fix. Threshold 0: one duplicated catalog is the signal.
        if (DirectoryHealthRepository::shouldEmitAlert(
            $duplicateLibraries,
            0,
            $this->directoryHealth->lastDuplicateLibraryAlertAt(),
            $now,
        )) {
            $this->eventLogger->warning('maintenance', 'duplicate_library_detected', [
                'catalogs' => $duplicateLibraries,
                'nodes' => count($duplicateLibraryRows),
            ]);
        }

        // Event stats (24h).
        // deposit_404s comes from the dedicated aggregated counter, not hub_events:
        // we SUM the per-(uuid, hour) counter so the tile keeps reporting the true
        // number of 404 hits even though the underlying table now holds one row per
        // mailbox per hour instead of one row per hit.
        $deposit404s = $this->deposit404Log->countSince($now->modify('-24 hours'));
        $recentWarnings = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM hub_events WHERE level = 'warning' AND created_at >= ?",
            [$yesterday],
        );
        $recentErrors = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM hub_events WHERE level = 'error' AND created_at >= ?",
            [$yesterday],
        );

        // Busy mailboxes (top 10) — joined with library_profiles for anonymous node_id
        $busyMailboxes = $conn->fetchAllAssociative(
            'SELECT rm.mailbox_uuid,
                    COUNT(*) AS msg_count,
                    MIN(rm.created_at) AS oldest_msg,
                    lp.node_id AS profile_node_id
             FROM relay_messages rm
             LEFT JOIN library_profiles lp ON lp.relay_mailbox_id = rm.mailbox_uuid
             GROUP BY rm.mailbox_uuid, lp.node_id
             ORDER BY msg_count DESC
             LIMIT 10',
        );

        // Recent events (last 50)
        $recentEvents = $conn->fetchAllAssociative(
            'SELECT level, channel, message, context, created_at
             FROM hub_events
             ORDER BY created_at DESC
             LIMIT 50',
        );

        // Token / failure counts
        $inviteTokenCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM invite_tokens');
        $registrationFailureCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM registration_failures');

        // Nightly prune marker (written by app:db:prune). null => never ran.
        $lastPruneAt = $conn->fetchOne(
            "SELECT MAX(created_at) FROM hub_events WHERE channel = 'maintenance' AND message = 'prune_run'",
        ) ?: null;

        // Activity stats
        $totalBooks = (int) $conn->fetchOne('SELECT COALESCE(SUM(book_count), 0) FROM library_profiles');
        $borrowingEnabledCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM library_profiles WHERE allow_borrowing = true');
        $totalLoans = (int) $conn->fetchOne('SELECT COUNT(*) FROM borrow_requests');
        $acceptedLoans = (int) $conn->fetchOne("SELECT COUNT(*) FROM borrow_requests WHERE status = 'accepted'");
        $recentLoans = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM borrow_requests WHERE created_at >= NOW() - INTERVAL '30 days'",
        );

        // Loans per day — last 30 days (for chart)
        $loansPerDay = $conn->fetchAllAssociative(
            "SELECT TO_CHAR(created_at, 'YYYY-MM-DD') AS day, COUNT(*) AS count
             FROM borrow_requests
             WHERE created_at >= NOW() - INTERVAL '30 days'
             GROUP BY day
             ORDER BY day ASC",
        );

        // New libraries per week — last 8 weeks (for chart)
        $libraryGrowth = $conn->fetchAllAssociative(
            "SELECT TO_CHAR(DATE_TRUNC('week', created_at), 'YYYY-MM-DD') AS week, COUNT(*) AS count
             FROM library_profiles
             WHERE created_at >= NOW() - INTERVAL '8 weeks'
             GROUP BY week
             ORDER BY week ASC",
        );

        // Version distribution among active (24h) profiles. Diagnostic for
        // correlating hub-side anomalies with specific client builds.
        $versionDistribution = $conn->fetchAllAssociative(
            "SELECT COALESCE(app_version, 'unknown') AS version, COUNT(*) AS count
             FROM library_profiles
             WHERE last_seen_at >= ?
             GROUP BY app_version
             ORDER BY count DESC, version ASC
             LIMIT 20",
            [$yesterday],
        );

        // DB table sizes (PostgreSQL)
        $tableSizes = $conn->fetchAllAssociative(
            "SELECT relname AS table_name,
                    pg_total_relation_size(quote_ident(relname)) AS total_bytes
             FROM pg_stat_user_tables
             WHERE schemaname = 'public'
             ORDER BY total_bytes DESC",
        );

        return $this->render('admin/dashboard_stats.html.twig', [
            'total_profiles' => $totalProfiles,
            'active_profiles' => $activeProfiles,
            'profiles_with_relay' => $profilesWithRelay,
            'total_mailboxes' => $totalMailboxes,
            'active_mailboxes' => $activeMailboxes,
            'pending_messages' => $pendingMessages,
            'stale_messages' => $staleMessages,
            'orphan_profile_refs' => $orphanProfileRefs,
            'orphan_mailbox_refs' => $orphanMailboxRefs,
            'shared_mailbox_refs' => $sharedMailboxRefs,
            'hijack_attempts_24h' => $hijackAttempts24h,
            'hijack_offenders' => $hijackOffenders,
            'catalog_coverage_gaps' => $catalogCoverageGaps,
            'duplicate_libraries' => $duplicateLibraries,
            'duplicate_library_rows' => $duplicateLibraryRows,
            'catalog_coverage_gap_rows' => $catalogCoverageGapRows,
            'catalog_coverage_alert_threshold' => $this->catalogCoverageAlertThreshold,
            'placeholder_leaks_24h' => $placeholderLeaks24h,
            'ghost_lookups_7d' => $ghostLookups7d,
            'deposit_404s' => $deposit404s,
            'recent_warnings' => $recentWarnings,
            'recent_errors' => $recentErrors,
            'busy_mailboxes' => $busyMailboxes,
            'recent_events' => $recentEvents,
            'invite_token_count' => $inviteTokenCount,
            'registration_failure_count' => $registrationFailureCount,
            'last_prune_at' => $lastPruneAt,
            'table_sizes' => $tableSizes,
            'total_books' => $totalBooks,
            'borrowing_enabled_count' => $borrowingEnabledCount,
            'total_loans' => $totalLoans,
            'accepted_loans' => $acceptedLoans,
            'recent_loans' => $recentLoans,
            'loans_per_day' => $loansPerDay,
            'library_growth' => $libraryGrowth,
            'version_distribution' => $versionDistribution,
        ]);
    }

    #[Route('/admin/mailbox/{uuid}/delete', name: 'admin_mailbox_delete', methods: ['POST'])]
    public function deleteMailbox(string $uuid, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-mailbox-' . $uuid, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin');
        }

        $mailbox = $this->mailboxRepository->findByUuid($uuid);
        if ($mailbox === null) {
            $this->addFlash('warning', 'Mailbox not found (already deleted?).');
            return $this->redirectToRoute('admin');
        }

        $this->mailboxRepository->deleteWithMessages($uuid);
        $this->eventLogger->warning('relay', 'mailbox purged from dashboard (inactive)', ['uuid' => $uuid]);
        $this->addFlash('success', sprintf('Mailbox %s... and its messages deleted.', substr($uuid, 0, 8)));

        return $this->redirectToRoute('admin');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('BiblioGenius Hub');
    }

    public function configureAssets(): Assets
    {
        // Rewrites every [data-utc] timestamp to the admin's local timezone.
        return Assets::new()
            ->addJsFile('static/js/local-time.js');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Directory');
        yield MenuItem::linkToCrud('Library Profiles', 'fas fa-book', \App\Entity\LibraryProfile::class);
        yield MenuItem::linkToCrud('Follows', 'fas fa-user-friends', \App\Entity\Follow::class);
        yield MenuItem::linkToCrud('Cached Catalogs', 'fas fa-database', \App\Entity\CachedCatalog::class);
        yield MenuItem::linkToCrud('Reg. Failures', 'fas fa-exclamation-triangle', \App\Entity\RegistrationFailure::class);
        yield MenuItem::section('Relay');
        yield MenuItem::linkToCrud('Mailboxes', 'fas fa-envelope', \App\Entity\RelayMailbox::class);
        yield MenuItem::section('Monitoring');
        yield MenuItem::linkToCrud('Hub Events', 'fas fa-clipboard-list', \App\Entity\HubEvent::class);
        yield MenuItem::section('System');
        yield MenuItem::linkToCrud('Users', 'fas fa-users', \App\Entity\User::class);
        yield MenuItem::linkToRoute('Backup DB', 'fas fa-download', 'admin_backup');
    }
}
