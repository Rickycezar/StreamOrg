<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The order Twitch category search results are offered in. */
final class TwitchSearchRankTest extends TestCase
{
    private static function rows(string ...$names): array
    {
        return array_map(static fn (string $n): array => ['id' => md5($n), 'name' => $n, 'cover' => null], $names);
    }

    public function testSameTitleFirstThenPrefixThenContainsThenTwitchOrder(): void
    {
        $ranked = Twitch::rank(self::rows('Baltron', 'Disney\'s Hades Challenge', 'Hades II', 'Ball Roller', 'Hades'), 'hades');

        self::assertSame(['Hades', 'Hades II', 'Disney\'s Hades Challenge', 'Baltron', 'Ball Roller'], array_column($ranked, 'name'));
    }

    public function testCloseSpellingCountsAsTheSameTitle(): void
    {
        $ranked = Twitch::rank(self::rows('Baltron', 'Balatro'), 'Balatro');

        self::assertSame('Balatro', $ranked[0]['name']);
    }
}
