<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Transport-level failure of an external source (timeout, 5xx, undecodable
 * body). Mapped to the '"status": "unavailable"' envelope and never cached
 * (ADR-060 section 3.3).
 */
class DiscoverySourceException extends \RuntimeException
{
}
