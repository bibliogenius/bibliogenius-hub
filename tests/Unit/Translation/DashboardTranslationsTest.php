<?php

declare(strict_types=1);

namespace App\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the two-locale admin catalogue, which nothing else can catch: a
 * missing key renders as its own name, and a missing placeholder renders
 * as a sentence with a hole in it. Both only show up in the browser, in
 * the locale nobody was testing.
 *
 *  - the English and French catalogues hold exactly the same keys;
 *  - every dotted key the dashboard template asks for exists in both,
 *    whatever its namespace;
 *  - a translation carries the same %placeholders% as its counterpart, so
 *    a figure cannot vanish from one language.
 */
final class DashboardTranslationsTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/admin/dashboard_stats.html.twig';

    /** Keys built at render time from a loop variable, invisible to a static scan. */
    private const DYNAMIC_KEYS = [
        'discovery.reason.no_anchor_resolved',
        'discovery.reason.disjoint_anchors',
        'discovery.reason.no_clear_winner',
        'discovery.reason.name_not_verified',
        'discovery.reason.no_usable_members',
        'discovery.reason.no_usable_works',
    ];

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        $parsed = Yaml::parseFile(__DIR__ . '/../../../translations/messages.' . $locale . '.yaml');
        $flat = [];
        $flatten = static function (array $node, string $prefix) use (&$flatten, &$flat): void {
            foreach ($node as $key => $value) {
                $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) {
                    $flatten($value, $full);
                } else {
                    $flat[$full] = (string) $value;
                }
            }
        };
        $flatten($parsed, '');

        return $flat;
    }

    public function testBothLocalesHoldTheSameKeys(): void
    {
        $en = array_keys($this->catalogue('en'));
        $fr = array_keys($this->catalogue('fr'));
        sort($en);
        sort($fr);

        $this->assertSame($en, $fr, 'a key exists in one locale only, so one language renders the raw key');
    }

    public function testEveryKeyTheDashboardAsksForExists(): void
    {
        $template = file_get_contents(self::TEMPLATE);
        // Namespace-agnostic on purpose: a key is any dotted name piped to
        // trans, so a section translated later is covered the day it lands
        // rather than the day someone remembers to widen this regex. Flat
        // keys carry no dot and belong to the untranslated remainder.
        preg_match_all("/'([a-z][a-z0-9_]*\.[a-z0-9_.]+)'\s*\|\s*trans/i", $template, $matches);
        $used = array_unique(array_merge($matches[1], self::DYNAMIC_KEYS));
        sort($used);

        $this->assertNotEmpty($used, 'the scan itself must keep working if the template is reshaped');

        $en = $this->catalogue('en');
        $fr = $this->catalogue('fr');
        foreach ($used as $key) {
            $this->assertArrayHasKey($key, $en, sprintf('%s is used by the dashboard but missing in English', $key));
            $this->assertArrayHasKey($key, $fr, sprintf('%s is used by the dashboard but missing in French', $key));
        }
    }

    public function testTranslationsCarryTheSamePlaceholders(): void
    {
        $en = $this->catalogue('en');
        $fr = $this->catalogue('fr');

        foreach ($en as $key => $english) {
            preg_match_all('/%[a-z_]+%/', $english, $enPlaceholders);
            preg_match_all('/%[a-z_]+%/', $fr[$key] ?? '', $frPlaceholders);
            $expected = array_unique($enPlaceholders[0]);
            $actual = array_unique($frPlaceholders[0]);
            sort($expected);
            sort($actual);

            $this->assertSame($expected, $actual, sprintf('%s: the two locales interpolate different values', $key));
        }
    }
}
