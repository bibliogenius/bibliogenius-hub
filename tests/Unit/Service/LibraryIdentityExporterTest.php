<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Follow;
use App\Entity\LibraryProfile;
use App\Entity\RelayMailbox;
use App\Repository\BorrowRequestRepository;
use App\Repository\Deposit404LogRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Repository\RelayMessageRepository;
use App\Service\LibraryIdentityExporter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Guards the admin single-library analysis export.
 *
 * The load-bearing test is the first one: the bundle is a file that leaves the
 * server and lands in a support ticket or a laptop, so a secret leaking into it
 * is worse than the ad-hoc dump workflow it replaces. Relay credentials in
 * particular are covered by the hub security rules (S1/S2): the write token is
 * shareable only with authenticated requesters and the read token must never
 * travel at all.
 *
 * The rest pins the diagnostic value: a catalog whose entries carry an ISBN but
 * no title is the exact shape of the "blank tile on the peer library screen"
 * symptom, so the export has to surface it as a count plus a sample rather than
 * making an admin eyeball a JSON payload.
 */
#[AllowMockObjectsWithoutExpectations]
final class LibraryIdentityExporterTest extends TestCase
{
    private const NODE_ID = '11111111-2222-3333-4444-555555555555';
    private const WRITE_TOKEN = 'wt-0123456789abcdef0123456789abcdef';
    private const RELAY_WRITE_TOKEN = 'rwt-0123456789abcdef0123456789abcd';
    private const READ_TOKEN = 'rt-0123456789abcdef0123456789abcdef';
    private const RECOVERY_HASH = 'rch-0123456789abcdef0123456789abcde';
    private const X25519_KEY = 'x25519-0123456789abcdef0123456789';
    private const ENCRYPTED_CONTACT = 'ec-0123456789abcdef0123456789abcdef';

    public function testBundleNeverCarriesSecretValues(): void
    {
        $follow = new Follow(self::NODE_ID, 'peer-node');
        $follow->approve()->setEncryptedContact(self::ENCRYPTED_CONTACT);

        $bundle = $this->buildExporter(
            profile: $this->makeProfile(),
            mailbox: $this->makeMailbox(),
            follows: [$follow],
        )->export(self::NODE_ID);

        self::assertNotNull($bundle);
        $serialized = json_encode($bundle, JSON_THROW_ON_ERROR);

        foreach ([
            self::WRITE_TOKEN,
            self::RELAY_WRITE_TOKEN,
            self::READ_TOKEN,
            self::RECOVERY_HASH,
            self::X25519_KEY,
            self::ENCRYPTED_CONTACT,
        ] as $secret) {
            self::assertStringNotContainsString($secret, $serialized, 'a secret leaked into the export bundle');
        }

        // Presence is reported so an admin can still tell a missing credential
        // from a set one, which is the whole diagnostic need.
        self::assertSame(
            ['present' => true, 'length' => strlen(self::WRITE_TOKEN)],
            $bundle['profile']['write_token'],
        );
        self::assertSame(['present' => false], $bundle['profile']['recovery_code_hash']);
        self::assertSame(['present' => true, 'length' => strlen(self::READ_TOKEN)], $bundle['relay']['read_token']);
    }

    public function testCatalogAnalysisCountsAndSamplesIncompleteEntries(): void
    {
        $catalog = [
            ['isbn' => '9780000000001', 'title' => 'Complete', 'author' => 'A', 'cover_url' => 'https://x/1.jpg'],
            ['isbn' => '9780000000002', 'title' => '', 'author' => 'B', 'cover_url' => 'https://x/2.jpg'],
            ['isbn' => '15307', 'title' => '', 'author' => '', 'cover_url' => null],
            ['isbn' => '9780000000004', 'title' => 'No cover', 'author' => 'D'],
        ];

        $bundle = $this->buildExporter(
            profile: $this->makeProfile(),
            catalogRow: [
                'isbn_payload' => json_encode(['9780000000001', '9780000000002', '15307', '9780000000009']),
                'catalog_payload' => json_encode($catalog),
                'catalog_hash' => str_repeat('a', 64),
                'updated_at' => '2026-08-13 07:00:00',
                'expires_at' => '2026-08-20 07:00:00',
            ],
        )->export(self::NODE_ID);

        self::assertNotNull($bundle);
        $analysis = $bundle['catalog'];

        self::assertSame(4, $analysis['isbn_count']);
        self::assertSame(4, $analysis['entry_count']);
        self::assertSame(2, $analysis['entries_without_title']);
        self::assertSame(1, $analysis['entries_without_author']);
        self::assertSame(2, $analysis['entries_without_cover']);

        // An ISBN advertised in the legacy payload but absent from the enriched
        // catalog is why a peer can show a book it cannot render.
        self::assertSame(1, $analysis['isbns_missing_from_catalog']);

        self::assertCount(3, $analysis['incomplete_samples']);
        self::assertSame('15307', $analysis['incomplete_samples'][1]['isbn']);
        self::assertSame(['title', 'author', 'cover_url'], $analysis['incomplete_samples'][1]['missing']);
    }

    public function testMissingTitlesOutrankMissingCoversInTheSample(): void
    {
        // A cover-less book is ordinary; a title-less one is the bug. With a
        // flat cap and no ordering, a library holding more cover-less books
        // than the sample size hides every title-less entry behind them.
        $catalog = [];
        for ($i = 0; $i < LibraryIdentityExporter::MAX_SAMPLES + 5; $i++) {
            $catalog[] = ['isbn' => sprintf('978000000%04d', $i), 'title' => 'T', 'author' => 'A'];
        }
        $catalog[] = ['isbn' => '15307', 'title' => '', 'author' => ''];

        $bundle = $this->buildExporter(
            profile: $this->makeProfile(),
            catalogRow: [
                'isbn_payload' => json_encode([]),
                'catalog_payload' => json_encode($catalog),
                'catalog_hash' => null,
                'updated_at' => '2026-08-13 07:00:00',
                'expires_at' => '2026-08-20 07:00:00',
            ],
        )->export(self::NODE_ID);

        self::assertNotNull($bundle);
        $samples = $bundle['catalog']['incomplete_samples'];

        self::assertCount(LibraryIdentityExporter::MAX_SAMPLES, $samples);
        self::assertSame('15307', $samples[0]['isbn'], 'the title-less entry must lead the sample');
        self::assertSame(['title', 'author', 'cover_url'], $samples[0]['missing']);
    }

    public function testCatalogSectionIsNullWhenNoCatalogWasEverPushed(): void
    {
        $bundle = $this->buildExporter(profile: $this->makeProfile())->export(self::NODE_ID);

        self::assertNotNull($bundle);
        self::assertNull($bundle['catalog']);
    }

    public function testUnknownNodeYieldsNull(): void
    {
        self::assertNull($this->buildExporter(profile: null)->export(self::NODE_ID));
    }

    public function testHubEventLookupEscapesLikeWildcards(): void
    {
        $captured = [];
        $exporter = $this->buildExporter(
            profile: $this->makeProfile(),
            onFetchAll: function (string $sql, array $params) use (&$captured): void {
                if (str_contains($sql, 'hub_events')) {
                    $captured = $params;
                }
            },
        );

        // A node id is caller-controlled at registration time, so an id holding
        // LIKE metacharacters must not widen the scan (OWASP A03).
        $exporter->export('abc%_def');

        self::assertNotSame([], $captured, 'hub_events was never queried');
        self::assertContains('%abc\%\_def%', $captured);
    }

    public function testRowScansAreBounded(): void
    {
        $captured = [];
        $exporter = $this->buildExporter(
            profile: $this->makeProfile(),
            onFetchAll: function (string $sql, array $params) use (&$captured): void {
                $captured[] = $params;
            },
        );

        $exporter->export(self::NODE_ID);

        self::assertNotSame([], $captured);
        foreach ($captured as $params) {
            self::assertContains(
                LibraryIdentityExporter::MAX_ROWS,
                $params,
                'every free-form scan must carry the row cap (performance policy)',
            );
        }
    }

    /**
     * @param Follow[]                     $follows
     * @param array<string, mixed>|null    $catalogRow
     * @param (callable(string, array):void)|null $onFetchAll
     */
    private function buildExporter(
        ?LibraryProfile $profile,
        ?RelayMailbox $mailbox = null,
        array $follows = [],
        ?array $catalogRow = null,
        ?callable $onFetchAll = null,
    ): LibraryIdentityExporter {
        $profiles = $this->createStub(LibraryProfileRepository::class);
        $profiles->method('findByNodeId')->willReturn($profile);

        $followRepo = $this->createStub(FollowRepository::class);
        $followRepo->method('findAllInvolving')->willReturn($follows);

        $borrowRepo = $this->createStub(BorrowRequestRepository::class);
        $borrowRepo->method('findAllInvolving')->willReturn([]);

        $mailboxRepo = $this->createStub(RelayMailboxRepository::class);
        $mailboxRepo->method('findByOwnerNodeId')->willReturn($mailbox);

        $messageRepo = $this->createStub(RelayMessageRepository::class);
        $messageRepo->method('countByMailbox')->willReturn(2);

        $depositRepo = $this->createStub(Deposit404LogRepository::class);
        $depositRepo->method('countByMailbox')->willReturn(0);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static fn(string $sql, array $params = []): array|false => str_contains($sql, 'cached_catalogs')
                ? ($catalogRow ?? false)
                : false,
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($onFetchAll): array {
                if ($onFetchAll !== null) {
                    $onFetchAll($sql, $params);
                }
                return [];
            },
        );

        return new LibraryIdentityExporter(
            $profiles,
            $followRepo,
            $borrowRepo,
            $mailboxRepo,
            $messageRepo,
            $depositRepo,
            $connection,
        );
    }

    private function makeProfile(): LibraryProfile
    {
        $profile = new LibraryProfile(self::NODE_ID, self::WRITE_TOKEN, 'Eve');
        $profile->setBookCount(108)
            ->setRelayUrl('https://relay.example')
            ->setRelayMailboxId('mbx-uuid')
            ->setRelayWriteToken(self::RELAY_WRITE_TOKEN)
            ->setX25519PublicKey(self::X25519_KEY);

        return $profile;
    }

    private function makeMailbox(): RelayMailbox
    {
        return (new RelayMailbox())
            ->setUuid('mbx-uuid')
            ->setReadToken(self::READ_TOKEN)
            ->setWriteToken(self::WRITE_TOKEN)
            ->setOwnerNodeId(self::NODE_ID);
    }
}
