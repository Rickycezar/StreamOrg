<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/** Which title hashtags count as sponsor tags for Twitch. */
final class TwitchSponsorTagsTest extends DatabaseTestCase
{
    private static function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(TwitchPush::class, $method))->invoke(null, ...$args);
    }

    public static function hashtags(): array
    {
        return [
            'key site by name'       => ['Keymailer', true],
            'differently cased'      => ['keyMAILER', true],
            'multi-word site'        => ['PressEngine', true],
            'game studio'            => ['SuperGiantGames', false],
            'language tag'           => ['pt_br', false],
            'non-crediting source'   => ['Purchased', false],
        ];
    }

    #[DataProvider('hashtags')]
    public function testOnlyCreditableKeySitesCount(string $hashtag, bool $sponsor): void
    {
        $this->pdo->exec("INSERT INTO key_platforms (code, tags_content) VALUES ('keymailer', true), ('press_engine', true)
                          ON CONFLICT (code) DO UPDATE SET tags_content = true");
        $this->pdo->exec("UPDATE key_platforms SET tags_content = false WHERE code = 'purchased'");

        $keys = self::call('sponsorKeys');

        self::assertSame($sponsor, isset($keys[self::call('normalise', $hashtag)]));
    }
}
