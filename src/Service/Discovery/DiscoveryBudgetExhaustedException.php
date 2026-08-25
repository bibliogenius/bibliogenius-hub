<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * The hub-wide outbound budget toward the sources is exhausted (ADR-060
 * section 3.4). Cache misses map to '"status": "unavailable"' instead of
 * queuing; nothing is cached.
 *
 * Two ways to run out, told apart because they cost very differently. The
 * bucket can empty MID-RESOLUTION, which is the expensive one: the calls
 * already made are spent and the payload is never written, so the tokens
 * bought nothing. Or the resolution can be refused UP FRONT, when the
 * bucket cannot cover a cold resolution at the point where one is about to
 * start; that answer is immediate and free. Both keep the same
 * 'unavailable' envelope, both are source pressure, and /admin counts them
 * together; the reason is what says which of the two is happening.
 */
class DiscoveryBudgetExhaustedException extends \RuntimeException
{
    /** The bucket emptied while the resolution was already running. */
    public const REASON_EXHAUSTED = 'outbound_budget_exhausted';

    /** The bucket could not cover a cold resolution, refused before starting. */
    public const REASON_INSUFFICIENT = 'outbound_budget_insufficient';

    public function __construct(
        string $message,
        private readonly string $reason = self::REASON_EXHAUSTED,
    ) {
        parent::__construct($message);
    }

    /** Journalling key, never the message: messages carry numbers, reasons group. */
    public function reason(): string
    {
        return $this->reason;
    }
}
