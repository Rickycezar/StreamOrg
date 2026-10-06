<?php
declare(strict_types=1);

/** Title prefixes with counters: numbers taken on save, given back on delete, renames followed. */
final class TitleCountersTest extends DatabaseTestCase
{
    private function setUpUser(array $prefixes, array $counters): int
    {
        $user = $this->createUser('phpunit_counters_' . bin2hex(random_bytes(3)));
        $rows = TitleCounters::fromInput($counters);
        ContentDefaults::save($user, 120, ContentDefaults::prefixesFromInput($prefixes, array_column($rows, 'name')), $rows);

        return $user;
    }

    private function stream(int $user, string $title): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO streams (user_id, streaming_platform_id, title, status)
             VALUES (?, (SELECT id FROM streaming_platforms WHERE code = 'twitch'), ?, 'planned') RETURNING id"
        );
        $stmt->execute([$user, $title]);

        return (int) $stmt->fetchColumn();
    }

    private function value(int $user, string $name): int
    {
        return (int) array_column(TitleCounters::forUser($user), 'value', 'name')[$name];
    }

    public function testPrefixesTakeTheNextNumberInTheChosenOrder(): void
    {
        $user = $this->setUpUser(
            [['text' => '[STREAM #{stream}]', 'default' => '1'], ['text' => '[PT-BR]'], ['text' => '[DAY {day} · #{stream}]']],
            [['name' => 'stream', 'value' => '152'], ['name' => 'day', 'value' => '2']]
        );
        $ids = array_column(ContentDefaults::prefixes($user), 'id');

        $taken = TitleCounters::take($this->pdo, $user, [$ids[1], $ids[0], $ids[2]]);

        self::assertSame('[PT-BR] [STREAM #153] [DAY 3 · #153]', $taken['text']);
        self::assertSame(153, $this->value($user, 'stream'));
        self::assertSame(3, $this->value($user, 'day'));
        self::assertTrue(ContentDefaults::prefixes($user)[0]['is_default']);
    }

    public function testDeletingTheLatestGivesTheNumberBackButNotAnOlderOne(): void
    {
        $user = $this->setUpUser([['text' => '#{stream}']], [['name' => 'stream', 'value' => '9']]);
        $id   = ContentDefaults::prefixes($user)[0]['id'];

        $first = TitleCounters::take($this->pdo, $user, [$id]);
        $a     = $this->stream($user, $first['text']);
        TitleCounters::record($this->pdo, $a, $first['uses']);

        $second = TitleCounters::take($this->pdo, $user, [$id]);
        $b      = $this->stream($user, $second['text']);
        TitleCounters::record($this->pdo, $b, $second['uses']);

        self::assertSame(['#10', '#11'], [$first['text'], $second['text']]);

        TitleCounters::release($this->pdo, $a);
        self::assertSame(11, $this->value($user, 'stream'), 'an older number stays used');

        TitleCounters::release($this->pdo, $b);
        self::assertSame(10, $this->value($user, 'stream'), 'the latest number comes back');
    }

    public function testRenamingACounterRenamesItInThePrefixes(): void
    {
        $user     = $this->setUpUser([['text' => '[#{stream}]'], ['text' => '[{day}]']], [['name' => 'stream', 'value' => '1'], ['name' => 'day', 'value' => '5']]);
        $existing = TitleCounters::forUser($user);
        $byName   = array_column($existing, 'id', 'name');

        $posted  = TitleCounters::fromInput([
            ['id' => $byName['stream'], 'name' => 'day', 'value' => '1'],
            ['id' => $byName['day'], 'name' => 'stream', 'value' => '5'],
        ]);
        $renames = TitleCounters::renames($existing, $posted);
        $rows    = ContentDefaults::prefixesFromInput([['text' => '[#{stream}]'], ['text' => '[{day}]']], array_column($posted, 'name'), $renames);
        ContentDefaults::save($user, 120, $rows, $posted);

        self::assertSame(['[#{day}]', '[{stream}]'], array_column(ContentDefaults::prefixes($user), 'prefix'));
        self::assertSame(1, $this->value($user, 'day'));
    }

    public function testPrefixesMayOnlyUseExistingCounters(): void
    {
        $this->expectException(UserError::class);
        ContentDefaults::prefixesFromInput([['text' => '[#{nope}]']], ['stream']);
    }

    public function testCounterInputIsChecked(): void
    {
        foreach ([[['name' => '1st']], [['name' => 'ok', 'value' => '-1']], [['name' => 'a'], ['name' => 'a']]] as $bad) {
            try {
                TitleCounters::fromInput($bad);
                self::fail('Accepted ' . json_encode($bad));
            } catch (UserError) {
                self::assertTrue(true);
            }
        }
    }
}
