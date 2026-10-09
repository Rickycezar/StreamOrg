<?php
declare(strict_types=1);

/**
 * Overlays: created with their type's defaults and a secret link, settings
 * checked field by field, the state an open overlay asks for (by key, with
 * settings only when they changed and the signals after the last one it
 * saw), and the switches that turn overlays off.
 */
final class OverlaysTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            foreach ($this->pdo->query('SELECT path FROM media_assets')->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $this->files[] = $path;
            }
        }

        parent::tearDown();

        foreach ($this->files as $path) {
            if (!$this->pdo->query('SELECT 1 FROM media_assets WHERE path = ' . $this->pdo->quote($path))->fetchColumn()) {
                @unlink(dirname(__DIR__, 2) . '/public/' . $path);
            }
        }
    }

    private static function keyOf(array $overlay): string
    {
        return substr((string) $overlay['link'], strpos((string) $overlay['link'], '#') + 1);
    }

    private static function wav(): string
    {
        return 'RIFF' . pack('V', 36) . 'WAVE' . str_repeat("\0", 64);
    }

    public function testNewOverlayHasDefaultsAndASecretLink(): void
    {
        $user    = $this->createUser('phpunit_overlay');
        $overlay = Overlays::find(Overlays::create($user, 'alert', '  My   alert '), $user);

        self::assertSame('My alert', $overlay['name']);
        self::assertSame(6, $overlay['settings']['show_seconds']);
        self::assertSame(['broadcaster', 'mod'], $overlay['settings']['roles']);
        self::assertNull($overlay['settings']['sound']);
        self::assertMatchesRegularExpression('~/overlay/alert#[A-Za-z0-9_-]{32}$~', (string) $overlay['link']);

        $stored = $this->pdo->query("SELECT key_hash, key_secret FROM overlays WHERE id = {$overlay['id']}")->fetch();
        self::assertSame(hash('sha256', self::keyOf($overlay)), $stored['key_hash']);
        self::assertStringNotContainsString(self::keyOf($overlay), (string) $stored['key_secret']);
    }

    public function testUnknownTypesAndTheLimitAreRefused(): void
    {
        $user = $this->createUser('phpunit_overlay');
        Settings::set('overlay.max_per_user', '1', $user);
        Overlays::create($user, 'alert');

        try {
            Overlays::create($user, 'alert');
            self::fail('A second overlay passed the limit of one.');
        } catch (UserError) {
        }

        $this->expectException(UserError::class);
        Overlays::create($this->createUser('phpunit_overlay2'), 'nope');
    }

    public function testStateByKeySendsSettingsOnlyWhenChanged(): void
    {
        $user    = $this->createUser('phpunit_overlay');
        $overlay = Overlays::find(Overlays::create($user, 'alert'), $user);
        $key     = self::keyOf($overlay);

        self::assertNull(Overlays::state('wrong-key', 0, -1));

        $first = Overlays::state($key, 0, -1);
        self::assertSame('on', $first['state']);
        self::assertSame(6, $first['settings']['show_seconds']);
        self::assertSame([], $first['signals']);

        $again = Overlays::state($key, $first['version'], $first['since']);
        self::assertArrayNotHasKey('settings', $again);

        Overlays::update($overlay['id'], $user, 'Renamed', true, ['show_seconds' => 999, 'position' => 'sideways', 'bogus' => 1]);
        $changed = Overlays::state($key, $first['version'], $first['since']);

        self::assertSame($first['version'] + 1, $changed['version']);
        self::assertSame(60, $changed['settings']['show_seconds']);
        self::assertSame('center', $changed['settings']['position']);
        self::assertArrayNotHasKey('bogus', $changed['settings']);
        self::assertSame(['settings'], array_column($changed['signals'], 'kind'));
    }

    public function testTestsReachOnlyOverlaysAlreadyOpen(): void
    {
        $user    = $this->createUser('phpunit_overlay');
        $overlay = Overlays::find(Overlays::create($user, 'alert'), $user);
        $key     = self::keyOf($overlay);

        Overlays::test($overlay['id'], $user, 'alert');
        $opened = Overlays::state($key, 0, -1);
        self::assertSame([], $opened['signals'], 'An overlay opened later must not replay old tests.');

        Overlays::test($overlay['id'], $user, 'alert');
        $next = Overlays::state($key, $opened['version'], $opened['since']);
        self::assertSame('test', $next['signals'][0]['kind']);
        self::assertSame('alert', $next['signals'][0]['payload']['event']);

        $this->expectException(UserError::class);
        Overlays::test($overlay['id'], $user, 'not_a_test');
    }

    public function testSwitchesTurnTheOverlayOff(): void
    {
        $user    = $this->createUser('phpunit_overlay');
        $overlay = Overlays::find(Overlays::create($user, 'alert'), $user);
        $key     = self::keyOf($overlay);

        Overlays::update($overlay['id'], $user, '', false, []);
        self::assertSame('off', Overlays::state($key, 0, -1)['state']);

        Overlays::update($overlay['id'], $user, '', true, []);
        Settings::set('overlay.types_off', 'alert', $user);
        self::assertSame('off', Overlays::state($key, 0, -1)['state']);

        Settings::set('overlay.types_off', '', $user);
        Settings::set('overlay.enabled', '0', $user);
        self::assertSame('off', Overlays::state($key, 0, -1)['state']);

        Settings::set('overlay.enabled', '1', $user);
        self::assertSame('on', Overlays::state($key, 0, -1)['state']);
    }

    public function testNewLinkRetiresTheOldKey(): void
    {
        $user = $this->createUser('phpunit_overlay');
        $id   = Overlays::create($user, 'alert');
        $old  = self::keyOf(Overlays::find($id, $user));

        Overlays::newLink($id, $user);
        $new = self::keyOf(Overlays::find($id, $user));

        self::assertNotSame($old, $new);
        self::assertNull(Overlays::state($old, 0, -1));
        self::assertSame('on', Overlays::state($new, 0, -1)['state']);
    }

    public function testOthersCannotChangeAnOverlay(): void
    {
        $owner = $this->createUser('phpunit_overlay');
        $id    = Overlays::create($owner, 'alert');

        $this->expectException(UserError::class);
        Overlays::update($id, $this->createUser('phpunit_overlay2'), 'Mine now', true, []);
    }

    public function testSettingsAreCleanedAndMediaResolved(): void
    {
        $user  = $this->createUser('phpunit_overlay');
        $other = $this->createUser('phpunit_overlay2');
        $sound = MediaLibrary::store($user, self::wav(), 'boom.wav', 3.0);
        $sound = MediaLibrary::update($sound['id'], $user, false, 'Boom', [['name' => 'Hit', 'start' => 1, 'duration' => 0.5]]);
        $theirs = MediaLibrary::store($other, self::wav(), 'theirs.wav', 1.0);

        $clean = OverlayTypes::clean('alert', [
            'message' => "  Hi\n {user}  ",
            'command' => '!Alerta-2 X',
            'roles'   => ['mod', 'admin', 'everyone'],
            'accent'  => 'red',
            'sound'   => ['asset' => $sound['id'], 'segment' => 's1', 'volume' => 7],
            'image'   => ['asset' => $theirs['id']],
        ], $user);

        self::assertSame('Hi {user}', $clean['message']);
        self::assertSame('alerta2x', $clean['command']);
        self::assertSame(['mod', 'everyone'], $clean['roles']);
        self::assertSame('#9146ff', $clean['accent']);
        self::assertSame(['asset' => $sound['id'], 'segment' => 's1', 'volume' => 1.0, 'start' => null, 'duration' => null, 'fade_in' => 0.0, 'fade_out' => 0.0], $clean['sound']);
        self::assertNull($clean['image'], 'Another user\'s media must not be usable.');

        $resolved = OverlayTypes::resolve('alert', $clean, $user);
        self::assertSame([1.0, 0.5, 1.0], [$resolved['sound']['start'], $resolved['sound']['duration'], $resolved['sound']['volume']]);
        self::assertStringEndsWith('/' . $sound['path'], $resolved['sound']['url']);

        MediaLibrary::delete($sound['id'], $user, false);
        self::assertNull(OverlayTypes::resolve('alert', $clean, $user)['sound']);
    }

    private function connectTwitch(int $user, string $login): void
    {
        $this->pdo->prepare(
            "INSERT INTO twitch_connections (user_id, twitch_user_id, twitch_login, access_token, refresh_token, expires_at, scopes)
             VALUES (?, ?, ?, 'x', 'x', now() + interval '1 hour', '')"
        )->execute([$user, (string) random_int(1000, 999999), $login]);
    }

    public function testTheSpecialRuleReachesOnlyItsChannel(): void
    {
        $mine   = $this->createUser('phpunit_overlay');
        $theirs = $this->createUser('phpunit_overlay2');
        $this->connectTwitch($mine, 'ricky_cezar');
        $this->connectTwitch($theirs, 'someone_else');

        $keyOf = fn (int $user, string $type): string => self::keyOf(Overlays::find(Overlays::create($user, $type), $user));

        $special = Overlays::state($keyOf($mine, 'watch_streak'), 0, -1)['settings']['_special'] ?? [];
        self::assertSame(['b_suzuki'], array_keys($special));
        self::assertMatchesRegularExpression('~/overlay/special/[0-9a-f]{32}$~', $special['b_suzuki']['sound']['url']);
        self::assertFileExists((string) OverlaySpecials::file((string) preg_replace('~^.*/([0-9a-f]{32})$~', '$1', $special['b_suzuki']['sound']['url'])));

        self::assertArrayNotHasKey('_special', Overlays::state($keyOf($theirs, 'watch_streak'), 0, -1)['settings']);
        self::assertArrayNotHasKey('_special', Overlays::state($keyOf($mine, 'chat'), 0, -1)['settings']);
        self::assertNull(OverlaySpecials::file(str_repeat('0', 32)));
        self::assertArrayNotHasKey('_special', Overlays::find(Overlays::create($mine, 'watch_streak'), $mine)['settings'], 'Never part of the saved settings.');
    }

    public function testViewerSoundsFromTheSimpleFormKeepWhatAdvancedModeSet(): void
    {
        $user  = $this->createUser('phpunit_overlay');
        $sound = MediaLibrary::store($user, self::wav(), 'yay.wav', 2.0);
        $id    = Overlays::create($user, 'watch_streak');

        Overlays::update($id, $user, '', true, [], true, "[usuario fan_one]\nsom = yay\nfade_out = 2\ndedicatoria = OI\n");

        Overlays::update($id, $user, '', true, ['users' => [
            '',
            ['login' => '@Fan_One', 'sound' => ['asset' => $sound['id'], 'segment' => '', 'volume' => '0.5'], 'always' => '1'],
            ['login' => 'fan_two', 'sound' => ['asset' => '', 'segment' => '', 'volume' => '1']],
            ['login' => 'not a login!'],
        ]]);
        $users = Overlays::find($id, $user)['settings']['users'];

        self::assertSame(['fan_one', 'fan_two'], array_keys($users));
        self::assertSame([true, 'OI', 0.5, 2.0], [$users['fan_one']['always'], $users['fan_one']['dedication'], (float) $users['fan_one']['sound']['volume'], (float) $users['fan_one']['sound']['fade_out']]);
        self::assertNull($users['fan_two']['sound']);

        Overlays::update($id, $user, '', true, ['users' => ['']]);
        self::assertSame([], Overlays::find($id, $user)['settings']['users'], 'Removing every row removes every rule.');
    }
}
