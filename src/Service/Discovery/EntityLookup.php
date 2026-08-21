<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Batched entity attribute lookups shared by the discovery pipelines
 * (ADR-060): labels of a set of entities, and ISO 639-1 codes of language
 * entities. Both are pure fan-in over InventaireClient::entitiesByUris(),
 * which already folds the API's `redirects` map back in, so a merged or
 * ISBN-addressed uri resolves like any other.
 */
class EntityLookup
{
    public function __construct(
        private readonly InventaireClient $inventaire,
    ) {
    }

    /**
     * Display labels for a set of entity URIs.
     *
     * @param list<string> $uris
     *
     * @return array<string, string> uri => label
     */
    public function labels(array $uris): array
    {
        if ($uris === []) {
            return [];
        }
        $labels = [];
        foreach ($this->inventaire->entitiesByUris($uris) as $uri => $entity) {
            if (is_array($entity)) {
                $label = Labels::pick($entity);
                if ($label !== null) {
                    $labels[$uri] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * ISO 639-1 codes for language entities (claim P218).
     *
     * @param list<string> $uris
     *
     * @return array<string, string> uri => code
     */
    public function languageCodes(array $uris): array
    {
        if ($uris === []) {
            return [];
        }
        $codes = [];
        foreach ($this->inventaire->entitiesByUris($uris) as $uri => $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $values = Claims::values($entity, 'wdt:P218');
            if (isset($values[0])) {
                $codes[$uri] = strtolower($values[0]);
            }
        }

        return $codes;
    }
}
