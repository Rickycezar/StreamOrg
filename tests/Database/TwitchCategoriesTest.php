<?php
declare(strict_types=1);

/** Every game has a Twitch category: its own, one picked, or the Just Chatting stand-in. */
final class TwitchCategoriesTest extends DatabaseTestCase
{
    private function game(string $title): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO games (title, slug) VALUES (?, ?) RETURNING id');
        $stmt->execute([$title, 'phpunit-' . bin2hex(random_bytes(4))]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{twitch_category_id:string, twitch_category_name:string, twitch_category_source:string} */
    private function categoryOf(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT twitch_category_id, twitch_category_name, twitch_category_source FROM games WHERE id = ?');
        $stmt->execute([$gameId]);

        return $stmt->fetch();
    }

    public function testNewGamesStreamAsJustChattingUntilFound(): void
    {
        $game = $this->game('PHPUnit Unlisted');

        self::assertSame(
            ['twitch_category_id' => LiveTracker::JUST_CHATTING, 'twitch_category_name' => 'Just Chatting', 'twitch_category_source' => 'default'],
            $this->categoryOf($game)
        );
    }

    public function testCategoryCannotBeRemoved(): void
    {
        $game = $this->game('PHPUnit Required');

        $this->expectException(PDOException::class);
        $this->pdo->prepare('UPDATE games SET twitch_category_id = NULL WHERE id = ?')->execute([$game]);
    }

    public function testStoringAndClearing(): void
    {
        $game = $this->game('PHPUnit Picked');

        TwitchCategories::store($this->pdo, $game, ['id' => '42', 'name' => 'PHPUnit Category'], 'manual');
        self::assertSame('manual', $this->categoryOf($game)['twitch_category_source']);
        self::assertSame('42', $this->categoryOf($game)['twitch_category_id']);

        TwitchCategories::store($this->pdo, $game, null);
        self::assertSame('default', $this->categoryOf($game)['twitch_category_source']);
        self::assertSame(LiveTracker::JUST_CHATTING, $this->categoryOf($game)['twitch_category_id']);
    }

    public function testGamesMayShareACategory(): void
    {
        $scarlet = $this->game('PHPUnit Scarlet');
        $violet  = $this->game('PHPUnit Violet');
        $shared  = ['id' => '777000', 'name' => 'PHPUnit Scarlet and Violet'];

        TwitchCategories::store($this->pdo, $scarlet, $shared, 'manual');
        TwitchCategories::store($this->pdo, $violet, $shared, 'manual');

        self::assertSame('777000', $this->categoryOf($violet)['twitch_category_id']);
    }

    public function testAddingFromTwitchFindsTheGameAlreadyThere(): void
    {
        $known = $this->game('PHPUnit Known');
        TwitchCategories::store($this->pdo, $known, ['id' => '777001', 'name' => 'PHPUnit Known'], 'twitch');

        self::assertSame($known, GameCatalog::findByCategory($this->pdo, ['id' => '777001', 'name' => 'PHPUnit Known']));
    }

    public function testAddingFromTwitchGivesAWaitingGameItsCategory(): void
    {
        $waiting = $this->game('PHPUnit Waiting Game');

        self::assertSame($waiting, GameCatalog::findByCategory($this->pdo, ['id' => '777002', 'name' => 'phpunit waiting game']));
        self::assertSame(['twitch_category_id' => '777002', 'twitch_category_name' => 'phpunit waiting game', 'twitch_category_source' => 'twitch'], $this->categoryOf($waiting));
    }

    public function testJustChattingStandInsAreNotTheJustChattingGame(): void
    {
        $this->game('PHPUnit Stand-in');

        self::assertNull(GameCatalog::findByCategory($this->pdo, ['id' => LiveTracker::JUST_CHATTING, 'name' => 'PHPUnit no such title']));
    }

    public function testAPickedCategoryNeverReplacesARealOne(): void
    {
        $game = $this->game('PHPUnit Keeps');
        TwitchCategories::store($this->pdo, $game, ['id' => '777003', 'name' => 'PHPUnit Keeps'], 'manual');

        GameCatalog::findByCategory($this->pdo, ['id' => '777004', 'name' => 'PHPUnit Keeps']);

        self::assertSame('777003', $this->categoryOf($game)['twitch_category_id']);
    }
}
