<?php
declare(strict_types=1);

final class GameImagesTest extends DatabaseTestCase
{
    private ?int $game = null;

    protected function tearDown(): void
    {
        if ($this->game !== null) {
            GameImages::deleteFiles($this->game);
        }

        parent::tearDown();
    }

    private function createGame(): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO games (title, slug) VALUES ('PHPUnit Game', ?) RETURNING id");
        $stmt->execute(['phpunit-game-' . bin2hex(random_bytes(4))]);

        return $this->game = (int) $stmt->fetchColumn();
    }

    private static function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 80, 40));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private static function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(GameImages::class, $method))->invoke(null, ...$args);
    }

    public function testHeroIsShrunkToMaxWidthKeepingProportions(): void
    {
        $out = self::call('shrink', ['bytes' => self::jpeg(3200, 1000), 'mime' => 'image/jpeg', 'width' => 3200, 'height' => 1000], 1600);

        self::assertSame([1600, 500], [$out['width'], $out['height']]);
        self::assertSame([1600, 500], array_slice(getimagesizefromstring($out['bytes']), 0, 2));
    }

    public function testSmallImageIsLeftAlone(): void
    {
        $in = ['bytes' => self::jpeg(800, 300), 'mime' => 'image/jpeg', 'width' => 800, 'height' => 300];

        self::assertSame($in, self::call('shrink', $in, 1600));
    }

    public function testUnchangedSourceIsNotDownloadedAgainAndThumbIsMade(): void
    {
        $game = $this->createGame();
        $url  = 'https://example.invalid/header.jpg?t=1';

        self::call('store', $this->pdo, $game, 'header',
            ['bytes' => self::jpeg(460, 215), 'mime' => 'image/jpeg', 'width' => 460, 'height' => 215], $url, null);

        $result = GameImages::sync($this->pdo, $game, ['header' => $url]);

        self::assertSame(['header'], $result['kept']);
        self::assertSame([], $result['failed']);

        $urls = GameImages::urls($this->pdo, $game);
        self::assertArrayHasKey('thumb', $urls);

        $thumb = dirname(__DIR__, 2) . '/public/media/games/' . $game . '/thumb.webp';
        self::assertSame([92, 44, 'image/webp'], [getimagesize($thumb)[0], getimagesize($thumb)[1], getimagesize($thumb)['mime']]);
    }

    public function testChangedSourceThatFailsKeepsThePreviousFile(): void
    {
        $game = $this->createGame();
        self::call('store', $this->pdo, $game, 'header',
            ['bytes' => self::jpeg(460, 215), 'mime' => 'image/jpeg', 'width' => 460, 'height' => 215], 'https://example.invalid/old.jpg', null);

        $result = GameImages::sync($this->pdo, $game, ['header' => 'https://example.invalid/new.jpg']);

        self::assertSame(['header'], $result['failed']);
        self::assertFileExists(dirname(__DIR__, 2) . '/public/media/games/' . $game . '/header.jpg');
        self::assertSame('https://example.invalid/old.jpg',
            $this->pdo->query("SELECT source_url FROM game_images WHERE game_id = {$game} AND kind = 'header'")->fetchColumn());
    }

    public function testDeleteFilesRemovesTheFolder(): void
    {
        $game = $this->createGame();
        self::call('store', $this->pdo, $game, 'header',
            ['bytes' => self::jpeg(10, 10), 'mime' => 'image/jpeg', 'width' => 10, 'height' => 10], 'x', null);

        GameImages::deleteFiles($game);

        self::assertDirectoryDoesNotExist(dirname(__DIR__, 2) . '/public/media/games/' . $game);
    }
}
