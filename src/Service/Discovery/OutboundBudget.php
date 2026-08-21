<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Hub-wide token bucket on external resolution calls (ADR-060 section
 * 3.4): whatever the traffic pattern (organic spike, checksum-valid ISBN
 * flood, buggy client release), the hub never inflicts more than the
 * configured rate on the sources, and FrankenPHP worker occupancy on the
 * slow path stays bounded.
 *
 * Keyed under one global key, unlike the per-IP limiters: this budget
 * protects the sources, not the hub.
 */
class OutboundBudget
{
    private const GLOBAL_KEY = 'global';

    public function __construct(
        private readonly RateLimiterFactoryInterface $discoverySourceBudgetLimiter,
    ) {
    }

    /**
     * Consume one token before an outbound source call; throw when the
     * budget is exhausted so the resolution aborts as 'unavailable'.
     *
     * @throws DiscoveryBudgetExhaustedException
     */
    public function consumeOrFail(): void
    {
        $limit = $this->discoverySourceBudgetLimiter->create(self::GLOBAL_KEY)->consume();
        if (!$limit->isAccepted()) {
            throw new DiscoveryBudgetExhaustedException('Global outbound budget exhausted');
        }
    }
}
