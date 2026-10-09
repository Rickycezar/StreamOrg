<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The chat bot's version is documented, and the migration it waits for exists. */
final class BotVersionTest extends TestCase
{
    public function testTheBotNamesARealMigrationAndDocumentsItsVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $pkg  = json_decode((string) file_get_contents($root . '/bot/package.json'), true);

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $pkg['version']);
        self::assertStringContainsString('## ' . $pkg['version'] . ' ', (string) file_get_contents($root . '/bot/CHANGELOG.md'));

        $migration = (string) ($pkg['streamorg']['requiresMigration'] ?? '');
        self::assertFileExists($root . '/db/migrations/' . $migration . '.sql', 'The bot would wait forever for a migration that does not exist.');
    }
}
