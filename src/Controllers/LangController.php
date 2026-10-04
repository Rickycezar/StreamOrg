<?php
declare(strict_types=1);

/**
 * Editing the language files from the interface.
 *
 * The files stay the single source of truth — bin/check_lang.php keeps
 * working, and nothing has to merge database overrides at render time.
 */
final class LangController
{
    /** Lookup tables whose rows need a label, and the group each uses. */
    private const CODE_GROUPS = [
        'key_platform'       => 'SELECT code FROM key_platforms ORDER BY code',
        'game_platform'      => 'SELECT code FROM game_platforms ORDER BY code',
        'streaming_platform' => 'SELECT code FROM streaming_platforms ORDER BY code',
        'genre'              => 'SELECT code FROM genres ORDER BY code',
    ];

    public static function index(): void
    {
        Auth::requireAdmin();

        $locales = Lang::available();
        sort($locales);

        $locale = (string) ($_GET['locale'] ?? ($locales[0] ?? 'en'));

        if (!LangFile::isValidLocale($locale)) {
            $locale = $locales[0] ?? 'en';
        }

        $reference = null;

        foreach ($locales as $candidate) {
            if ($candidate !== $locale) {
                $reference = $candidate;
                break;
            }
        }

        $data      = LangFile::load($locale);
        $refData   = $reference !== null ? LangFile::load($reference) : [];
        $groups    = LangFile::groups($data);
        $group     = (string) ($_GET['group'] ?? ($groups[0] ?? ''));

        if (!in_array($group, $groups, true)) {
            $group = $groups[0] ?? '';
        }

        $entries = LangFile::group($data, $group);
        $refRows = $reference !== null ? LangFile::group($refData, $group) : [];

        $search = trim((string) ($_GET['q'] ?? ''));

        if ($search !== '') {
            $entries = array_filter(
                $entries,
                static fn ($value, $key): bool => mb_stripos((string) $key, $search) !== false
                    || mb_stripos((string) $value, $search) !== false,
                ARRAY_FILTER_USE_BOTH
            );
        }

        View::render('admin/lang', [
            'locales'   => $locales,
            'locale'    => $locale,
            'reference' => $reference,
            'groups'    => $groups,
            'group'     => $group,
            'entries'   => $entries,
            'refRows'   => $refRows,
            'search'    => $search,
            'missing'   => self::missing($data),
            'counts'    => self::counts($data, $refData),
        ], __('ui.nav.languages'));
    }

    /**
     * Codes that exist in the database with no label in this locale, and
     * keys the reference locale has that this one does not. These are the
     * reason the screen exists.
     *
     * @return array<string, list<string>>
     */
    private static function missing(array $data): array
    {
        $pdo     = Database::connection();
        $missing = [];

        foreach (self::CODE_GROUPS as $group => $sql) {
            $labels = $data[$group] ?? [];

            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $code) {
                if (!isset($labels[$code])) {
                    $missing[$group][] = (string) $code;
                }
            }
        }

        return $missing;
    }

    /** @return array{keys:int, ref:int} */
    private static function counts(array $data, array $refData): array
    {
        $count = static function (array $a) use (&$count): int {
            $n = 0;

            foreach ($a as $v) {
                $n += is_array($v) ? $count($v) : 1;
            }

            return $n;
        };

        return ['keys' => $count($data), 'ref' => $count($refData)];
    }

    public static function save(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $locale = (string) ($_POST['locale'] ?? '');
        $group  = (string) ($_POST['group'] ?? '');
        $values = $_POST['values'] ?? [];

        if (!LangFile::isValidLocale($locale) || $group === '' || !is_array($values)) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/admin/lang');
        }

        $data     = LangFile::load($locale);
        $existing = LangFile::group($data, $group);

        if ($existing === [] && !in_array($group, LangFile::groups($data), true)) {
            flash('error', __('ui.message.not_found'));
            redirect('/admin/lang');
        }

        $merged = $existing;

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (!preg_match('/^[A-Za-z0-9_]{1,60}$/', $key)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                unset($merged[$key]);
                continue;
            }

            $merged[ctype_digit($key) ? (int) $key : $key] = $value;
        }

        if ($merged !== [] && array_filter(array_keys($merged), 'is_int') === array_keys($merged)) {
            ksort($merged);
        }

        try {
            $result = LangFile::saveGroup($locale, $group, $merged);
        } catch (Throwable $e) {
            error_log('StreamOrg lang save: ' . $e->getMessage());
            flash('error', __('ui.message.lang_write_failed') . ' ' . $e->getMessage());
            redirect('/admin/lang?locale=' . urlencode($locale) . '&group=' . urlencode($group));
        }

        flash('success', sprintf(__('ui.message.lang_saved'), $result['count'], $result['backup']));
        redirect('/admin/lang?locale=' . urlencode($locale) . '&group=' . urlencode($group));
    }
}
