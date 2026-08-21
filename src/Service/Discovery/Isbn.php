<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * ISBN validation for the discovery endpoints (ADR-060 section 3.1):
 * format AND checksum, the first line of defense against cache-miss
 * flooding with random identifiers.
 */
final class Isbn
{
    /**
     * Canonical ISBN-13 (no separators) for a raw ISBN-10/13 input, or
     * null when the input fails format or checksum validation.
     */
    public static function toIsbn13(string $raw): ?string
    {
        $clean = strtoupper(str_replace(['-', ' '], '', trim($raw)));

        if (preg_match('/^\d{13}$/', $clean) === 1) {
            return self::isbn13ChecksumValid($clean) ? $clean : null;
        }
        if (preg_match('/^\d{9}[\dX]$/', $clean) === 1) {
            return self::isbn10ChecksumValid($clean) ? self::isbn10To13($clean) : null;
        }

        return null;
    }

    private static function isbn13ChecksumValid(string $isbn13): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += ((int) $isbn13[$i]) * ($i % 2 === 0 ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $isbn13[12];
    }

    private static function isbn10ChecksumValid(string $isbn10): bool
    {
        $sum = 0;
        for ($i = 0; $i < 9; ++$i) {
            $sum += ((int) $isbn10[$i]) * (10 - $i);
        }
        $sum += $isbn10[9] === 'X' ? 10 : (int) $isbn10[9];

        return $sum % 11 === 0;
    }

    private static function isbn10To13(string $isbn10): string
    {
        $base = '978' . substr($isbn10, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += ((int) $base[$i]) * ($i % 2 === 0 ? 1 : 3);
        }

        return $base . (string) ((10 - ($sum % 10)) % 10);
    }
}
