<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Claim access on a source entity (ADR-060). Wikidata-shaped claims reach
 * us through Inventaire in the same envelope whichever graph they come
 * from, so one reader serves every pipeline.
 */
final class Claims
{
    /**
     * String values of one claim, in source order; empty when the claim is
     * absent or malformed.
     *
     * @param array<string, mixed> $entity
     *
     * @return list<string>
     */
    public static function values(array $entity, string $property): array
    {
        $values = $entity['claims'][$property] ?? [];
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * How many values the claim carries, string or not: an edition whose
     * wdt:P629 lists several works is an omnibus whatever the value shapes.
     *
     * @param array<string, mixed> $entity
     */
    public static function count(array $entity, string $property): int
    {
        $values = $entity['claims'][$property] ?? [];

        return is_array($values) ? count($values) : 0;
    }

    /**
     * Publication year from a date claim like "1997" or "1942-10-05".
     *
     * @param array<string, mixed> $entity
     */
    public static function year(array $entity, string $property): ?int
    {
        $values = self::values($entity, $property);
        if (!isset($values[0]) || preg_match('/^(\d{4})/', $values[0], $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
