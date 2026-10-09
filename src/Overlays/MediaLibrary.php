<?php
declare(strict_types=1);

/**
 * The media library: sounds and images for overlays (and anything else
 * that needs them), each a user's own or shared with everyone — shared
 * ones are managed by administrators.
 *
 * A file is accepted by what it contains, not by its name: MP3, OGG or
 * WAV for sounds; PNG, JPEG, WebP or GIF for images (never SVG, which can
 * carry scripts). Files are kept in public/media/library/ under random
 * names. A sound can be a sprite: named segments of it (start and
 * duration in seconds), each played on its own by SoundLib. Uploads are
 * limited per file and per user (OverlayConfig). See 045_overlays.sql.
 */
final class MediaLibrary
{
    public const KINDS = ['sound', 'image'];
    public const MAX_SEGMENTS = 50;
    private const FOLDER = 'media/library';

    /**
     * Keeps an uploaded file, for a user or (null) for everyone.
     *
     * @return array<string, mixed> the asset, as view() shows it
     * @throws UserError when the file is not a sound or image we take, is too big, or the user is out of room
     */
    public static function store(?int $userId, string $bytes, string $name, ?float $duration = null): array
    {
        $type = self::detect($bytes);

        if ($type === null) {
            throw new UserError(__('ui.message.media_not_supported'));
        }

        [$kind, $mime, $ext, $width, $height] = $type;

        if (strlen($bytes) > OverlayConfig::maxBytes($kind)) {
            throw new UserError(sprintf(__('ui.message.media_too_big'), intdiv(OverlayConfig::maxBytes($kind), 1024 * 1024)));
        }

        if ($userId !== null && self::usage($userId) + strlen($bytes) > OverlayConfig::quotaBytes()) {
            throw new UserError(sprintf(__('ui.message.media_quota'), intdiv(OverlayConfig::quotaBytes(), 1024 * 1024)));
        }

        $relative  = self::FOLDER . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $absolute  = dirname(__DIR__, 2) . '/public/' . $relative;
        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the media library folder.');
        }

        if (file_put_contents($absolute, $bytes) === false) {
            throw new RuntimeException('Cannot write a media file.');
        }

        $label = mb_substr(trim((string) preg_replace('/\s+/u', ' ', preg_replace('/\.[a-z0-9]{2,4}$/i', '', $name) ?? '')), 0, 80) ?: $kind;

        $stmt = Database::connection()->prepare(
            'INSERT INTO media_assets (user_id, kind, name, path, mime, bytes, width, height, duration)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *'
        );
        $stmt->execute([
            $userId, $kind, $label, $relative, $mime, strlen($bytes), $width, $height,
            $kind === 'sound' && $duration !== null && $duration > 0 && $duration < 36000 ? round($duration, 3) : null,
        ]);

        return self::view($stmt->fetch());
    }

    /**
     * What a user can use: their own media and the shared media, sounds or
     * images (or both), newest first, shared last.
     *
     * @return list<array<string, mixed>>
     */
    public static function available(int $userId, ?string $kind = null): array
    {
        return self::fetch('(user_id = ? OR user_id IS NULL)', [$userId], $kind, 'user_id IS NULL, created_at DESC');
    }

    /**
     * The shared media (administrators manage it).
     *
     * @return list<array<string, mixed>>
     */
    public static function shared(?string $kind = null): array
    {
        return self::fetch('user_id IS NULL', [], $kind, 'created_at DESC');
    }

    /**
     * One asset a user may use (their own or shared), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function usable(int $assetId, int $userId): ?array
    {
        return self::fetch('id = ? AND (user_id = ? OR user_id IS NULL)', [$assetId, $userId])[0] ?? null;
    }

    /**
     * Renames an asset and (for a sound) sets its segments. Users change
     * their own media; administrators also change shared media.
     *
     * @return array<string, mixed>
     * @throws UserError when it is not theirs to change
     */
    public static function update(int $assetId, int $userId, bool $isAdmin, string $name, array $segments = []): array
    {
        $asset = self::editable($assetId, $userId, $isAdmin);
        $name  = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 80);

        if ($name === '') {
            throw new UserError(__('ui.message.media_name_needed'));
        }

        $clean = $asset['kind'] === 'sound' ? self::cleanSegments($segments, $asset['duration']) : [];

        $stmt = Database::connection()->prepare('UPDATE media_assets SET name = ?, segments = CAST(? AS jsonb) WHERE id = ? RETURNING *');
        $stmt->execute([$name, json_encode($clean, JSON_UNESCAPED_UNICODE), $assetId]);

        return self::view($stmt->fetch());
    }

    /**
     * Deletes an asset and its file (overlays that used it go without it).
     *
     * @throws UserError when it is not theirs to delete
     */
    public static function delete(int $assetId, int $userId, bool $isAdmin): void
    {
        $asset = self::editable($assetId, $userId, $isAdmin);

        Database::connection()->prepare('DELETE FROM media_assets WHERE id = ?')->execute([$assetId]);
        @unlink(dirname(__DIR__, 2) . '/public/' . $asset['path']);
    }

    /** How many bytes of media a user keeps. */
    public static function usage(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT coalesce(sum(bytes), 0) FROM media_assets WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Segments of a sound, checked: each with a key (kept, or a new one),
     * a name, and a start and duration inside the sound.
     *
     * @return list<array{key:string, name:string, start:float, duration:float}>
     */
    public static function cleanSegments(array $segments, mixed $length = null): array
    {
        $length = $length !== null ? (float) $length : null;
        $out    = [];
        $used   = [];

        foreach (array_slice(array_values($segments), 0, self::MAX_SEGMENTS) as $segment) {
            if (!is_array($segment) || !is_numeric($segment['start'] ?? null) || !is_numeric($segment['duration'] ?? null)) {
                continue;
            }

            $start    = max(0.0, round((float) $segment['start'], 3));
            $duration = round((float) $segment['duration'], 3);

            if ($length !== null) {
                $start    = min($start, $length);
                $duration = min($duration, $length - $start + 0.05);
            }

            if ($duration < 0.05) {
                continue;
            }

            $key = (string) ($segment['key'] ?? '');
            $key = preg_match('/^s\d{1,4}$/', $key) && !isset($used[$key]) ? $key : '';

            $out[] = [
                'key'      => $key,
                'name'     => mb_substr(trim((string) ($segment['name'] ?? '')), 0, 40) ?: sprintf('%.1Fs', $start),
                'start'    => $start,
                'duration' => $duration,
            ];

            if ($key !== '') {
                $used[$key] = true;
            }
        }

        $next = 1 + max([0, ...array_map(static fn (string $k): int => (int) substr($k, 1), array_keys($used))]);

        foreach ($out as &$segment) {
            if ($segment['key'] === '') {
                $segment['key'] = 's' . $next++;
            }
        }
        unset($segment);

        return $out;
    }

    /**
     * An asset as pages and overlays use it.
     *
     * @return array<string, mixed>
     */
    public static function view(array $row): array
    {
        return [
            'id'       => (int) $row['id'],
            'kind'     => (string) $row['kind'],
            'name'     => (string) $row['name'],
            'url'      => url('/' . $row['path']),
            'shared'   => $row['user_id'] === null,
            'mime'     => (string) $row['mime'],
            'bytes'    => (int) $row['bytes'],
            'width'    => $row['width'] !== null ? (int) $row['width'] : null,
            'height'   => $row['height'] !== null ? (int) $row['height'] : null,
            'duration' => $row['duration'] !== null ? (float) $row['duration'] : null,
            'segments' => json_decode((string) $row['segments'], true) ?: [],
            'path'     => (string) $row['path'],
        ];
    }

    /**
     * What a file really is: [kind, mime, extension, width, height], or
     * null when it is none of the sounds and images taken.
     *
     * @return array{0:string, 1:string, 2:string, 3:?int, 4:?int}|null
     */
    public static function detect(string $bytes): ?array
    {
        if (strlen($bytes) < 12) {
            return null;
        }

        $image = @getimagesizefromstring($bytes);

        if ($image !== false) {
            return match ($image[2]) {
                IMAGETYPE_PNG  => ['image', 'image/png', 'png', $image[0], $image[1]],
                IMAGETYPE_JPEG => ['image', 'image/jpeg', 'jpg', $image[0], $image[1]],
                IMAGETYPE_GIF  => ['image', 'image/gif', 'gif', $image[0], $image[1]],
                IMAGETYPE_WEBP => ['image', 'image/webp', 'webp', $image[0], $image[1]],
                default        => null,
            };
        }

        $head = substr($bytes, 0, 12);

        return match (true) {
            str_starts_with($head, 'ID3'), ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0 => ['sound', 'audio/mpeg', 'mp3', null, null],
            str_starts_with($head, 'OggS')                                                          => ['sound', 'audio/ogg', 'ogg', null, null],
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE'                        => ['sound', 'audio/wav', 'wav', null, null],
            default                                                                                 => null,
        };
    }

    /**
     * @return array<string, mixed>
     * @throws UserError
     */
    private static function editable(int $assetId, int $userId, bool $isAdmin): array
    {
        $rows = self::fetch($isAdmin ? '(id = ? AND (user_id = ? OR user_id IS NULL))' : '(id = ? AND user_id = ?)', [$assetId, $userId]);

        if ($rows === []) {
            throw new UserError(__('ui.message.media_not_found'));
        }

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private static function fetch(string $where, array $params, ?string $kind = null, string $order = 'id'): array
    {
        if ($kind !== null && in_array($kind, self::KINDS, true)) {
            $where .= ' AND kind = ?';
            $params[] = $kind;
        }

        $stmt = Database::connection()->prepare("SELECT * FROM media_assets WHERE {$where} ORDER BY {$order}");
        $stmt->execute($params);

        return array_map([self::class, 'view'], $stmt->fetchAll());
    }
}
