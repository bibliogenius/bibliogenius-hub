<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'name' => 'BiblioGenius Hub',
            'version' => $_ENV['APP_VERSION'] ?? 'dev',
            'description' => 'Central directory and discovery service for BiblioGenius ecosystem',
            'endpoints' => [
'GET /api/health' => 'Liveness check (public)',
                'GET /api/health/detailed' => 'Full metrics (requires HEALTH_TOKEN)',
                'GET /api/feedback/health' => 'Feedback system health check',
            ],
            'documentation' => 'https://github.com/bibliogenius/bibliogenius-hub',
        ]);
    }
}
