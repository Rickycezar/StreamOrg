<?php
declare(strict_types=1);

/** Title counters number content in calendar order, and keep up with every change. */
final class TitleCountersTest extends DatabaseTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('phpunit_counters_' . bin2hex(random_bytes(3)));
        $counters   = TitleCounters::fromInput([['name' => 'stream', 'value' => '152'], ['name' => 'day', 'value' => '0']]);
        ContentDefaults::save($this->user, 120, ContentDefaults::prefixesFromInput(
            [['text' => '[STREAM #{stream}]'], ['text' => '[PT-BR]'], ['text' => '[DAY {day} · #{stream}]']],
            ['stream', 'day']
        ), $counters);
    }

    private function stream(?string $at, array $prefixes, string $body = 'Playing'): int
    {
        $template = trim(implode(' ', $prefixes) . ' ' . $body);
        $stmt     = $this->pdo->prepare(
            "INSERT INTO streams (user_id, streaming_platform_id, title, title_template, status, scheduled_start)
             VALUES (?, (SELECT id FROM streaming_platforms WHERE code = 'twitch'), ?, ?, 'planned', ?) RETURNING id"
        );
        $stmt->execute([$this->user, $template, TitleCounters::templateFor($this->user, $template), $at]);

        return (int) $stmt->fetchColumn();
    }

    private function title(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT title FROM streams WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    public function testNumbersFollowTheCalendarAndUndatedShowsAQuestionMark(): void
    {
        $later   = $this->stream('2030-01-10 20:00+00', ['[STREAM #{stream}]', '[PT-BR]']);
        $earlier = $this->stream('2030-01-05 20:00+00', ['[STREAM #{stream}]']);
        $undated = $this->stream(null, ['[STREAM #{stream}]']);
        $both    = $this->stream('2030-01-20 20:00+00', ['[DAY {day} · #{stream}]'], 'Control');

        self::assertSame('[STREAM #153] Playing', $this->title($earlier));
        self::assertSame('[STREAM #154] [PT-BR] Playing', $this->title($later));
        self::assertSame('[STREAM #?] Playing', $this->title($undated));
        self::assertSame('[DAY 1 · #155] Control', $this->title($both));
    }

    public function testMovingCancellingAndDeletingRenumber(): void
    {
        $a = $this->stream('2030-01-05 20:00+00', ['[STREAM #{stream}]']);
        $b = $this->stream('2030-01-10 20:00+00', ['[STREAM #{stream}]']);
        $c = $this->stream('2030-01-15 20:00+00', ['[STREAM #{stream}]']);

        $this->pdo->prepare("UPDATE streams SET scheduled_start = '2030-01-12 20:00+00' WHERE id = ?")->execute([$a]);
        self::assertSame(['[STREAM #154] Playing', '[STREAM #153] Playing'], [$this->title($a), $this->title($b)]);

        $this->pdo->prepare("UPDATE streams SET status = 'cancelled' WHERE id = ?")->execute([$b]);
        self::assertSame('[STREAM #?] Playing', $this->title($b));
        self::assertSame('[STREAM #153] Playing', $this->title($a));

        $this->pdo->prepare('DELETE FROM streams WHERE id = ?')->execute([$a]);
        self::assertSame('[STREAM #153] Playing', $this->title($c));
    }

    public function testChangingWhereACounterStartsRenumbers(): void
    {
        $a = $this->stream('2030-01-05 20:00+00', ['[STREAM #{stream}]']);

        $this->pdo->prepare("UPDATE user_counters SET value = 199 WHERE user_id = ? AND name = 'stream'")->execute([$this->user]);

        self::assertSame('[STREAM #200] Playing', $this->title($a));
    }

    public function testRenamingFollowsIntoContentAndUsedCountersStay(): void
    {
        $a        = $this->stream('2030-01-05 20:00+00', ['[STREAM #{stream}]']);
        $existing = TitleCounters::forUser($this->user);
        $byName   = array_column($existing, 'id', 'name');
        $posted   = TitleCounters::fromInput([['id' => $byName['stream'], 'name' => 'live', 'value' => '152'], ['id' => $byName['day'], 'name' => 'day', 'value' => '0']]);
        $renames  = TitleCounters::renames($existing, $posted);

        ContentDefaults::save($this->user, 120, ContentDefaults::prefixesFromInput([['text' => '[STREAM #{stream}]']], ['live', 'day'], $renames), $posted, $renames);

        $stmt = $this->pdo->prepare('SELECT title_template FROM streams WHERE id = ?');
        $stmt->execute([$a]);
        self::assertSame('[STREAM #{live}] Playing', $stmt->fetchColumn());
        self::assertSame('[STREAM #153] Playing', $this->title($a));

        $this->expectException(UserError::class);
        ContentDefaults::save($this->user, 120, [], TitleCounters::fromInput([['id' => $byName['day'], 'name' => 'day', 'value' => '0']]));
    }

    public function testOnlyTitlesUsingACounterKeepATemplate(): void
    {
        self::assertSame('[STREAM #{stream}] Hi', TitleCounters::templateFor($this->user, '[STREAM #{stream}] Hi'));
        self::assertNull(TitleCounters::templateFor($this->user, '[PT-BR] Hi {nope}'));

        $plain = $this->stream('2030-01-05 20:00+00', ['[PT-BR]']);
        self::assertSame('[PT-BR] Playing', $this->title($plain));
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
