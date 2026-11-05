<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Streamers signing up: a waiting, inactive account that confirms its
 * address, then an administrator's approval (or auto-approval), what
 * sign-in tells them meanwhile, and the public contact addresses.
 */
final class SignupsTest extends DatabaseTestCase
{
    private const FORM = ['username' => 'phpunit_signup', 'name' => 'Sign Up', 'email' => 'Signup@Example.com', 'password' => 'long-password-1', 'confirm' => 'long-password-1', 'channel' => '@Some_Channel', 'timezone' => 'America/Sao_Paulo'];

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
        Settings::set('mail.mode', 'preview', null);
        $this->pdo->exec('DELETE FROM email_outbox');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Settings::forget();
    }

    private function templates(): array
    {
        return $this->pdo->query('SELECT template FROM email_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testASignUpWaitsInactiveAndConfirmsItsAddress(): void
    {
        $user = Signups::create(self::FORM, 'pt-BR');

        self::assertFalse((bool) $user['is_active']);
        self::assertSame('pending', $user['signup_status']);
        self::assertSame('signup@example.com', $user['email']);
        self::assertSame('some_channel', $user['channel_handle']);
        self::assertSame(['verify_email'], $this->templates());
        self::assertContains('phpunit_signup', array_column(Signups::pending(), 'username'));

        $token = EmailTokens::issue((int) $user['id'], 'verify', 'signup@example.com', 60);
        self::assertNotNull(EmailTokens::find(['verify'], $token), 'A waiting account can confirm its address…');
        $reset = EmailTokens::issue((int) $user['id'], 'reset', 'signup@example.com', 30);
        self::assertNull(EmailTokens::find(['reset'], $reset), '…but nothing else.');
    }

    /** @return iterable<string, array{array}> */
    public static function badForms(): iterable
    {
        yield 'bad username' => [['username' => 'a b']];
        yield 'bad e-mail'   => [['email' => 'nope']];
        yield 'no name'      => [['name' => '   ']];
        yield 'bad channel'  => [['channel' => 'no spaces allowed']];
        yield 'short password' => [['password' => 'short', 'confirm' => 'short']];
        yield 'mismatch'     => [['confirm' => 'something-else-1']];
    }

    #[DataProvider('badForms')]
    public function testABadFormIsRefused(array $change): void
    {
        $this->expectException(UserError::class);
        Signups::create($change + self::FORM, 'en');
    }

    public function testTakenNamesAndAddressesAreRefused(): void
    {
        Signups::create(self::FORM, 'en');
        $refused = 0;

        foreach ([['email' => 'other@example.com'], ['username' => 'phpunit_other']] as $change) {
            try {
                Signups::create($change + self::FORM, 'en');
            } catch (UserError) {
                $refused++;
            }
        }

        self::assertSame(2, $refused, 'The same username, or the same address, is refused.');
    }

    public function testSignInSaysWhatIsMissingOnlyWithTheRightPassword(): void
    {
        $user = Signups::create(self::FORM, 'en');

        self::assertFalse(Auth::attempt('phpunit_signup', 'long-password-1'), 'A waiting account cannot sign in.');
        self::assertSame(__('ui.message.signup_confirm_first'), Signups::waitingMessage('phpunit_signup', 'long-password-1'));
        self::assertNull(Signups::waitingMessage('phpunit_signup', 'wrong-password-1'));

        $this->pdo->exec('UPDATE users SET email_verified_at = now() WHERE id = ' . (int) $user['id']);
        self::assertSame(__('ui.message.signup_waiting'), Signups::waitingMessage('signup@example.com', 'long-password-1'));
    }

    public function testAnAdministratorApprovesAConfirmedSignUpOrDeclinesIt(): void
    {
        $admin = $this->createUser('phpunit_signup_admin');
        $this->pdo->exec("UPDATE users SET role = 'admin' WHERE id = {$admin}");
        $user = Signups::create(self::FORM, 'en');

        self::assertFalse(Signups::approve((int) $user['id']), 'Not before the address is confirmed.');

        $this->pdo->exec('UPDATE users SET email_verified_at = now() WHERE id = ' . (int) $user['id']);
        self::assertFalse(Signups::confirmed(['email_verified_at' => 'now'] + $user), 'Without auto-approval it waits.');
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM notification_recipients WHERE user_id = {$admin}")->fetchColumn(), 'The administrators are told.');

        self::assertTrue(Signups::approve((int) $user['id']));
        $row = $this->pdo->query('SELECT is_active, signup_status FROM users WHERE id = ' . (int) $user['id'])->fetch();
        self::assertTrue((bool) $row['is_active']);
        self::assertNull($row['signup_status']);
        self::assertContains('signup_approved', $this->templates());

        $other = Signups::create(['username' => 'phpunit_signup2', 'email' => 'two@example.com'] + self::FORM, 'en');
        $this->pdo->exec('UPDATE users SET email_verified_at = now() WHERE id = ' . (int) $other['id']);
        self::assertTrue(Signups::decline((int) $other['id']));
        self::assertFalse($this->pdo->query('SELECT 1 FROM users WHERE id = ' . (int) $other['id'])->fetchColumn(), 'Declining deletes it.');
        self::assertContains('signup_declined', $this->templates());
    }

    public function testAutoApprovalLetsAConfirmedSignUpIn(): void
    {
        Settings::set('signup.auto_approve', '1', null);
        $user = Signups::create(self::FORM, 'en');
        $this->pdo->exec('UPDATE users SET email_verified_at = now() WHERE id = ' . (int) $user['id']);

        self::assertTrue(Signups::confirmed(['email_verified_at' => 'now'] + $user));
        self::assertTrue((bool) $this->pdo->query('SELECT is_active FROM users WHERE id = ' . (int) $user['id'])->fetchColumn());
    }

    public function testUnconfirmedSignUpsAreRemovedAfterAWhile(): void
    {
        $user = Signups::create(self::FORM, 'en');
        $this->pdo->exec("UPDATE users SET created_at = now() - interval '8 days' WHERE id = " . (int) $user['id']);

        self::assertSame(1, Signups::purgeStale());
    }

    public function testThePublicAddressesFallBackToTheConfiguredOne(): void
    {
        Settings::set('site.contact_email', '', null);
        Settings::set('site.privacy_email', '', null);
        self::assertSame((string) Config::get('app.contact_email', ''), contact_email());

        Settings::set('site.contact_email', 'hello@example.com', null);
        self::assertSame('hello@example.com', contact_email());
        self::assertSame('hello@example.com', privacy_email(), 'Privacy falls back to the contact address.');

        Settings::set('site.privacy_email', 'privacy@example.com', null);
        self::assertSame('privacy@example.com', privacy_email());
    }
}
