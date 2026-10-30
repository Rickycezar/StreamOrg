<?php
declare(strict_types=1);

/**
 * Administration → Game texts: the texts of the hosted games (Games),
 * apart from StreamOrg's own interface texts. Each game ships its texts
 * for some languages; here an administrator changes any of them, or gives
 * a game a whole new language, without a new build of the game. The
 * changes are kept in the database and the game fetches them itself
 * (GameFiles); a text that would drop or invent a {placeholder} is
 * refused.
 */
final class GameTextsController
{
    public static function index(): void
    {
        Auth::requireAdmin();

        $games = Games::all();

        if ($games === []) {
            View::render('admin/game_texts', ['games' => []], __('ui.nav.game_texts'));
            return;
        }

        $game = (string) ($_GET['game'] ?? '');
        $game = isset($games[$game]) ? $game : (string) array_key_first($games);

        $languages = Games::languages($game);
        $locale    = (string) ($_GET['locale'] ?? '');

        if (!Games::isLocale($locale)) {
            $locale = in_array(Lang::locale(), $languages, true) ? Lang::locale() : 'en';
        }
        if (!in_array($locale, $languages, true)) {
            $languages[] = $locale;
        }

        $english   = Games::defaults($game, 'en');
        $own       = Games::defaults($game, $locale);
        $changes   = Games::overrides($game, $locale);
        $sections  = array_values(array_unique(array_map(static fn (string $k): string => explode('.', $k)[0], array_keys($english))));
        sort($sections);
        $section   = (string) ($_GET['section'] ?? '');
        $section   = in_array($section, $sections, true) ? $section : '';
        $search    = trim((string) ($_GET['q'] ?? ''));
        $changed   = (string) ($_GET['changed'] ?? '') === '1';

        $rows = [];

        foreach ($english as $key => $text) {
            if (($section !== '' && !str_starts_with($key, $section . '.')) || ($changed && !isset($changes[$key]))) {
                continue;
            }

            $row = [
                'key'          => $key,
                'value'        => $changes[$key] ?? $own[$key] ?? '',
                'default'      => $own[$key] ?? null,
                'english'      => $text,
                'changed'      => isset($changes[$key]),
                'placeholders' => Games::placeholders($own[$key] ?? $text),
            ];

            if ($search !== '' && mb_stripos($key . ' ' . $row['value'] . ' ' . $text, $search) === false) {
                continue;
            }

            $rows[] = $row;
        }

        View::render('admin/game_texts', [
            'games'     => $games,
            'game'      => $game,
            'languages' => $languages,
            'locale'    => $locale,
            'shipped'   => (array) $games[$game]['languages'],
            'sections'  => $sections,
            'section'   => $section,
            'search'    => $search,
            'changed'   => $changed,
            'rows'      => $rows,
            'total'     => count($english),
            'missing'   => count(array_diff_key($english, $own, $changes)),
            'changeCount' => count($changes),
            'publicUrl' => url('/games/' . $game . '/'),
        ], __('ui.nav.game_texts'));
    }

    /** POST /admin/game-texts — saves the texts shown, and goes back to the same view. */
    public static function save(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $game   = (string) ($_POST['game'] ?? '');
        $locale = (string) ($_POST['locale'] ?? '');
        $back   = '/admin/game-texts?' . http_build_query(array_filter([
            'game'    => $game,
            'locale'  => $locale,
            'section' => (string) ($_POST['section'] ?? ''),
            'q'       => (string) ($_POST['q'] ?? ''),
            'changed' => (string) ($_POST['changed'] ?? ''),
        ]));

        try {
            $result = Games::saveTexts($game, $locale, array_map('strval', array_filter((array) ($_POST['values'] ?? []), 'is_string')), Auth::id());
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        flash('success', sprintf(__('ui.message.game_texts_saved'), $result['saved']));

        if ($result['refused'] !== []) {
            flash('error', sprintf(__('ui.message.game_texts_refused'), implode(', ', $result['refused'])));
        }

        redirect($back);
    }
}
