<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * The hub-wide outbound budget toward the sources is exhausted (ADR-060
 * section 3.4). Cache misses map to '"status": "unavailable"' instead of
 * queuing; nothing is cached.
 */
class DiscoveryBudgetExhaustedException extends \RuntimeException
{
}
