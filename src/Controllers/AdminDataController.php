<?php
declare(strict_types=1);

/**
 * Administration → User data: what every account keeps (content, key
 * vaults without their codes, collabs, streamer lists), read-only, filtered
 * by user, status and words. See AdminData.
 */
final class AdminDataController
{
    /** GET /admin/data — the overview of the four lists. */
    public static function index(): void
    {
        redirect('/admin/data/content');
    }

    /** GET /admin/data/content */
    public static function content(): void
    {
        self::show('content');
    }

    /** GET /admin/data/keys */
    public static function keys(): void
    {
        self::show('keys');
    }

    /** GET /admin/data/collabs */
    public static function collabs(): void
    {
        self::show('collabs');
    }

    /** GET /admin/data/streamers */
    public static function streamers(): void
    {
        self::show('streamers');
    }

    private static function show(string $subject): void
    {
        Auth::requireAdmin();

        $filters = [
            'user'   => filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT) ?: null,
            'status' => in_array($_GET['status'] ?? '', AdminData::STATUSES[$subject], true) ? (string) $_GET['status'] : '',
            'q'      => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
        ];
        $page   = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
        $result = AdminData::page($subject, $filters, $page);

        View::render('admin/data', [
            'subject' => $subject,
            'filters' => $filters,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($result['total'] / AdminData::PER_PAGE)),
            'total'   => $result['total'],
            'rows'    => $result['rows'],
            'users'   => AdminData::users(),
            'totals'  => AdminData::totals(),
        ], __('ui.admin_data.title') . ' · ' . __('ui.admin_data.tab_' . $subject));
    }
}
