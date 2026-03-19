<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->redirectToRoute('admin_library_profile_index');
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
        yield MenuItem::section('Monitoring');
        yield MenuItem::linkToCrud('Hub Events', 'fas fa-clipboard-list', \App\Entity\HubEvent::class);
        yield MenuItem::section('System');
        yield MenuItem::linkToCrud('Users', 'fas fa-users', \App\Entity\User::class);
        yield MenuItem::linkToRoute('Backup DB', 'fas fa-download', 'admin_backup');
    }
}
