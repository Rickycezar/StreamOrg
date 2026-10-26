<?php
declare(strict_types=1);

/**
 * Game artwork kept on disk (see 019_game_images.sql).
 *
 * sync() takes the image URLs a provider reported for a game, downloads
 * the ones that are new or changed, and records them in game_images. Each
 * file is checked to really be an image before it replaces anything; a
 * failed download leaves the previous file in place.
 */
final class GameImages
{
    public const KINDS = ['header', 'capsule', 'portrait', 'hero', 'logo', 'thumb'];

    /** Wider than any screen it is shown on; the originals run to 3840. */
    private const HERO_MAX_WIDTH = 1600;

    /** The picker shows 46x22; twice that for sharp high-DPI screens. */
    private const THUMB_SIZE = [92, 44];

    private const MAX_BYTES = 15 * 1024 * 1024;

    /** Who calls sync() decides whether a missing download is worth logging. */
    private static array $problems = [];

    /** @return list<string> */
    public static function problems(): array
    {
        return self::$problems;
    }

    /**
     * @param array<string,string> $sources kind => URL, as the provider reported them
     * @param bool $force re-download even when the URL is unchanged — an
     *                    explicit refresh asks for current files
     * @return array{downloaded:list<string>, kept:list<string>, failed:list<string>}
     */
    public static function sync(PDO $pdo, int $gameId, array $sources, bool $force = false): array
    {
        self::$problems = [];
        $result = ['downloaded' => [], 'kept' => [], 'failed' => []];
        $stored = self::stored($pdo, $gameId);

        foreach ($sources as $kind => $url) {
            if ($kind === 'thumb' || !in_array($kind, self::KINDS, true) || !is_string($url) || $url === '') {
                continue;
            }

            $current = $stored[$kind] ?? null;

            if (!$force && $current !== null && $current['source_url'] === $url && is_file(self::absolute($current['path']))) {
                $result['kept'][] = $kind;
                continue;
            }

            $image = self::download($url);

            if ($image === null) {
                $result['failed'][] = $kind;
                continue;
            }

            if ($kind === 'hero') {
                $image = self::shrink($image, self::HERO_MAX_WIDTH);
            }

            self::store($pdo, $gameId, $kind, $image, $url, $current['path'] ?? null);
            $result['downloaded'][] = $kind;
        }

        $stored = self::stored($pdo, $gameId);
        $base   = $stored['header'] ?? $stored['capsule'] ?? $stored['portrait'] ?? null;

        if ($base !== null) {
            $marker = 'generated:' . $base['path'] . '@' . $base['updated_at'];

            if ($force || ($stored['thumb']['source_url'] ?? null) !== $marker
                || !is_file(self::absolute($stored['thumb']['path'] ?? ''))) {
                $thumb = self::thumbnail(self::absolute($base['path']));

                if ($thumb !== null) {
                    self::store($pdo, $gameId, 'thumb', $thumb, $marker, $stored['thumb']['path'] ?? null);
                }
            }
        }

        return $result;
    }

    /**
     * Public URLs of a game's images, versioned so a replaced file is
     * fetched again instead of served from the browser cache.
     *
     * @return array<string,string> kind => URL
     */
    public static function urls(PDO $pdo, int $gameId): array
    {
        $urls = [];

        foreach (self::stored($pdo, $gameId) as $kind => $row) {
            $urls[$kind] = self::publicUrl($row['path'], (string) $row['updated_at']);
        }

        return $urls;
    }

    /**
     * A game's box art for the public pages' tall (2:3) frames: its
     * portrait, or else its widest picture, marked wide so it is shown
     * whole over a blurred copy instead of cropped.
     *
     * @return array{url:string, wide:bool}|null
     */
    public static function boxArt(PDO $pdo, int $gameId): ?array
    {
        $urls = self::urls($pdo, $gameId);

        if (isset($urls['portrait'])) {
            return ['url' => $urls['portrait'], 'wide' => false];
        }

        foreach (['header', 'capsule', 'hero', 'thumb'] as $kind) {
            if (isset($urls[$kind])) {
                return ['url' => $urls[$kind], 'wide' => true];
            }
        }

        return null;
    }

    /**
     * A LEFT JOIN LATERAL for a list query: the game's wide art (the Steam
     * header, else the capsule) as <alias>.path and <alias>.updated_at.
     */
    public static function artJoin(string $gameId, string $alias = 'art'): string
    {
        return "LEFT JOIN LATERAL (
                     SELECT i.path, i.updated_at FROM game_images i
                      WHERE i.game_id = {$gameId} AND i.kind IN ('header', 'capsule')
                   ORDER BY i.kind = 'header' DESC LIMIT 1
                 ) {$alias} ON true";
    }

    /**
     * The wide art of several games at once, for lists that cannot join it.
     *
     * @param list<int> $gameIds
     * @return array<int, array{path:string, updated_at:string}>
     */
    public static function artFor(PDO $pdo, array $gameIds): array
    {
        $gameIds = array_values(array_unique(array_filter(array_map('intval', $gameIds))));
        if ($gameIds === []) {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT ON (game_id) game_id, path, updated_at FROM game_images
              WHERE game_id = ANY(?::int[]) AND kind IN ('header', 'capsule')
           ORDER BY game_id, kind = 'header' DESC"
        );
        $stmt->execute(['{' . implode(',', $gameIds) . '}']);

        $art = [];
        foreach ($stmt->fetchAll() as $row) {
            $art[(int) $row['game_id']] = ['path' => (string) $row['path'], 'updated_at' => (string) $row['updated_at']];
        }

        return $art;
    }

    public static function publicUrl(string $path, string $version): string
    {
        return url('/' . $path) . '?v=' . substr(md5($version), 0, 8);
    }

    /** Removes a game's files; the rows go with the game (ON DELETE CASCADE). */
    public static function deleteFiles(int $gameId): void
    {
        $dir = self::directory($gameId);

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    /** @return array<string, array{path:string, source_url:?string, updated_at:string}> */
    private static function stored(PDO $pdo, int $gameId): array
    {
        $stmt = $pdo->prepare('SELECT kind, path, source_url, updated_at FROM game_images WHERE game_id = ?');
        $stmt->execute([$gameId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[$row['kind']] = $row;
        }

        return $rows;
    }

    /**
     * @return array{bytes:string, mime:string, width:int, height:int}|null
     *         null when the response is not a usable image
     */
    private static function download(string $url): ?array
    {
        $response = Http::get($url, ['Accept' => 'image/*'], 30, self::MAX_BYTES);

        if ($response['status'] !== 200 || $response['body'] === '' || strlen($response['body']) > self::MAX_BYTES) {
            self::$problems[] = "{$url}: HTTP {$response['status']}" . ($response['error'] ? " ({$response['error']})" : '');
            return null;
        }

        $info = @getimagesizefromstring($response['body']);

        if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            self::$problems[] = "{$url}: not an image";
            return null;
        }

        return ['bytes' => $response['body'], 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1]];
    }

    /** Scales an image down to a maximum width, re-encoded as JPEG. */
    private static function shrink(array $image, int $maxWidth): array
    {
        if ($image['width'] <= $maxWidth) {
            return $image;
        }

        $source = @imagecreatefromstring($image['bytes']);

        if ($source === false) {
            return $image;
        }

        $height = (int) round($image['height'] * $maxWidth / $image['width']);
        $scaled = imagescale($source, $maxWidth, $height, IMG_BICUBIC);

        if ($scaled === false) {
            return $image;
        }

        ob_start();
        imagejpeg($scaled, null, 84);

        return ['bytes' => (string) ob_get_clean(), 'mime' => 'image/jpeg', 'width' => $maxWidth, 'height' => $height];
    }

    /** A small centre-cropped WebP for the pickers. */
    private static function thumbnail(string $file): ?array
    {
        $source = @imagecreatefromstring((string) @file_get_contents($file));

        if ($source === false) {
            return null;
        }

        [$tw, $th] = self::THUMB_SIZE;
        $sw = imagesx($source);
        $sh = imagesy($source);

        $scale = max($tw / $sw, $th / $sh);
        $cw = (int) round($tw / $scale);
        $ch = (int) round($th / $scale);

        $thumb = imagecreatetruecolor($tw, $th);
        imagecopyresampled($thumb, $source, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $tw, $th, $cw, $ch);

        ob_start();
        imagewebp($thumb, null, 82);

        return ['bytes' => (string) ob_get_clean(), 'mime' => 'image/webp', 'width' => $tw, 'height' => $th];
    }

    /** Writes the file atomically and records it, replacing the previous one. */
    private static function store(PDO $pdo, int $gameId, string $kind, array $image, string $source, ?string $previous): void
    {
        $ext = match ($image['mime']) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };

        $dir = self::directory($gameId);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $path = 'media/games/' . $gameId . '/' . $kind . '.' . $ext;
        $tmp  = self::absolute($path) . '.part';

        file_put_contents($tmp, $image['bytes']);
        rename($tmp, self::absolute($path));

        if ($previous !== null && $previous !== $path) {
            @unlink(self::absolute($previous));
        }

        $pdo->prepare(
            'INSERT INTO game_images (game_id, kind, path, source_url, width, height, bytes, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (game_id, kind) DO UPDATE
                SET path = EXCLUDED.path, source_url = EXCLUDED.source_url, width = EXCLUDED.width,
                    height = EXCLUDED.height, bytes = EXCLUDED.bytes, updated_at = now()'
        )->execute([$gameId, $kind, $path, $source, $image['width'], $image['height'], strlen($image['bytes'])]);
    }

    private static function directory(int $gameId): string
    {
        return dirname(__DIR__) . '/public/media/games/' . $gameId;
    }

    private static function absolute(string $path): string
    {
        return dirname(__DIR__) . '/public/' . ltrim($path, '/');
    }
}
