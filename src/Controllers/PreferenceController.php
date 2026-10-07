<?php
declare(strict_types=1);

/**
 * Per-user interface preferences: which table columns are hidden on which
 * screen (columns.<table>), and their order (columns.<table>.order).
 */
final class PreferenceController
{
    /** Tables whose columns can be chosen and ordered. Any other key is rejected. */
    private const TABLES = ['keys', 'content', 'catalog', 'embargoes', 'streamers', 'collabs'];

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

        if (!self::allowed($key)) {
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

    /** columns.<table> or columns.<table>.order, for a table that has a column chooser. */
    public static function allowed(string $key): bool
    {
        return (bool) preg_match('/^columns\.(' . implode('|', self::TABLES) . ')(\.order)?$/', $key);
    }
}
