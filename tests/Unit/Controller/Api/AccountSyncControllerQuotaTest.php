<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\AccountSyncController;
use App\Entity\Account;
use App\Repository\AccountAuthChallengeRepository;
use App\Repository\AccountDeviceRegistryRepository;
use App\Repository\AccountEntityRepository;
use App\Repository\AccountRepository;
use App\Service\AccountAuthService;
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
 * Guards the per-account storage quota on push (ADR-043's reserved quota hook,
 * now enforced). The contract protects hosting costs WITHOUT disturbing normal
 * sync:
 *
 *  - a push that adds ciphertext is rejected with 507 once quota_bytes_used
 *    reaches the limit (per-account quota_bytes_limit, or the default);
 *  - a tombstone-only push MUST pass even over quota, so an over-quota account
 *    can always free space (never lock an account out of shrinking);
 *  - a failing account lookup MUST fail open: the quota exists to bound abuse,
 *    it must never break a legitimate sync;
 *  - the quota counter is recomputed after every accepted push, bounding
 *    counter drift to a single batch.
 */
final class AccountSyncControllerQuotaTest extends TestCase
{
    private const ACCOUNT_ID = 'acct-quota-test';
    private const DEFAULT_QUOTA_BYTES = 512 * 1024 * 1024;

    public function testPushAddingDataIsRejected507WhenOverDefaultQuota(): void
    {
        $lanes = $this->createMock(AccountEntityRepository::class);
        $lanes->expects($this->never())->method('pushLanes');

        $controller = $this->buildController(
            lanes: $lanes,
            accounts: $this->accountsWith(used: self::DEFAULT_QUOTA_BYTES, limit: null),
        );

        $response = $controller->push($this->pushRequest([$this->dataLane()]));

        $this->assertSame(Response::HTTP_INSUFFICIENT_STORAGE, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Storage quota exceeded', $body['error']);
    }

    public function testTombstoneOnlyPushPassesEvenOverQuota(): void
    {
        $lanes = $this->createMock(AccountEntityRepository::class);
        $lanes->expects($this->once())->method('pushLanes')->willReturn(42);

        $controller = $this->buildController(
            lanes: $lanes,
            accounts: $this->accountsWith(used: self::DEFAULT_QUOTA_BYTES * 2, limit: null),
        );

        $response = $controller->push($this->pushRequest([$this->tombstoneLane()]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(1, $body['accepted']);
    }

    public function testPushUnderQuotaPassesAndRecomputesTheCounter(): void
    {
        $lanes = $this->createMock(AccountEntityRepository::class);
        $lanes->expects($this->once())->method('pushLanes')->willReturn(7);
        $lanes->expects($this->once())->method('recomputeQuotaBytes')->with(self::ACCOUNT_ID);

        $controller = $this->buildController(
            lanes: $lanes,
            accounts: $this->accountsWith(used: 1024, limit: null),
        );

        $response = $controller->push($this->pushRequest([$this->dataLane()]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPerAccountLimitOverrideBeatsTheDefault(): void
    {
        $lanes = $this->createMock(AccountEntityRepository::class);
        $lanes->expects($this->never())->method('pushLanes');

        $controller = $this->buildController(
            lanes: $lanes,
            // Tiny bespoke limit, way under the default: must be honored.
            accounts: $this->accountsWith(used: 2048, limit: 1024),
        );

        $response = $controller->push($this->pushRequest([$this->dataLane()]));

        $this->assertSame(Response::HTTP_INSUFFICIENT_STORAGE, $response->getStatusCode());
    }

    public function testQuotaLookupFailureFailsOpen(): void
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findOneBy')->willThrowException(new \RuntimeException('db down'));

        $lanes = $this->createMock(AccountEntityRepository::class);
        $lanes->expects($this->once())->method('pushLanes')->willReturn(3);

        $controller = $this->buildController(lanes: $lanes, accounts: $accounts);

        $response = $controller->push($this->pushRequest([$this->dataLane()]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testApproachingWarningFiresWhenPushCrossesTheThreshold(): void
    {
        // limit 1000 -> warn at 800. Before: 100 (below), after recompute: 850.
        $lanes = $this->createStub(AccountEntityRepository::class);
        $lanes->method('pushLanes')->willReturn(5);
        $lanes->method('recomputeQuotaBytes')->willReturn(850);

        $logger = $this->createMock(HubEventLogger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('account_sync', 'account approaching storage quota', $this->anything());

        $controller = $this->buildController(
            lanes: $lanes,
            accounts: $this->accountsWith(used: 100, limit: 1000),
            eventLogger: $logger,
        );

        $response = $controller->push($this->pushRequest([$this->dataLane()]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testNoApproachingWarningWhenAlreadyAboveThreshold(): void
    {
        // Before: 900 (already above 800): a push that stays above must NOT
        // re-emit the warning on every cycle (event-flood guard).
        $lanes = $this->createStub(AccountEntityRepository::class);
        $lanes->method('pushLanes')->willReturn(6);
        $lanes->method('recomputeQuotaBytes')->willReturn(950);

        $logger = $this->createMock(HubEventLogger::class);
        $logger->expects($this->never())->method('warning');

        $controller = $this->buildController(
            lanes: $lanes,
            accounts: $this->accountsWith(used: 900, limit: 1000),
            eventLogger: $logger,
        );

        $response = $controller->push($this->pushRequest([$this->dataLane()]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRecomputeFailureIsLoggedButDoesNotFailThePush(): void
    {
        $lanes = $this->createStub(AccountEntityRepository::class);
        $lanes->method('pushLanes')->willReturn(9);
        $lanes->method('recomputeQuotaBytes')->willThrowException(new \RuntimeException('agg failed'));

        $logger = $this->createMock(HubEventLogger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('account_sync', 'quota recompute failed', $this->anything());

        $controller = $this->buildController(
            lanes: $lanes,
            accounts: $this->accountsWith(used: 100, limit: 1000),
            eventLogger: $logger,
        );

        $response = $controller->push($this->pushRequest([$this->dataLane()]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Harness (same pattern as RelayControllerRateLimitTest)
    // ------------------------------------------------------------------

    private function accountsWith(int $used, ?int $limit): AccountRepository
    {
        $account = new Account();
        $account->setQuotaBytesUsed($used)->setQuotaBytesLimit($limit);

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findOneBy')->willReturn($account);

        return $accounts;
    }

    private function dataLane(): array
    {
        return [
            'opaque_id' => 'oid-data-1',
            'deleted' => false,
            'size_bucket' => 1024,
            'blob' => base64_encode('ciphertext'),
        ];
    }

    private function tombstoneLane(): array
    {
        return [
            'opaque_id' => 'oid-tomb-1',
            'deleted' => true,
            'size_bucket' => 0,
            'blob' => null,
        ];
    }

    private function pushRequest(array $lanes): Request
    {
        $request = new Request(content: (string) json_encode([
            'device_id' => 'device-web-test',
            'lanes' => $lanes,
        ]));
        $request->headers->set('Authorization', 'Bearer dummy-token');

        return $request;
    }

    private function acceptingLimiter(): RateLimiterFactoryInterface
    {
        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    private function buildController(
        AccountEntityRepository $lanes,
        AccountRepository $accounts,
        ?HubEventLogger $eventLogger = null,
    ): AccountSyncController {
        $auth = $this->createStub(AccountAuthService::class);
        $auth->method('authenticate')->willReturn(self::ACCOUNT_ID);

        $controller = new AccountSyncController(
            $this->createStub(EntityManagerInterface::class),
            $lanes,
            $accounts,
            $this->createStub(AccountDeviceRegistryRepository::class),
            $this->createStub(AccountAuthChallengeRepository::class),
            $auth,
            $eventLogger ?? $this->createStub(HubEventLogger::class),
            $this->acceptingLimiter(),
            $this->acceptingLimiter(),
        );
        $controller->setContainer(new Container());

        return $controller;
    }
}
