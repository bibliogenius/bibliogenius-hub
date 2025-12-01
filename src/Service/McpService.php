<?php

namespace App\Service;

use App\Entity\RegisteredLibrary;
use Doctrine\ORM\EntityManagerInterface;

class McpService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function processRequest(string $jsonRequest): string
    {
        $request = json_decode($jsonRequest, true);

        if (!$request || !isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $this->createErrorResponse(null, -32600, 'Invalid Request');
        }

        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        try {
            switch ($method) {
                case 'initialize':
                    return $this->handleInitialize($id);
                case 'tools/list':
                    return $this->handleToolsList($id);
                case 'tools/call':
                    return $this->handleToolsCall($id, $params);
                default:
                    // For now, ignore other notifications or methods
                    // But for a request with ID, we must return method not found
                    if ($id !== null) {
                        return $this->createErrorResponse($id, -32601, 'Method not found');
                    }
                    return '';
            }
        } catch (\Throwable $e) {
            return $this->createErrorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    private function handleInitialize($id): string
    {
        return $this->createSuccessResponse($id, [
            'protocolVersion' => '2024-11-05',
            'serverInfo' => [
                'name' => 'BiblioGenius Hub',
                'version' => '1.0.0',
            ],
            'capabilities' => [
                'tools' => [],
            ],
        ]);
    }

    private function handleToolsList($id): string
    {
        return $this->createSuccessResponse($id, [
            'tools' => [
                [
                    'name' => 'search_libraries',
                    'description' => 'Search for libraries in the BiblioGenius network by name or tag.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'The search query (name or tag)',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ]);
    }

    private function handleToolsCall($id, array $params): string
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        if ($name === 'search_libraries') {
            return $this->searchLibraries($id, $args);
        }

        return $this->createErrorResponse($id, -32601, "Tool not found: $name");
    }

    private function searchLibraries($id, array $args): string
    {
        $query = $args['query'] ?? '';

        $repository = $this->entityManager->getRepository(RegisteredLibrary::class);
        $qb = $repository->createQueryBuilder('l');

        if (!empty($query)) {
            $qb->where('l.name LIKE :query')
                ->orWhere('l.description LIKE :query')
                // JSON searching is tricky in standard SQL, simplified for now
                ->setParameter('query', '%' . $query . '%');
        }

        $libraries = $qb->setMaxResults(10)->getQuery()->getResult();

        $results = [];
        foreach ($libraries as $lib) {
            /** @var RegisteredLibrary $lib */
            $results[] = [
                'name' => $lib->getName(),
                'url' => $lib->getUrl(),
                'description' => $lib->getDescription(),
                'tags' => $lib->getTags(),
            ];
        }

        return $this->createSuccessResponse($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($results, JSON_PRETTY_PRINT),
                ],
            ],
        ]);
    }

    private function createSuccessResponse($id, array $result): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function createErrorResponse($id, int $code, string $message): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }
}
