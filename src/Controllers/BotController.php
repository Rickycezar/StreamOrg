<?php
declare(strict_types=1);

/**
 * The chat bot from both sides: Administration → Chat bot (the account it
 * speaks as, on/off, prefix, default commands, adding it to channels or
 * blocking them, activity) and the
 * streamer's Profile → Chat bot tab, in sub-tabs: overview (adding it to
 * their channel, how it reads chat, statistics), commands (built-in ones
 * made their own, and their own custom commands), timed messages and
 * the activity log. See ChatBot.
 */
final class BotController
{
    /** GET /admin/bot */
    public static function admin(): void
    {
        Auth::requireAdmin();

        View::render('admin/bot', [
            'account'          => ChatBot::account(),
            'defaults'         => ChatBot::defaults(),
            'channels'         => ChatBot::channels(),
            'stats'            => ChatBot::statsSummary(),
            'log'              => ChatBot::recentLog(),
            'twitchConfigured' => Twitch::isConfigured(),
        ], __('ui.nav.chat_bot'));
    }

    /** GET /admin/bot/connect — off to Twitch, to approve as the bot account. */
    public static function connect(): void
    {
        Auth::requireAdmin();

        if (!Twitch::isConfigured()) {
            flash('error', __('ui.message.twitch_not_configured'));
            redirect('/admin/bot');
        }

        header('Location: ' . ChatBot::authorizeUrl());
        exit;
    }

    /** Twitch's answer for the bot account, handed over by the shared callback. */
    public static function callback(string $code): never
    {
        Auth::requireAdmin();

        try {
            $login = ChatBot::complete($code);
            flash('success', sprintf(__('ui.message.bot_connected'), $login));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/bot');
    }

    /** POST /admin/bot/disconnect */
    public static function disconnect(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        ChatBot::disconnect();

        flash('success', __('ui.message.bot_disconnected'));
        redirect('/admin/bot');
    }

    /** POST /admin/bot/settings — on/off and the command prefix. */
    public static function settings(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        try {
            ChatBot::saveSettings(!empty($_POST['is_enabled']), (string) ($_POST['command_prefix'] ?? '!'));
            flash('success', __('ui.message.saved'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/bot');
    }

    /** POST /admin/bot/command — a default command. */
    public static function defaultCommand(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $code = (string) ($_POST['code'] ?? '');

        try {
            ChatBot::saveCommand(null, $code, ChatBot::commandFromInput($code, $_POST));
            flash('success', __('ui.message.saved'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/bot');
    }

    /** POST /admin/bot/channel — block or unblock a channel. */
    public static function block(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: 0;

        ChatBot::setBlocked($userId, ($_POST['blocked'] ?? '') === '1');

        flash('success', __('ui.message.saved'));
        redirect('/admin/bot');
    }

    /** POST /admin/bot/add — an admin adds the bot to a streamer's channel, or removes it. */
    public static function adminChannel(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: 0;
        $on     = ($_POST['enabled'] ?? '') === '1';

        if ($on && TwitchUser::connection($userId) === null) {
            flash('error', __('ui.message.bot_user_needs_twitch'));
            redirect('/admin/bot');
        }

        ChatBot::setChannel($userId, $on, true);

        flash('success', __($on ? 'ui.message.bot_added_admin' : 'ui.message.bot_removed_admin'));
        redirect('/admin/bot');
    }

    /** The sub-tabs of Profile → Chat bot: path => [label, view]. */
    public const SECTIONS = [
        '/bot'          => ['ui.label.bot_overview', 'overview'],
        '/bot/commands' => ['ui.label.bot_commands', 'commands'],
        '/bot/timers'   => ['ui.label.bot_timers', 'timers'],
        '/bot/logs'     => ['ui.label.bot_logs', 'logs'],
    ];

    /** GET /profile/bot… — the chat bot moved to Stream tools (/bot…). */
    public static function moved(): never
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        redirect('/bot' . (preg_match('~/profile/bot(/[a-z]+)?$~', $path, $m) ? ($m[1] ?? '') : ''));
    }

    /** GET /bot */
    public static function profile(): void
    {
        self::section('/bot', static fn (int $userId): array => [
            'channel' => ChatBot::channel($userId),
            'twitch'  => TwitchUser::connection($userId),
            'stats'   => ChatBot::statsSummary($userId),
            'granted' => TwitchUser::hasScope($userId, TwitchUser::BOT_SCOPE) && TwitchUser::hasScope($userId, TwitchUser::CHATTERS_SCOPE),
            'heartbeat' => ChatBot::commandsFor($userId)['heartbeat']['trigger'] ?? 'heartbeat',
        ]);
    }

    /** GET /bot/commands — the built-in commands as the channel uses them, and the streamer's own. */
    public static function commands(): void
    {
        self::section('/bot/commands', static fn (int $userId): array => [
            'commands' => ChatBot::commandsFor($userId),
            'custom'   => ChatBot::customCommands($userId),
        ]);
    }

    /** GET /bot/timers */
    public static function timers(): void
    {
        self::section('/bot/timers', static fn (int $userId): array => [
            'timers' => ChatBot::timers($userId),
        ]);
    }

    /** GET /bot/logs */
    public static function logs(): void
    {
        self::section('/bot/logs', static fn (int $userId): array => [
            'log' => ChatBot::recentLog($userId, 200),
        ]);
    }

    /** Renders one sub-tab inside Stream tools, with the bot's account and the sub-menu. */
    private static function section(string $path, callable $data): void
    {
        Auth::requireLogin();

        if (!ChatBot::isAvailable()) {
            redirect('/overlays');
        }

        $userId = (int) Auth::id();

        View::render('stream/frame', [
            'tab'     => '/bot',
            'tabView' => 'bot/index',
            'tabData' => [
                'section'     => $path,
                'account'     => ChatBot::account(),
                'sectionData' => $data($userId),
            ],
        ], __('ui.nav.chat_bot') . ' · ' . __(self::SECTIONS[$path][0]));
    }

    /** POST /bot/custom — adds a custom command, or changes one. */
    public static function saveCustom(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;

        try {
            ChatBot::saveCustom((int) Auth::id(), $id, ChatBot::customFromInput($_POST));
            flash('success', __('ui.message.saved'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/bot/commands');
    }

    /** POST /bot/custom/delete */
    public static function deleteCustom(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        ChatBot::deleteCustom((int) Auth::id(), filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0);

        flash('success', __('ui.message.deleted'));
        redirect('/bot/commands');
    }

    /** POST /bot/timer — adds a timed message, or changes one. */
    public static function saveTimer(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;

        try {
            ChatBot::saveTimer((int) Auth::id(), $id, ChatBot::timerFromInput($_POST));
            flash('success', __('ui.message.saved'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/bot/timers');
    }

    /** POST /bot/timer/delete */
    public static function deleteTimer(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        ChatBot::deleteTimer((int) Auth::id(), filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0);

        flash('success', __('ui.message.deleted'));
        redirect('/bot/timers');
    }

    /** POST /bot — adds the bot to the streamer's channel, or removes it. */
    public static function toggleChannel(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $userId = (int) Auth::id();
        $on     = ($_POST['enabled'] ?? '') === '1';

        if ($on && TwitchUser::connection($userId) === null) {
            flash('error', __('ui.message.bot_needs_twitch'));
            redirect('/bot');
        }

        ChatBot::setChannel($userId, $on);

        flash('success', __($on ? 'ui.message.bot_added' : 'ui.message.bot_removed'));
        redirect('/bot');
    }

    /** POST /bot/recheck — the streamer just modded (or unmodded) the bot: look again now. */
    public static function recheck(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        ChatBot::recheck((int) Auth::id());

        flash('success', __('ui.message.bot_rechecking'));
        redirect('/bot');
    }

    /** POST /bot/command — the streamer's own version of a command, or back to the default. */
    public static function personalCommand(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $userId = (int) Auth::id();
        $code   = (string) ($_POST['code'] ?? '');

        try {
            if (($_POST['reset'] ?? '') === '1') {
                ChatBot::resetCommand($userId, $code);
            } else {
                ChatBot::saveCommand($userId, $code, ChatBot::commandFromInput($code, $_POST));
            }

            flash('success', __('ui.message.saved'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/bot/commands');
    }
}
