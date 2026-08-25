<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hub-wide token bucket on external resolution calls (ADR-060 section
 * 3.4): whatever the traffic pattern (organic spike, checksum-valid ISBN
 * flood, buggy client release), the hub never inflicts more than the
 * configured rate on the sources, and FrankenPHP worker occupancy on the
 * slow path stays bounded.
 *
 * Keyed under one global key, unlike the per-IP limiters: this budget
 * protects the sources, not the hub.
 *
 * It is also the single gate every outbound call already passes through,
 * which is why the wall-clock deadline of one resolution lives here rather
 * than in a service of its own: same question ("may I make this call"),
 * second dimension.
 */
class OutboundBudget implements ResetInterface
{
    private const GLOBAL_KEY = 'global';

    /**
     * Outbound calls one COLD resolution is expected to need, measured in
     * recette 2026-08-21 on a 7-volume series and held to by the author
     * lane (AuthorResolutionPipeline::EDITION_WORKS_MAX exists to keep it
     * in the same range).
     *
     * Used as the price of admission, not as a quota: a resolution that
     * cannot be paid for in full is refused before its first call rather
     * than aborted halfway. Halfway is the expensive failure, because the
     * tokens already spent buy nothing ('unavailable' is never cached), so
     * the more contention there is, the less of the budget converts into
     * the pooled rows that would have made the next readers free.
     */
    public const COLD_RESOLUTION_CALLS = 21;

    /**
     * Wall-clock ceiling on ONE resolution, every outbound call included.
     *
     * The per-call timeouts (5s REST, 10s SPARQL) are inactivity timeouts:
     * they bound a single call and cannot bound a chain of about twenty.
     * The client gives up at 8s, so the hub renounces at 6s, before the
     * reader does: past that point the worker and the tokens are being
     * spent on an answer nobody will read. 6s is also 2.4x the 2.5s a cold
     * resolution measured in recette, so the nominal case keeps ample
     * room.
     */
    public const RESOLUTION_DEADLINE_SECONDS = 6.0;

    /**
     * Floor on the per-call max_duration derived from the remaining time.
     * Symfony reads max_duration = 0 as "unlimited", so it can never be
     * passed as zero; and a call handed a few milliseconds would die as a
     * source failure, mislabelling in the journal what is really the
     * deadline doing its job. The cost of the floor is that a resolution
     * may overshoot by up to half a second, still well under the client's
     * 8s.
     */
    private const MIN_CALL_SECONDS = 0.5;

    private ?float $deadlineAt = null;

    public function __construct(
        private readonly RateLimiterFactoryInterface $discoverySourceBudgetLimiter,
        private readonly float $deadlineSeconds = self::RESOLUTION_DEADLINE_SECONDS,
    ) {
    }

    /**
     * Open the wall-clock window of one resolution. Paired with reset() in
     * a finally block by the caller, so a thrown resolution cannot leave
     * its deadline behind for the next one.
     */
    public function startResolution(): void
    {
        $this->deadlineAt = microtime(true) + $this->deadlineSeconds;
    }

    /**
     * Close the window. Also the ResetInterface hook, so the deadline
     * cannot survive into another request should the hub ever run in
     * worker mode.
     */
    public function reset(): void
    {
        $this->deadlineAt = null;
    }

    /**
     * Refuse a COLD resolution the budget cannot pay for in full, before
     * its first outbound call.
     *
     * Deliberately not called on the whole request: a warm one costs no
     * outbound call, and gating it would empty the lane of its substance
     * for no source relief. It gates the entity stage only, which is where
     * seventeen of the twenty-one calls are spent; the anchor stage stays
     * on the per-call guard because it costs two or three calls and fills
     * the cheap '*_lookup' rows that survive a failure and pay off later.
     *
     * @throws DiscoveryBudgetExhaustedException
     */
    public function requireHeadroomForColdResolution(): void
    {
        // consume(0) is a pure peek: TokenBucketLimiter::reserve() skips
        // the storage write when zero tokens are asked for.
        $available = $this->discoverySourceBudgetLimiter->create(self::GLOBAL_KEY)->consume(0)->getRemainingTokens();
        if ($available < self::COLD_RESOLUTION_CALLS) {
            throw new DiscoveryBudgetExhaustedException(
                sprintf(
                    'Outbound budget cannot cover a cold resolution (%d tokens available, %d needed)',
                    $available,
                    self::COLD_RESOLUTION_CALLS,
                ),
                DiscoveryBudgetExhaustedException::REASON_INSUFFICIENT,
            );
        }
    }

    /**
     * Consume one token before an outbound source call, and check the
     * resolution has time left; throw when either is spent so the
     * resolution aborts as 'unavailable'.
     *
     * The deadline is checked FIRST: an out-of-time resolution must not
     * pay a token for a call it has already decided not to make.
     *
     * @throws DiscoveryDeadlineExceededException
     * @throws DiscoveryBudgetExhaustedException
     */
    public function consumeOrFail(): void
    {
        if ($this->deadlineAt !== null && microtime(true) >= $this->deadlineAt) {
            throw new DiscoveryDeadlineExceededException(
                sprintf('Resolution exceeded its %.1fs wall-clock deadline', $this->deadlineSeconds),
            );
        }

        $limit = $this->discoverySourceBudgetLimiter->create(self::GLOBAL_KEY)->consume();
        if (!$limit->isAccepted()) {
            throw new DiscoveryBudgetExhaustedException('Global outbound budget exhausted');
        }
    }

    /**
     * Seconds one outbound call may take at most, or null outside a
     * resolution (the clients then keep their own timeouts alone).
     *
     * This is what stops a single hung call from walking past the
     * deadline: the inactivity timeout would let a source dribbling bytes
     * hold the worker indefinitely, and no between-calls check ever runs
     * during one call.
     */
    public function remainingSeconds(): ?float
    {
        if ($this->deadlineAt === null) {
            return null;
        }

        return max(self::MIN_CALL_SECONDS, $this->deadlineAt - microtime(true));
    }
}
