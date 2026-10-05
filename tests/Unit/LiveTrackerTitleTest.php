<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** When a Twitch category name counts as the same game as a catalogue title. */
final class LiveTrackerTitleTest extends TestCase
{
    public static function titles(): array
    {
        return [
            'identical'               => ['Hades', 'Hades', true],
            'case and punctuation'    => ['Hollow Knight: Silksong', 'hollow knight silksong', true],
            'apostrophe'              => ["Baldur's Gate 3", 'Baldurs Gate 3', true],
            'accents'                 => ['Pokémon Legends: Z-A', 'Pokemon Legends Z-A', true],
            'roman numeral'           => ['Hades II', 'Hades 2', true],
            'edition suffix'          => ['Cyberpunk 2077 Ultimate Edition', 'Cyberpunk 2077', true],
            'leading article'         => ['The Witcher 3: Wild Hunt', 'Witcher 3: Wild Hunt', true],
            'small typo, long title'  => ['Disco Elysium The Final Cut', 'Disco Elysium The Final Cutt', true],
            'sequel is another game'  => ['Hades', 'Hades II', false],
            'different game'          => ['Celeste', 'Celeste Classic', false],
            'empty'                   => ['', 'Hades', false],
        ];
    }

    #[DataProvider('titles')]
    public function testSameTitle(string $game, string $category, bool $same): void
    {
        self::assertSame($same, LiveTracker::sameTitle($game, $category));
    }

    public function testCategoryIdBeatsTitle(): void
    {
        $games = [
            ['id' => 1, 'title' => 'Something Else', 'category_id' => '42'],
            ['id' => 2, 'title' => 'Hades', 'category_id' => null],
        ];

        self::assertSame(['id' => 1, 'by' => 'id'], LiveTracker::match($games, '42', 'Hades'));
        self::assertSame(['id' => 2, 'by' => 'title'], LiveTracker::match($games, '7', 'Hades'));
        self::assertNull(LiveTracker::match($games, '7', 'Celeste'));
    }

    public function testContentWithoutGamesIsJustChatting(): void
    {
        self::assertSame(0, LiveTracker::match([], LiveTracker::JUST_CHATTING, 'Just Chatting')['id']);
        self::assertNull(LiveTracker::match([], '42', 'Hades'));
    }
}
