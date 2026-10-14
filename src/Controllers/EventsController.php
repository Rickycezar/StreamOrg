<?php
declare(strict_types=1);

/**
 * Stream tools → Events (what the chat bot recorded in the channel) and
 * Stream tools → Unfollows (only for channels an administrator chose),
 * with the administrators' switches for unfollow tracking. See
 * ChannelEvents.
 */
final class EventsController
{
    /** GET /events */
    public static function index(): void
    {
        Auth::requireLogin();

        if (!ChatBot::isAvailable()) {
            redirect('/overlays');
        }

        $userId = (int) Auth::id();
        $filter = isset(ChannelEvents::FILTERS[$_GET['type'] ?? '']) ? (string) $_GET['type'] : null;
        $page   = max(1, (int) ($_GET['page'] ?? 1));

        View::render('stream/frame', [
            'tab'     => '/events',
            'tabView' => 'events/index',
            'tabData' => ChannelEvents::page($userId, $filter, $page) + [
                'filter'   => $filter,
                'page'     => $page,
                'counts'   => ChannelEvents::counts($userId),
                'channel'  => ChatBot::channel($userId),
                'follows'  => ChannelEvents::followsVisible($userId),
            ],
        ], __('ui.nav.events'));
    }

    /** GET /unfollows */
    public static function unfollows(): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::id();

        if (!ChannelEvents::unfollowsFor($userId)) {
            redirect('/overlays');
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));

        View::render('stream/frame', [
            'tab'     => '/unfollows',
            'tabView' => 'events/unfollows',
            'tabData' => ChannelEvents::page($userId, 'unfollows', $page) + [
                'page'     => $page,
                'tracking' => ChannelEvents::tracking($userId),
                'follows'  => ChannelEvents::followsVisible($userId),
            ],
        ], __('ui.nav.unfollows'));
    }

    /** POST /admin/bot/unfollows — unfollow tracking on or off for everyone. */
    public static function adminSwitch(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        ChannelEvents::setUnfollowsOn(($_POST['enabled'] ?? '') === '1', (int) Auth::id());

        flash('success', __('ui.message.saved'));
        redirect('/admin/bot#unfollows');
    }

    /** POST /admin/bot/unfollows/user — unfollow tracking on or off for one channel. */
    public static function adminUser(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: 0;

        if ($userId > 0) {
            ChannelEvents::setUnfollowsFor($userId, ($_POST['enabled'] ?? '') === '1');
            flash('success', __('ui.message.saved'));
        }

        redirect('/admin/bot#channels');
    }
}
