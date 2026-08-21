<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\Isbn;
use PHPUnit\Framework\TestCase;

/**
 * Freezes the ISBN validation contract of the discovery endpoints (ADR-060
 * section 3.1): format AND checksum, ISBN-10 converted to canonical
 * ISBN-13. A checksum-valid-looking but wrong identifier must be rejected
 * before it can flood the cache with misses.
 */
final class IsbnTest extends TestCase
{
    public function testValidIsbn13IsCanonicalized(): void
    {
        $this->assertSame('9782070541270', Isbn::toIsbn13('978-2-07-054127-0'));
        $this->assertSame('9780306406157', Isbn::toIsbn13('9780306406157'));
    }

    public function testValidIsbn10IsConvertedToIsbn13(): void
    {
        $this->assertSame('9780441007318', Isbn::toIsbn13('0-441-00731-7'));
    }

    public function testIsbn10WithXCheckDigit(): void
    {
        // 043942089X = Harry Potter and the Order of the Phoenix (US).
        $this->assertSame('9780439420891', Isbn::toIsbn13('043942089X'));
    }

    public function testWrongCheckDigitIsRejected(): void
    {
        // Valid length, wrong check digit (last digit should be 7).
        $this->assertNull(Isbn::toIsbn13('9780306406150'));
        $this->assertNull(Isbn::toIsbn13('0441007312'));
        // The real check digit is 7; 8 looks plausible and must still fail.
        $this->assertNull(Isbn::toIsbn13('0-441-00731-8'));
    }

    public function testGarbageIsRejected(): void
    {
        $this->assertNull(Isbn::toIsbn13(''));
        $this->assertNull(Isbn::toIsbn13('not-an-isbn'));
        $this->assertNull(Isbn::toIsbn13('12345'));
        $this->assertNull(Isbn::toIsbn13('97820705412700'));
    }
}
