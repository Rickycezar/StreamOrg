<?php
declare(strict_types=1);

/**
 * A giveaway's draw by a hosted game (Giveaways → a giveaway → "Draw with
 * a battle"): the page that frames the game and speaks the integration
 * contract with it (public/assets/app.js), and the addresses that page
 * calls — the options for the next draw, the game's own settings and the
 * result, recorded as the giveaway's winners (GameDraws).
 */
final class GameDrawController
{
    public const GAME = 'boss-draw';

    public static function show(): void
    {
        Auth::requireLogin();

        $userId   = (int) Auth::id();
        $giveaway = Giveaways::find($userId, (int) ($_GET['id'] ?? 0));
        $manifest = Games::manifest(self::GAME);

        if ($giveaway === null || $giveaway['is_direct'] || $manifest === null) {
            flash('error', __('ui.message.not_found'));
            redirect('/giveaways');
        }

        $locale = GameDraws::locale(self::GAME);
        $config = GameDraws::config($userId, $giveaway, self::GAME);

        View::render('giveaways/battle', [
            'giveaway' => $giveaway,
            'game'     => self::GAME,
            'manifest' => $manifest,
            'gameUrl'  => url('/games/' . self::GAME . '/' . $manifest['entry']),
            'config'   => $config,
            'slots'    => GameDraws::openSlots((int) $giveaway['id']),
            'canDraw'  => GameDraws::canDraw($giveaway),
            'options'  => Games::options(self::GAME),
            'rules'    => Games::preferences($userId, self::GAME),
            'texts'    => Games::texts(self::GAME, $locale),
        ], __('ui.label.game_draw') . ' · ' . $giveaway['title']);
    }

    /** POST /giveaways/battle/options — keeps the options and StreamOrg's draw rules, and answers the config to send the game. */
    public static function options(): void
    {
        Auth::requireLogin();
        Csrf::verify(true);

        $userId   = (int) Auth::id();
        $giveaway = self::giveaway($userId);
        $options  = Games::saveOptions($userId, self::GAME, (array) ($_POST['options'] ?? []));
        GameDraws::saveRules($userId, self::GAME, !empty($_POST['sync_winners']), !empty($_POST['exclude_winners']));

        json_response(['ok' => true, 'config' => GameDraws::config($userId, $giveaway, self::GAME, $options)]);
    }

    /** POST /giveaways/battle/settings — keeps the settings the game asked to save. */
    public static function settings(): void
    {
        Auth::requireLogin();
        Csrf::verify(true);

        try {
            Games::saveSettings((int) Auth::id(), self::GAME, json_decode((string) ($_POST['settings'] ?? ''), true));
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        json_response(['ok' => true]);
    }

    /** POST /giveaways/battle/result — records the game's result as the giveaway's winners. */
    public static function result(): void
    {
        Auth::requireLogin();
        Csrf::verify(true);

        $userId   = (int) Auth::id();
        $giveaway = self::giveaway($userId);
        $result   = json_decode((string) ($_POST['result'] ?? ''), true);

        try {
            $recorded = GameDraws::record($userId, (int) $giveaway['id'], self::GAME, $result);
        } catch (UserError $e) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            ErrorLog::note('game draw result: ' . $e->getMessage());
            json_response(['ok' => false, 'message' => __('ui.message.provider_error')], 502);
        }

        json_response([
            'ok'      => true,
            'message' => sprintf(__('ui.message.game_winners_recorded'), $recorded['count'])
                . match ($recorded['posted']) { true => ' ' . __('ui.message.winner_link_posted'), false => ' ' . __('ui.message.winner_link_not_posted'), null => '' },
            'config'  => GameDraws::config($userId, Giveaways::find($userId, (int) $giveaway['id']) ?? $giveaway, self::GAME),
        ]);
    }

    /** The giveaway named by the posted id, or a JSON error. */
    private static function giveaway(int $userId): array
    {
        $giveaway = Giveaways::find($userId, (int) ($_POST['id'] ?? 0));

        if ($giveaway === null || $giveaway['is_direct'] || Games::manifest(self::GAME) === null) {
            json_response(['ok' => false, 'message' => __('ui.message.not_found'), 'error' => __('ui.message.not_found')], 404);
        }

        return $giveaway;
    }
}
