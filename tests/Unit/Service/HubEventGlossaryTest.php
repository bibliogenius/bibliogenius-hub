<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\HubEventGlossary;
use PHPUnit\Framework\TestCase;

/**
 * A glossary entry is only useful while the code still logs that exact
 * message: renaming a message used to lose its explanation silently.
 */
final class HubEventGlossaryTest extends TestCase
{
    public function testEveryGlossaryKeyIsStillLoggedSomewhere(): void
    {
        $sources = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'HubEventGlossary.php') {
                $sources .= file_get_contents($file->getPathname());
            }
        }

        foreach (array_keys(HubEventGlossary::TIPS) as $message) {
            self::assertStringContainsString(
                "'" . $message . "'",
                $sources,
                sprintf('glossary key "%s" is not logged by any file in src/', $message),
            );
        }
    }
}
