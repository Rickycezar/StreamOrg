<?php
declare(strict_types=1);

/**
 * Administration → Errors: the problems the application ran into (see
 * ErrorLog), to read, resolve, open again or clear.
 */
final class ErrorLogController
{
    public const SHOWS = ['open', 'resolved', 'all'];

    /** GET /admin/errors */
    public static function index(): void
    {
        Auth::requireAdmin();

        $focus = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
        $show  = in_array($_GET['show'] ?? '', self::SHOWS, true) ? (string) $_GET['show'] : ($focus !== null ? 'all' : 'open');
        $level = in_array($_GET['level'] ?? '', ErrorLog::LEVELS, true) ? (string) $_GET['level'] : '';
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);

        View::render('admin/errors', [
            'errors' => ErrorLog::list($show, $level, $query),
            'counts' => ErrorLog::openCounts(),
            'show'   => $show,
            'level'  => $level,
            'query'  => $query,
            'focus'  => $focus,
        ], __('ui.nav.errors'));
    }

    /** POST /admin/errors/resolve — resolves (or, with reopen=1, opens again) the chosen problems. */
    public static function resolve(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $reopen = ($_POST['reopen'] ?? '') === '1' || isset($_POST['reopen_one']);
        $ids    = match (true) {
            isset($_POST['reopen_one']) => [(int) $_POST['reopen_one']],
            isset($_POST['resolve_one']) => [(int) $_POST['resolve_one']],
            default => array_map('intval', (array) ($_POST['ids'] ?? [])),
        };
        $done   = ErrorLog::resolve($ids, (int) Auth::id(), !$reopen);

        flash('success', sprintf(__($reopen ? 'ui.message.errors_reopened' : 'ui.message.errors_resolved'), $done));
        redirect(self::back());
    }

    /** POST /admin/errors/clear — deletes the resolved problems. */
    public static function clear(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        flash('success', sprintf(__('ui.message.errors_cleared'), ErrorLog::clearResolved()));
        redirect('/admin/errors');
    }

    private static function back(): string
    {
        $query = array_filter([
            'show'  => in_array($_POST['show'] ?? '', self::SHOWS, true) ? $_POST['show'] : null,
            'level' => in_array($_POST['level'] ?? '', ErrorLog::LEVELS, true) ? $_POST['level'] : null,
            'q'     => trim((string) ($_POST['q'] ?? '')) ?: null,
        ]);

        return '/admin/errors' . ($query ? '?' . http_build_query($query) : '');
    }
}
