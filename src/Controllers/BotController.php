<?php
declare(strict_types=1);

/**
 * The chat bot from both sides: Administration → Chat bot (the account it
 * speaks as, on/off, prefix, default commands, adding it to channels or
 * blocking them, activity) and the
 * streamer's Profile → Chat bot tab (adding it to their channel and
 * personalising its commands). See ChatBot.
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

    /** GET /profile/bot */
    public static function profile(): void
    {
        Auth::requireLogin();

        if (!ChatBot::isAvailable()) {
            redirect('/profile');
        }

        $userId = (int) Auth::id();

        ProfileController::renderTab('/profile/bot', 'profile/bot', [
            'account'  => ChatBot::account(),
            'channel'  => ChatBot::channel($userId),
            'twitch'   => TwitchUser::connection($userId),
            'commands' => ChatBot::commandsFor($userId),
            'log'      => ChatBot::recentLog($userId, 15),
        ]);
    }

    /** POST /profile/bot — adds the bot to the streamer's channel, or removes it. */
    public static function toggleChannel(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $userId = (int) Auth::id();
        $on     = ($_POST['enabled'] ?? '') === '1';

        if ($on && TwitchUser::connection($userId) === null) {
            flash('error', __('ui.message.bot_needs_twitch'));
            redirect('/profile/bot');
        }

        ChatBot::setChannel($userId, $on);

        flash('success', __($on ? 'ui.message.bot_added' : 'ui.message.bot_removed'));
        redirect('/profile/bot');
    }

    /** POST /profile/bot/command — the streamer's own version of a command, or back to the default. */
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

        redirect('/profile/bot');
    }
}
