<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\RelayController;
use App\Repository\Deposit404LogRepository;
use App\Repository\RelayMailboxRepository;
use App\Repository\RelayMessageRepository;
use App\Service\HubEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Guards the per-IP anti-hammering contract on public relay endpoints:
 *
 *  - depositMessage / collectMessages MUST consult the limiter BEFORE the
 *    mailbox DB lookup. The whole point of the limiter is to spare the DB
 *    (and the deposit_404_log upsert) when an IP hammers with random valid
 *    UUIDs - if the lookup ran first, the limiter would only protect CPU
 *    after the damage was done.
 *  - createMailbox MUST consult the limiter BEFORE writing a fresh row to
 *    relay_mailboxes - otherwise an IP can fill the table even if 429s are
 *    returned afterwards.
 *  - The 429 response MUST carry a numeric Retry-After header derived from
 *    the limiter's own clock so honest clients (peers retrying their flush
 *    after a catalog rebuild burst) back off by the correct amount.
 */
final class RelayControllerRateLimitTest extends TestCase
{
    private const VALID_UUID = '5048d99b-cd0d-4fea-9fb0-6e5db1e9e848';

    public function testDepositReturns429AndSkipsDbWhenRateLimited(): void
    {
        $mailboxRepo = $this->createMock(RelayMailboxRepository::class);
        $mailboxRepo->expects($this->never())->method('findByUuid');

        $deposit404Log = $this->createMock(Deposit404LogRepository::class);
        $deposit404Log->expects($this->never())->method('recordHit');

        $controller = $this->buildController(
            mailboxRepo: $mailboxRepo,
            deposit404Log: $deposit404Log,
            depositLimiter: $this->buildLimiter(accepted: false, retryAfterSeconds: 30),
        );

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer dummy-token');

        $response = $controller->depositMessage(self::VALID_UUID, $request);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertSame('30', $response->headers->get('Retry-After'));
    }

    public function testDepositConsumesLimiterBeforeDbLookupWhenAccepted(): void
    {
        $mailboxRepo = $this->createMock(RelayMailboxRepository::class);
        $mailboxRepo->expects($this->once())
            ->method('findByUuid')
            ->with(self::VALID_UUID)
            ->willReturn(null);

        $deposit404Log = $this->createMock(Deposit404LogRepository::class);
        $deposit404Log->expects($this->once())->method('recordHit')->with(self::VALID_UUID);

        $controller = $this->buildController(
            mailboxRepo: $mailboxRepo,
            deposit404Log: $deposit404Log,
            depositLimiter: $this->buildLimiter(accepted: true),
        );

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer dummy-token');

        $response = $controller->depositMessage(self::VALID_UUID, $request);

        // Limiter accepted, mailbox missing -> regular 404 path runs through.
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testCreateMailboxReturns429AndSkipsPersistWhenRateLimited(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $controller = $this->buildController(
            entityManager: $em,
            mailboxCreateLimiter: $this->buildLimiter(accepted: false, retryAfterSeconds: 5),
        );

        $response = $controller->createMailbox(new Request());

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertSame('5', $response->headers->get('Retry-After'));
    }

    public function testCollectReturns429AndSkipsDbWhenRateLimited(): void
    {
        $mailboxRepo = $this->createMock(RelayMailboxRepository::class);
        $mailboxRepo->expects($this->never())->method('findByUuid');

        $controller = $this->buildController(
            mailboxRepo: $mailboxRepo,
            collectLimiter: $this->buildLimiter(accepted: false, retryAfterSeconds: 1),
        );

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer dummy-token');

        $response = $controller->collectMessages(self::VALID_UUID, $request);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
    }

    public function testRetryAfterFloorIsOneSecondEvenWhenLimiterClockHasAlreadyPassed(): void
    {
        // getRetryAfter() in the past would yield a negative value. The
        // controller must clamp to 1 so honest clients always get a usable
        // hint instead of "Retry-After: -3".
        $controller = $this->buildController(
            depositLimiter: $this->buildLimiter(accepted: false, retryAfterSeconds: -3),
        );

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer dummy-token');

        $response = $controller->depositMessage(self::VALID_UUID, $request);

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
    }

    private function buildLimiter(bool $accepted, int $retryAfterSeconds = 0): RateLimiterFactoryInterface
    {
        // Stubs (no call-count assertions) - the contract under test is the
        // controller's response, not how many times it pokes the limiter.
        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn($accepted);
        $rateLimit->method('getRetryAfter')->willReturn(
            new \DateTimeImmutable('@'.(time() + $retryAfterSeconds)),
        );

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    private function buildController(
        ?RelayMailboxRepository $mailboxRepo = null,
        ?RelayMessageRepository $messageRepo = null,
        ?EntityManagerInterface $entityManager = null,
        ?Deposit404LogRepository $deposit404Log = null,
        ?RateLimiterFactoryInterface $depositLimiter = null,
        ?RateLimiterFactoryInterface $mailboxCreateLimiter = null,
        ?RateLimiterFactoryInterface $collectLimiter = null,
    ): RelayController {
        // Tests pass explicit mocks for the deps they assert against.
        // The unused fillers are stubs - createMock would emit a "no
        // expectations configured" notice for every test.
        $controller = new RelayController(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $mailboxRepo ?? $this->createStub(RelayMailboxRepository::class),
            $messageRepo ?? $this->createStub(RelayMessageRepository::class),
            $this->createStub(HubEventLogger::class),
            $deposit404Log ?? $this->createStub(Deposit404LogRepository::class),
            $depositLimiter ?? $this->buildLimiter(accepted: true),
            $mailboxCreateLimiter ?? $this->buildLimiter(accepted: true),
            $collectLimiter ?? $this->buildLimiter(accepted: true),
            null,
        );
        // AbstractController::json() falls back to new JsonResponse() when
        // the container has no 'serializer' service - an empty container
        // therefore exercises the same code path as production.
        $controller->setContainer(new Container());

        return $controller;
    }
}
