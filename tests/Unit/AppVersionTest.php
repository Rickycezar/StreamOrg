<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The VERSION file holds a semantic version, and the changelog describes it. */
final class AppVersionTest extends TestCase
{
    public function testTheVersionIsSemanticAndInTheChangelog(): void
    {
        $version = AppVersion::current();

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        self::assertStringContainsString('## ' . $version . ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'),
            'Every version needs its entry in CHANGELOG.md.');
    }
}
