<?php
declare(strict_types=1);

/**
 * Administration → Winners: find giveaway winners across every streamer,
 * and take a key back from one, with a reason (see PrizeRemovals).
 */
final class PrizeRemovalController
{
    /** GET /admin/winners */
    public static function index(): void
    {
        Auth::requireAdmin();

        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
        $state = in_array($_GET['state'] ?? '', PrizeRemovals::STATES, true) ? (string) $_GET['state'] : 'all';

        View::render('admin/winners', [
            'query'    => $query,
            'state'    => $state,
            'winners'  => PrizeRemovals::search($query, $state),
            'removals' => PrizeRemovals::recent(),
        ], __('ui.nav.winners'));
    }

    /** POST /admin/winners/remove */
    public static function remove(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $back = '/admin/winners?' . http_build_query(array_filter([
            'q'     => (string) ($_POST['q'] ?? ''),
            'state' => (string) ($_POST['state'] ?? ''),
        ]));

        try {
            $outcome = PrizeRemovals::remove(
                (int) Auth::id(),
                filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0,
                (string) ($_POST['reason'] ?? ''),
                (string) ($_POST['outcome'] ?? 'revoked')
            );
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        flash('success', __('ui.message.prize_removed_' . $outcome));
        redirect($back);
    }
}
