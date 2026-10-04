<?php
declare(strict_types=1);

/**
 * Negotiations with publishers: key requests and the conversations around
 * them. Only a placeholder for now; the negotiations table already exists
 * (keys can point at one through game_keys.negotiation_id).
 */
final class NegotiationController
{
    public static function index(): void
    {
        Auth::requireLogin();

        View::render('negotiations/index', [], __('ui.nav.negotiations'));
    }
}
