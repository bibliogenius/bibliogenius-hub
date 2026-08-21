<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Shared HTTP helpers, born with the account E2EE sync controllers
 * (ADR-043) and since reused by other JSON API controllers
 * (DiscoveryController, ADR-060). Keeps the rate-limit, JSON, body-size,
 * and blob-decode boilerplate in one place so the controllers do not
 * drift. Business logic lives in the services and repositories, never
 * here.
 *
 * Used by classes extending AbstractController (provides json()).
 */
trait AccountApiTrait
{
    private function decodeJson(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Consume one token from $limiter under $key (client IP for anon endpoints,
     * account id for authenticated ones). Returns a 429 response if throttled.
     */
    private function enforce(RateLimiterFactoryInterface $limiter, string $key): ?JsonResponse
    {
        $limit = $limiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            return $this->rateLimited($limit);
        }

        return null;
    }

    private function rateLimited(RateLimit $rateLimit): JsonResponse
    {
        $retryAfter = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());

        return $this->json(
            ['error' => 'Rate limit exceeded'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }

    /**
     * Early reject of oversized bodies via Content-Length, before the body is
     * read into memory and json_decode'd. Bounds memory on the push/registry
     * paths (a large authenticated body would otherwise risk memory_limit).
     */
    private function rejectIfBodyTooLarge(Request $request, int $maxBytes): ?JsonResponse
    {
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && (int) $contentLength > $maxBytes) {
            return $this->json(
                ['error' => sprintf('Request body exceeds maximum of %d bytes', $maxBytes)],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        return null;
    }

    private static function blobToString($blob): string
    {
        if (is_resource($blob)) {
            return (string) stream_get_contents($blob);
        }

        return (string) $blob;
    }
}
