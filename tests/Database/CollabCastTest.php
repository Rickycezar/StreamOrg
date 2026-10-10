<?php
declare(strict_types=1);

/**
 * A collab's cast: saved whole (several people, their roles), keeping what
 * each person had, and followed by the lives still planned from it, title
 * credit included.
 */
final class CollabCastTest extends DatabaseTestCase
{
    private function streamer(int $user, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO streamers (user_id, name) VALUES (?, ?) RETURNING id');
        $stmt->execute([$user, $name]);

        return (int) $stmt->fetchColumn();
    }

    private function collab(int $user): int
    {
        return (int) $this->pdo->query("INSERT INTO collabs (user_id, title) VALUES ({$user}, 'Raid night') RETURNING id")->fetchColumn();
    }

    private function stream(int $user, int $collab, string $title, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO streams (user_id, collab_id, title, status, streaming_platform_id) VALUES (?, ?, ?, ?, (SELECT id FROM streaming_platforms LIMIT 1)) RETURNING id'
        );
        $stmt->execute([$user, $collab, $title, $status]);

        return (int) $stmt->fetchColumn();
    }

    public function testEveryoneTickedStaysWithTheirRoleAndConfirmation(): void
    {
        $user   = $this->createUser('phpunit_cast');
        $collab = $this->collab($user);
        $ana    = $this->streamer($user, 'Ana');
        $bia    = $this->streamer($user, 'Bia');
        $cris   = $this->streamer($user, 'Cris');
        $other  = $this->streamer($this->createUser('phpunit_cast2'), 'Not mine');

        CollabCast::save($this->pdo, $collab, $user, [$ana, $bia, $cris, $other], [$ana => 'host', $bia => 'co_host']);
        $this->pdo->exec("UPDATE collab_streamers SET confirmation = 'confirmed', note = 'ok' WHERE streamer_id = {$ana}");

        CollabCast::save($this->pdo, $collab, $user, ['', (string) $ana, (string) $cris], [$ana => 'host', $cris => 'raid']);

        $rows = $this->pdo->query("SELECT st.name, cs.role, cs.confirmation, cs.note FROM collab_streamers cs JOIN streamers st ON st.id = cs.streamer_id WHERE cs.collab_id = {$collab} ORDER BY st.name")->fetchAll(PDO::FETCH_NUM);

        self::assertSame([['Ana', 'host', 'confirmed', 'ok'], ['Cris', 'raid', 'invited', null]], $rows);
        self::assertSame(['Ana', 'Cris'], CollabCast::names($this->pdo, $collab));
    }

    public function testPlannedLivesFollowTheCastAndTheirCredit(): void
    {
        $user   = $this->createUser('phpunit_cast');
        $collab = $this->collab($user);
        $ana    = $this->streamer($user, 'Ana');
        $bia    = $this->streamer($user, 'Bia Lima');

        CollabCast::save($this->pdo, $collab, $user, [$ana], []);
        $planned = $this->stream($user, $collab, 'Raid night ft. @Ana #coop', 'planned');
        $done    = $this->stream($user, $collab, 'Raid night ft. @Ana', 'done');
        $bare    = $this->stream($user, $collab, 'Raid night #coop #fun', 'planned');

        $before = CollabCast::names($this->pdo, $collab);
        CollabCast::save($this->pdo, $collab, $user, [$ana, $bia], []);
        self::assertSame(2, CollabCast::followPlanned($this->pdo, $collab, $user, $before));

        $title = fn (int $id): string => (string) $this->pdo->query("SELECT title FROM streams WHERE id = {$id}")->fetchColumn();
        $people = fn (int $id): int => (int) $this->pdo->query("SELECT count(*) FROM stream_collaborators WHERE stream_id = {$id}")->fetchColumn();

        self::assertSame('Raid night ft. @Ana, @BiaLima #coop', $title($planned));
        self::assertSame('Raid night ft. @Ana, @BiaLima #coop #fun', $title($bare));
        self::assertSame('Raid night ft. @Ana', $title($done), 'Lives no longer planned are left alone.');
        self::assertSame([2, 2, 0], [$people($planned), $people($bare), $people($done)]);

        CollabCast::save($this->pdo, $collab, $user, [], []);
        CollabCast::followPlanned($this->pdo, $collab, $user, ['Ana', 'Bia Lima']);
        self::assertSame(['Raid night #coop', 0], [$title($planned), $people($planned)]);
    }

    public function testCreditUsesTheUsersOwnWord(): void
    {
        self::assertSame('feat. @A, @B', CollabCast::credit('feat.', ['A', 'B']));
        self::assertSame('@A', CollabCast::credit('', ['A']));
        self::assertSame('', CollabCast::credit('ft.', []));
        self::assertSame('Title @A', CollabCast::retitle('Title', '', '@A'));
    }
}
