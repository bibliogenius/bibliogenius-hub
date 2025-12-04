<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Peer;
use App\Repository\PeerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/peers', name: 'api_peers_')]
class PeerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PeerRepository $peerRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        // Return both active and pending (outgoing) peers
        $peers = $this->peerRepository->findBy(['status' => ['active', 'pending']], ['name' => 'ASC']);

        $data = array_map(function (Peer $peer) {
            return [
                'id' => $peer->getId(),
                'name' => $peer->getName(),
                'url' => $peer->getUrl(),
                'status' => $peer->getStatus(),
            ];
        }, $peers);

        return $this->json(['data' => $data]);
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q');
        if (!$query) {
            return $this->json(['data' => []]);
        }

        // Search in local peers
        $peers = $this->peerRepository->createQueryBuilder('p')
            ->where('p.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->getQuery()
            ->getResult();

        $data = array_map(function (Peer $peer) {
            return [
                'id' => $peer->getId(),
                'name' => $peer->getName(),
                'url' => $peer->getUrl(),
                'status' => $peer->getStatus(),
                'source' => 'local',
            ];
        }, $peers);

        // MOCK: Simulate finding a remote library in the global directory
        // In production, this would call the central Directory Server
        if (stripos('Bibliothèque de Thomas', $query) !== false) {
            $data[] = [
                'id' => 999, // Fake ID
                'name' => 'Bibliothèque de Thomas',
                'url' => 'http://thomas-library.local', // Fake URL
                'status' => 'new',
                'source' => 'directory',
            ];
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/connect', name: 'connect', methods: ['POST'])]
    public function connect(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name'], $data['url'])) {
            return $this->json(['error' => 'Missing required fields'], 400);
        }

        // Check if peer already exists
        $existing = $this->peerRepository->findOneBy(['url' => $data['url']]);

        if ($existing) {
            return $this->json(['message' => 'Peer already exists', 'status' => $existing->getStatus()]);
        }

        $peer = new Peer();
        $peer->setName($data['name']);
        $peer->setUrl($data['url']);
        $peer->setDirection('outgoing');
        // Status defaults to 'pending'

        $this->entityManager->persist($peer);
        $this->entityManager->flush();

        // Notify remote peer's Rust server about incoming connection
        try {
            // Get my library config to send my details
            $myConfig = $this->entityManager->getRepository(\App\Entity\LibraryConfig::class)->findOneBy([]);

            if ($myConfig) {
                $myName = $myConfig->getName();
                // The remote peer needs to know MY URL to connect back
                // For Docker setup: bibliogenius-a:8000 or bibliogenius-b:8000
                // We need to determine which instance we are
                $myUrl = $_ENV['MY_LIBRARY_URL'] ?? 'http://bibliogenius-a:8000'; // TODO: Make configurable

                $remoteUrl = $data['url'] . '/api/peers/incoming';
                $postData = json_encode([
                    'name' => $myName,
                    'url' => $myUrl,
                ]);

                $options = [
                    'http' => [
                        'header' => "Content-type: application/json\r\n",
                        'method' => 'POST',
                        'content' => $postData,
                        'timeout' => 2,
                    ]
                ];
                $context = stream_context_create($options);
                @file_get_contents($remoteUrl, false, $context);
            }
        } catch (\Throwable $e) {
            // Notification failed, but we still created our outgoing peer
            // User can manually connect from the other side if needed
        }

        return $this->json([
            'message' => 'Connection request sent',
            'peer_id' => $peer->getId(),
            'status' => $peer->getStatus(),
        ]);
    }

    #[Route('/receive_connection', name: 'receive_connection', methods: ['POST'])]
    public function receiveConnection(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name'], $data['url'])) {
            return $this->json(['error' => 'Missing required fields'], 400);
        }

        // Check if peer already exists
        $existing = $this->peerRepository->findOneBy(['url' => $data['url']]);

        if ($existing) {
            return $this->json(['message' => 'Peer already exists', 'status' => $existing->getStatus()]);
        }

        $peer = new Peer();
        $peer->setName($data['name']);
        $peer->setUrl($data['url']);
        $peer->setDirection('incoming');
        $peer->setStatus('pending');

        $this->entityManager->persist($peer);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Connection request received',
            'peer_id' => $peer->getId(),
            'status' => $peer->getStatus(),
        ]);
    }

    #[Route('/requests', name: 'requests', methods: ['GET'])]
    public function requests(): JsonResponse
    {
        $pendingPeers = $this->peerRepository->findPendingRequests();

        $data = array_map(function (Peer $peer) {
            return [
                'id' => $peer->getId(),
                'name' => $peer->getName(),
                'url' => $peer->getUrl(),
                'direction' => $peer->getDirection(),
                'created_at' => $peer->getCreatedAt()->format('c'),
            ];
        }, $pendingPeers);

        return $this->json(['requests' => $data]);
    }

    #[Route('/{id}/status', name: 'update_status', methods: ['PUT'])]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $peer = $this->peerRepository->find($id);

        if (!$peer) {
            return $this->json(['error' => 'Peer not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $status = $data['status'] ?? null;

        if (!in_array($status, ['active', 'rejected'])) {
            return $this->json(['error' => 'Invalid status'], 400);
        }

        $peer->setStatus($status);
        $this->entityManager->flush();

        // If accepted, push to Rust server (Library Node)
        if ($status === 'active') {
            try {
                // Assuming Rust server is reachable at bibliogenius-a:8000
                // This is a best-effort sync
                $url = 'http://bibliogenius-a:8000/api/peers/connect';
                $postData = json_encode([
                    'name' => $peer->getName(),
                    'url' => $peer->getUrl(),
                ]);
                $options = [
                    'http' => [
                        'header' => "Content-type: application/json\r\n",
                        'method' => 'POST',
                        'content' => $postData,
                        'timeout' => 2,
                    ]
                ];
                $context = stream_context_create($options);
                @file_get_contents($url, false, $context);
            } catch (\Throwable $e) {
                // Log error or ignore
            }
        }

        return $this->json(['message' => 'Status updated', 'status' => $peer->getStatus()]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $peer = $this->peerRepository->find($id);

        if (!$peer) {
            return $this->json(['error' => 'Peer not found'], 404);
        }

        $this->entityManager->remove($peer);
        $this->entityManager->flush();

        return $this->json(['message' => 'Peer removed successfully']);
    }
}
