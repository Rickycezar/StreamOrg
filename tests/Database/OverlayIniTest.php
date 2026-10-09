<?php
declare(strict_types=1);

/**
 * Advanced mode's settings text: written from settings and read back to
 * the same settings, forgiving about how keys and values are written, and
 * skipping (with the line) what does not fit.
 */
final class OverlayIniTest extends DatabaseTestCase
{
    private string $locale = 'en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = Lang::locale();
    }

    protected function tearDown(): void
    {
        Lang::setLocale($this->locale);

        $paths = $this->pdo->inTransaction() ? $this->pdo->query('SELECT path FROM media_assets')->fetchAll(PDO::FETCH_COLUMN) : [];

        parent::tearDown();

        foreach ($paths as $path) {
            if (!$this->pdo->query('SELECT 1 FROM media_assets WHERE path = ' . $this->pdo->quote($path))->fetchColumn()) {
                @unlink(dirname(__DIR__, 2) . '/public/' . $path);
            }
        }
    }

    private static function wav(): string
    {
        return 'RIFF' . pack('V', 36) . 'WAVE' . str_repeat("\0", 64);
    }

    public function testEveryTypeSurvivesTheRoundTripInBothLanguages(): void
    {
        $user = $this->createUser('phpunit_ini');

        foreach (['en', 'pt-BR'] as $locale) {
            Lang::setLocale($locale);

            foreach (array_keys(OverlayTypes::all()) as $type) {
                $settings = OverlayTypes::defaults($type);
                [$raw, $warnings] = OverlayIni::parse($type, OverlayIni::render($type, $settings, $user), $user);

                self::assertSame([], $warnings, "{$type} ({$locale})");
                self::assertEquals($settings, OverlayTypes::clean($type, $raw, $user), "{$type} ({$locale})");
            }
        }
    }

    public function testKeysAndValuesAreReadForgivingly(): void
    {
        $user = $this->createUser('phpunit_ini');
        $text = "Tempo na Tela: 9,5\nTEMPO-NA-TELA-GRANDE = 20  # comment\nshow_message = não\n"
              . "sequencias_grandes = 7; 70 700\ncor do painel = #abc\ntitulo_grande = \"  QUOTED  \"\n";

        [$raw] = OverlayIni::parse('watch_streak', $text, $user);
        $s = OverlayTypes::clean('watch_streak', $raw, $user);

        self::assertSame([9.5, 20.0, false, [7, 70, 700], '#aabbcc', 'QUOTED'],
            [$s['show_seconds'], $s['show_seconds_big'], $s['show_message'], $s['big_streaks'], $s['panel_color'], $s['banner']]);
    }

    public function testBlocksSetSoundOptionsAndRules(): void
    {
        $user  = $this->createUser('phpunit_ini');
        $sound = MediaLibrary::store($user, self::wav(), 'Seq Comum.wav', 4.0);
        MediaLibrary::update($sound['id'], $user, false, 'Seq Comum', [['name' => 'Drum hit', 'start' => 1, 'duration' => 0.5]]);

        $text = "som_normal = seq comum\n[som normal]\nvolume = 80%\ntrecho = drum hit\nfade_out = 2\n"
              . "tempo_na_tela = 12\n[sequencia 100]\nsom = #{$sound['id']}\ninicio = 3\n"
              . "[usuario @Some_Fan]\nsempre = sim\ndedicatoria = SALVE\n";

        [$raw, $warnings] = OverlayIni::parse('watch_streak', $text, $user);
        $s = OverlayTypes::clean('watch_streak', $raw, $user);

        self::assertSame([], $warnings);
        self::assertSame(12, (int) $s['show_seconds'], 'Settings after a block still apply.');
        self::assertSame([0.8, 's1', 2.0], [$s['sound_normal']['volume'], $s['sound_normal']['segment'], $s['sound_normal']['fade_out']]);
        self::assertSame([$sound['id'], 3.0], [$s['streaks']['100']['sound']['asset'], $s['streaks']['100']['sound']['start']]);
        self::assertSame(['always' => true, 'dedication' => 'SALVE'], $s['users']['some_fan']);

        $resolved = OverlayTypes::resolve('watch_streak', $s, $user);
        self::assertSame([1.0, 0.5], [$resolved['sound_normal']['start'], $resolved['sound_normal']['duration']]);
        self::assertSame(3.0, $resolved['streaks']['100']['sound']['start'], 'A start written in the text wins over the part.');
    }

    public function testWhatDoesNotFitIsSkippedWithItsLine(): void
    {
        Lang::setLocale('en');
        $user = $this->createUser('phpunit_ini');
        $text = "canal = someone\ntempo_na_tela = abc\nnope = 1\njust words\n[sequencia zero]\nsom = missing sound\nshow_message = maybe\ncor_do_painel = red";

        [$raw, $warnings] = OverlayIni::parse('watch_streak', $text, $user);
        $s = OverlayTypes::clean('watch_streak', $raw, $user);

        self::assertSame([1, 2, 3, 4, 5, 7, 8], array_column($warnings, 'line'));
        self::assertSame([11, true, '#1c1c3c'], [$s['show_seconds'], $s['show_message'], $s['panel_color']], 'Defaults stay.');
    }

    public function testAdvancedModeKeepsTheTextAndOnlyShowsAllowedCss(): void
    {
        $user = $this->createUser('phpunit_ini');
        $id   = Overlays::create($user, 'chat');
        $text = "# my notes\ntema = outline\nalinhamento = direita\n";

        $warnings = Overlays::update($id, $user, 'Chat', true, [], true, $text, ".chatMessage { color: red }</style><script>");
        $overlay  = Overlays::find($id, $user);

        self::assertSame([], $warnings);
        self::assertSame(['outline', 'right'], [$overlay['settings']['theme'], $overlay['settings']['align']]);
        self::assertSame($text, Overlays::textFor($overlay, $user), 'The text is shown as it was written.');
        self::assertStringNotContainsStringIgnoringCase('</style', $overlay['custom_css']);

        $key = substr((string) $overlay['link'], strpos((string) $overlay['link'], '#') + 1);
        self::assertStringContainsString('color: red', Overlays::state($key, 0, -1)['css']);

        Settings::set('overlay.custom_css', '0', $user);
        self::assertSame('', Overlays::state($key, 0, -1)['css']);

        Overlays::update($id, $user, 'Chat', true, ['theme' => 'cards']);
        $overlay = Overlays::find($id, $user);
        self::assertSame(['cards', 'right'], [$overlay['settings']['theme'], $overlay['settings']['align']], 'The simple form keeps what it does not show.');
        self::assertStringNotContainsString('# my notes', Overlays::textFor($overlay, $user), 'After simple-mode changes the text is written again.');
    }
}
