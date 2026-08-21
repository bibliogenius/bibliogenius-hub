<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\DiscoveryController;
use App\Service\DiscoveryResolverService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Freezes the ADR-060 section 3.1 request contract of both lanes: strict,
 * cheap validation before any cache or source access (checksum-valid ISBNs
 * capped at 3, opaque length-capped name, short language codes capped at
 * 8), per-IP rate limiting with Retry-After, and the always-200 envelope
 * for every resolution outcome.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DiscoveryControllerTest extends TestCase
{
    private function buildLimiter(bool $accepted, int $retryAfterSeconds = 0): RateLimiterFactoryInterface
    {
        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn($accepted);
        $rateLimit->method('getRetryAfter')->willReturn(
            new \DateTimeImmutable('@' . (time() + $retryAfterSeconds)),
        );

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    private function controller(
        ?DiscoveryResolverService $resolver = null,
        ?RateLimiterFactoryInterface $limiter = null,
    ): DiscoveryController {
        $controller = new DiscoveryController(
            $resolver ?? $this->createStub(DiscoveryResolverService::class),
            $limiter ?? $this->buildLimiter(true),
        );
        // AbstractController::json() falls back to new JsonResponse() when
        // the container has no 'serializer' service - an empty container
        // therefore exercises the same code path as production.
        $controller->setContainer(new Container());

        return $controller;
    }

    private static function request(mixed $body, string $path = '/api/discovery/series'): Request
    {
        return Request::create(
            $path,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            is_string($body) ? $body : (string) json_encode($body),
        );
    }

    private static function authorRequest(mixed $body): Request
    {
        return self::request($body, '/api/discovery/author');
    }

    public function testRateLimitedRequestGets429WithRetryAfter(): void
    {
        $resolver = $this->createMock(DiscoveryResolverService::class);
        $resolver->expects($this->never())->method('resolveSeries');

        $response = $this->controller($resolver, $this->buildLimiter(false, 30))
            ->series(self::request(['isbns' => ['9782070541270']]));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function testInvalidJsonBodyIs400(): void
    {
        $response = $this->controller()->series(self::request('this is not json'));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMissingEmptyOrOversizedIsbnArrayIs400(): void
    {
        $controller = $this->controller();
        $this->assertSame(400, $controller->series(self::request(['name' => 'x']))->getStatusCode());
        $this->assertSame(400, $controller->series(self::request(['isbns' => []]))->getStatusCode());
        $this->assertSame(400, $controller->series(self::request([
            'isbns' => ['9782070541270', '9780747532699', '9780306406157', '9780441007318'],
        ]))->getStatusCode());
    }

    public function testChecksumInvalidIsbnIs400(): void
    {
        $resolver = $this->createMock(DiscoveryResolverService::class);
        $resolver->expects($this->never())->method('resolveSeries');

        // One valid anchor does not save a request carrying a bad one.
        $response = $this->controller($resolver)->series(self::request([
            'isbns' => ['9782070541270', '9780306406150'],
        ]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testOversizedNameIs400(): void
    {
        $response = $this->controller()->series(self::request([
            'isbns' => ['9782070541270'],
            'name' => str_repeat('a', 257),
        ]));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMalformedLangsAre400(): void
    {
        $controller = $this->controller();
        $this->assertSame(400, $controller->series(self::request([
            'isbns' => ['9782070541270'],
            'langs' => ['français'],
        ]))->getStatusCode());
        $this->assertSame(400, $controller->series(self::request([
            'isbns' => ['9782070541270'],
            'langs' => ['f'],
        ]))->getStatusCode());
        $this->assertSame(400, $controller->series(self::request([
            'isbns' => ['9782070541270'],
            'langs' => ['fr', 'en', 'es', 'de', 'it', 'pt', 'tr', 'bg', 'ja'],
        ]))->getStatusCode());
    }

    public function testAuthorRequestWithoutANameIs400(): void
    {
        $resolver = $this->createMock(DiscoveryResolverService::class);
        $resolver->expects($this->never())->method('resolveAuthor');
        $controller = $this->controller($resolver);

        // The name is not a tiebreaker here but the thing the resolver
        // verifies the anchor's author entity against: without it there is
        // nothing to protect against homonyms.
        $this->assertSame(400, $controller->author(self::authorRequest([
            'anchor_isbns' => ['9782070360024'],
        ]))->getStatusCode());
        $this->assertSame(400, $controller->author(self::authorRequest([
            'name' => '   ',
            'anchor_isbns' => ['9782070360024'],
        ]))->getStatusCode());
        $this->assertSame(400, $controller->author(self::authorRequest([
            'name' => str_repeat('a', 257),
            'anchor_isbns' => ['9782070360024'],
        ]))->getStatusCode());
    }

    public function testAuthorAnchorIsbnsAreValidatedLikeSeriesIsbns(): void
    {
        $resolver = $this->createMock(DiscoveryResolverService::class);
        $resolver->expects($this->never())->method('resolveAuthor');
        $controller = $this->controller($resolver);

        $this->assertSame(400, $controller->author(self::authorRequest([
            'name' => 'Albert Camus',
        ]))->getStatusCode());
        $this->assertSame(400, $controller->author(self::authorRequest([
            'name' => 'Albert Camus',
            'anchor_isbns' => ['9782070360025'],
        ]))->getStatusCode());
        $this->assertSame(400, $controller->author(self::authorRequest([
            'name' => 'Albert Camus',
            'anchor_isbns' => ['9782070360024', '9780747532699', '9780306406157', '9780441007318'],
        ]))->getStatusCode());
        $this->assertSame(400, $controller->author(self::authorRequest([
            'name' => 'Albert Camus',
            'anchor_isbns' => ['9782070360024'],
            'langs' => ['français'],
        ]))->getStatusCode());
    }

    public function testRateLimitedAuthorRequestGets429BeforeAnyResolution(): void
    {
        $resolver = $this->createMock(DiscoveryResolverService::class);
        $resolver->expects($this->never())->method('resolveAuthor');

        $response = $this->controller($resolver, $this->buildLimiter(false, 30))
            ->author(self::authorRequest(['name' => 'Albert Camus', 'anchor_isbns' => ['9782070360024']]));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function testValidAuthorRequestTrimsTheNameAndReturnsTheEnvelope(): void
    {
        $resolver = $this->createMock(DiscoveryResolverService::class);
        $resolver->expects($this->once())
            ->method('resolveAuthor')
            ->with('Ursula K. Le Guin', ['9780441007318'], ['fr', 'en'])
            ->willReturn(['status' => 'ambiguous']);

        $response = $this->controller($resolver)->author(self::authorRequest([
            'name' => '  Ursula K. Le Guin  ',
            'anchor_isbns' => ['0-441-00731-7'],
            'langs' => ['fr', 'en'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ambiguous'], json_decode((string) $response->getContent(), true));
    }

    public function testValidRequestCanonicalizesIsbnsAndReturnsTheEnvelope(): void
    {
        $resolver = $this->createMock(DiscoveryResolverService::class);
        $resolver->expects($this->once())
            ->method('resolveSeries')
            // ISBN-10 input reaches the resolver as canonical ISBN-13.
            ->with(['9780441007318'], 'Hainish Cycle', ['fr', 'pt-BR'])
            ->willReturn(['status' => 'unknown']);

        $response = $this->controller($resolver)->series(self::request([
            'isbns' => ['0-441-00731-7'],
            'name' => 'Hainish Cycle',
            'langs' => ['fr', 'pt-BR'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'unknown'], json_decode((string) $response->getContent(), true));
    }
}
