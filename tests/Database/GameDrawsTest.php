<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/** A hosted game's texts and options, and its result recorded as a giveaway's winners, in order, all or none. */
final class GameDrawsTest extends DatabaseTestCase
{
    private const GAME = 'boss-draw';

    private int $user;
    private int $game;

    protected function setUp(): void
    {
        parent::setUp();

        if (Games::manifest(self::GAME) === null) {
            self::markTestSkipped('The boss-draw package is not in public/games/.');
        }

        $this->user = $this->createUser('phpunit_battler');
        $stmt = $this->pdo->prepare('INSERT INTO games (title, slug) VALUES (?, ?) RETURNING id');
        $stmt->execute(['PHPUnit Battle', 'phpunit-battle-' . bin2hex(random_bytes(3))]);
        $this->game = (int) $stmt->fetchColumn();

        GameDraws::$lookup = static fn (string $login): array => ['ref' => (string) crc32($login), 'login' => $login, 'name' => ucfirst($login)];
    }

    protected function tearDown(): void
    {
        GameDraws::$lookup = null;
        parent::tearDown();
    }

    private function giveaway(string $mode, int $prizes): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO giveaways (user_id, title, keyword, winner_mode, claim_days, status) VALUES (?, 'Battle', ?, ?, 30, 'open') RETURNING id"
        );
        $stmt->execute([$this->user, 'k' . bin2hex(random_bytes(3)), $mode]);
        $giveawayId = (int) $stmt->fetchColumn();
        $keys       = [];

        for ($i = 1; $i <= $prizes; $i++) {
            $code = 'BATTLE-' . $i . '-' . bin2hex(random_bytes(2));
            $stmt = $this->pdo->prepare(
                "INSERT INTO game_keys (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash, status)
                 VALUES (?, ?, (SELECT id FROM key_platforms ORDER BY id LIMIT 1), (SELECT id FROM game_platforms ORDER BY id LIMIT 1), ?, ?, 'for_giveaway')
                 RETURNING id"
            );
            $stmt->execute([$this->user, $this->game, Vault::encryptCode($this->user, $code), Vault::hashCode($this->user, $code)]);
            $keys[] = (int) $stmt->fetchColumn();
        }

        if ($keys !== []) {
            Giveaways::addPrizes($this->user, $giveawayId, $keys);
        }

        return $giveawayId;
    }

    private function gameResult(array $logins, array $extra = []): array
    {
        return $extra + [
            'contract' => Games::CONTRACT,
            'game'     => self::GAME,
            'version'  => '1.0.1',
            'channel'  => 'phpunit_channel',
            'winners'  => array_map(static fn (string $l): array => ['login' => $l, 'name' => ucfirst($l)], $logins),
            'forfeited' => [],
            'entrants' => 12,
            'seed'     => 'abc',
            'options'  => ['hearts' => 4, 'showdown' => true],
        ];
    }

    public function testWinnersAreRecordedInTheGamesOrderAndGetThePrizesInThatOrder(): void
    {
        $g = $this->giveaway('assigned', 3);

        self::assertSame(3, GameDraws::openSlots($g));
        self::assertSame(2, GameDraws::record($this->user, $g, self::GAME, $this->gameResult(['first_one', 'second_one'])));

        $rows = $this->pdo->query(
            "SELECT w.twitch_login, w.method, p.id AS prize FROM giveaway_winners w JOIN giveaway_prizes p ON p.winner_id = w.id
              WHERE w.giveaway_id = {$g} ORDER BY p.id"
        )->fetchAll();

        self::assertSame(['first_one', 'second_one'], array_column($rows, 'twitch_login'));
        self::assertSame(['draw', 'draw'], array_column($rows, 'method'));
        self::assertSame(1, GameDraws::openSlots($g));
        self::assertSame(['first_one', 'second_one'], GameDraws::winnerLogins($g));
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM giveaway_game_draws WHERE giveaway_id = {$g} AND winners = 2")->fetchColumn());
        self::assertNotNull($this->pdo->query("SELECT drawn_at FROM giveaways WHERE id = {$g}")->fetchColumn());
        self::assertSame(4, Games::preferences($this->user, self::GAME)['options']['hearts'], 'The options played become the next ones.');
    }

    public function testAResultIsRecordedWholeOrNotAtAll(): void
    {
        $g = $this->giveaway('pick', 3);
        GameDraws::record($this->user, $g, self::GAME, $this->gameResult(['already_won']));

        try {
            GameDraws::record($this->user, $g, self::GAME, $this->gameResult(['new_one', 'already_won']));
            self::fail('A winner who already won is refused.');
        } catch (UserError) {
        }

        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM giveaway_winners WHERE giveaway_id = {$g}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM giveaway_game_draws WHERE giveaway_id = {$g}")->fetchColumn());
    }

    public function testMoreWinnersThanPrizesLeftIsRefused(): void
    {
        $g = $this->giveaway('pick', 1);

        $this->expectException(UserError::class);
        GameDraws::record($this->user, $g, self::GAME, $this->gameResult(['one', 'two']));
    }

    /** @return iterable<string, array{array}> */
    public static function badResults(): iterable
    {
        yield 'another contract' => [['contract' => 2]];
        yield 'another game'     => [['game' => 'other-game']];
        yield 'bad login'        => [['winners' => [['login' => 'not a login']]]];
        yield 'twice the same'   => [['winners' => [['login' => 'same'], ['login' => 'SAME']]]];
        yield 'no winners'       => [['winners' => []]];
        yield 'eleven winners'   => [['winners' => array_map(static fn (int $i): array => ['login' => 'w' . $i], range(1, 11))]];
    }

    #[DataProvider('badResults')]
    public function testAResultOutsideTheContractIsRefused(array $change): void
    {
        $g = $this->giveaway('pick', 0);

        try {
            GameDraws::record($this->user, $g, self::GAME, $this->gameResult([], $change) + $this->gameResult(['someone']));
            self::fail('Refused.');
        } catch (UserError) {
        }

        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM giveaway_winners WHERE giveaway_id = {$g}")->fetchColumn());
    }

    public function testAnEndedGiveawayTakesNoResult(): void
    {
        $g = $this->giveaway('pick', 0);
        $this->pdo->exec("UPDATE giveaways SET status = 'finished' WHERE id = {$g}");

        $this->expectException(UserError::class);
        GameDraws::record($this->user, $g, self::GAME, $this->gameResult(['someone']));
    }

    public function testTheConfigLocksOnlyTheChannel(): void
    {
        $g = $this->giveaway('pick', 2);
        Games::saveOptions($this->user, self::GAME, ['hearts' => '3', 'unknown' => 1, 'sub_bonus' => 'nonsense']);

        $config = GameDraws::config($this->user, Giveaways::find($this->user, $g), self::GAME);

        self::assertSame(2, $config['winners']);
        self::assertSame(['channel'], $config['lock']);
        self::assertStringStartsWith('!', $config['entry']);
        self::assertSame(3, $config['options']->hearts);
        self::assertFalse(isset($config['options']->unknown));
        self::assertSame('none', $config['options']->sub_bonus, 'An invalid value takes the default.');

        $free = GameDraws::config($this->user, Giveaways::find($this->user, $this->giveaway('pick', 0)), self::GAME);
        self::assertSame(GameDraws::MAX_WINNERS, $free['winners'], 'Without prizes, up to the game\'s most.');
    }

    public function testATextChangeMustKeepItsPlaceholders(): void
    {
        $english = Games::defaults(self::GAME, 'en');
        $key     = (string) array_key_first(array_filter($english, static fn (string $t): bool => Games::placeholders($t) !== []));
        $plain   = (string) array_key_first(array_filter($english, static fn (string $t): bool => Games::placeholders($t) === []));

        $saved = Games::saveTexts(self::GAME, 'en', [$key => 'No placeholders here', $plain => 'PHPUnit text', 'no.such.key' => 'x'], null);

        self::assertSame(['saved' => 1, 'refused' => [$key]], $saved);
        self::assertSame(['plain' => 'PHPUnit text'], ['plain' => Games::overrides(self::GAME, 'en')[$plain]]);
        self::assertSame('PHPUnit text', Games::texts(self::GAME, 'en')[$plain]);

        Games::saveTexts(self::GAME, 'es', [$plain => 'Texto PHPUnit'], null);
        self::assertContains('es', Games::languages(self::GAME), 'A language given texts can be shown.');
        self::assertSame($english[$key], Games::texts(self::GAME, 'es')[$key], 'Missing texts fall back to English.');

        Games::saveTexts(self::GAME, 'en', [$plain => $english[$plain]], null);
        self::assertArrayNotHasKey($plain, Games::overrides(self::GAME, 'en'), 'Typing the default clears the change.');
    }

    public function testSettingsAreKeptAsTheyCameWithinTheirLimit(): void
    {
        Games::saveSettings($this->user, self::GAME, ['volume' => 0.4, 'layout' => ['a' => 1]]);
        self::assertEquals(['volume' => 0.4, 'layout' => ['a' => 1]], Games::preferences($this->user, self::GAME)['settings']);

        $this->expectException(UserError::class);
        Games::saveSettings($this->user, self::GAME, ['blob' => str_repeat('x', Games::SETTINGS_MAX)]);
    }
}
