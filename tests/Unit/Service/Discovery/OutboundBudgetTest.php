<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\DiscoveryDeadlineExceededException;
use App\Service\Discovery\OutboundBudget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Guards the two ceilings the outbound budget carries (ADR-060 section
 * 3.4): the token bucket that protects the sources, and the wall-clock
 * deadline that protects the four FrankenPHP workers.
 *
 * Built on a real TokenBucketLimiter over InMemoryStorage rather than a
 * mocked RateLimit: the admission check relies on consume(0) being a pure
 * peek, which is a property of the real limiter, and a mock would assert
 * nothing about it.
 */
final class OutboundBudgetTest extends TestCase
{
    private function budget(int $limit, float $deadlineSeconds = OutboundBudget::RESOLUTION_DEADLINE_SECONDS): OutboundBudget
    {
        $factory = new RateLimiterFactory(
            [
                'id' => 'discovery_source_budget',
                'policy' => 'token_bucket',
                'limit' => $limit,
                'rate' => ['interval' => '1 minute', 'amount' => 60],
            ],
            new InMemoryStorage(),
        );

        return new OutboundBudget($factory, $deadlineSeconds);
    }

    // -------------------------------------------------------------------
    // Admission: never start what cannot be finished
    // -------------------------------------------------------------------

    public function testAdmitsAColdResolutionWhenTheBudgetCanCoverIt(): void
    {
        $budget = $this->budget(60);

        $budget->requireHeadroomForColdResolution();

        $this->expectNotToPerformAssertions();
    }

    /**
     * The expensive failure this exists to prevent: the bucket holds fewer
     * tokens than a cold resolution needs, so the resolution would die
     * halfway with its tokens already spent and nothing cached. Refusing
     * it up front is free and leaves those tokens to a resolution that can
     * actually finish.
     */
    public function testRefusesAColdResolutionTheBudgetCannotCover(): void
    {
        $budget = $this->budget(OutboundBudget::COLD_RESOLUTION_CALLS - 1);

        try {
            $budget->requireHeadroomForColdResolution();
            $this->fail('Expected the admission check to refuse the resolution');
        } catch (DiscoveryBudgetExhaustedException $e) {
            // The journal has to tell "refused up front" from "died
            // halfway": they cost the sources very differently.
            $this->assertSame(DiscoveryBudgetExhaustedException::REASON_INSUFFICIENT, $e->reason());
        }
    }

    /**
     * The admission check must not itself spend the budget it is
     * measuring, or an onboarding sweep would pay a token per resolution
     * just to be told it may proceed.
     */
    public function testAdmissionCheckConsumesNothing(): void
    {
        $budget = $this->budget(60);

        for ($i = 0; $i < 5; ++$i) {
            $budget->requireHeadroomForColdResolution();
        }

        // All 60 tokens still there: 59 consumptions succeed, the 60th too.
        for ($i = 0; $i < 60; ++$i) {
            $budget->consumeOrFail();
        }

        $this->expectException(DiscoveryBudgetExhaustedException::class);
        $budget->consumeOrFail();
    }

    public function testMidFlightExhaustionKeepsItsOwnReason(): void
    {
        $budget = $this->budget(1);
        $budget->consumeOrFail();

        try {
            $budget->consumeOrFail();
            $this->fail('Expected the bucket to be empty');
        } catch (DiscoveryBudgetExhaustedException $e) {
            $this->assertSame(DiscoveryBudgetExhaustedException::REASON_EXHAUSTED, $e->reason());
        }
    }

    // -------------------------------------------------------------------
    // Wall-clock deadline
    // -------------------------------------------------------------------

    /**
     * A resolution chains about twenty calls, so per-call inactivity
     * timeouts bound none of it. Once the allowance is spent, the next
     * call is refused rather than made.
     */
    public function testRefusesTheNextCallOnceTheDeadlineHasPassed(): void
    {
        $budget = $this->budget(60, 0.0);
        $budget->startResolution();

        $this->expectException(DiscoveryDeadlineExceededException::class);
        $budget->consumeOrFail();
    }

    /** An expired resolution must not pay for a call it will not make. */
    public function testAnExpiredResolutionSpendsNoToken(): void
    {
        $budget = $this->budget(1, 0.0);
        $budget->startResolution();

        try {
            $budget->consumeOrFail();
        } catch (DiscoveryDeadlineExceededException) {
            // expected
        }

        $budget->reset();
        // The single token survived the refused call.
        $budget->consumeOrFail();
        $this->expectNotToPerformAssertions();
    }

    public function testCallsProceedWhileTheResolutionHasTimeLeft(): void
    {
        $budget = $this->budget(60);
        $budget->startResolution();

        $budget->consumeOrFail();
        $budget->consumeOrFail();

        $this->expectNotToPerformAssertions();
    }

    /**
     * The deadline must not survive its resolution: a leftover one would
     * silently shorten the next resolution served by the same worker.
     */
    public function testResetClosesTheWindow(): void
    {
        $budget = $this->budget(60, 0.0);
        $budget->startResolution();
        $budget->reset();

        $budget->consumeOrFail();

        $this->assertNull($budget->remainingSeconds());
    }

    // -------------------------------------------------------------------
    // Per-call allowance derived from the deadline
    // -------------------------------------------------------------------

    public function testRemainingSecondsIsNullOutsideAResolution(): void
    {
        $this->assertNull($this->budget(60)->remainingSeconds());
    }

    public function testRemainingSecondsShrinksWithinTheDeadline(): void
    {
        $budget = $this->budget(60, 4.0);
        $budget->startResolution();

        $remaining = $budget->remainingSeconds();

        $this->assertNotNull($remaining);
        $this->assertLessThanOrEqual(4.0, $remaining);
        $this->assertGreaterThan(3.0, $remaining);
    }

    /**
     * Symfony reads max_duration = 0 as "unlimited", which would turn the
     * deadline into its exact opposite, so the derived allowance can never
     * reach zero.
     */
    public function testRemainingSecondsNeverReachesZero(): void
    {
        $budget = $this->budget(60, 0.0);
        $budget->startResolution();

        $remaining = $budget->remainingSeconds();

        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0.0, $remaining);
    }

    /** The hub must give up before the client does, never after. */
    public function testTheDeadlineSitsUnderTheClientTimeout(): void
    {
        $this->assertLessThan(8.0, OutboundBudget::RESOLUTION_DEADLINE_SECONDS);
    }
}
