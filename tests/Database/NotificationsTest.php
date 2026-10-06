<?php
declare(strict_types=1);

/** Notifications from the administration: who sees them, in which language, and what counts as unread. */
final class NotificationsTest extends DatabaseTestCase
{
    private function note(?array $users, array $texts = ['en' => ['title' => 'Hello', 'body' => 'Body'], 'pt-BR' => ['title' => 'Olá', 'body' => 'Texto']]): int
    {
        return Notifications::send(['texts' => $texts, 'level' => 'news', 'link' => null, 'users' => $users], null);
    }

    private function backdate(int $userId): void
    {
        $this->pdo->prepare("UPDATE users SET created_at = now() - interval '1 day' WHERE id = ?")->execute([$userId]);
    }

    public function testEveryoneAndChosenUsers(): void
    {
        $ana = $this->createUser('phpunit_note_ana');
        $bia = $this->createUser('phpunit_note_bia');
        $this->backdate($ana);
        $this->backdate($bia);

        $all   = $this->note(null);
        $onlyA = $this->note([$ana]);

        $idsFor = static fn (int $u): array => array_column(Notifications::forUser($u), 'id');

        self::assertContains($all, $idsFor($ana));
        self::assertContains($onlyA, $idsFor($ana));
        self::assertContains($all, $idsFor($bia));
        self::assertNotContains($onlyA, $idsFor($bia));
        self::assertNull(Notifications::open($bia, $onlyA));
    }

    public function testReadInTheUsersLanguageElseEnglishElseAny(): void
    {
        $user = $this->createUser('phpunit_note_lang');
        $both = $this->note([$user]);
        $pt   = $this->note([$user], ['pt-BR' => ['title' => 'Só português', 'body' => '']]);

        Lang::setLocale('pt-BR');
        $byId = array_column(Notifications::forUser($user), 'title', 'id');
        self::assertSame('Olá', $byId[$both]);

        Lang::setLocale('en');
        $byId = array_column(Notifications::forUser($user), 'title', 'id');
        self::assertSame('Hello', $byId[$both]);
        self::assertSame('Só português', $byId[$pt]);
    }

    public function testUnreadCountsOnlyWhatWasSentSinceTheAccountExisted(): void
    {
        $old = $this->createUser('phpunit_note_old');
        $this->backdate($old);

        $earlier = $this->note(null);
        $this->pdo->prepare("UPDATE notifications SET created_at = now() - interval '2 days' WHERE id = ?")->execute([$earlier]);
        $recent = $this->note(null);

        $mine = array_column(Notifications::forUser($old), 'unread', 'id');
        self::assertFalse($mine[$earlier]);
        self::assertTrue($mine[$recent]);

        $before = Notifications::unreadCount($old);
        Notifications::open($old, $recent);
        self::assertSame($before - 1, Notifications::unreadCount($old));

        $this->note([$old]);
        Notifications::markAllRead($old);
        self::assertSame(0, Notifications::unreadCount($old));
    }

    public function testTakingBackRemovesItForEveryone(): void
    {
        $user = $this->createUser('phpunit_note_back');
        $id   = $this->note([$user]);

        Notifications::delete($id);

        self::assertNotContains($id, array_column(Notifications::forUser($user), 'id'));
    }

    public function testComposerInput(): void
    {
        $note = Notifications::fromInput([
            'title' => ['en' => '  New   feature ', 'pt-BR' => ''],
            'body' => ['en' => "Line one\r\nLine two", 'pt-BR' => ''],
            'level' => 'success', 'link' => '/content', 'audience' => 'users', 'users' => ['3', '3', 'x', '5'],
        ]);

        self::assertSame(['en' => ['title' => 'New feature', 'body' => "Line one\nLine two"]], $note['texts']);
        self::assertSame('/content', $note['link']);
        self::assertSame([3, 5], $note['users']);

        foreach ([
            ['title' => ['en' => '']],
            ['title' => ['en' => 'x'], 'link' => 'javascript:alert(1)'],
            ['title' => ['en' => 'x'], 'level' => 'shout'],
            ['title' => ['en' => 'x'], 'audience' => 'users'],
            ['title' => ['en' => ''], 'body' => ['en' => 'text without a title']],
        ] as $bad) {
            try {
                Notifications::fromInput($bad);
                self::fail('Accepted ' . json_encode($bad));
            } catch (UserError) {
                self::assertTrue(true);
            }
        }
    }
}
