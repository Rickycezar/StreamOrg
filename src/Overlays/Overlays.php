<?php
declare(strict_types=1);

/**
 * A streamer's overlays: created from a type, with settings checked
 * against it, a secret key in their OBS link, test events sent from the
 * settings page, and the state an open overlay asks for.
 *
 * The key goes after the "#" of the link (…/overlay/alert#KEY), the part
 * browsers never send to a server, so it never reaches the server's logs:
 * the page reads it and sends it with each request for its state. The
 * database keeps its hash (to find the overlay) and an encrypted copy (so
 * the owner can copy the link again); a new key retires the old one.
 *
 * Every change an open overlay must react to (new settings, a test) is a
 * signal: kept for an hour for overlays that check for changes, and sent
 * at once as a NOTIFY on streamorg_overlay for a live connection.
 *
 * Settings come from the simple form or, in advanced mode, from a text the
 * owner writes (OverlayIni), kept as written; advanced mode also takes CSS
 * of the owner's own, applied on top of the type's look when
 * administrators allow it. See 045_overlays.sql.
 */
final class Overlays
{
    public const NOTIFY_CHANNEL = 'streamorg_overlay';
    public const MAX_CSS = 20000;
    private const LOOKUP_LIMIT = 30;

    /**
     * Adds an overlay of a type, with its default settings.
     *
     * @throws UserError when overlays or this type are off, or the user has as many as allowed
     */
    public static function create(int $userId, string $type, string $name = ''): int
    {
        if (!OverlayConfig::enabled() || !OverlayConfig::typeAllowed($type)) {
            throw new UserError(__('ui.message.overlay_type_off'));
        }

        $stmt = Database::connection()->prepare('SELECT count(*) FROM overlays WHERE user_id = ?');
        $stmt->execute([$userId]);

        if ((int) $stmt->fetchColumn() >= OverlayConfig::maxPerUser()) {
            throw new UserError(sprintf(__('ui.message.overlay_limit'), OverlayConfig::maxPerUser()));
        }

        $key  = self::newKey();
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 80) ?: __('ui.overlay_type.' . $type);

        $stmt = Database::connection()->prepare(
            'INSERT INTO overlays (user_id, type, name, key_hash, key_secret, settings) VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb)) RETURNING id'
        );
        $stmt->execute([$userId, $type, $name, hash('sha256', $key), Crypto::encrypt($key), json_encode(OverlayTypes::defaults($type), JSON_UNESCAPED_UNICODE)]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A user's overlays, oldest first, each with its link.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM overlays WHERE user_id = ? ORDER BY created_at, id');
        $stmt->execute([$userId]);

        return array_map([self::class, 'view'], $stmt->fetchAll());
    }

    /**
     * One of the user's overlays, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $overlayId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM overlays WHERE id = ? AND user_id = ?');
        $stmt->execute([$overlayId, $userId]);
        $row = $stmt->fetch();

        return $row !== false ? self::view($row) : null;
    }

    /**
     * Saves an overlay's name, whether it is on, and its settings; open
     * overlays get the new settings. In simple mode $input is the form's
     * settings (fields it does not show keep their values); in advanced
     * mode $text is read instead and kept as written. $css (null: keep)
     * is the owner's own style.
     *
     * @return list<array{line:int, message:string}> what the advanced text had wrong
     * @throws UserError when it is not the user's
     */
    public static function update(int $overlayId, int $userId, string $name, bool $enabled, array $input, bool $advanced = false, ?string $text = null, ?string $css = null): array
    {
        $overlay = self::owned($overlayId, $userId);
        $name    = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 80) ?: $overlay['name'];

        [$settings, $warnings] = self::settingsFrom($overlay, $userId, $input, $advanced, $text);

        Database::connection()->prepare(
            'UPDATE overlays SET name = ?, is_enabled = ?, settings = CAST(? AS jsonb), advanced = ?, config_text = ?, custom_css = ?,
                    version = version + 1, updated_at = now() WHERE id = ?'
        )->execute([
            $name, $enabled ? 'true' : 'false', json_encode($settings, JSON_UNESCAPED_UNICODE),
            $advanced ? 'true' : 'false',
            $advanced && $text !== null ? mb_substr(str_replace("\r\n", "\n", $text), 0, OverlayIni::MAX_LENGTH) : $overlay['config_text'],
            $css !== null ? self::cleanCss($css) : $overlay['custom_css'],
            $overlayId,
        ]);

        self::signal($overlayId, 'settings');

        return $warnings;
    }

    /**
     * Switches an overlay between the simple form and advanced mode (its
     * settings stay as they are).
     *
     * @throws UserError when it is not the user's
     */
    public static function setMode(int $overlayId, int $userId, bool $advanced): void
    {
        self::owned($overlayId, $userId);

        Database::connection()->prepare('UPDATE overlays SET advanced = ?, version = version + 1, updated_at = now() WHERE id = ?')
            ->execute([$advanced ? 'true' : 'false', $overlayId]);
        self::signal($overlayId, 'settings');
    }

    /**
     * The settings a form or text gives, checked, without saving them (the
     * preview uses this too).
     *
     * @return array{0: array<string, mixed>, 1: list<array{line:int, message:string}>}
     */
    public static function settingsFrom(array $overlay, int $userId, array $input, bool $advanced, ?string $text): array
    {
        if ($advanced) {
            [$raw, $warnings] = OverlayIni::parse($overlay['type'], (string) $text, $userId);

            return [OverlayTypes::clean($overlay['type'], $raw, $userId), $warnings];
        }

        $merged = $overlay['settings'];
        $input  = self::rulesFromRows($overlay, $input);

        foreach ($input as $key => $value) {
            $merged[$key] = is_array($value) && is_array($merged[$key] ?? null) && isset($value['asset'])
                && (int) $value['asset'] === (int) ($merged[$key]['asset'] ?? 0)
                ? array_replace($merged[$key], $value)
                : $value;
        }

        return [OverlayTypes::clean($overlay['type'], $merged, $userId), []];
    }

    /**
     * Rules per viewer from the simple form's rows (login and the simple
     * sub-fields): each kept with what advanced mode set on it, a sound
     * keeping its options while it is the same file; rows taken away
     * remove their rule.
     */
    private static function rulesFromRows(array $overlay, array $input): array
    {
        foreach ((array) (OverlayTypes::get($overlay['type'])['fields'] ?? []) as $name => $field) {
            if ($field['kind'] !== 'map' || empty($field['simple']) || !is_array($input[$name] ?? null)) {
                continue;
            }

            $before = is_array($overlay['settings'][$name] ?? null) ? $overlay['settings'][$name] : [];
            $rules  = [];

            foreach ($input[$name] as $row) {
                $target = is_array($row) ? OverlayTypes::target($field, (string) ($row['login'] ?? '')) : null;

                if ($target === null) {
                    continue;
                }

                $rule = is_array($before[$target] ?? null) ? $before[$target] : [];

                foreach ($field['simple'] as $sub) {
                    $value = $row[$sub] ?? null;

                    if ($field['fields'][$sub]['kind'] === 'toggle') {
                        $rule[$sub] = !empty($value);
                    } elseif ($field['fields'][$sub]['kind'] === 'sound') {
                        $rule[$sub] = is_array($value) && is_array($rule[$sub] ?? null) && (int) ($value['asset'] ?? 0) === (int) ($rule[$sub]['asset'] ?? 0)
                            ? array_replace($rule[$sub], $value)
                            : $value;
                    }
                }

                $rules[$target] = $rule;
            }

            $input[$name] = $rules;
        }

        return $input;
    }

    /**
     * The owner's CSS as kept: within the size limit, without anything that
     * could end the style element it goes in.
     */
    public static function cleanCss(string $css): string
    {
        return mb_substr(str_ireplace('</style', '', str_replace("\r\n", "\n", $css)), 0, self::MAX_CSS);
    }

    /**
     * The advanced text to show for an overlay: the one its owner wrote
     * when it still gives the saved settings, otherwise one written from
     * them (after simple-mode changes).
     */
    public static function textFor(array $overlay, int $userId): string
    {
        if ($overlay['config_text'] !== '') {
            [$settings] = self::settingsFrom($overlay, $userId, [], true, $overlay['config_text']);

            if ($settings == $overlay['settings']) {
                return $overlay['config_text'];
            }
        }

        return OverlayIni::render($overlay['type'], $overlay['settings'], $userId);
    }

    /**
     * Gives an overlay a new key: its old link stops working at once.
     *
     * @throws UserError when it is not the user's
     */
    public static function newLink(int $overlayId, int $userId): void
    {
        self::owned($overlayId, $userId);
        $key = self::newKey();

        Database::connection()->prepare('UPDATE overlays SET key_hash = ?, key_secret = ?, updated_at = now() WHERE id = ?')
            ->execute([hash('sha256', $key), Crypto::encrypt($key), $overlayId]);
        self::signal($overlayId, 'reload');
    }

    public static function delete(int $overlayId, int $userId): void
    {
        Database::connection()->prepare('DELETE FROM overlays WHERE id = ? AND user_id = ?')->execute([$overlayId, $userId]);
    }

    /**
     * Sends a test event (one of the type's tests) to the open overlays.
     *
     * @throws UserError when it is not the user's or not one of its tests
     */
    public static function test(int $overlayId, int $userId, string $event): void
    {
        $overlay = self::owned($overlayId, $userId);

        if (!in_array($event, (array) (OverlayTypes::get($overlay['type'])['tests'] ?? []), true)) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        self::signal($overlayId, 'test', ['event' => $event, 'user' => self::sampleUser($userId), 'label' => __('ui.overlay.test_tag')]);
    }

    /**
     * Records something open overlays must react to, and tells a live
     * connection at once. Signals older than an hour are cleared now and
     * then.
     */
    public static function signal(int $overlayId, string $kind, array $payload = []): int
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO overlay_signals (overlay_id, kind, payload) VALUES (?, ?, CAST(? AS jsonb)) RETURNING id');
        $stmt->execute([$overlayId, $kind, json_encode($payload ?: new stdClass(), JSON_UNESCAPED_UNICODE)]);
        $id = (int) $stmt->fetchColumn();

        $pdo->prepare('SELECT pg_notify(?, ?)')->execute([self::NOTIFY_CHANNEL, json_encode(['overlay' => $overlayId, 'signal' => $id])]);

        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM overlay_signals WHERE created_at < now() - interval '1 hour'");
        }

        return $id;
    }

    /**
     * What an open overlay asks for, by its key: its settings (only when
     * they changed since the version it has), the signals after the last
     * one it saw (on its first request, only where they stand), how often
     * to check again, and the channel whose chat it may read. Null when
     * the key is unknown; "off" when the overlay, its type or overlays as
     * a whole are switched off.
     *
     * @return array<string, mixed>|null
     */
    public static function state(string $key, int $version, int $since): ?array
    {
        if ($key === '' || strlen($key) > 100) {
            return null;
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT o.*, t.twitch_login, t.twitch_user_id FROM overlays o LEFT JOIN twitch_connections t ON t.user_id = o.user_id WHERE o.key_hash = ?'
        );
        $stmt->execute([hash('sha256', $key)]);
        $overlay = $stmt->fetch();

        if ($overlay === false) {
            return null;
        }

        if (!OverlayConfig::enabled() || !OverlayConfig::typeAllowed((string) $overlay['type']) || !$overlay['is_enabled']) {
            return ['ok' => true, 'state' => 'off', 'poll' => max(30, OverlayConfig::pollSeconds())];
        }

        if ($overlay['last_seen_at'] === null || strtotime((string) $overlay['last_seen_at']) < time() - 60) {
            $pdo->prepare('UPDATE overlays SET last_seen_at = now() WHERE id = ?')->execute([$overlay['id']]);
        }

        $state = [
            'ok'      => true,
            'state'   => 'on',
            'type'    => (string) $overlay['type'],
            'version' => (int) $overlay['version'],
            'poll'    => OverlayConfig::pollSeconds(),
            'live'    => OverlayConfig::liveUrl(),
            'channel' => $overlay['twitch_login'] !== null ? strtolower((string) $overlay['twitch_login']) : null,
            'channel_id' => $overlay['twitch_user_id'] !== null ? (string) $overlay['twitch_user_id'] : null,
        ];

        if ($version !== (int) $overlay['version']) {
            $state['settings'] = OverlayTypes::resolve((string) $overlay['type'], json_decode((string) $overlay['settings'], true) ?: [], (int) $overlay['user_id']);

            if (($special = OverlaySpecials::for((string) $overlay['type'], $state['channel'])) !== []) {
                $state['settings']['_special'] = $special;
            }

            $state['css']      = self::cssFor((bool) $overlay['advanced'], (string) $overlay['custom_css']);
        }

        if ($since < 0) {
            $stmt = $pdo->prepare('SELECT coalesce(max(id), 0) FROM overlay_signals WHERE overlay_id = ?');
            $stmt->execute([$overlay['id']]);
            $state['since']   = (int) $stmt->fetchColumn();
            $state['signals'] = [];

            return $state;
        }

        $stmt = $pdo->prepare('SELECT id, kind, payload FROM overlay_signals WHERE overlay_id = ? AND id > ? ORDER BY id LIMIT 20');
        $stmt->execute([$overlay['id'], $since]);
        $signals = array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'kind' => $s['kind'], 'payload' => json_decode((string) $s['payload'], true) ?: []], $stmt->fetchAll());

        $state['signals'] = $signals;
        $state['since']   = $signals !== [] ? end($signals)['id'] : $since;

        return $state;
    }

    /** The owner's CSS an overlay applies: only in advanced mode, and only while administrators allow it. */
    public static function cssFor(bool $advanced, string $css): string
    {
        return $advanced && OverlayConfig::customCss() ? $css : '';
    }

    /**
     * A channel's details for a shoutout, asked for by an open overlay (by
     * its key): name, picture, category with its box art, and title. Null
     * when the key is unknown, the overlay is off or of a type that does
     * not look channels up, or it asked too often.
     *
     * @return array<string, mixed>|null
     */
    public static function lookup(string $key, string $login): ?array
    {
        if ($key === '' || strlen($key) > 100) {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT * FROM overlays WHERE key_hash = ?');
        $stmt->execute([hash('sha256', $key)]);
        $overlay = $stmt->fetch();

        if ($overlay === false || !$overlay['is_enabled'] || !OverlayConfig::enabled() || !OverlayConfig::typeAllowed((string) $overlay['type'])
            || empty(OverlayTypes::get((string) $overlay['type'])['lookup'])) {
            return null;
        }

        return self::channelDetails($login, (int) $overlay['id']);
    }

    /**
     * A channel's details, from the ten-minute cache or from Twitch; a miss
     * counts against the overlay's allowance (LOOKUP_LIMIT per ten
     * minutes). Also used by the settings page's preview.
     *
     * @return array<string, mixed>|null
     */
    public static function channelDetails(string $login, int $overlayId): ?array
    {
        $login = strtolower(ltrim(trim($login), '@'));

        if (!preg_match('/^[a-z0-9_]{1,25}$/', $login)) {
            return null;
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare("SELECT data FROM twitch_lookups WHERE login = ? AND fetched_at > now() - interval '10 minutes'");
        $stmt->execute([$login]);
        $cached = $stmt->fetchColumn();

        if ($cached !== false) {
            return json_decode((string) $cached, true) ?: null;
        }

        $stmt = $pdo->prepare(
            "UPDATE overlays SET lookups = CASE WHEN lookups_from IS NULL OR lookups_from < now() - interval '10 minutes' THEN 1 ELSE lookups + 1 END,
                                 lookups_from = CASE WHEN lookups_from IS NULL OR lookups_from < now() - interval '10 minutes' THEN now() ELSE lookups_from END
             WHERE id = ? RETURNING lookups"
        );
        $stmt->execute([$overlayId]);

        if ((int) $stmt->fetchColumn() > self::LOOKUP_LIMIT) {
            return null;
        }

        $user = Twitch::user($login, true);

        if ($user === null) {
            return null;
        }

        $channel  = Twitch::channel($user['ref']);
        $category = $channel !== null && $channel['category_id'] !== null ? Twitch::category($channel['category_id'], '285x380') : null;
        $details  = [
            'login'    => $user['login'],
            'name'     => $user['name'],
            'avatar'   => $user['avatar'],
            'category' => $channel['category'] ?? null,
            'box_art'  => $category['box_art'] ?? null,
            'title'    => $channel['title'] ?? '',
        ];

        $pdo->prepare(
            'INSERT INTO twitch_lookups (login, data) VALUES (?, CAST(? AS jsonb))
             ON CONFLICT (login) DO UPDATE SET data = EXCLUDED.data, fetched_at = now()'
        )->execute([$login, json_encode($details, JSON_UNESCAPED_UNICODE)]);

        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM twitch_lookups WHERE fetched_at < now() - interval '1 day'");
        }

        return $details;
    }

    /** The link to add to OBS: the overlay's page, with its key after the "#". */
    public static function link(string $type, string $key): string
    {
        return OverlayConfig::baseUrl() . '/overlay/' . rawurlencode($type) . '#' . $key;
    }

    /**
     * An overlay row as pages use it: settings decoded, the link built from
     * its key.
     *
     * @return array<string, mixed>
     */
    private static function view(array $row): array
    {
        $key = Crypto::decrypt((string) $row['key_secret']);

        return [
            'id'           => (int) $row['id'],
            'type'         => (string) $row['type'],
            'name'         => (string) $row['name'],
            'enabled'      => (bool) $row['is_enabled'],
            'settings'     => json_decode((string) $row['settings'], true) ?: [],
            'advanced'     => (bool) $row['advanced'],
            'config_text'  => (string) $row['config_text'],
            'custom_css'   => (string) $row['custom_css'],
            'version'      => (int) $row['version'],
            'link'         => $key !== null ? self::link((string) $row['type'], $key) : null,
            'last_seen_at' => $row['last_seen_at'],
            'created_at'   => $row['created_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws UserError
     */
    private static function owned(int $overlayId, int $userId): array
    {
        $overlay = self::find($overlayId, $userId);

        if ($overlay === null) {
            throw new UserError(__('ui.message.overlay_not_found'));
        }

        return $overlay;
    }

    /** A test event's viewer: the streamer's own Twitch name, or a stand-in. */
    public static function sampleUser(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT twitch_login FROM twitch_connections WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (string) ($stmt->fetchColumn() ?: 'StreamOrg');
    }

    private static function newKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
