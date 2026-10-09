<?php
declare(strict_types=1);

/**
 * The media library: files taken by what they contain, size and quota
 * limits, sprite segments, and who may use and change what (own media,
 * shared media changed only by administrators).
 */
final class MediaLibraryTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        $paths = $this->pdo->inTransaction() ? $this->pdo->query('SELECT path FROM media_assets')->fetchAll(PDO::FETCH_COLUMN) : [];

        parent::tearDown();

        foreach ($paths as $path) {
            if (!$this->pdo->query('SELECT 1 FROM media_assets WHERE path = ' . $this->pdo->quote($path))->fetchColumn()) {
                @unlink(dirname(__DIR__, 2) . '/public/' . $path);
            }
        }
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(30, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function wav(int $padding = 64): string
    {
        return 'RIFF' . pack('V', 36) . 'WAVE' . str_repeat("\0", $padding);
    }

    public function testFilesAreTakenByContent(): void
    {
        self::assertSame(['image', 'image/png', 'png', 30, 20], MediaLibrary::detect(self::png()));
        self::assertSame('wav', MediaLibrary::detect(self::wav())[2]);
        self::assertSame('mp3', MediaLibrary::detect('ID3' . str_repeat("\0", 20))[2]);
        self::assertSame('mp3', MediaLibrary::detect("\xFF\xFB\x90\x00" . str_repeat("\0", 20))[2]);
        self::assertSame('ogg', MediaLibrary::detect('OggS' . str_repeat("\0", 20))[2]);
        self::assertNull(MediaLibrary::detect('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        self::assertNull(MediaLibrary::detect("<?php echo 'hello'; ?>"));
    }

    public function testStoreKeepsTheFileUnderARandomName(): void
    {
        $user  = $this->createUser('phpunit_media');
        $asset = MediaLibrary::store($user, self::png(), 'my logo.png');

        self::assertSame(['image', 'my logo', 30, 20, false], [$asset['kind'], $asset['name'], $asset['width'], $asset['height'], $asset['shared']]);
        self::assertMatchesRegularExpression('~^media/library/[0-9a-f]{32}\.png$~', $asset['path']);
        self::assertFileExists(dirname(__DIR__, 2) . '/public/' . $asset['path']);

        MediaLibrary::delete($asset['id'], $user, false);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/public/' . $asset['path']);
    }

    public function testSizeAndQuotaLimits(): void
    {
        $user = $this->createUser('phpunit_media');
        Settings::set('media.sound_max_mb', '1', $user);
        Settings::set('media.quota_mb', '1', $user);

        try {
            MediaLibrary::store($user, self::wav(1024 * 1024 + 1), 'big.wav');
            self::fail('A sound over the size limit was kept.');
        } catch (UserError) {
        }

        MediaLibrary::store($user, self::wav(700 * 1024), 'a.wav');
        self::assertGreaterThan(700 * 1024, MediaLibrary::usage($user));

        $this->expectException(UserError::class);
        MediaLibrary::store($user, self::wav(400 * 1024), 'b.wav');
    }

    public function testSegmentsAreCheckedAndKeyed(): void
    {
        $segments = MediaLibrary::cleanSegments([
            ['key' => 's4', 'name' => 'Kept', 'start' => 1, 'duration' => 1],
            ['key' => 's4', 'name' => 'Duplicate key', 'start' => 2, 'duration' => 0.5],
            ['name' => '', 'start' => 9, 'duration' => 5],
            ['name' => 'Too short', 'start' => 1, 'duration' => 0.01],
            ['name' => 'Not numbers', 'start' => 'x', 'duration' => 1],
            'junk',
        ], 10.0);

        self::assertSame(['s4', 's5', 's6'], array_column($segments, 'key'));
        self::assertSame('9.0s', $segments[2]['name']);
        self::assertSame(1.05, $segments[2]['duration']);
        self::assertCount(MediaLibrary::MAX_SEGMENTS, MediaLibrary::cleanSegments(array_fill(0, 80, ['start' => 0, 'duration' => 1])));
    }

    public function testWhoMayUseAndChangeWhat(): void
    {
        $admin  = $this->createUser('phpunit_media_admin');
        $user   = $this->createUser('phpunit_media');
        $other  = $this->createUser('phpunit_media2');
        $shared = MediaLibrary::store(null, self::png(), 'shared.png');
        $mine   = MediaLibrary::store($user, self::png(), 'mine.png');
        MediaLibrary::store($other, self::png(), 'theirs.png');

        self::assertEqualsCanonicalizing([$mine['id'], $shared['id']], array_column(MediaLibrary::available($user), 'id'));
        self::assertNotNull(MediaLibrary::usable($shared['id'], $user));
        self::assertNull(MediaLibrary::usable($mine['id'], $other));

        self::assertSame('Admin name', MediaLibrary::update($shared['id'], $admin, true, 'Admin name')['name']);

        foreach ([[$shared['id'], $user, false], [$mine['id'], $other, false], [$mine['id'], $other, true]] as [$asset, $who, $isAdmin]) {
            try {
                MediaLibrary::update($asset, $who, $isAdmin, 'Taken');
                self::fail("User {$who} changed asset {$asset}.");
            } catch (UserError) {
            }
        }
    }
}
