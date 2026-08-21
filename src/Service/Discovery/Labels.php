<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use function Symfony\Component\String\u;

/**
 * Label handling shared by every discovery lane (ADR-060).
 *
 * Both halves of this class are load-bearing for precision, and both were
 * learned the hard way in the volet 1 recette (2026-08-21), so they live in
 * ONE place rather than once per pipeline:
 *
 *   - [self::pick()] must accept the 'mul' language code. Wikidata moved
 *     labels that are identical across Latin-script languages to that code
 *     and DROPPED the per-language ones, so an en/fr-only chain returns the
 *     first label in alphabetical order of language (Amharic, in the case
 *     that was found) or nothing at all for entities as central as
 *     J. K. Rowling.
 *   - [self::normalize()] is the comparison form of every name and title
 *     match: the series name tiebreaker, the author name verification and
 *     the title deduplication.
 */
final class Labels
{
    /**
     * Normalized comparison form: lowercase, fold diacritics, keep
     * alphanumeric words joined by single spaces.
     */
    public static function normalize(string $s): string
    {
        $folded = u($s)->ascii()->lower()->toString();
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', $words ?: []);
    }

    /**
     * True when two person names denote the same normalized name, either
     * written the same way or with the same words in another order.
     *
     * The word-order clause covers the one divergence that is not a
     * homonym risk: catalogues store "Le Guin, Ursula K." where the source
     * holds "Ursula K. Le Guin". Anything looser (initials dropped, one
     * name a prefix of the other) is refused on purpose: "Alexandre Dumas"
     * and "Alexandre Dumas fils" are two people, and author completion
     * showing one for the other is exactly the humiliation ADR-060 section
     * 3.2 forbids.
     */
    public static function sameName(string $a, string $b): bool
    {
        $left = self::normalize($a);
        $right = self::normalize($b);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }
        $leftWords = explode(' ', $left);
        $rightWords = explode(' ', $right);
        sort($leftWords);
        sort($rightWords);

        return $leftWords === $rightWords;
    }

    /**
     * Best display label of a source entity: English, then French, then
     * 'mul', then whatever comes first.
     *
     * @param array<string, mixed> $entity
     */
    public static function pick(array $entity): ?string
    {
        $labels = $entity['labels'] ?? [];
        if (!is_array($labels) || $labels === []) {
            return null;
        }
        foreach (['en', 'fr', 'mul'] as $lang) {
            if (isset($labels[$lang]) && is_string($labels[$lang])) {
                return $labels[$lang];
            }
        }
        $first = reset($labels);

        return is_string($first) ? $first : null;
    }

    /**
     * How many languages label this entity: the notability proxy the
     * author lane ranks works with. A work translated into eighty
     * languages outranks one known to a single catalogue, which is the
     * ordering a reader expects from "works by an author you like", and it
     * costs nothing beyond the entity fetch the pipeline already makes.
     *
     * @param array<string, mixed> $entity
     */
    public static function languageCount(array $entity): int
    {
        $labels = $entity['labels'] ?? [];

        return is_array($labels) ? count($labels) : 0;
    }
}
