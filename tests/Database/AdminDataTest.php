<?php
declare(strict_types=1);

/**
 * Administration → User data: every account's lists, filtered by user,
 * status and words, and never a key's code, fingerprint or notes.
 */
final class AdminDataTest extends DatabaseTestCase
{
    public function testKeysNeverShowTheirCodeOrNotes(): void
    {
        $user = $this->createUser('phpunit_data');
        $game = (int) $this->pdo->query("INSERT INTO games (title, slug) VALUES ('Data Game', 'phpunit-data-" . bin2hex(random_bytes(3)) . "') RETURNING id")->fetchColumn();
        $this->pdo->exec(
            "INSERT INTO game_keys (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash, status, notes, source_note)
             VALUES ({$user}, {$game}, (SELECT id FROM key_platforms LIMIT 1), (SELECT id FROM game_platforms LIMIT 1),
                     'SECRET-CODE', 'SECRET-HASH', 'available', 'SECRET-NOTE', 'Keymailer')"
        );

        $page = AdminData::page('keys', ['user' => $user], 1);

        self::assertSame(1, $page['total']);
        self::assertSame(['Data Game', 'Keymailer'], [$page['rows'][0]['game'], $page['rows'][0]['source_note']]);
        self::assertStringNotContainsString('SECRET', json_encode($page['rows']));
        self::assertArrayNotHasKey('key_code', $page['rows'][0]);
    }

    public function testListsFilterByUserStatusAndWords(): void
    {
        $alice = $this->createUser('phpunit_alice');
        $bob   = $this->createUser('phpunit_bob');

        foreach ([[$alice, 'Alice plays Hades', 'planned'], [$alice, 'Alice chats', 'done'], [$bob, 'Bob plays Hades', 'planned']] as [$user, $title, $status]) {
            $this->pdo->prepare('INSERT INTO streams (user_id, title, status, streaming_platform_id) VALUES (?, ?, ?, (SELECT id FROM streaming_platforms LIMIT 1))')
                ->execute([$user, $title, $status]);
        }

        $titles = static fn (array $page): array => array_column($page['rows'], 'title');

        self::assertEqualsCanonicalizing(['Alice plays Hades', 'Alice chats'], $titles(AdminData::page('content', ['user' => $alice], 1)));
        self::assertSame(['Alice plays Hades'], $titles(AdminData::page('content', ['user' => $alice, 'status' => 'planned'], 1)));
        self::assertEqualsCanonicalizing(['Alice plays Hades', 'Bob plays Hades'], $titles(AdminData::page('content', ['q' => 'hades', 'status' => 'planned'], 1)));
        self::assertSame(2, AdminData::page('content', ['user' => $alice, 'status' => 'not-a-status'], 1)['total'], 'Unknown statuses are ignored.');
        self::assertSame(0, AdminData::page('content', ['user' => $alice, 'q' => '%'], 1)['total'], '% is searched as itself.');
    }
}
