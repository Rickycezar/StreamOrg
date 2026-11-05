<?php
declare(strict_types=1);

/**
 * Administration → E-mails: the system messages in each language (edit,
 * preview, send a test), the outbox (every e-mail StreamOrg queued, as it
 * would arrive) and the settings (mode, sender, provider, limits, the
 * addresses written to no more). See Mailer for what each mode does.
 */
final class EmailAdminController
{
    public const TABS = [
        '/admin/emails'          => 'ui.email_admin.tab_messages',
        '/admin/emails/outbox'   => 'ui.email_admin.tab_outbox',
        '/admin/emails/settings' => 'ui.email_admin.tab_settings',
    ];

    public static function index(): void
    {
        Auth::requireAdmin();

        $edited = [];

        foreach (Database::connection()->query('SELECT code, locale, count(*) FROM email_template_overrides GROUP BY code, locale') as $row) {
            $edited[$row['code']][$row['locale']] = (int) $row['count'];
        }

        View::render('admin/emails/index', ['tab' => '/admin/emails', 'edited' => $edited, 'locales' => Lang::available()], __('ui.nav.emails'));
    }

    public static function edit(): void
    {
        Auth::requireAdmin();

        $code   = (string) ($_GET['code'] ?? '');
        $locale = (string) ($_GET['locale'] ?? Lang::locale());

        if (!EmailTemplates::exists($code) || !in_array($locale, Lang::available(), true)) {
            redirect('/admin/emails');
        }

        View::render('admin/emails/edit', [
            'tab'       => '/admin/emails',
            'code'      => $code,
            'locale'    => $locale,
            'locales'   => Lang::available(),
            'texts'     => EmailTemplates::texts($code, $locale),
            'defaults'  => EmailTemplates::defaults($code, $locale),
            'overrides' => EmailTemplates::overrides($code, $locale),
            'preview'   => self::preview($code, $locale, null),
        ], __('ui.nav.emails'));
    }

    /** POST /admin/emails/save */
    public static function save(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $code   = (string) ($_POST['code'] ?? '');
        $locale = (string) ($_POST['locale'] ?? '');
        $back   = '/admin/emails/edit?code=' . rawurlencode($code) . '&locale=' . rawurlencode($locale);

        try {
            $result = EmailTemplates::save($code, $locale, array_map('strval', array_filter((array) ($_POST['texts'] ?? []), 'is_string')), Auth::id());
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/admin/emails');
        }

        flash('success', __('ui.message.saved'));

        if ($result['refused'] !== []) {
            flash('error', sprintf(__('ui.message.email_texts_refused'), implode(', ', array_map(static fn (string $f): string => __('ui.email_admin.field_' . $f), $result['refused']))));
        }

        redirect($back);
    }

    /** POST /admin/emails/preview — the message as it would arrive with the texts typed (JSON, not saved). */
    public static function livePreview(): void
    {
        Auth::requireAdmin();
        Csrf::verify(true);

        $code   = (string) ($_POST['code'] ?? '');
        $locale = (string) ($_POST['locale'] ?? '');

        if (!EmailTemplates::exists($code) || !in_array($locale, Lang::available(), true)) {
            json_response(['ok' => false], 404);
        }

        $typed = array_map('strval', array_filter((array) ($_POST['texts'] ?? []), 'is_string'));
        $texts = array_replace(EmailTemplates::texts($code, $locale), array_intersect_key($typed, array_flip(EmailTemplates::FIELDS)));

        json_response(['ok' => true] + self::preview($code, $locale, $texts));
    }

    /** POST /admin/emails/test — the message (as saved) to the administrator's own address. */
    public static function test(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $code   = (string) ($_POST['code'] ?? 'test');
        $locale = (string) ($_POST['locale'] ?? Lang::locale());
        $back   = $code === 'test' ? '/admin/emails/settings' : '/admin/emails/edit?code=' . rawurlencode($code) . '&locale=' . rawurlencode($locale);

        if (!EmailTemplates::exists($code)) {
            redirect('/admin/emails');
        }

        $user = Auth::user();
        $id   = Mailer::queue($code, ['user' => ['email_verified_at' => 'now', 'email_notifications' => true] + $user, 'locale' => $locale],
            self::samples($code, $locale), self::sampleLink($code, $locale), true);

        flash($id !== null ? 'success' : 'error', $id !== null
            ? sprintf(__(Mailer::mode() === 'live' ? 'ui.message.email_test_queued' : 'ui.message.email_test_held'), $user['email'])
            : __('ui.message.email_not_queued'));
        redirect($back);
    }

    public static function outbox(): void
    {
        Auth::requireAdmin();

        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, ['queued', 'held', 'sending', 'sent', 'failed', 'suppressed'], true) ? $status : null;

        View::render('admin/emails/outbox', [
            'tab'    => '/admin/emails/outbox',
            'rows'   => Mailer::recent(200, $status),
            'counts' => Mailer::counts(),
            'status' => $status,
            'mode'   => Mailer::mode(),
        ], __('ui.nav.emails'));
    }

    public static function message(): void
    {
        Auth::requireAdmin();

        $stmt = Database::connection()->prepare('SELECT o.*, u.username FROM email_outbox o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?');
        $stmt->execute([filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0]);
        $row = $stmt->fetch();

        if ($row === false) {
            redirect('/admin/emails/outbox');
        }

        View::render('admin/emails/message', ['tab' => '/admin/emails/outbox', 'row' => $row], __('ui.nav.emails'));
    }

    /** POST /admin/emails/discard — drops held or failed e-mails from the outbox. */
    public static function discard(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;

        $stmt = Database::connection()->prepare(
            "DELETE FROM email_outbox WHERE status IN ('held', 'failed', 'suppressed')" . ($id !== null ? ' AND id = ?' : '')
        );
        $stmt->execute($id !== null ? [$id] : []);

        flash('success', sprintf(__('ui.message.email_discarded'), $stmt->rowCount()));
        redirect('/admin/emails/outbox');
    }

    public static function settings(): void
    {
        Auth::requireAdmin();

        View::render('admin/emails/settings', [
            'tab'          => '/admin/emails/settings',
            'mode'         => Mailer::mode(),
            'sender'       => Mailer::sender(),
            'perDay'       => (int) Settings::get('mail.per_day', (string) Mailer::PER_DAY),
            'hasToken'     => trim((string) Crypto::decrypt(Settings::get('mail.postmark_token'))) !== '',
            'stream'       => (string) Settings::get('mail.postmark_stream', 'outbound'),
            'suppressions' => Database::connection()->query('SELECT * FROM email_suppressions ORDER BY created_at DESC LIMIT 200')->fetchAll(),
        ], __('ui.nav.emails'));
    }

    /** POST /admin/emails/settings */
    public static function saveSettings(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $userId  = Auth::id();
        $mode    = (string) ($_POST['mode'] ?? 'off');
        $from    = strtolower(trim((string) ($_POST['from_address'] ?? '')));
        $reply   = strtolower(trim((string) ($_POST['reply_to'] ?? '')));
        $token   = trim((string) ($_POST['postmark_token'] ?? ''));
        $perDay  = filter_input(INPUT_POST, 'per_day', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);

        if (($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) || ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) || $perDay === false || $perDay === null) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/admin/emails/settings');
        }

        Settings::set('mail.from_name', mb_substr(trim(str_replace(['"', "\r", "\n"], '', (string) ($_POST['from_name'] ?? ''))), 0, 60) ?: 'StreamOrg', $userId);
        Settings::set('mail.from_address', $from, $userId);
        Settings::set('mail.reply_to', $reply, $userId);
        Settings::set('mail.per_day', (string) $perDay, $userId);
        Settings::set('mail.postmark_stream', preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['postmark_stream'] ?? 'outbound'))) ?: 'outbound', $userId);

        if (!empty($_POST['postmark_clear'])) {
            Settings::set('mail.postmark_token', '', $userId);
        } elseif ($token !== '') {
            Settings::set('mail.postmark_token', (string) Crypto::encrypt($token), $userId);
        }

        if ($mode === 'live' && PostmarkTransport::fromSettings() === null) {
            flash('error', __('ui.message.email_live_needs_provider'));
            $mode = Mailer::mode() === 'live' ? 'preview' : Mailer::mode();
        }

        Settings::set('mail.mode', in_array($mode, Mailer::MODES, true) ? $mode : 'off', $userId);

        flash('success', __('ui.message.saved'));
        redirect('/admin/emails/settings');
    }

    /** POST /admin/emails/suppressions — adds or removes an address written to no more. */
    public static function suppression(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $address = strtolower(trim((string) ($_POST['address'] ?? '')));

        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            flash('error', __('ui.message.invalid_email'));
            redirect('/admin/emails/settings#suppressions');
        }

        if (($_POST['action'] ?? '') === 'remove') {
            Database::connection()->prepare('DELETE FROM email_suppressions WHERE address = ?')->execute([$address]);
        } else {
            Mailer::suppress($address, 'manual');
        }

        flash('success', __('ui.message.saved'));
        redirect('/admin/emails/settings#suppressions');
    }

    /**
     * A message as it would arrive, with sample values.
     *
     * @return array{subject:string, html:string, text:string}
     */
    private static function preview(string $code, string $locale, ?array $texts): array
    {
        $rule = EmailTemplates::CODES[$code];

        return EmailRenderer::render($code, $locale, self::samples($code, $locale), self::sampleLink($code, $locale),
            $rule['optional'] ? Mailer::unsubscribeUrl((int) Auth::id(), $locale) : null, $texts);
    }

    /** @return array<string, string|int> example values for a message's placeholders */
    private static function samples(string $code, string $locale): array
    {
        $user = Auth::user();

        return [
            'name'      => (string) ($user['display_name'] ?: $user['username']),
            'username'  => (string) $user['username'],
            'hours'     => $code === 'welcome' ? AccountEmails::WELCOME_HOURS : AccountEmails::VERIFY_HOURS,
            'minutes'   => AccountEmails::RESET_MINUTES,
            'new_email' => AccountEmails::masked((string) $user['email']),
            'time'      => (new DateTimeImmutable('now', new DateTimeZone((string) ($user['timezone'] ?: 'UTC'))))->format('d/m/Y H:i T'),
            'title'     => Lang::t('ui.email_admin.sample_title', $locale),
            'text'      => Lang::t('ui.email_admin.sample_text', $locale),
        ];
    }

    private static function sampleLink(string $code, string $locale): ?string
    {
        $path = match ($code) {
            'welcome', 'password_reset' => '/password/reset?t=EXAMPLE',
            'verify_email'              => '/email/verify?t=EXAMPLE',
            'password_changed'          => '/profile/security',
            'notification'              => '/notifications',
            'test'                      => '/admin/emails',
            default                     => null,
        };

        return $path === null ? null : rtrim(public_base_url($locale), '/') . url($path);
    }
}
