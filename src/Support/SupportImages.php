<?php
declare(strict_types=1);

/**
 * Screenshots sent with bug reports and their comments.
 *
 * An image is uploaded on its own first and kept as a draft of its author;
 * sending the report (or comment) attaches it. Every image is decoded and
 * written again as WebP, at most 2560 pixels on its longest side, so what
 * is kept is a plain picture with no hidden data. Files live in
 * tmp/support/ (persistent, never served directly): only the reporter and
 * administrators can open them, through SupportController::image().
 * Drafts nobody attached are deleted after a day. See 044_support.sql.
 */
final class SupportImages
{
    public const MAX_BYTES  = 10 * 1024 * 1024;
    public const MAX_SIDE   = 2560;
    public const MAX_IMAGES = 6;
    public const MAX_PINS   = 12;

    /**
     * Keeps an uploaded image as a draft of this user.
     *
     * @return array{id:int, width:int, height:int}
     * @throws UserError when it is not an image, or too big
     */
    public static function store(int $userId, string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new UserError(sprintf(__('ui.message.support_image_too_big'), intdiv(self::MAX_BYTES, 1024 * 1024)));
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > 50_000_000) {
            throw new UserError(__('ui.message.support_image_not_image'));
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new UserError(__('ui.message.support_image_not_image'));
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        $scale  = min(1, self::MAX_SIDE / max($width, $height));
        $w      = max(1, (int) round($width * $scale));
        $h      = max(1, (int) round($height * $scale));

        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $w, $h, $width, $height);
        imagedestroy($source);

        ob_start();
        imagewebp($image, null, 86);
        $webp = (string) ob_get_clean();
        imagedestroy($image);

        $relative  = 'support/' . date('Y-m') . '/' . bin2hex(random_bytes(16)) . '.webp';
        $absolute  = self::root() . '/' . $relative;
        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the support images folder.');
        }

        if (file_put_contents($absolute, $webp) === false) {
            throw new RuntimeException('Cannot write a support image.');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO support_images (user_id, path, width, height, bytes) VALUES (?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$userId, $relative, $w, $h, strlen($webp)]);
        $id = (int) $stmt->fetchColumn();

        if (random_int(1, 20) === 1) {
            self::dropOldDrafts();
        }

        return ['id' => $id, 'width' => $w, 'height' => $h];
    }

    /**
     * Attaches this user's draft images to a report (and comment), with the
     * pins marked on each. Images that are not their drafts are ignored.
     *
     * @param list<int> $ids
     * @param array<int|string, mixed> $pins image id => list of {x, y, note}
     * @return int how many were attached
     */
    public static function attach(PDO $pdo, int $userId, array $ids, int $bugId, ?int $commentId = null, array $pins = []): int
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::MAX_IMAGES);

        if ($ids === []) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'UPDATE support_images SET bug_id = ?, comment_id = ?, pins = CAST(? AS jsonb)
              WHERE id = ? AND user_id = ? AND bug_id IS NULL'
        );
        $done = 0;

        foreach ($ids as $id) {
            $stmt->execute([$bugId, $commentId, json_encode(self::cleanPins($pins[$id] ?? $pins[(string) $id] ?? [])), $id, $userId]);
            $done += $stmt->rowCount();
        }

        return $done;
    }

    /**
     * The images of a report, by comment (0 for the report itself).
     *
     * @return array<int, list<array{id:int, width:int, height:int, pins:list<array>}>>
     */
    public static function forBug(int $bugId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, comment_id, width, height, pins FROM support_images WHERE bug_id = ? ORDER BY id'
        );
        $stmt->execute([$bugId]);
        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[(int) ($row['comment_id'] ?? 0)][] = [
                'id'     => (int) $row['id'],
                'width'  => (int) $row['width'],
                'height' => (int) $row['height'],
                'pins'   => json_decode((string) $row['pins'], true) ?: [],
            ];
        }

        return $out;
    }

    /**
     * The file of an image this user may see (their own, or any for an
     * administrator), or null.
     */
    public static function fileFor(int $imageId, int $userId, bool $isAdmin): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.path FROM support_images i LEFT JOIN bug_reports b ON b.id = i.bug_id
              WHERE i.id = ? AND (CAST(? AS boolean) OR i.user_id = ? OR b.user_id = ?)'
        );
        $stmt->execute([$imageId, $isAdmin ? 'true' : 'false', $userId, $userId]);
        $path = $stmt->fetchColumn();

        if (!is_string($path) || !preg_match('#^support/\d{4}-\d{2}/[0-9a-f]{32}\.webp$#', $path)) {
            return null;
        }

        $file = self::root() . '/' . $path;

        return is_file($file) ? $file : null;
    }

    /**
     * Pins as they are kept: at most 12, each a point inside the image
     * (fractions of its width and height) with a short note.
     *
     * @return list<array{x:float, y:float, note:string}>
     */
    public static function cleanPins(mixed $pins): array
    {
        if (is_string($pins)) {
            $pins = json_decode($pins, true);
        }

        if (!is_array($pins)) {
            return [];
        }

        $out = [];

        foreach (array_slice(array_values($pins), 0, self::MAX_PINS) as $pin) {
            if (!is_array($pin) || !is_numeric($pin['x'] ?? null) || !is_numeric($pin['y'] ?? null)) {
                continue;
            }

            $out[] = [
                'x'    => round(max(0, min(1, (float) $pin['x'])), 4),
                'y'    => round(max(0, min(1, (float) $pin['y'])), 4),
                'note' => mb_substr(trim((string) ($pin['note'] ?? '')), 0, 200),
            ];
        }

        return $out;
    }

    /** Deletes drafts that were never attached, a day after their upload. */
    public static function dropOldDrafts(): void
    {
        $stmt = Database::connection()->query(
            "DELETE FROM support_images WHERE bug_id IS NULL AND created_at < now() - interval '1 day' RETURNING path"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            @unlink(self::root() . '/' . $path);
        }
    }

    /** Where support images are kept: the app's persistent tmp/ folder. */
    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/tmp';
    }
}
