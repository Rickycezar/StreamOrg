<?php
declare(strict_types=1);

/**
 * Notifications: the bell's panel and the notifications page for every
 * user, and Administration → Notifications for sending them (to everyone
 * or to chosen users, in each language) and taking them back. See
 * Notifications.
 */
final class NotificationController
{
    /**
     * GET /notifications — everything sent to the user, newest first. The
     * ones shown count as read from now on, but are still marked as new on
     * this visit.
     */
    public static function index(): void
    {
        Auth::requireLogin();

        $before = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT) ?: null;
        $items  = Notifications::forUser((int) Auth::id(), Notifications::PAGE + 1, $before);
        $more   = count($items) > Notifications::PAGE;
        $items  = array_slice($items, 0, Notifications::PAGE);

        Notifications::markRead((int) Auth::id(), array_column(array_filter($items, static fn (array $i): bool => $i['unread']), 'id'));

        View::render('notifications/index', [
            'items' => $items,
            'more'  => $more,
        ], __('ui.nav.notifications'));
    }

    /** GET /notifications/panel — the bell's dropdown, loaded when it opens. */
    public static function panel(): void
    {
        Auth::requireLogin();

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        echo View::partial('notifications/panel', [
            'items'  => Notifications::forUser((int) Auth::id(), Notifications::PANEL),
            'unread' => Notifications::unreadCount((int) Auth::id()),
        ]);
    }

    /** GET /notifications/open?id= — marks it read and follows its link (or shows it on the page). */
    public static function open(): void
    {
        Auth::requireLogin();

        $id   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
        $item = Notifications::open((int) Auth::id(), $id);

        if ($item === null) {
            redirect('/notifications');
        }

        if (!empty($item['link_url'])) {
            if (str_starts_with((string) $item['link_url'], '/')) {
                redirect((string) $item['link_url']);
            }

            header('Location: ' . $item['link_url']);
            exit;
        }

        redirect('/notifications#n-' . $id);
    }

    /** POST /notifications/read-all */
    public static function readAll(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');

        Notifications::markAllRead((int) Auth::id());

        if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
            json_response(['ok' => true]);
        }

        redirect('/notifications');
    }

    /** GET /admin/notifications — the composer and what was sent. */
    public static function admin(): void
    {
        Auth::requireAdmin();

        View::render('admin/notifications', [
            'locales' => Lang::available(),
            'sent'    => Notifications::sent(),
        ], __('ui.nav.notifications'));
    }

    /** POST /admin/notifications */
    public static function send(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        try {
            $notification = Notifications::fromInput($_POST);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/admin/notifications');
        }

        Notifications::send($notification, (int) Auth::id());

        flash('success', $notification['users'] === null
            ? __('ui.message.notification_sent_everyone')
            : sprintf(__('ui.message.notification_sent_users'), count($notification['users'])));
        redirect('/admin/notifications');
    }

    /** POST /admin/notifications/delete — takes a notification back. */
    public static function delete(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        Notifications::delete(filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0);

        flash('success', __('ui.message.notification_deleted'));
        redirect('/admin/notifications');
    }
}
