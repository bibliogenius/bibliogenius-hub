<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RegisteredLibrary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/discovery', name: 'api_discovery_')]
class DiscoveryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/peers', name: 'peers', methods: ['GET'])]
    public function peers(Request $request): JsonResponse
    {
        $tags = $request->query->all('tags') ?? [];
        $limit = $request->query->getInt('limit', 10);

        $qb = $this->entityManager
            ->getRepository(RegisteredLibrary::class)
            ->createQueryBuilder('l');

        // Filter by tags if provided
        if (!empty($tags)) {
            foreach ($tags as $index => $tag) {
                $qb->andWhere("l.tags LIKE :tag{$index}")
                    ->setParameter("tag{$index}", '%"' . $tag . '"%');
            }
        }

        // Only active libraries (heartbeat within last hour)
        $oneHourAgo = new \DateTime('-1 hour');
        $qb->andWhere('l.lastHeartbeat > :oneHourAgo')
            ->setParameter('oneHourAgo', $oneHourAgo);

        $qb->setMaxResults($limit);

        $libraries = $qb->getQuery()->getResult();

        $peers = array_map(function (RegisteredLibrary $library) {
            return [
                'id' => $library->getId(),
                'name' => $library->getName(),
                'url' => $library->getUrl(),
                'tags' => $library->getTags(),
                'description' => $library->getDescription(),
            ];
        }, $libraries);

        return $this->json(['peers' => $peers]);
    }
}
