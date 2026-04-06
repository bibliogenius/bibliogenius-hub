<?php

namespace App\Controller\Admin;

use App\Repository\RelayMailboxRepository;
use App\Service\HubEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
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

        // Event stats (24h)
        $deposit404s = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM hub_events WHERE channel = 'relay' AND message LIKE '%404%' AND created_at >= ?",
            [$yesterday],
        );
        $recentWarnings = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM hub_events WHERE level = 'warning' AND created_at >= ?",
            [$yesterday],
        );
        $recentErrors = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM hub_events WHERE level = 'error' AND created_at >= ?",
            [$yesterday],
        );

        // Busy mailboxes (top 10 with pending messages)
        $busyMailboxes = $conn->fetchAllAssociative(
            'SELECT rm.mailbox_uuid, COUNT(*) AS msg_count, MIN(rm.created_at) AS oldest_msg
             FROM relay_messages rm
             GROUP BY rm.mailbox_uuid
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

        return $this->render('admin/dashboard_stats.html.twig', [
            'total_profiles' => $totalProfiles,
            'active_profiles' => $activeProfiles,
            'profiles_with_relay' => $profilesWithRelay,
            'total_mailboxes' => $totalMailboxes,
            'active_mailboxes' => $activeMailboxes,
            'pending_messages' => $pendingMessages,
            'deposit_404s' => $deposit404s,
            'recent_warnings' => $recentWarnings,
            'recent_errors' => $recentErrors,
            'busy_mailboxes' => $busyMailboxes,
            'recent_events' => $recentEvents,
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
