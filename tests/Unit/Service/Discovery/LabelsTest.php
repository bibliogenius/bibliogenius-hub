<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\Labels;
use PHPUnit\Framework\TestCase;

/**
 * The two rules every discovery lane leans on (ADR-060): which label to
 * read from a source entity, and when two names are the same name.
 */
final class LabelsTest extends TestCase
{
    public function testNormalizeFoldsCaseDiacriticsAndPunctuation(): void
    {
        $this->assertSame('harry potter', Labels::normalize("  Harry Pötter! "));
        $this->assertSame('l integrale', Labels::normalize("L'Intégrale"));
        $this->assertSame('', Labels::normalize('...'));
    }

    /**
     * Wikidata moved labels identical across Latin-script languages to the
     * 'mul' code and dropped the per-language ones. An en/fr-only chain
     * therefore returns the first label in alphabetical order of language
     * for entities as central as J. K. Rowling, which is how the volet 1
     * recette found a series labelled in Amharic.
     */
    public function testPickAcceptsTheMultilingualCodeBeforeFallingThrough(): void
    {
        $this->assertSame('J. K. Rowling', Labels::pick([
            'labels' => ['am' => 'ጄ. ኬ. ሮውሊንግ', 'mul' => 'J. K. Rowling'],
        ]));
        $this->assertSame('Harry Potter', Labels::pick([
            'labels' => ['mul' => 'Harry Potter mul', 'fr' => 'Harry Potter'],
        ]));
        $this->assertNull(Labels::pick(['labels' => []]));
        $this->assertNull(Labels::pick([]));
    }

    public function testSameNameAcceptsWordOrderButNotMissingWords(): void
    {
        $this->assertTrue(Labels::sameName('Ursula K. Le Guin', 'ursula k le guin'));
        // Catalogues store "Lastname, Firstname" where the sources hold the
        // natural order: the same words, so the same person.
        $this->assertTrue(Labels::sameName('Le Guin, Ursula K.', 'Ursula K. Le Guin'));
        $this->assertTrue(Labels::sameName('Gabriel García Márquez', 'Gabriel Garcia Marquez'));

        // Anything looser would offer one person's books for another's:
        // these two are father and son, and neither is the other.
        $this->assertFalse(Labels::sameName('Alexandre Dumas', 'Alexandre Dumas fils'));
        $this->assertFalse(Labels::sameName('Ursula Le Guin', 'Ursula K. Le Guin'));
        $this->assertFalse(Labels::sameName('', 'Albert Camus'));
        $this->assertFalse(Labels::sameName('...', 'Albert Camus'));
    }

    public function testLanguageCountIsTheNotabilityProxy(): void
    {
        $this->assertSame(3, Labels::languageCount(['labels' => ['en' => 'a', 'fr' => 'b', 'de' => 'c']]));
        $this->assertSame(0, Labels::languageCount(['labels' => 'broken']));
        $this->assertSame(0, Labels::languageCount([]));
    }
}
