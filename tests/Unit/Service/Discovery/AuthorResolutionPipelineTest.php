<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\AuthorResolutionPipeline;
use App\Service\Discovery\DiscoveryBudgetExhaustedException;
use App\Service\Discovery\EditionResolver;
use App\Service\Discovery\EntityLookup;
use App\Service\Discovery\InventaireClient;
use App\Service\Discovery\OutboundBudget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Freezes the ADR-060 section 3.2 author pipeline against fixtures
 * CAPTURED from the real Inventaire API (Albert Camus, 2026-08-21):
 * entities keyed by canonical uri with a separate redirects map, real
 * label maps (the notability proxy), real omnibus editions claiming a
 * dozen works, and works whose only editions carry no ISBN.
 *
 * The volet 1 recette proved what invented fixtures are worth: 175 green
 * tests on a feature that could not resolve anything in production,
 * because the mock answered a shape the API does not have. These
 * assertions therefore check the QUALITY of the payload (titles, authors,
 * editions), not only that something came back: a resolution that
 * succeeds with empty fields is exactly what the drift sentinel of
 * section 3.5 cannot see.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class AuthorResolutionPipelineTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/discovery';

    /** Anchor: the French Gallimard edition of "L'Etranger". */
    private const ANCHOR_ISBN = '9782070360024';

    private const AUTHOR_URI = 'wd:Q34670';
    private const AUTHOR_NAME = 'Albert Camus';

    /** "Oeuvres" (Pleiade), one edition claiming seventeen works. */
    private const OMNIBUS_ISBN = '9782072886218';

    /** A Camus-and-Caligula two-work volume. */
    private const TWO_WORK_ISBN = '9782070117024';

    private int $calls = 0;

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . '/' . $name), true);
    }

    private function inventaireHttp(): MockHttpClient
    {
        $entities = self::fixture('inventaire_author_entities.json');
        $reverse = self::fixture('inventaire_author_reverse_claims.json');
        $redirects = self::fixture('inventaire_author_redirects.json');

        return new MockHttpClient(function (string $method, string $url) use ($entities, $reverse, $redirects): MockResponse {
            ++$this->calls;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if (($q['action'] ?? '') === 'by-uris') {
                $selected = [];
                $followed = [];
                foreach (explode('|', (string) ($q['uris'] ?? '')) as $uri) {
                    $canonical = $redirects[$uri] ?? $uri;
                    if (!isset($entities[$canonical])) {
                        continue;
                    }
                    $selected[$canonical] = $entities[$canonical];
                    if ($canonical !== $uri) {
                        $followed[$uri] = $canonical;
                    }
                }

                return new MockResponse((string) json_encode([
                    'entities' => $selected,
                    'redirects' => (object) $followed,
                ]));
            }
            if (($q['action'] ?? '') === 'reverse-claims') {
                $key = ($q['property'] ?? '') . '|' . ($q['value'] ?? '');

                return new MockResponse((string) json_encode(['uris' => $reverse[$key] ?? []]));
            }

            return new MockResponse('{}', ['http_code' => 404]);
        });
    }

    private function pipeline(?OutboundBudget $budget = null): AuthorResolutionPipeline
    {
        $budget ??= $this->createStub(OutboundBudget::class);
        $inventaire = new InventaireClient($this->inventaireHttp(), $budget);
        $entities = new EntityLookup($inventaire);

        return new AuthorResolutionPipeline($inventaire, $entities, new EditionResolver($inventaire, $entities));
    }

    public function testResolveAnchorFollowsEditionWorkAuthorChainWithLabels(): void
    {
        $candidates = $this->pipeline()->resolveAnchor(self::ANCHOR_ISBN);

        // The label travels with the uri so a cached anchor can verify the
        // requested name without any outbound call.
        $this->assertSame([['uri' => self::AUTHOR_URI, 'label' => self::AUTHOR_NAME]], $candidates);
    }

    public function testResolveAnchorOnAnUnknownIsbnYieldsNoCandidate(): void
    {
        $this->assertSame([], $this->pipeline()->resolveAnchor('9780306406157'));
    }

    public function testBibliographyIsRankedNotablesFirstAndKeepsOnlyWorks(): void
    {
        $payload = $this->pipeline()->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME);

        $this->assertNotNull($payload);
        $this->assertSame('wikidata', $payload['source']);
        $this->assertSame('Q34670', $payload['source_id']);
        $this->assertSame(self::AUTHOR_NAME, $payload['label']);

        // The series entity answering the same P50 claim is not a work and
        // is dropped; the absurd-cycle group collapses to its entry point
        // (see testSeriesGroupsCollapseToTheirEntryPoint); the remaining
        // works come back ranked by how many languages label them (84 for
        // "L'Etranger", 25 for "Le Premier Homme").
        $this->assertSame(
            ['The Stranger', 'The Plague', 'The Fall', 'The First Man'],
            array_column($payload['works'], 'title'),
        );
    }

    public function testSeriesGroupsCollapseToTheirEntryPoint(): void
    {
        // The Naruto lesson (2026-08-23): sources model every volume of a
        // series as a work of its author, and editions_count ranking then
        // offers arbitrary mid-series volumes (tome 22 of 72). A series
        // group must collapse to its ENTRY POINT, the lowest ordinal: the
        // reader either owns it (membrane drops it, the series lane is the
        // right door) or it is the one sane place to start. Standalone
        // works are untouched, so cycle-writing authors (Camus here: the
        // absurd cycle) keep their bibliography.
        $payload = $this->pipeline()->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME);

        $this->assertNotNull($payload);
        $titles = array_column($payload['works'], 'title');
        $this->assertSame(
            ['The Stranger', 'The Plague', 'The Fall', 'The First Man'],
            $titles,
        );
        // Ordinals 2 and 3 of the cycle never surface as author works.
        $this->assertNotContains('The Myth of Sisyphus', $titles);
        $this->assertNotContains('Caligula', $titles);
    }

    public function testWorksCarryTheirAuthorsYearAndNeutralLabelMap(): void
    {
        $payload = $this->pipeline()->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME);

        $this->assertNotNull($payload);
        foreach ($payload['works'] as $work) {
            // Authors are the load-bearing half of the client membrane: a
            // work returned without them cannot be matched against the
            // library by title+author, and the reader gets offered the
            // translation of a book already on the shelf.
            $this->assertSame([self::AUTHOR_NAME], $work['authors']);
            $this->assertNotSame('', $work['title']);
            $this->assertSame('fr', $work['original_lang']);
        }

        $stranger = $payload['works'][0];
        $this->assertSame(1942, $stranger['year']);
        // The neutral label map is what lets serve time hand a French
        // reader the French title AND the alternates their library may be
        // catalogued under.
        $this->assertSame("L'Étranger", $stranger['labels']['fr']);
        $this->assertSame('The Stranger', $stranger['labels']['en']);
    }

    public function testEditionsAreAttachedWithCountsAndOmnibusesAreExcluded(): void
    {
        $payload = $this->pipeline()->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME);

        $this->assertNotNull($payload);
        $byTitle = array_column($payload['works'], null, 'title');

        // editions_count is the source-known total (the popularity proxy),
        // not the number of editions that survived filtering.
        $this->assertSame(5, $byTitle['The Stranger']['editions_count']);
        $this->assertSame(4, $byTitle['The Plague']['editions_count']);

        $offered = [];
        foreach ($payload['works'] as $work) {
            $offered = array_merge($offered, array_column($work['editions'], 'isbn'));
        }
        // The Pleiade "Oeuvres" is claimed by seventeen works and the
        // Camus-and-Caligula volume by nine: neither is an edition of any
        // single work, and importing one under a novel's title is the
        // false positive ADR-060 forbids.
        $this->assertNotContains(self::OMNIBUS_ISBN, $offered);
        $this->assertNotContains(self::TWO_WORK_ISBN, $offered);

        // Single-work editions are kept, language-neutral, capped at two
        // per language.
        $this->assertSame(
            [['isbn' => '9784102114018', 'lang' => 'ja'], ['isbn' => '9782040160104', 'lang' => 'fr']],
            array_map(
                static fn (array $e): array => ['isbn' => $e['isbn'], 'lang' => $e['lang']],
                $byTitle['The Stranger']['editions'],
            ),
        );
        // The Fall keeps its single usable edition (its inv: sibling has
        // no ISBN and is skipped). Asserted on a SURVIVING work: the
        // absurd-cycle members past the entry point are collapsed away, so
        // Sisyphus no longer carries the second-work check.
        $this->assertSame(
            ['9780394702230'],
            array_column($byTitle['The Fall']['editions'], 'isbn'),
        );
        $this->assertSame(
            'https://inventaire.io/img/entities/969690a52b3a28628b52ab569d501880b1b0e62e',
            $byTitle['The Stranger']['editions'][1]['cover_url'],
        );
    }

    /**
     * A work whose editions all lack an ISBN (or are omnibuses) still
     * belongs in the answer: ADR-060 section 4.2 lets the client show a
     * title-and-author card, it simply loses the ISBN-keyed niceties.
     */
    public function testWorkWithoutUsableEditionIsStillReturned(): void
    {
        $payload = $this->pipeline()->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME);

        $this->assertNotNull($payload);
        $byTitle = array_column($payload['works'], null, 'title');
        $this->assertSame([], $byTitle['The Plague']['editions']);
        $this->assertArrayHasKey('The Plague', $byTitle);
    }

    /**
     * The cold cost of one author has to stay in the range measured for a
     * cold series (about 21 outbound calls, ADR-060 consequences): the
     * global outbound budget is 60 per minute for the whole hub.
     */
    public function testColdResolutionStaysWithinTheOutboundBudgetRange(): void
    {
        $pipeline = $this->pipeline();
        $this->calls = 0;
        $pipeline->resolveAnchor(self::ANCHOR_ISBN);
        $pipeline->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME);

        $this->assertLessThanOrEqual(21, $this->calls);
    }

    public function testOversizedBibliographyIsTruncatedByRank(): void
    {
        $works = [];
        $entities = [];
        for ($i = 1; $i <= AuthorResolutionPipeline::MAX_WORKS + 5; ++$i) {
            $uri = sprintf('wd:Q%d', 900000 + $i);
            $works[] = $uri;
            $entities[$uri] = [
                'uri' => $uri,
                'type' => 'work',
                // Later works are labelled in fewer languages, so rank
                // follows the loop index.
                'labels' => array_fill_keys(array_slice(['en', 'fr', 'de', 'es'], 0, max(1, 5 - intdiv($i, 12))), 'Work ' . $i),
                'claims' => ['wdt:P50' => [self::AUTHOR_URI]],
            ];
        }
        $http = new MockHttpClient(static function (string $method, string $url) use ($entities, $works): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if (($q['action'] ?? '') === 'reverse-claims') {
                return new MockResponse((string) json_encode([
                    'uris' => ($q['property'] ?? '') === 'wdt:P50' ? $works : [],
                ]));
            }
            $selected = [];
            foreach (explode('|', (string) ($q['uris'] ?? '')) as $uri) {
                if (isset($entities[$uri])) {
                    $selected[$uri] = $entities[$uri];
                }
            }

            return new MockResponse((string) json_encode(['entities' => $selected, 'redirects' => (object) []]));
        });
        $budget = $this->createStub(OutboundBudget::class);
        $inventaire = new InventaireClient($http, $budget);
        $lookup = new EntityLookup($inventaire);
        $pipeline = new AuthorResolutionPipeline($inventaire, $lookup, new EditionResolver($inventaire, $lookup));

        $payload = $pipeline->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME);

        $this->assertNotNull($payload);
        // A bibliography is truncated, never rejected: unlike an oversized
        // "series", its tail is dead payload, not noise the client would
        // do arithmetic on.
        $this->assertCount(AuthorResolutionPipeline::MAX_WORKS, $payload['works']);
        $this->assertSame('Work 1', $payload['works'][0]['title']);
    }

    public function testAuthorWithoutWorksIsUnknown(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            (string) json_encode(['uris' => [], 'entities' => [], 'redirects' => (object) []]),
        ));
        $budget = $this->createStub(OutboundBudget::class);
        $inventaire = new InventaireClient($http, $budget);
        $lookup = new EntityLookup($inventaire);
        $pipeline = new AuthorResolutionPipeline($inventaire, $lookup, new EditionResolver($inventaire, $lookup));

        $this->assertNull($pipeline->resolveAuthorEntity(self::AUTHOR_URI, self::AUTHOR_NAME));
    }

    /**
     * One anchor the source refuses must not veto the others. Before the
     * client told a 400 apart from an outage, a single checksum-valid but
     * structurally invalid ISBN in a reader's library aborted the whole
     * lookup, and the failure was never cached, so it came back every day.
     */
    public function testAnAnchorRefusedByTheSourceYieldsNoCandidateInsteadOfFailing(): void
    {
        $budget = $this->createStub(OutboundBudget::class);
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"status":400,"message":"invalid uri id"}',
            ['http_code' => 400],
        ));
        $inventaire = new InventaireClient($http, $budget);
        $lookup = new EntityLookup($inventaire);
        $pipeline = new AuthorResolutionPipeline($inventaire, $lookup, new EditionResolver($inventaire, $lookup));

        $this->assertSame([], $pipeline->resolveAnchor('9791234567896'));
    }

    public function testBudgetExhaustionPropagates(): void
    {
        $budget = $this->createStub(OutboundBudget::class);
        $budget->method('consumeOrFail')
            ->willThrowException(new DiscoveryBudgetExhaustedException('exhausted'));

        $this->expectException(DiscoveryBudgetExhaustedException::class);
        $this->pipeline($budget)->resolveAnchor(self::ANCHOR_ISBN);
    }
}
