<?php
declare(strict_types=1);

/**
 * The overlay pages OBS loads, and the state they ask for. Served before
 * any session starts: overlays never carry or set a sign-in cookie.
 *
 * GET  /overlay/<type>   the page: the same for every overlay of a type,
 *                        it reads its key from the "#" of the link
 * POST /overlay/state    the overlay's settings and signals, by key
 *                        (a plain form post, answered to any origin)
 * POST /overlay/lookup   a channel's details for a shoutout, by key
 * GET  /overlay/special/<token>   a channel's special sound (OverlaySpecials)
 *
 * Pages run sandboxed (an origin of their own, no access to StreamOrg's
 * pages or sign-in), may be framed only by StreamOrg (the preview), and
 * may reach only StreamOrg, Twitch chat and the emote and font services
 * they need.
 */
final class OverlayController
{
    /** Handles /overlay/… and ends the request; does nothing for other paths. */
    public static function dispatch(string $path, string $method): void
    {
        if ($path === '/overlay/state' && $method === 'POST') {
            self::state();
        }

        if ($path === '/overlay/lookup' && $method === 'POST') {
            self::lookup();
        }

        if (preg_match('~^/overlay/special/([0-9a-f]{32})$~', $path, $m) && $method === 'GET') {
            self::special($m[1]);
        }

        if (preg_match('~^/overlay/([a-z_]{2,30})$~', $path, $m) && $method === 'GET') {
            self::page($m[1]);
        }

        if (str_starts_with($path, '/overlay/')) {
            http_response_code(404);
            exit;
        }
    }

    private static function page(string $type): never
    {
        $definition = OverlayTypes::get($type);

        if ($definition === null) {
            http_response_code(404);
            exit;
        }

        $live = OverlayConfig::liveUrl();

        header_remove('X-Frame-Options');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-cache');
        header('Content-Security-Policy: sandbox allow-scripts; default-src \'none\'; script-src \'self\'; '
            . 'style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com data:; '
            . 'img-src \'self\' https: data:; media-src \'self\' https:; '
            . 'connect-src \'self\' wss://irc-ws.chat.twitch.tv https://api.betterttv.net https://7tv.io https://api.frankerfacez.com https://api.twitchinsights.net' . ($live !== '' ? ' ' . $live : '') . '; '
            . 'base-uri \'none\'; form-action \'none\'; frame-ancestors \'self\'');

        echo View::partial('overlay/page', ['type' => $type, 'definition' => $definition]);
        exit;
    }

    private static function special(string $token): never
    {
        $file = OverlaySpecials::file($token);

        if ($file === null) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    private static function lookup(): never
    {
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');

        $details = Overlays::lookup(trim((string) ($_POST['k'] ?? '')), (string) ($_POST['login'] ?? ''));

        json_response(['ok' => $details !== null, 'channel' => $details], $details !== null ? 200 : 404);
    }

    private static function state(): never
    {
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');

        $state = Overlays::state(
            trim((string) ($_POST['k'] ?? '')),
            (int) ($_POST['v'] ?? 0),
            (int) ($_POST['since'] ?? -1)
        );

        json_response($state ?? ['ok' => false, 'state' => 'unknown'], $state === null ? 404 : 200);
    }
}
