<?php
declare(strict_types=1);

/**
 * How a hosted game's files are served (public/games/<name>/): under the
 * game's own Content-Security-Policy (the integration contract's) and
 * readable from any origin, since inside a giveaway the game runs in a
 * sandboxed frame with an opaque origin. In production Apache sends these
 * headers for /games/ (docker/apache.conf, kept in step with CSP below);
 * the PHP development server goes through serveDev().
 *
 * Also the only dynamic addresses inside a game's folder: its text
 * changes from Administration → Game texts, texts/overrides/<locale>.json
 * and texts/overrides/index.json (the languages it can show).
 */
final class GameFiles
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self'; "
        . "connect-src 'self' wss://irc-ws.chat.twitch.tv; frame-ancestors 'self'";

    private const TYPES = [
        'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml',
        'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'txt' => 'text/plain; charset=utf-8',
    ];

    /** The game's headers, replacing the application's own. */
    public static function headers(): void
    {
        header_remove('X-Frame-Options');
        header_remove('Cross-Origin-Opener-Policy');
        header('Content-Security-Policy: ' . self::CSP);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
    }

    /**
     * Answers a game address the application handles itself, if $path is
     * one: the text changes. True when it answered.
     */
    public static function handle(string $path): bool
    {
        if (!preg_match('#^/games/([a-z0-9-]{2,40})/texts/overrides/([A-Za-z0-9-]{2,12})\.json$#', $path, $m) || Games::manifest($m[1]) === null) {
            return false;
        }

        self::headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=60');

        if ($m[2] === 'index') {
            echo json_encode(['languages' => Games::languages($m[1])]);
            return true;
        }

        $texts = Games::overrides($m[1], $m[2]);

        if ($texts === []) {
            http_response_code(404);
            echo '{}';
            return true;
        }

        echo json_encode((object) $texts, JSON_UNESCAPED_UNICODE);

        return true;
    }

    /**
     * The PHP development server: serves a file of public/games/ with the
     * game's headers (Apache does it in production). False when it is not
     * a game file, so the request goes on as usual.
     */
    public static function serveDev(string $path): bool
    {
        if (!str_starts_with($path, '/games/')) {
            return false;
        }

        $root = realpath(Games::dir());
        $file = realpath(dirname(__DIR__) . '/public' . $path);

        if ($file !== false && is_dir($file)) {
            $file = realpath($file . '/index.html');
        }

        if ($root === false || $file === false || !str_starts_with($file, $root . '/') || !is_file($file)) {
            return false;
        }

        self::headers();
        header('Content-Type: ' . (self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        readfile($file);

        return true;
    }
}
