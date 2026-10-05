<?php
declare(strict_types=1);

/**
 * The viewer side: claim links, Twitch sign-in for viewers, "My prizes",
 * deleting a viewer profile, and the privacy page. None of this needs a
 * StreamOrg account; see Viewers and Giveaways.
 */
final class ViewerController
{
    /** GET /claim?t=… — the page behind a winner's link. */
    public static function claim(): void
    {
        $token = (string) ($_GET['t'] ?? '');
        $claim = Giveaways::claimByToken($token);

        if ($claim !== null) {
            $private = Giveaways::needsCopies((int) $claim['giveaway']['user_id']);

            $claim['reserved'] = Giveaways::reservedPrize($claim['giveaway'], $claim['winner']);
            $claim['locked']   = $private && array_filter($claim['prizes'], static fn (array $p): bool => !$p['ready']) !== [];
            $claim['why']      = $claim['giveaway']['copies_taken_back_at'] !== null ? 'protected' : 'preparing';
        }

        $viewer = self::viewer();

        if ($claim !== null && $claim['winner']['claimed_at'] !== null && $viewer !== null
            && (string) $viewer['twitch_user_id'] === (string) $claim['winner']['twitch_user_id']) {
            $claim['claimed'] = Giveaways::claimedPrize($claim['winner']);
        }

        View::publicPage('viewer/claim', [
            'token'      => $token,
            'claim'      => $claim,
            'viewer'     => $viewer,
            'celebrate'  => self::takeCelebration($token),
        ], __('ui.viewer.claim_title'));
    }

    /** POST /claim — redeems the prize (the chosen one in "winner picks" mode). */
    public static function redeem(): void
    {
        Csrf::verify();

        $token  = (string) ($_POST['t'] ?? '');
        $back   = '/claim?t=' . rawurlencode($token);
        $viewer = self::viewer();

        if ($viewer === null) {
            redirect('/viewer/login?back=' . rawurlencode($back));
        }

        try {
            $code = Giveaways::claim($token, $viewer, filter_input(INPUT_POST, 'prize_id', FILTER_VALIDATE_INT) ?: null);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        if ($code === null) {
            flash('success', __('ui.viewer.reserved_flash'));
            redirect($back);
        }

        $_SESSION['claim_celebrate'] = hash('sha256', $token);

        redirect($back);
    }

    /** GET /viewer/login — "Sign in with Twitch" for viewers. */
    public static function login(): void
    {
        if (!Twitch::isConfigured()) {
            flash('error', __('ui.message.twitch_not_configured'));
            redirect('/login');
        }

        header('Location: ' . Viewers::authorizeUrl((string) ($_GET['back'] ?? '/prizes')));
        exit;
    }

    /** Twitch's answer for a viewer sign-in, handed over by the shared callback. */
    public static function callback(string $code): void
    {
        if ($code === '') {
            flash('error', __('ui.viewer.signin_declined'));
            redirect('/login?as=viewer');
        }

        try {
            $back = Viewers::complete($code);
        } catch (UserError $e) {
            error_log('StreamOrg viewer sign-in: ' . TwitchUser::lastError());
            flash('error', $e->getMessage());
            redirect('/login?as=viewer');
        }

        redirect($back);
    }

    public static function logout(): void
    {
        Csrf::verify();
        Viewers::logout();
        redirect('/');
    }

    /** GET /prizes — the keys a viewer (or a creator, through their Twitch) has won. */
    public static function prizes(): void
    {
        $viewer = self::viewer();

        if ($viewer === null) {
            redirect('/login?as=viewer');
        }

        View::publicPage('viewer/prizes', [
            'viewer'  => $viewer,
            'prizes'  => Giveaways::prizesOf($viewer),
            'pending' => Giveaways::pendingFor($viewer),
            'creator' => Auth::check(),
        ], __('ui.viewer.prizes_title'));
    }

    /** POST /viewer/delete — removes the viewer profile (not a creator's account). */
    public static function delete(): void
    {
        Csrf::verify();

        $viewer = Viewers::current();

        if ($viewer === null) {
            redirect('/login?as=viewer');
        }

        Viewers::delete($viewer);

        flash('success', __('ui.viewer.deleted'));
        redirect('/');
    }

    /** GET /privacy */
    public static function privacy(): void
    {
        View::publicPage('privacy', ['email' => (string) Config::get('app.contact_email', '')], __('ui.privacy.title'));
    }

    /**
     * Who is redeeming: a signed-in viewer, or a signed-in creator through
     * the Twitch account they connected.
     */
    private static function viewer(): ?array
    {
        $viewer = Viewers::current();

        if ($viewer === null && Auth::check()) {
            $viewer = Viewers::forCreator((int) Auth::id());
        }

        return $viewer;
    }

    /** Whether the prize behind this token was claimed just now: the page celebrates once. */
    private static function takeCelebration(string $token): bool
    {
        $just = $_SESSION['claim_celebrate'] ?? null;
        unset($_SESSION['claim_celebrate']);

        return is_string($just) && hash_equals($just, hash('sha256', $token));
    }
}
