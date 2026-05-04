<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\InviteController;
use PHPUnit\Framework\TestCase;

/**
 * Guards the entropy contract on invite tokens.
 *
 * generateToken() draws one char per byte from a 62-char alphabet. The naive
 * `% 62` over a uniform 0..255 byte gives indexes 0..7 a 5/256 chance and
 * indexes 8..61 a 4/256 chance - a small but real bias. The fix is rejection
 * sampling: discard any byte >= 248 (the largest multiple of 62 below 256)
 * so every alphabet index is equiprobable.
 *
 * The 62-bucket histogram below would yield chi-squared ~7900 against the
 * biased implementation; the threshold of 200 leaves multiple orders of
 * magnitude of headroom on both sides (61 df, p<0.001 critical ~99.6).
 */
final class InviteControllerTest extends TestCase
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const TOKEN_LENGTH = 12;
    private const SAMPLE_TOKENS = 100_000;
    private const CHI_SQUARED_THRESHOLD = 200.0;

    public function testGeneratedTokenShapeIsAlphanumericTwelveChars(): void
    {
        $token = $this->callGenerateToken();

        $this->assertSame(self::TOKEN_LENGTH, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $token);
    }

    public function testCharDistributionHasNoDetectableModuloBias(): void
    {
        $counts = array_fill_keys(str_split(self::ALPHABET), 0);

        for ($i = 0; $i < self::SAMPLE_TOKENS; $i++) {
            foreach (str_split($this->callGenerateToken()) as $char) {
                $counts[$char]++;
            }
        }

        $totalChars = self::SAMPLE_TOKENS * self::TOKEN_LENGTH;
        $expected = $totalChars / strlen(self::ALPHABET);

        $chiSquared = 0.0;
        foreach ($counts as $observed) {
            $delta = $observed - $expected;
            $chiSquared += ($delta * $delta) / $expected;
        }

        $this->assertLessThan(
            self::CHI_SQUARED_THRESHOLD,
            $chiSquared,
            sprintf(
                'Char distribution looks biased: chi-squared=%.2f over %d samples (threshold=%.0f).',
                $chiSquared,
                $totalChars,
                self::CHI_SQUARED_THRESHOLD,
            ),
        );
    }

    private function callGenerateToken(): string
    {
        // generateToken() is private static by design - it is an internal
        // detail of the create() endpoint, not a reusable helper. Reflection
        // keeps the visibility honest while still letting us assert on the
        // entropy contract.
        static $method = null;
        if ($method === null) {
            $method = new \ReflectionMethod(InviteController::class, 'generateToken');
        }

        return $method->invoke(null);
    }
}
