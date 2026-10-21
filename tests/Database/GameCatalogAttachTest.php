<?php
declare(strict_types=1);

/** Games added by hand get their provider record in place; names compare loosely; Steam extras are told apart. */
final class GameCatalogAttachTest extends DatabaseTestCase
{
    private Provider $steam;

    protected function setUp(): void
    {
        parent::setUp();

        Lang::setLocale('en');

        $this->steam = new class (['is_enabled' => true]) extends Provider {
            public function code(): string
            {
                return 'steam';
            }

            public function requiredCredentials(): array
            {
                return [];
            }

            public function search(string $term): array
            {
                return [
                    ['ref' => '901', 'title' => 'PHPUnit Quest Playtest', 'year' => null, 'image' => null],
                    ['ref' => '900', 'title' => 'PHPUnit Quest™', 'year' => null, 'image' => null],
                ];
            }

            public function fetch(string $ref): ?array
            {
                return $ref !== '900' ? null : [
                    'ref' => '900', 'title' => 'PHPUnit Quest', 'description' => 'A test.', 'release_date' => '2026-01-02',
                    'publishers' => ['PHPUnit Publishing'], 'developers' => ['PHPUnit Studio'], 'genres' => [],
                    'cover_url' => null, 'images' => [], 'store_url' => 'https://store.steampowered.com/app/900',
                ];
            }
        };
    }

    public function testNamesCompareLooselyAndExtrasAreLeftOut(): void
    {
        self::assertTrue(GameCatalog::sameName('HELLBOUND, Inc.™', 'Hellbound, Inc.'));
        self::assertTrue(GameCatalog::sameName('Dressmaker (2026)', 'Dressmaker'));
        self::assertFalse(GameCatalog::sameName('Hades II', 'Hades'));
        self::assertTrue(GameCatalog::isSteamExtra('Benny Bash Playtest'));
        self::assertTrue(GameCatalog::isSteamExtra('Hades Original Soundtrack'));
        self::assertFalse(GameCatalog::isSteamExtra('Demon Slayer'));
        self::assertSame('900', GameCatalog::steamMatch($this->steam, 'PHPUnit Quest (2026)'));
    }

    public function testAGameAddedByHandKeepsItsRowAndGetsTheDetails(): void
    {
        $id = (int) $this->pdo->query("INSERT INTO games (title, slug) VALUES ('Phpunit quest', 'phpunit-quest-hand') RETURNING id")->fetchColumn();
        $user = $this->createUser('phpunit_attach');
        $this->pdo->exec("INSERT INTO game_embargoes (user_id, game_id, kind, lifts_at, source) VALUES ({$user}, {$id}, 'review', now(), 'manual')");

        $result = GameCatalog::attach($this->pdo, $id, $this->steam, '900', false);

        self::assertSame($id, $result['id']);
        self::assertSame('PHPUnit Publishing', $result['publisher']);
        self::assertSame('PHPUnit Studio', $result['developer']);

        $row = $this->pdo->query("SELECT source_provider, source_ref, title, release_date FROM games WHERE id = {$id}")->fetch();
        self::assertSame(['steam', '900', 'PHPUnit Quest', '2026-01-02'], [$row['source_provider'], $row['source_ref'], $row['title'], $row['release_date']]);
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM game_embargoes WHERE game_id = {$id}")->fetchColumn(), 'What pointed at the game still does.');
        self::assertTrue(GameCatalog::isImported($this->pdo, $id));
    }

    public function testAProviderRecordAlreadyInTheCatalogueIsNotTakenTwice(): void
    {
        $first  = (int) $this->pdo->query("INSERT INTO games (title, slug, source_provider, source_ref) VALUES ('PHPUnit Quest', 'phpunit-quest-steam', 'steam', '900') RETURNING id")->fetchColumn();
        $second = (int) $this->pdo->query("INSERT INTO games (title, slug) VALUES ('PHPUnit Quest copy', 'phpunit-quest-copy') RETURNING id")->fetchColumn();

        try {
            GameCatalog::attach($this->pdo, $second, $this->steam, '900', false);
            self::fail('Linked a second game to the same Steam app');
        } catch (UserError $e) {
            self::assertStringContainsString('#' . $first, $e->getMessage());
        }

        self::assertFalse(GameCatalog::isImported($this->pdo, $second));
    }

    public function testNothingChangesWhenTheProviderHasNoRecord(): void
    {
        $id = (int) $this->pdo->query("INSERT INTO games (title, slug) VALUES ('PHPUnit Lost', 'phpunit-lost') RETURNING id")->fetchColumn();

        try {
            GameCatalog::attach($this->pdo, $id, $this->steam, '404', false);
            self::fail('Attached a missing record');
        } catch (UserError) {
            self::assertFalse(GameCatalog::isImported($this->pdo, $id));
        }
    }
}
