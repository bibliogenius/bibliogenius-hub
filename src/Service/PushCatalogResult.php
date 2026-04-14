<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CachedCatalog;

/**
 * Result of a catalog push: the stored entity plus a flag indicating
 * whether the push was a no-op (same hash as what the hub already has).
 *
 * Callers can use `$unchanged` to return 304 Not Modified while still
 * reading metadata from `$catalog` (e.g. refreshed `updated_at` / TTL).
 */
final readonly class PushCatalogResult
{
    public function __construct(
        public CachedCatalog $catalog,
        public bool $unchanged,
    ) {}
}
