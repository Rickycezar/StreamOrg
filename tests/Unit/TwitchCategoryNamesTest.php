<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The names a catalogue title is looked up by on Twitch. */
final class TwitchCategoryNamesTest extends TestCase
{
    public static function titles(): array
    {
        return [
            'plain'             => ['Hades', ['Hades']],
            'sequel kept'       => ['Hades II', ['Hades II']],
            'edition suffix'    => ['Cyberpunk 2077 Ultimate Edition', ['Cyberpunk 2077 Ultimate Edition', 'Cyberpunk 2077']],
            'dashed GOTY'       => ['The Witcher 3: Wild Hunt - Game of the Year Edition', ['The Witcher 3: Wild Hunt - Game of the Year Edition', 'The Witcher 3: Wild Hunt']],
            'extra spaces'      => ['  Hollow   Knight ', ['Hollow Knight']],
            'only "Edition"'    => ['Edition', ['Edition']],
        ];
    }

    #[DataProvider('titles')]
    public function testNames(string $title, array $expected): void
    {
        $this->assertSame($expected, TwitchCategories::names($title));
    }
}
