<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RegisteredLibrary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $libraries = $this->entityManager
            ->getRepository(RegisteredLibrary::class)
            ->findAll();

        $totalLibraries = count($libraries);

        // Count active libraries (heartbeat within last hour)
        $oneHourAgo = new \DateTime('-1 hour');
        $activeLibraries = array_filter($libraries, function (RegisteredLibrary $lib) use ($oneHourAgo) {
            return $lib->getLastHeartbeat() > $oneHourAgo;
        });

        return $this->render('admin/dashboard.html.twig', [
            'libraries' => $libraries,
            'total_libraries' => $totalLibraries,
            'active_libraries' => count($activeLibraries),
        ]);
    }
}
