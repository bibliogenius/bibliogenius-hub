<?php

namespace App\Command;

use App\Service\McpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mcp:run',
    description: 'Runs the Model Context Protocol (MCP) Server via Stdio',
)]
class McpServerCommand extends Command
{
    private McpService $mcpService;

    public function __construct(McpService $mcpService)
    {
        $this->mcpService = $mcpService;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // MCP Stdio transport reads line by line from STDIN
        $stdin = fopen('php://stdin', 'r');

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $response = $this->mcpService->processRequest($line);

            if (!empty($response)) {
                $output->writeln($response);
            }
        }

        fclose($stdin);

        return Command::SUCCESS;
    }
}
