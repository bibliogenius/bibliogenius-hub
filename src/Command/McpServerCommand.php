<?php

namespace App\Command;

use App\Entity\RegisteredLibrary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mcp:server',
    description: 'Runs the MCP Server (JSON-RPC over Stdio)',
)]
class McpServerCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Use raw streams for Stdio to avoid Console formatting interference
        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        while (!feof($stdin)) {
            $line = fgets($stdin);
            if (false === $line) {
                break;
            }

            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            try {
                $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $response = $this->handleRequest($request);

                if ($response) {
                    fwrite($stdout, json_encode($response) . "\n");
                    fflush($stdout);
                }
            } catch (\Throwable $e) {
                // Log error to stderr to avoid breaking JSON-RPC on stdout
                fwrite(STDERR, "Error processing request: " . $e->getMessage() . "\n");
            }
        }

        fclose($stdin);
        fclose($stdout);

        return Command::SUCCESS;
    }

    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? null;
        $id = $request['id'] ?? null;

        if (!$method) {
            return null;
        }

        $result = match ($method) {
            'initialize' => $this->handleInitialize($request),
            'notifications/initialized' => null, // No response needed
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($request),
            default => null, // Ignore unknown methods for now
        };

        if ($id === null) {
            return null; // Notification, no response
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function handleInitialize(array $request): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [],
            ],
            'serverInfo' => [
                'name' => 'bibliogenius-hub',
                'version' => '0.1.0',
            ],
        ];
    }

    private function handleToolsList(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'search_libraries',
                    'description' => 'Search for registered libraries by name or description.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'The search term (name or description)',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }

    private function handleToolsCall(array $request): array
    {
        $name = $request['params']['name'] ?? '';
        $arguments = $request['params']['arguments'] ?? [];

        if ($name === 'search_libraries') {
            return $this->searchLibraries($arguments['query'] ?? '');
        }

        throw new \RuntimeException("Unknown tool: $name");
    }

    private function searchLibraries(string $query): array
    {
        $qb = $this->entityManager->getRepository(RegisteredLibrary::class)->createQueryBuilder('l');

        $libraries = $qb->where('l.name LIKE :query')
            ->orWhere('l.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $results = [];
        foreach ($libraries as $lib) {
            $results[] = [
                'name' => $lib->getName(),
                'url' => $lib->getUrl(),
                'description' => $lib->getDescription(),
                'tags' => $lib->getTags(),
            ];
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($results, JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }
}
