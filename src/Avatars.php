<?php
declare(strict_types=1);

/**
 * Profile pictures: uploaded, or copied from the user's Twitch profile.
 *
 * Every image is decoded and re-encoded with GD — cropped to a centred
 * square and resized to 256×256 WebP — so nothing the user sends is ever
 * served as-is. Files live in public/media/avatars/ (the persistent media
 * volume) under a random name, and the previous file is removed.
 */
final class Avatars
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    private const SIZE = 256;

    /** The URL to show, or null when the user has no avatar. */
    public static function url(?array $user): ?string
    {
        $path = $user['avatar_path'] ?? null;

        if (!is_string($path) || $path === '') {
            return null;
        }

        return url('/' . $path) . '?v=' . substr(md5((string) ($user['avatar_updated_at'] ?? '')), 0, 8);
    }

    /**
     * Stores image bytes as the user's avatar.
     *
     * @throws UserError when the bytes are not an image GD can read
     */
    public static function store(int $userId, string $bytes): void
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new UserError(__('ui.message.avatar_too_big'));
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > 40_000_000) {
            throw new UserError(__('ui.message.avatar_not_image'));
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new UserError(__('ui.message.avatar_not_image'));
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        $side   = min($width, $height);

        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, imagecolorallocatealpha($square, 0, 0, 0, 127));
        imagecopyresampled(
            $square, $source, 0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE, $side, $side
        );
        imagedestroy($source);

        ob_start();
        imagewebp($square, null, 85);
        $webp = (string) ob_get_clean();
        imagedestroy($square);

        $directory = dirname(__DIR__) . '/public/media/avatars';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the avatar folder.');
        }

        $relative = 'media/avatars/' . $userId . '-' . bin2hex(random_bytes(6)) . '.webp';

        if (file_put_contents(dirname(__DIR__) . '/public/' . $relative, $webp) === false) {
            throw new RuntimeException('Cannot write the avatar file.');
        }

        $previous = self::currentPath($userId);

        Database::connection()
            ->prepare('UPDATE users SET avatar_path = ?, avatar_updated_at = now() WHERE id = ?')
            ->execute([$relative, $userId]);

        self::deleteFile($previous);
    }

    /**
     * Copies the profile picture of the user's Twitch channel: the connected
     * account, or else the Twitch handle in their details.
     *
     * @throws UserError when there is no channel or the picture cannot be fetched
     */
    public static function fromTwitch(int $userId, ?array $user): void
    {
        $login = TwitchUser::connection($userId)['twitch_login'] ?? null;

        if ($login === null && ($user['channel_platform_code'] ?? null) === 'twitch') {
            $login = $user['channel_handle'] ?? null;
        }

        if (!is_string($login) || $login === '' || !Twitch::isConfigured()) {
            throw new UserError(__('ui.message.avatar_no_twitch'));
        }

        $profile = Twitch::user($login, true);
        $image   = $profile['avatar'] ?? null;

        if (!is_string($image) || safe_url($image) === null) {
            throw new UserError(__('ui.message.avatar_twitch_failed'));
        }

        $response = Http::get($image, [], 15, self::MAX_BYTES);

        if ($response['status'] !== 200 || $response['body'] === '') {
            throw new UserError(__('ui.message.avatar_twitch_failed'));
        }

        self::store($userId, $response['body']);
    }

    public static function remove(int $userId): void
    {
        $previous = self::currentPath($userId);

        Database::connection()
            ->prepare('UPDATE users SET avatar_path = NULL, avatar_updated_at = now() WHERE id = ?')
            ->execute([$userId]);

        self::deleteFile($previous);
    }

    /** Removes the file of a deleted user. */
    public static function forget(?string $path): void
    {
        self::deleteFile($path);
    }

    private static function currentPath(int $userId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT avatar_path FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() ?: null;
    }

    private static function deleteFile(?string $path): void
    {
        if ($path !== null && preg_match('#^media/avatars/\d+-[0-9a-f]{12}\.webp$#', $path)) {
            @unlink(dirname(__DIR__) . '/public/' . $path);
        }
    }
}
