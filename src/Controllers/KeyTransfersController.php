<?php
declare(strict_types=1);

/**
 * Keys → Transfers: sending keys to another creator's vault, and the
 * transfers received and sent (see KeyTransfers). Everyone is shown by
 * handle only.
 */
final class KeyTransfersController
{
    /** GET /keys/transfers */
    public static function index(): void
    {
        Auth::requireLogin();
        KeyTransfers::expireStale();

        $userId = (int) Auth::id();
        $pick   = array_map('intval', (array) ($_GET['key'] ?? []));

        View::render('keys/transfers', [
            'transfers'   => KeyTransfers::forUser($userId),
            'sendable'    => KeyTransfers::sendable($userId),
            'picked'      => $pick,
            'vaultLocked' => !Vault::isUnlocked($userId),
            'myHandle'    => KeyTransfers::handleOf($userId),
        ], __('ui.nav.key_transfers'));
    }

    /** GET /keys/transfers/search?q= — creators by handle, for the picker. */
    public static function search(): void
    {
        Auth::requireLogin();

        json_response(['ok' => true, 'results' => array_map(static fn (array $c): array => [
            'ref'    => $c['handle'],
            'login'  => $c['handle'],
            'name'   => '@' . $c['handle'],
            'note'   => __($c['twitch'] ? 'ui.transfer.via_twitch' : 'ui.transfer.via_login'),
            'avatar' => $c['avatar'],
        ], KeyTransfers::search((int) Auth::id(), (string) ($_GET['q'] ?? '')))]);
    }

    /** POST /keys/transfers — sends the chosen keys to one creator. */
    public static function send(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        try {
            $sent = KeyTransfers::send(
                (int) Auth::id(),
                (string) ($_POST['handle'] ?? ''),
                array_map('intval', (array) ($_POST['key_ids'] ?? [])),
                (string) ($_POST['message'] ?? '')
            );
        } catch (VaultLocked) {
            flash('error', __('ui.message.transfer_vault_locked'));
            redirect('/keys/transfers');
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/keys/transfers');
        }

        flash('success', sprintf(__('ui.message.transfer_sent'), $sent['count'], '@' . $sent['handle']));
        redirect('/keys/transfers');
    }

    /** POST /keys/transfers/accept */
    public static function accept(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        try {
            $result = KeyTransfers::accept((int) Auth::id(), filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0);
        } catch (VaultLocked) {
            flash('error', __('ui.message.transfer_accept_locked'));
            redirect('/keys/transfers');
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/keys/transfers');
        }

        $message = sprintf(__('ui.message.transfer_accepted'), $result['moved']);

        if ($result['duplicates'] !== []) {
            $message .= ' ' . sprintf(__('ui.message.transfer_duplicates'), implode(', ', $result['duplicates']));
        }

        flash('success', $message);
        redirect('/keys');
    }

    /** POST /keys/transfers/decline */
    public static function decline(): void
    {
        self::close('decline', 'ui.message.transfer_declined');
    }

    /** POST /keys/transfers/cancel */
    public static function cancel(): void
    {
        self::close('cancel', 'ui.message.transfer_cancelled');
    }

    private static function close(string $action, string $done): never
    {
        Auth::requireLogin();
        Csrf::verify();

        $ok = KeyTransfers::$action((int) Auth::id(), filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0);

        flash($ok ? 'success' : 'error', __($ok ? $done : 'ui.message.transfer_gone'));
        redirect('/keys/transfers');
    }
}
