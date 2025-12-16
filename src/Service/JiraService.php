<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for creating issues in Jira Cloud.
 * Uses Basic Auth with email + API token.
 */
class JiraService
{
    private string $baseUrl;
    private string $projectKey;
    private string $email;
    private string $apiToken;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        string $jiraBaseUrl,
        string $jiraProjectKey,
        string $jiraEmail,
        string $jiraApiToken,
    ) {
        $this->baseUrl = rtrim($jiraBaseUrl, '/');
        $this->projectKey = $jiraProjectKey;
        $this->email = $jiraEmail;
        $this->apiToken = $jiraApiToken;
    }

    /**
     * Create a Jira issue from user feedback.
     *
     * @param string $type       'bug' or 'feature'
     * @param string $title      Issue summary
     * @param string $description Issue description (markdown)
     * @param array  $context    App context (version, os, profile, etc.)
     *
     * @return array{success: bool, issueKey: ?string, error: ?string}
     */
    public function createIssue(
        string $type,
        string $title,
        string $description,
        array $context = []
    ): array {
        // Map type to Jira issue type
        $issueType = match ($type) {
            'bug' => 'Bug',
            'feature' => 'Story', // Use Story for feature requests
            default => 'Task',
        };

        // Build description with context
        $fullDescription = $this->buildDescription($description, $context);

        // Jira REST API payload
        $payload = [
            'fields' => [
                'project' => [
                    'key' => $this->projectKey,
                ],
                'summary' => $title,
                'description' => [
                    'type' => 'doc',
                    'version' => 1,
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => $fullDescription,
                                ],
                            ],
                        ],
                    ],
                ],
                'issuetype' => [
                    'name' => $issueType,
                ],
                'labels' => ['beta-feedback', 'from-app'],
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/rest/api/3/issue', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'auth_basic' => [$this->email, $this->apiToken],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode === 201) {
                $this->logger->info('Jira issue created', [
                    'key' => $data['key'] ?? 'unknown',
                    'type' => $issueType,
                ]);

                return [
                    'success' => true,
                    'issueKey' => $data['key'] ?? null,
                    'error' => null,
                ];
            }

            $this->logger->error('Jira API error', [
                'status' => $statusCode,
                'response' => $data,
            ]);

            return [
                'success' => false,
                'issueKey' => null,
                'error' => $data['errorMessages'][0] ?? 'Unknown error',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Jira request failed', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'issueKey' => null,
                'error' => 'Failed to connect to Jira: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build full description with context info.
     */
    private function buildDescription(string $description, array $context): string
    {
        $lines = [$description];

        if (!empty($context)) {
            $lines[] = '';
            $lines[] = '---';
            $lines[] = 'Environment:';

            if (isset($context['app_version'])) {
                $lines[] = '• App Version: ' . $context['app_version'];
            }
            if (isset($context['os'])) {
                $lines[] = '• OS: ' . $context['os'];
            }
            if (isset($context['profile'])) {
                $lines[] = '• Profile: ' . $context['profile'];
            }
            if (isset($context['language'])) {
                $lines[] = '• Language: ' . $context['language'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Check if Jira integration is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->baseUrl)
            && !empty($this->projectKey)
            && !empty($this->email)
            && !empty($this->apiToken);
    }
}
