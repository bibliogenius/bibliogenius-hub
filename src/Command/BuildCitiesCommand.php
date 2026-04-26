<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Build per-country city data files for ADR-035 Phase 1.
 *
 * Downloads `https://download.geonames.org/export/dump/{CC}.zip`, extracts
 * the bundled `{CC}.txt` (tab-delimited GeoNames format), filters down to
 * the populated-place feature codes the directory picker cares about, and
 * writes a compact gzipped JSON array to `public/static/cities/{CC}.json.gz`.
 *
 * The output schema, kept frozen by the Flutter `CityRepository` parser:
 *
 *     [[id,"name","admin1",lat,lon], ...]
 *
 * with lat/lon quantized to 4 decimals (~10 m precision; ADR-035 §2bis).
 *
 * Run yearly or on demand:
 *
 *     php bin/console app:build-cities                # default European set
 *     php bin/console app:build-cities --country=FR
 *     php bin/console app:build-cities --country=FR --country=BE --country=CH
 *     php bin/console app:build-cities --all          # every ISO-3166 alpha-2
 *
 * No database, no Symfony services beyond HttpClient: this is a pure
 * batch command meant to run from any host with PHP, the `unzip` binary,
 * and outbound HTTPS.
 */
#[AsCommand(
    name: 'app:build-cities',
    description: 'Build hub static city files from GeoNames country exports (ADR-035)',
)]
class BuildCitiesCommand extends Command
{
    /**
     * Default country set: covers the early adopter footprint without
     * paying the bandwidth/disk cost of the long tail. Override with
     * --country or --all when shipping more.
     */
    private const DEFAULT_COUNTRIES = ['FR', 'BE', 'CH', 'LU', 'CA'];

    /**
     * GeoNames feature codes for populated places we surface in the picker.
     * Drops PPLW (destroyed), PPLQ (abandoned), PPLH (historical), and
     * PPLX (sub-sections of populated places - would inflate the file
     * with arrondissement-level entries that ADR-035 §1 rejects).
     */
    private const KEEP_FEATURE_CODES = [
        'PPL', 'PPLA', 'PPLA2', 'PPLA3', 'PPLA4', 'PPLA5',
        'PPLC', 'PPLF', 'PPLL', 'PPLG',
    ];

    private const GEONAMES_BASE_URL = 'https://download.geonames.org/export/dump';

    /**
     * GeoNames dump column indices (zero-based, tab-delimited).
     * https://download.geonames.org/export/dump/readme.txt
     */
    private const COL_ID = 0;
    private const COL_NAME = 1;
    private const COL_LAT = 4;
    private const COL_LON = 5;
    private const COL_FEATURE_CLASS = 6;
    private const COL_FEATURE_CODE = 7;
    private const COL_ADMIN1 = 10;
    private const COL_ADMIN2 = 11;

    private readonly HttpClientInterface $http;
    private readonly ?Connection $connection;

    public function __construct(
        ?HttpClientInterface $http = null,
        ?Connection $connection = null,
    ) {
        parent::__construct();
        $this->http = $http ?? HttpClient::create([
            // GeoNames is generous but not infinite: identify ourselves so
            // they can warn us if the script ever turns into a hot loop.
            'headers' => ['User-Agent' => 'BiblioGenius-Hub/build-cities (https://bibliogenius.org)'],
            'timeout' => 120,
        ]);
        // Optional: when wired up by Symfony DI, the run is logged to
        // hub_events so the admin dashboard can show "last refresh: N
        // days ago" - main signal that the yearly cron is still alive.
        // Tests construct without DI and skip the logging.
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'country',
                'c',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'ISO 3166-1 alpha-2 country code(s) to build. Repeat to pass several. Default: '.implode(',', self::DEFAULT_COUNTRIES),
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Build every country GeoNames knows about (~250 files, several minutes).',
            )
            ->addOption(
                'output-dir',
                'o',
                InputOption::VALUE_REQUIRED,
                'Override output directory. Defaults to public/static/cities/.',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Re-download and rebuild even when an output file already exists.',
            )
            ->addOption(
                'base-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the GeoNames base URL. Useful for mirrors or local fixtures during smoke tests. Default: '.self::GEONAMES_BASE_URL,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GeoNames -> hub static city files');

        if (!$this->canUnzip($io)) {
            return Command::FAILURE;
        }

        $countries = $this->resolveCountries($input, $io);
        if ($countries === []) {
            return Command::FAILURE;
        }

        $outputDir = $this->resolveOutputDir($input);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            $io->error(sprintf('Cannot create output directory: %s', $outputDir));

            return Command::FAILURE;
        }

        $force = (bool) $input->getOption('force');
        $baseUrl = rtrim((string) ($input->getOption('base-url') ?? self::GEONAMES_BASE_URL), '/');
        $totals = ['ok' => 0, 'skipped' => 0, 'failed' => 0, 'rows_kept' => 0];

        // ADR-036: load the admin1 / admin2 code-to-name lookup tables once
        // before the country loop so each row can be enriched in memory
        // without an extra network round trip per country. A failure here
        // aborts before any country file is touched (atomicity).
        try {
            [$admin1Map, $admin2Map] = $this->loadAdminMaps($baseUrl, $io);
        } catch (\Throwable $e) {
            $io->error('Could not load GeoNames admin code tables: '.$e->getMessage());

            return Command::FAILURE;
        }

        foreach ($countries as $cc) {
            $cc = strtoupper($cc);
            $outFile = sprintf('%s/%s.json.gz', rtrim($outputDir, '/'), $cc);

            if (!$force && is_file($outFile)) {
                $io->writeln(sprintf('  <comment>%s</comment>: skip (already built; use --force to rebuild)', $cc));
                ++$totals['skipped'];
                continue;
            }

            try {
                $kept = $this->buildOne($cc, $outFile, $baseUrl, $admin1Map, $admin2Map, $io);
                ++$totals['ok'];
                $totals['rows_kept'] += $kept;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  <error>%s</error>: %s', $cc, $e->getMessage()));
                ++$totals['failed'];
            }
        }

        $io->newLine();
        $io->writeln(sprintf(
            '<info>Built %d, skipped %d, failed %d (%d rows total)</info>',
            $totals['ok'],
            $totals['skipped'],
            $totals['failed'],
            $totals['rows_kept'],
        ));

        $this->logBuildRun($totals, $io);

        return $totals['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Record a marker event so the admin dashboard can display the age of
     * the last successful refresh - primary way to detect a broken yearly
     * cron on the VPS host. Direct insert (vs HubEventLogger) because the
     * logger sanitizes context to an allowlist that would strip our totals.
     */
    private function logBuildRun(array $totals, SymfonyStyle $io): void
    {
        if ($this->connection === null) {
            return;
        }
        try {
            $this->connection->insert('hub_events', [
                'level' => $totals['failed'] === 0 ? 'info' : 'warning',
                'channel' => 'maintenance',
                'message' => 'build_cities_run',
                'context' => json_encode($totals, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            $io->warning(sprintf('Failed to log build_cities_run event: %s', $e->getMessage()));
        }
    }

    /**
     * @return list<string>
     */
    private function resolveCountries(InputInterface $input, SymfonyStyle $io): array
    {
        if ($input->getOption('all')) {
            return $this->fetchAllCountryCodes($io);
        }

        /** @var list<string> $picked */
        $picked = $input->getOption('country');
        if ($picked !== []) {
            return array_map(static fn (string $c) => strtoupper(trim($c)), $picked);
        }

        return self::DEFAULT_COUNTRIES;
    }

    private function resolveOutputDir(InputInterface $input): string
    {
        $override = $input->getOption('output-dir');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        // Project root = command dir/../../  (src/Command -> src -> root)
        $projectRoot = \dirname(__DIR__, 2);

        return $projectRoot.'/public/static/cities';
    }

    /**
     * Return every ISO 3166-1 alpha-2 code GeoNames publishes a file for.
     * Parses the directory listing rather than hard-coding a list - that
     * way the next ISO addition does not silently disappear.
     */
    private function fetchAllCountryCodes(SymfonyStyle $io): array
    {
        $io->writeln('Fetching country list from GeoNames...');
        try {
            $body = $this->http->request('GET', self::GEONAMES_BASE_URL.'/')->getContent();
        } catch (\Throwable $e) {
            $io->error('Could not list GeoNames country files: '.$e->getMessage());

            return [];
        }
        // GeoNames serves an Apache directory listing with one href per file.
        // Match exactly two-letter uppercase codes followed by .zip.
        preg_match_all('/href="([A-Z]{2})\.zip"/', $body, $m);
        $codes = array_values(array_unique($m[1] ?? []));
        sort($codes);

        return $codes;
    }

    private function canUnzip(SymfonyStyle $io): bool
    {
        $probe = new Process(['unzip', '-v']);
        try {
            $probe->run();
        } catch (\Throwable) {
            // Fall through.
        }
        if ($probe->isSuccessful()) {
            return true;
        }
        $io->error('The `unzip` binary is required (Debian: apt install unzip).');

        return false;
    }

    /**
     * Build a single country's `.json.gz`. Returns the number of populated
     * places kept after filtering. Throws on any I/O or HTTP error.
     *
     * @param array<string, string> $admin1Map composite-key map (e.g. "FR.11" => "Île-de-France")
     * @param array<string, string> $admin2Map composite-key map (e.g. "FR.11.92" => "Hauts-de-Seine")
     */
    private function buildOne(
        string $cc,
        string $outFile,
        string $baseUrl,
        array $admin1Map,
        array $admin2Map,
        SymfonyStyle $io,
    ): int {
        $tmpDir = sys_get_temp_dir().'/build-cities-'.bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Cannot create scratch dir '.$tmpDir);
        }

        try {
            $zipPath = $tmpDir.'/'.$cc.'.zip';
            $this->download($baseUrl.'/'.$cc.'.zip', $zipPath);

            $txtPath = $tmpDir.'/'.$cc.'.txt';
            $this->extract($zipPath, $cc.'.txt', $txtPath);

            // Stream parse + gzip write so memory stays flat regardless of
            // input size. CN/IN/RU/BR have hundreds of thousands of
            // populated places, accumulating them in a PHP array before
            // json_encode would blow the 128 MB cli memory_limit.
            // Level 9 max compression: built once a year, served to
            // thousands of clients — extra CPU here saves bandwidth on
            // every download.
            $tmpOut = $outFile.'.tmp';
            $gz = gzopen($tmpOut, 'wb9');
            if ($gz === false) {
                throw new \RuntimeException('Cannot open '.$tmpOut.' for writing');
            }

            try {
                [$kept, $dropped] = $this->streamFilter($txtPath, $gz, $cc, $admin1Map, $admin2Map);
            } catch (\Throwable $e) {
                @gzclose($gz);
                @unlink($tmpOut);
                throw $e;
            }

            if (gzclose($gz) === -1) {
                @unlink($tmpOut);
                throw new \RuntimeException('gzclose failed for '.$tmpOut);
            }

            // Atomic write so a crash mid-write never leaves a half-file
            // behind that nginx/Caddy would happily serve.
            rename($tmpOut, $outFile);

            $io->writeln(sprintf(
                '  <info>%s</info>: %d places kept, %d dropped (%s on disk)',
                $cc,
                $kept,
                $dropped,
                $this->formatBytes(filesize($outFile) ?: 0),
            ));

            return $kept;
        } finally {
            $this->rmrf($tmpDir);
        }
    }

    private function download(string $url, string $dest): void
    {
        $response = $this->http->request('GET', $url);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('GeoNames returned HTTP %d for %s', $status, $url));
        }
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open '.$dest.' for writing');
        }
        try {
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($fh, $chunk->getContent());
            }
        } finally {
            fclose($fh);
        }
    }

    private function extract(string $zipPath, string $entry, string $dest): void
    {
        // `unzip -p` writes the entry to stdout; redirect to dest.
        // Avoids depending on ext-zip while keeping the same effect.
        $proc = Process::fromShellCommandline(sprintf(
            'unzip -p %s %s > %s',
            escapeshellarg($zipPath),
            escapeshellarg($entry),
            escapeshellarg($dest),
        ));
        $proc->setTimeout(120);
        $proc->run();
        if (!$proc->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'unzip failed for %s: %s',
                $zipPath,
                trim($proc->getErrorOutput()),
            ));
        }
    }

    /**
     * Read the GeoNames text dump line by line, filter to populated-place
     * feature codes, resolve admin1 / admin2 names from the lookup tables,
     * and emit each kept row as JSON directly into the gzip stream.
     * Memory usage stays flat (one row at a time) so this scales to
     * multi-million-line inputs (CN, IN) without bumping memory_limit.
     *
     * Output format (ADR-036): top-level JSON array of arrays where each
     * inner array is `[id, name, admin1_code, admin1_name, admin2_code,
     * admin2_name, lat, lon]`. Empty strings for admin levels GeoNames
     * does not provide for that row.
     *
     * @param resource              $gz        gzopen handle, caller closes
     * @param array<string, string> $admin1Map keys "country.admin1"
     * @param array<string, string> $admin2Map keys "country.admin1.admin2"
     *
     * @return array{0: int, 1: int} [kept, dropped]
     */
    private function streamFilter(
        string $txtPath,
        $gz,
        string $cc,
        array $admin1Map,
        array $admin2Map,
    ): array {
        $keep = array_flip(self::KEEP_FEATURE_CODES);
        $kept = 0;
        $dropped = 0;
        $first = true;

        $fh = fopen($txtPath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot read '.$txtPath);
        }

        if (gzwrite($gz, '[') === 0) {
            fclose($fh);
            throw new \RuntimeException('gzwrite failed (open bracket)');
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $cols = explode("\t", rtrim($line, "\r\n"));
                // ADR-036 needs admin2 (col 11), so 12 columns minimum.
                if (\count($cols) < 12) {
                    continue;
                }
                if ($cols[self::COL_FEATURE_CLASS] !== 'P') {
                    continue;
                }
                if (!isset($keep[$cols[self::COL_FEATURE_CODE]])) {
                    ++$dropped;
                    continue;
                }
                $admin1Code = $cols[self::COL_ADMIN1];
                $admin2Code = $cols[self::COL_ADMIN2];
                $admin1Name = ($admin1Code !== '')
                    ? ($admin1Map[$cc.'.'.$admin1Code] ?? '')
                    : '';
                $admin2Name = ($admin1Code !== '' && $admin2Code !== '')
                    ? ($admin2Map[$cc.'.'.$admin1Code.'.'.$admin2Code] ?? '')
                    : '';
                $row = [
                    (int) $cols[self::COL_ID],
                    $cols[self::COL_NAME],
                    $admin1Code,
                    $admin1Name,
                    $admin2Code,
                    $admin2Name,
                    round((float) $cols[self::COL_LAT], 4),
                    round((float) $cols[self::COL_LON], 4),
                ];
                $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (gzwrite($gz, ($first ? '' : ',').$json) === 0) {
                    throw new \RuntimeException('gzwrite failed mid-stream');
                }
                $first = false;
                ++$kept;
            }
        } finally {
            fclose($fh);
        }

        if (gzwrite($gz, ']') === 0) {
            throw new \RuntimeException('gzwrite failed (close bracket)');
        }

        return [$kept, $dropped];
    }

    /**
     * Download admin1CodesASCII.txt + admin2Codes.txt from GeoNames once
     * per run and parse them into associative arrays the per-country
     * loop can look up by composite key. Both files are tiny enough to
     * fit in memory (~150 KB and ~4 MB raw respectively).
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function loadAdminMaps(string $baseUrl, SymfonyStyle $io): array
    {
        $tmpDir = sys_get_temp_dir().'/build-cities-admin-'.bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Cannot create scratch dir '.$tmpDir);
        }

        try {
            $io->writeln('Loading GeoNames admin code lookups...');
            $admin1Path = $tmpDir.'/admin1CodesASCII.txt';
            $admin2Path = $tmpDir.'/admin2Codes.txt';
            $this->download($baseUrl.'/admin1CodesASCII.txt', $admin1Path);
            $this->download($baseUrl.'/admin2Codes.txt', $admin2Path);
            $admin1Map = $this->parseAdminFile($admin1Path);
            $admin2Map = $this->parseAdminFile($admin2Path);
            $io->writeln(sprintf(
                '  <info>loaded %d admin1 entries, %d admin2 entries</info>',
                \count($admin1Map),
                \count($admin2Map),
            ));

            return [$admin1Map, $admin2Map];
        } finally {
            $this->rmrf($tmpDir);
        }
    }

    /**
     * Parse a GeoNames admin codes file (tab-separated: code, utf8 name,
     * ascii name, geonameId). Returns a map of code => utf8 name. The
     * UTF-8 second column carries the localized name with accents
     * ("Île-de-France"); we deliberately skip the ASCII third column
     * because the picker already has its own diacritic-fold for search.
     *
     * @return array<string, string>
     */
    private function parseAdminFile(string $path): array
    {
        $map = [];
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot read '.$path);
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $cols = explode("\t", rtrim($line, "\r\n"));
                if (\count($cols) < 2 || $cols[0] === '') {
                    continue;
                }
                $map[$cols[0]] = $cols[1];
            }
        } finally {
            fclose($fh);
        }

        return $map;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }
}
