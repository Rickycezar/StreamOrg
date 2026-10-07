<?php
declare(strict_types=1);

/** HTML-escapes a value for output. Every echo in a view goes through this. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Translates a key. Shorthand for Lang::t(). */
function __(string $key): string
{
    return Lang::t($key);
}

/** Translates a database code. Shorthand for Lang::code(). */
function code_label(string $group, ?string $code): string
{
    return Lang::code($group, $code);
}

/** Builds an absolute path under the app's base URL. */
function url(string $path = '/'): string
{
    $base = rtrim((string) Config::get('app.base_path', ''), '/');

    return $base . '/' . ltrim($path, '/');
}

/** Emits JSON and stops. Used by every AJAX endpoint. */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Redirects and stops. */
function redirect(string $path): never
{
    $status = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 302 : 303;

    header('Location: ' . url($path), true, $status);
    exit;
}

/** Queues a one-shot message for the next page render. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($flashes) ? $flashes : [];
}

/** Formats a timestamp in the signed-in user's timezone. */
function fmt_datetime(?string $value, string $format = 'd/m/Y H:i'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $tz = Auth::user()['timezone'] ?? Config::get('app.timezone', 'UTC');

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone((string) $tz))
            ->format($format);
    } catch (Throwable) {
        return $value;
    }
}

function fmt_date(?string $value): string
{
    return $value === null || $value === '' ? '—' : fmt_datetime($value, 'd/m/Y');
}

/** Whether an IP address falls inside one of the given IPs or CIDR ranges. */
function ip_in_ranges(string $ip, array $ranges): bool
{
    $packed = @inet_pton($ip);

    if ($packed === false) {
        return false;
    }

    foreach ($ranges as $range) {
        [$subnet, $bits] = array_pad(explode('/', (string) $range, 2), 2, null);
        $net = @inet_pton(trim((string) $subnet));

        if ($net === false || strlen($net) !== strlen($packed)) {
            continue;
        }

        $bits = $bits === null ? strlen($net) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;

        if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            continue;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        if ((substr($packed, $bytes, 1) & $mask) === (substr($net, $bytes, 1) & $mask)) {
            return true;
        }
    }

    return false;
}

/**
 * The reverse proxies whose X-Forwarded-* headers are believed: loopback
 * always (Caddy on the same machine), plus app.trusted_proxies — on a
 * container platform, the proxy's network, e.g. 10.0.0.0/8.
 *
 * @return list<string>
 */
function trusted_proxies(): array
{
    return array_merge(['127.0.0.1', '::1'], (array) Config::get('app.trusted_proxies', []));
}

/**
 * True when the request comes through a trusted reverse proxy. Only then
 * are its X-Forwarded-* headers used; from anywhere else they could be
 * forged by the client.
 */
function from_trusted_proxy(): bool
{
    return ip_in_ranges((string) ($_SERVER['REMOTE_ADDR'] ?? ''), trusted_proxies());
}

/** Whether the browser reached the app over HTTPS, directly or via a trusted proxy. */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (!from_trusted_proxy() || empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return false;
    }

    $proto = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);

    return strtolower($proto) === 'https';
}

/**
 * The browser's IP address. Behind trusted proxies, X-Forwarded-For is read
 * from the right: each proxy appends the address it saw, so the right-most
 * entry that is not itself a trusted proxy is the client. The left-most
 * entries are whatever the client chose to send and cannot be believed.
 */
function client_ip(): ?string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if ($remote !== '' && from_trusted_proxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));

        foreach ($chain as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                break;
            }

            if (!ip_in_ranges($ip, trusted_proxies())) {
                return $ip;
            }
        }
    }

    return $remote === '' ? null : $remote;
}

/**
 * A link target that is safe to put in an href: only absolute http(s)
 * URLs pass. Anything else — javascript:, data:, relative paths, garbage —
 * becomes null, so a stored or provider-supplied value can never run
 * script when clicked.
 */
function safe_url(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '' || strlen($value) > 2000 || preg_match('/[\x00-\x1F\x7F\s]/', $value)) {
        return null;
    }

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    $host   = (string) parse_url($value, PHP_URL_HOST);

    return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $value : null;
}

/**
 * Security headers for every response the app renders.
 *
 * The CSP allows scripts from this origin only — no inline script, no
 * eval — which also neutralises an injected <script> or javascript: URL
 * should one ever slip through. Inline *styles* stay allowed: a few views
 * use style="", and FullCalendar and Turbo inject <style> elements. Images
 * may come from any HTTPS host (provider art before it is downloaded,
 * Twitch category art).
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');

    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/**
 * The public hosts this app answers on: the configured base URL's host and
 * every domain in app.domain_locales (each also with www.). Used where a
 * request's Host header must not be trusted blindly.
 *
 * @return list<string> lower-case host names, without port
 */
function app_hosts(): array
{
    $hosts = [];
    $base  = (string) parse_url((string) Config::get('app.base_url', ''), PHP_URL_HOST);

    if ($base !== '') {
        $hosts[] = strtolower($base);
    }

    foreach (array_keys((array) Config::get('app.domain_locales', [])) as $domain) {
        $hosts[] = strtolower((string) $domain);
        $hosts[] = 'www.' . strtolower((string) $domain);
    }

    return array_values(array_unique($hosts));
}

/**
 * Domains whose language is fixed, with no language switch (Administration
 * → Settings).
 *
 * @return list<string>
 */
function fixed_locale_domains(): array
{
    $value = (string) Settings::get('fixed_locale_domains', '');

    return array_values(array_filter(array_map(static fn (string $d): string => strtolower(trim($d)), explode(',', $value))));
}

/** The public address to give out for something meant for speakers of $locale (see Lang::baseUrlFor()). */
function public_base_url(?string $locale): string
{
    return Lang::baseUrlFor(
        $locale,
        (array) Config::get('app.domain_locales', []),
        fixed_locale_domains(),
        (string) (Config::get('app.base_url', '') ?: rtrim(url('/'), '/'))
    );
}
