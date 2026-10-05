<?php
declare(strict_types=1);

/**
 * Editing the language files from the interface.
 *
 * The files stay the single source of truth for interface strings —
 * bin/check_lang.php keeps working. Labels for codes added at runtime
 * (key sites, platforms, genres) are kept in the database instead
 * (CodeLabels), since edits to files would be lost at the next deploy.
 */
final class LangController
{
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
            'codeRows'  => $codeRows = self::codeRows($locales),
            'missingTotal' => self::missingTotal($codeRows, $locales),
            'counts'    => self::counts($data, $refData),
        ], __('ui.nav.languages'));
    }

    /**
     * Database codes that need a label from the database: those with no
     * label in some language file, plus those that already have one.
     * Each cell holds the file label (read-only here) and the stored one.
     *
     * @param list<string> $locales
     * @return array<string, array<string, array<string, array{file:?string, db:?string}>>> group => code => locale => labels
     */
    private static function codeRows(array $locales): array
    {
        $files = [];

        foreach ($locales as $locale) {
            $files[$locale] = LangFile::load($locale);
        }

        $rows = [];

        foreach (CodeLabels::codes() as $group => $codes) {
            foreach ($codes as $code) {
                $cells  = [];
                $needed = false;

                foreach ($locales as $locale) {
                    $file = $files[$locale][$group][$code] ?? null;
                    $db   = CodeLabels::get($group, $code, $locale);

                    $cells[$locale] = ['file' => is_string($file) ? $file : null, 'db' => $db];
                    $needed = $needed || $file === null || $db !== null;
                }

                if ($needed) {
                    $rows[$group][$code] = $cells;
                }
            }
        }

        return $rows;
    }

    /** Label slots with neither a file label nor a stored one. */
    private static function missingTotal(array $codeRows, array $locales): int
    {
        $missing = 0;

        foreach ($codeRows as $codes) {
            foreach ($codes as $cells) {
                foreach ($locales as $locale) {
                    $missing += $cells[$locale]['file'] === null && $cells[$locale]['db'] === null ? 1 : 0;
                }
            }
        }

        return $missing;
    }

    /** Saves labels for database codes: labels[group][code][locale]. A blank removes the stored label. */
    public static function saveCodeLabels(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $labels  = $_POST['labels'] ?? [];
        $known   = CodeLabels::codes();
        $locales = Lang::available();
        $saved   = 0;

        if (is_array($labels)) {
            foreach ($labels as $group => $codes) {
                if (!isset($known[$group]) || !is_array($codes)) {
                    continue;
                }

                foreach ($codes as $code => $perLocale) {
                    if (!in_array((string) $code, $known[$group], true) || !is_array($perLocale)) {
                        continue;
                    }

                    foreach ($perLocale as $locale => $label) {
                        if (!in_array($locale, $locales, true) || !is_string($label)) {
                            continue;
                        }

                        $before = CodeLabels::get($group, (string) $code, $locale);
                        $label  = trim($label);

                        if ($label !== (string) $before) {
                            CodeLabels::set($group, (string) $code, $locale, $label, Auth::id());
                            $saved++;
                        }
                    }
                }
            }
        }

        flash('success', sprintf(__('ui.message.code_labels_saved'), $saved));
        redirect('/admin/lang#code-labels');
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
