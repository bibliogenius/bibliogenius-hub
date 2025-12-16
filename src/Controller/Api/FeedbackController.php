<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\JiraService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoint for receiving feedback from the BiblioGenius app.
 * Creates Jira issues for bug reports and feature requests.
 */
#[Route('/api/feedback', name: 'api_feedback_')]
class FeedbackController extends AbstractController
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {

        // Parse JSON body
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Anti-spam: honeypot field (should be empty)
        if (!empty($data['website'] ?? '')) {
            $this->logger->warning('Spam detected via honeypot', [
                'ip' => $request->getClientIp(),
            ]);
            // Return success to not reveal detection
            return $this->json(['success' => true, 'issueKey' => 'SPAM-0']);
        }

        // Validate required fields
        $type = $data['type'] ?? 'bug';
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');

        if (empty($title)) {
            return $this->json([
                'success' => false,
                'error' => 'Title is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($description)) {
            return $this->json([
                'success' => false,
                'error' => 'Description is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check Jira configuration
        if (!$this->jiraService->isConfigured()) {
            $this->logger->error('Jira not configured');
            return $this->json([
                'success' => false,
                'error' => 'Feedback system is not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Extract context
        $context = $data['context'] ?? [];

        // Create Jira issue
        $result = $this->jiraService->createIssue($type, $title, $description, $context);

        if ($result['success']) {
            return $this->json([
                'success' => true,
                'issueKey' => $result['issueKey'],
                'message' => 'Thank you for your feedback!',
            ], Response::HTTP_CREATED);
        }

        return $this->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to create issue',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Health check for feedback endpoint.
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'jira_configured' => $this->jiraService->isConfigured(),
        ]);
    }
}
