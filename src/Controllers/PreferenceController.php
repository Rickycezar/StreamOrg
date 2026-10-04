<?php
declare(strict_types=1);

/**
 * Per-user interface preferences, currently just which table columns are
 * hidden on which screen.
 */
final class PreferenceController
{
    /** Preference keys the app recognises. Anything else is rejected. */
    private const ALLOWED = ['columns.keys', 'columns.content', 'columns.catalog', 'columns.embargoes'];

    /**
     * Every preference for the signed-in user, for injecting into the page.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (!Auth::check()) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT pref_key, value FROM user_preferences WHERE user_id = ?'
        );
        $stmt->execute([Auth::id()]);

        $prefs = [];

        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) $row['value'], true);
            $prefs[$row['pref_key']] = $decoded ?? [];
        }

        return $prefs;
    }

    /** AJAX: store one preference. */
    public static function save(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $key = (string) ($_POST['key'] ?? '');

        if (!in_array($key, self::ALLOWED, true)) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $value = json_decode((string) ($_POST['value'] ?? '[]'), true);

        if (!is_array($value)) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $value = array_slice(array_values(array_filter(
            array_map('strval', $value),
            static fn (string $v): bool => (bool) preg_match('/^[a-z0-9_]{1,40}$/', $v)
        )), 0, 40);

        $stmt = Database::connection()->prepare(
            'INSERT INTO user_preferences (user_id, pref_key, value)
             VALUES (?, ?, ?::jsonb)
             ON CONFLICT (user_id, pref_key) DO UPDATE SET value = EXCLUDED.value'
        );
        $stmt->execute([Auth::id(), $key, json_encode($value)]);

        json_response(['ok' => true]);
    }
}
