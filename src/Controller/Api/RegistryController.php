<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RegisteredLibrary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/registry', name: 'api_registry_')]
class RegistryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['library_name'], $data['url'])) {
            return $this->json(['error' => 'Missing required fields'], 400);
        }

        // Check if library already exists
        $existing = $this->entityManager
            ->getRepository(RegisteredLibrary::class)
            ->findOneBy(['url' => $data['url']]);

        if ($existing) {
            // Update existing
            $library = $existing;
            $library->updateHeartbeat();
        } else {
            // Create new
            $library = new RegisteredLibrary();
            $library->setUrl($data['url']);
        }

        $library->setName($data['library_name']);
        $library->setTags($data['tags'] ?? []);
        $library->setDescription($data['description'] ?? null);

        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Library registered successfully',
            'library_id' => $library->getId(),
        ]);
    }

    #[Route('/heartbeat', name: 'heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['url'])) {
            return $this->json(['error' => 'Missing URL'], 400);
        }

        $library = $this->entityManager
            ->getRepository(RegisteredLibrary::class)
            ->findOneBy(['url' => $data['url']]);

        if (!$library) {
            return $this->json(['error' => 'Library not found'], 404);
        }

        $library->updateHeartbeat();
        $this->entityManager->flush();

        return $this->json(['message' => 'Heartbeat updated']);
    }
}
