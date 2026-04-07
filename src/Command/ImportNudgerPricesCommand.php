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
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:import-nudger-prices',
    description: 'Import book prices from nudger.fr Open Data (ODbL)',
)]
class ImportNudgerPricesCommand extends Command
{
    private const NUDGER_URL = 'https://static.nudger.fr/opendata/isbn-open-data.zip';
    private const BATCH_SIZE = 1000;
    private const STALE_DAYS = 90;
    private const CLEANUP_PROBABILITY = 100; // 1 in 100 = 1%

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate the import without writing to the database')
            ->addOption('market', null, InputOption::VALUE_REQUIRED, 'Market code (2-letter country)', 'FR');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $market = strtoupper($input->getOption('market'));

        if (!preg_match('/^[A-Z]{2}$/', $market)) {
            $io->error('market must be a 2-letter country code.');
            return Command::FAILURE;
        }

        $io->title(sprintf('nudger.fr price import - market %s (ODbL - attribution: nudger.fr)', $market));

        if ($dryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        // Download ZIP archive (8M+ entries, ~410 MB compressed)
        $io->section('Downloading ZIP from nudger.fr...');
        $zipPath = $this->downloadToTempFile($io);
        if ($zipPath === null) {
            return Command::FAILURE;
        }

        // Extract CSV from ZIP
        $io->section('Extracting CSV from archive...');
        $csvPath = $this->extractCsvFromZip($zipPath, $io);
        unlink($zipPath);

        if ($csvPath === null) {
            return Command::FAILURE;
        }

        try {
            $result = $this->importFromFile($csvPath, $dryRun, $market, $io);
        } finally {
            unlink($csvPath);
        }

        if ($result === null) {
            return Command::FAILURE;
        }

        [$imported, $skipped] = $result;

        $io->success(sprintf(
            'Import complete: %s entries imported/updated, %s skipped.%s',
            number_format($imported),
            number_format($skipped),
            $dryRun ? ' (dry-run, nothing written)' : '',
        ));

        // Probabilistic cleanup (1% chance)
        if (!$dryRun && mt_rand(1, self::CLEANUP_PROBABILITY) === 1) {
            $pruned = $this->pruneStale();
            if ($pruned > 0) {
                $io->note(sprintf('Cleanup: pruned %d stale entries (>%d days old).', $pruned, self::STALE_DAYS));
            }
        }

        $io->note('Data source: nudger.fr - Open Database License (ODbL).');

        return Command::SUCCESS;
    }

    private function downloadToTempFile(SymfonyStyle $io): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::NUDGER_URL);
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $io->error(sprintf('Failed to download ZIP: HTTP %d', $statusCode));
                return null;
            }
        } catch (\Throwable $e) {
            $io->error(sprintf('Download failed: %s', $e->getMessage()));
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'nudger_');
        if ($tempFile === false) {
            $io->error('Failed to create temporary file.');
            return null;
        }

        $handle = fopen($tempFile, 'w');
        if ($handle === false) {
            $io->error('Failed to open temporary file for writing.');
            unlink($tempFile);
            return null;
        }

        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }
        } catch (\Throwable $e) {
            fclose($handle);
            unlink($tempFile);
            $io->error(sprintf('Download stream failed: %s', $e->getMessage()));
            return null;
        }

        fclose($handle);
        $io->writeln(sprintf('  Downloaded %s MB', number_format(filesize($tempFile) / 1048576, 1)));

        return $tempFile;
    }

    private function extractCsvFromZip(string $zipPath, SymfonyStyle $io): ?string
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            $io->error(sprintf('Failed to open ZIP archive (error code: %d).', $result));
            return null;
        }

        // Find the first CSV file in the archive
        $csvName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.csv')) {
                $csvName = $name;
                break;
            }
        }

        if ($csvName === null) {
            $zip->close();
            $io->error('No CSV file found in ZIP archive.');
            return null;
        }

        $csvPath = tempnam(sys_get_temp_dir(), 'nudger_csv_');
        if ($csvPath === false) {
            $zip->close();
            $io->error('Failed to create temporary file for CSV extraction.');
            return null;
        }

        // Extract to temp file
        $stream = $zip->getStream($csvName);
        if ($stream === false) {
            $zip->close();
            unlink($csvPath);
            $io->error(sprintf('Failed to read "%s" from ZIP archive.', $csvName));
            return null;
        }

        $outHandle = fopen($csvPath, 'w');
        while (!feof($stream)) {
            fwrite($outHandle, fread($stream, 8192));
        }
        fclose($stream);
        fclose($outHandle);
        $zip->close();

        $io->writeln(sprintf('  Extracted "%s" (%s MB)', $csvName, number_format(filesize($csvPath) / 1048576, 1)));

        return $csvPath;
    }

    /**
     * @return array{int, int}|null [imported, skipped] or null on header parse failure
     */
    private function importFromFile(string $filePath, bool $dryRun, string $market, SymfonyStyle $io): ?array
    {
        $io->section('Parsing and importing...');

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $io->error('Failed to open CSV file for reading.');
            return null;
        }

        // Parse header to find column indices (comma-delimited, quote-enclosed)
        $headerFields = fgetcsv($handle, 0, ',', '"');
        if ($headerFields === false || $headerFields === null) {
            fclose($handle);
            $io->error('CSV file is empty.');
            return null;
        }

        $headers = array_map(
            fn(string $h) => strtolower(trim($h)),
            $headerFields,
        );

        $isbnCol = array_search('isbn', $headers, true);
        $priceCol = array_search('min_price', $headers, true);
        $currencyCol = array_search('currency', $headers, true);
        $offersCol = array_search('offers_count', $headers, true);

        if ($isbnCol === false || $priceCol === false) {
            fclose($handle);
            $io->error(sprintf(
                'CSV header missing required columns (isbn, min_price). Found: %s',
                implode(', ', $headers),
            ));
            return null;
        }

        $io->writeln(sprintf('  Columns detected: %s', implode(', ', $headers)));

        $imported = 0;
        $skipped = 0;
        $batch = [];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        while (($fields = fgetcsv($handle, 0, ',', '"')) !== false) {
            if ($fields === null || $fields === [null]) {
                continue;
            }

            $isbn = isset($fields[$isbnCol]) ? trim($fields[$isbnCol]) : '';
            $priceRaw = isset($fields[$priceCol]) ? trim($fields[$priceCol]) : '';
            $currency = ($currencyCol !== false && isset($fields[$currencyCol]))
                ? strtoupper(trim($fields[$currencyCol]))
                : 'EUR';

            // Validate ISBN: 10 or 13 digits
            if (!preg_match('/^\d{10}(\d{3})?$/', $isbn)) {
                $skipped++;
                continue;
            }

            $priceCents = $this->parsePriceCents($priceRaw);
            if ($priceCents === null || $priceCents <= 0) {
                $skipped++;
                continue;
            }

            // Validate currency: 3 uppercase letters
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'EUR';
            }

            $offersRaw = ($offersCol !== false && isset($fields[$offersCol]))
                ? trim($fields[$offersCol])
                : '';
            $offersCount = ($offersRaw !== '' && ctype_digit($offersRaw)) ? (int) $offersRaw : null;

            $batch[] = [
                'isbn' => $isbn,
                'market' => $market,
                'price_cents' => $priceCents,
                'currency' => $currency,
                'offers_count' => $offersCount,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                if (!$dryRun) {
                    $this->upsertBatch($batch, $now);
                }
                $imported += count($batch);
                $batch = [];

                if ($imported % 100_000 === 0) {
                    $io->writeln(sprintf('  ... %s entries processed', number_format($imported)));
                }
            }
        }

        fclose($handle);

        // Flush remaining batch
        if (!empty($batch)) {
            if (!$dryRun) {
                $this->upsertBatch($batch, $now);
            }
            $imported += count($batch);
        }

        return [$imported, $skipped];
    }

    /**
     * @param array<array{isbn: string, market: string, price_cents: int, currency: string, offers_count: ?int}> $batch
     */
    private function upsertBatch(array $batch, string $now): void
    {
        if (empty($batch)) {
            return;
        }

        $values = [];
        $params = [];

        foreach ($batch as $i => $row) {
            $values[] = sprintf(
                '(:isbn_%d, :market_%d, :price_%d, :currency_%d, :offers_%d, :source_%d, :updated_%d)',
                $i, $i, $i, $i, $i, $i, $i,
            );
            $params["isbn_$i"] = $row['isbn'];
            $params["market_$i"] = $row['market'];
            $params["price_$i"] = $row['price_cents'];
            $params["currency_$i"] = $row['currency'];
            $params["offers_$i"] = $row['offers_count'];
            $params["source_$i"] = 'nudger';
            $params["updated_$i"] = $now;
        }

        $sql = sprintf(
            'INSERT INTO book_prices (isbn, market, price_cents, currency, offers_count, source, updated_at) VALUES %s
             ON CONFLICT (isbn, market) DO UPDATE SET
                price_cents = EXCLUDED.price_cents,
                currency = EXCLUDED.currency,
                offers_count = EXCLUDED.offers_count,
                source = EXCLUDED.source,
                updated_at = EXCLUDED.updated_at',
            implode(', ', $values),
        );

        $this->connection->executeStatement($sql, $params);
    }

    private function pruneStale(): int
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d days', self::STALE_DAYS));

        return $this->connection->executeStatement(
            'DELETE FROM book_prices WHERE updated_at < :threshold',
            ['threshold' => $threshold->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Parses a price string (euros, possibly with comma as decimal separator) into cents.
     */
    private function parsePriceCents(string $raw): ?int
    {
        // Handle European format: "12,50" -> "12.50"
        $raw = str_replace(',', '.', $raw);

        if (!is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw * 100);
    }
}
