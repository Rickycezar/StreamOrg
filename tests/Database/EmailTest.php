<?php
declare(strict_types=1);

/**
 * E-mail, without sending anything: what gets queued in each mode and
 * why not, how messages are rendered and escaped, the links' tokens, the
 * texts changed by administrators, notification copies, unsubscribing,
 * and delivery through a stand-in provider.
 */
final class EmailTest extends DatabaseTestCase
{
    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
        $this->pdo->exec('DELETE FROM email_outbox');

        $id = $this->createUser('phpunit_mail');
        $this->pdo->exec("UPDATE users SET email = 'phpunit_mail@example.com', locale = 'pt-BR', display_name = 'Ana <b>Bold</b>' WHERE id = {$id}");
        $this->user = $this->fetchUser($id);
    }

    protected function tearDown(): void
    {
        Mailer::$transport = null;
        parent::tearDown();
        Settings::forget();
    }

    private function fetchUser(int $id): array
    {
        return $this->pdo->query("SELECT * FROM users WHERE id = {$id}")->fetch();
    }

    private function mode(string $mode): void
    {
        Settings::set('mail.mode', $mode, null);
    }

    private function outbox(): array
    {
        return $this->pdo->query('SELECT * FROM email_outbox ORDER BY id')->fetchAll();
    }

    public function testNothingIsQueuedWhileOffButTests(): void
    {
        $this->mode('off');

        self::assertNull(AccountEmails::verify($this->user));
        self::assertNull(Mailer::queue('test', ['user' => $this->user]));
        self::assertNotNull(Mailer::queue('test', ['user' => $this->user], [], 'https://example.com/', true), 'An administrator\'s test still goes in.');
        self::assertFalse(Mailer::on());
    }

    public function testThePagesThatDependOnEmailShowOnlyWhenItReallyGoesOut(): void
    {
        $this->mode('preview');
        self::assertTrue(Mailer::on());
        self::assertFalse(Mailer::ready(), 'Preview prepares e-mails but sends none.');

        $this->mode('live');
        self::assertFalse(Mailer::ready(), 'Live without a provider is not ready.');

        Settings::set('mail.postmark_token', (string) Crypto::encrypt('phpunit-token'), null);
        self::assertFalse(Mailer::ready(), 'Nor without a sender address.');

        Settings::set('mail.from_address', 'no-reply@notify.example.com', null);
        self::assertTrue(Mailer::ready());
    }

    public function testPreviewHoldsEveryMessageRenderedAndEscapedInTheRecipientsLanguage(): void
    {
        $this->mode('preview');

        AccountEmails::verify($this->user);
        $row = $this->outbox()[0];

        self::assertSame('held', $row['status']);
        self::assertSame('pt-BR', $row['locale']);
        self::assertStringContainsString('Confirme seu e-mail', $row['subject']);
        self::assertStringContainsString('Ana &lt;b&gt;Bold&lt;/b&gt;', $row['html'], 'Values are escaped.');
        self::assertStringNotContainsString('<b>Bold</b>', $row['html']);
        self::assertStringContainsString('/email/verify?t=', $row['body_text'], 'The plain-text version carries the link.');
        self::assertStringContainsString('role="presentation"', $row['html']);
    }

    public function testTheRulesKeepMessagesFromBeingQueued(): void
    {
        $this->mode('preview');

        self::assertNull(Mailer::queue('notification', ['user' => $this->user], ['title' => 't', 'text' => 'x']), 'Notification copies need a confirmed address.');

        $verified = ['email_verified_at' => '2026-01-01'] + $this->user;
        self::assertNull(Mailer::queue('notification', ['user' => ['email_notifications' => false] + $verified], ['title' => 't', 'text' => 'x']), 'Nor to someone who turned them off.');
        self::assertNotNull(Mailer::queue('notification', ['user' => $verified], ['title' => 't', 'text' => 'x']));

        Mailer::suppress('phpunit_mail@example.com', 'bounce');
        $id = Mailer::queue('test', ['user' => $this->user]);
        self::assertSame('suppressed', $this->pdo->query("SELECT status FROM email_outbox WHERE id = {$id}")->fetchColumn());

        self::assertNull(Mailer::queue('test', ['address' => 'not an address']));

        $this->pdo->exec('DELETE FROM email_outbox');
        for ($i = 0; $i < Mailer::PER_HOUR; $i++) {
            self::assertNotNull(Mailer::queue('test', ['address' => 'busy@example.com']));
        }
        self::assertNull(Mailer::queue('test', ['address' => 'busy@example.com']), 'At most PER_HOUR an hour to one address.');
    }

    public function testATokenWorksOnceForItsAddressUntilItExpires(): void
    {
        $token = EmailTokens::issue((int) $this->user['id'], 'reset', 'phpunit_mail@example.com', 30);

        $row = EmailTokens::find(['reset'], $token);
        self::assertSame((int) $this->user['id'], (int) $row['id']);
        self::assertNull(EmailTokens::find(['verify'], $token), 'Only for its purpose.');
        self::assertTrue(EmailTokens::consume((int) $row['token_id']));
        self::assertFalse(EmailTokens::consume((int) $row['token_id']));
        self::assertNull(EmailTokens::find(['reset'], $token), 'Once.');

        $old = EmailTokens::issue((int) $this->user['id'], 'verify', 'phpunit_mail@example.com', 60);
        $new = EmailTokens::issue((int) $this->user['id'], 'verify', 'phpunit_mail@example.com', 60);
        self::assertNull(EmailTokens::find(['verify'], $old), 'A new link retires the older one.');

        $this->pdo->exec("UPDATE users SET email = 'other@example.com' WHERE id = " . (int) $this->user['id']);
        self::assertNull(EmailTokens::find(['verify'], $new), 'Not after the address changed.');

        $expired = EmailTokens::issue((int) $this->user['id'], 'reset', 'other@example.com', 30);
        $this->pdo->exec("UPDATE email_tokens SET expires_at = now() - interval '1 minute' WHERE purpose = 'reset' AND used_at IS NULL");
        self::assertNull(EmailTokens::find(['reset'], $expired));
    }

    public function testAnAdministratorsTextsMustKeepThePlaceholders(): void
    {
        $result = EmailTemplates::save('password_reset', 'en', [
            'subject' => 'Reset for {name} on {site}',
            'note'    => 'No minutes here',
            'heading' => 'Hello {password}',
        ], null);

        self::assertSame(1, $result['saved']);
        self::assertEqualsCanonicalizing(['note', 'heading'], $result['refused'], 'Dropping {minutes} or inventing {password} is refused.');
        self::assertSame('Reset for {name} on {site}', EmailTemplates::texts('password_reset', 'en')['subject']);

        EmailTemplates::save('password_reset', 'en', ['subject' => ''], null);
        self::assertSame(EmailTemplates::defaults('password_reset', 'en')['subject'], EmailTemplates::texts('password_reset', 'en')['subject'], 'Blank brings the default back.');
    }

    public function testNotificationCopiesGoToConfirmedPeopleWhoWantThem(): void
    {
        $this->mode('preview');
        $this->pdo->exec('UPDATE users SET email_verified_at = now() WHERE id = ' . (int) $this->user['id']);
        $other = $this->createUser('phpunit_mail_off');
        $this->pdo->exec("UPDATE users SET email_verified_at = now(), email_notifications = false WHERE id = {$other}");
        $unconfirmed = $this->createUser('phpunit_mail_new');

        Notifications::system([(int) $this->user['id'], $other, $unconfirmed], 'feedback_reply', ['Assunto <i>'], '/support/feedback?id=1');
        $rows = $this->outbox();

        self::assertCount(1, $rows);
        self::assertSame('notification', $rows[0]['template']);
        self::assertSame((int) $this->user['id'], (int) $rows[0]['user_id']);
        self::assertStringContainsString('/support/feedback?id=1', $rows[0]['html']);
        self::assertStringContainsString('/email/unsubscribe?u=', $rows[0]['html'], 'With a one-click way out.');

        Notifications::system([(int) $this->user['id']], 'error_new_php', ['x']);
        self::assertCount(1, $this->outbox(), 'Error alerts are not e-mailed.');
    }

    public function testUnsubscribeLinksAreSigned(): void
    {
        $url = Mailer::unsubscribeUrl(42, 'en');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        self::assertTrue(Mailer::validUnsubscribe(42, $q['s']));
        self::assertFalse(Mailer::validUnsubscribe(43, $q['s']));
        self::assertFalse(Mailer::validUnsubscribe(42, 'forged'));
    }

    public function testTheOldAddressIsToldOfTheChangeHalfHidden(): void
    {
        $this->mode('preview');
        $this->pdo->exec("UPDATE users SET email = 'newone@example.com' WHERE id = " . (int) $this->user['id']);

        AccountEmails::emailChanged($this->fetchUser((int) $this->user['id']), 'phpunit_mail@example.com');
        $rows = $this->outbox();

        self::assertSame(['phpunit_mail@example.com', 'newone@example.com'], array_column($rows, 'to_address'));
        self::assertSame(['email_changed', 'verify_email'], array_column($rows, 'template'));
        self::assertStringContainsString('ne••••@example.com', $rows[0]['body_text']);
        self::assertStringNotContainsString('newone@', $rows[0]['body_text']);
    }

    public function testDeliveryOnlyWhenLiveRetryingAndSuppressingRefusedAddresses(): void
    {
        $this->mode('preview');
        Mailer::queue('test', ['address' => 'held@example.com']);
        Mailer::$transport = new class implements MailTransport {
            public array $sent = [];

            public function send(array $message): array
            {
                $this->sent[] = $message['to_address'];

                return match ($message['to_address']) {
                    'gone@example.com'  => ['ok' => false, 'error' => 'inactive', 'permanent' => true],
                    'later@example.com' => ['ok' => false, 'error' => 'timeout'],
                    default             => ['ok' => true, 'id' => 'abc'],
                };
            }
        };

        self::assertSame(0, Mailer::deliver(), 'Nothing leaves outside live mode.');

        $this->mode('live');
        foreach (['ok@example.com', 'gone@example.com', 'later@example.com'] as $address) {
            Mailer::queue('test', ['address' => $address]);
        }

        self::assertSame(1, Mailer::deliver());
        self::assertSame(['ok@example.com', 'gone@example.com', 'later@example.com'], Mailer::$transport->sent, 'Held e-mails are not sent.');

        $status = $this->pdo->query('SELECT to_address, status FROM email_outbox')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('sent', $status['ok@example.com']);
        self::assertSame('failed', $status['gone@example.com']);
        self::assertSame('queued', $status['later@example.com'], 'Tried again later.');
        self::assertSame('held', $status['held@example.com']);
        self::assertTrue(Mailer::suppressed('gone@example.com'));
    }
}
