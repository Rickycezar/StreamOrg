<?php
declare(strict_types=1);

/**
 * Per-session CSRF token. One token for the whole session, compared with
 * hash_equals so the check is not timing-sensitive.
 */
final class Csrf
{
    private const KEY = 'streamorg_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function valid(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && hash_equals(self::token(), $token);
    }

    /**
     * Rejects the request unless it carries a valid token. Answers JSON for
     * AJAX callers and a plain 419 otherwise.
     */
    /** A fresh token, e.g. after sign-in. */
    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }

    public static function verify(bool $json = false): void
    {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (self::valid(is_string($token) ? $token : null)) {
            return;
        }

        if ($json) {
            json_response(['ok' => false, 'error' => __('ui.message.csrf_failed')], 419);
        }

        http_response_code(419);
        echo '<h1>419</h1><p>' . e(__('ui.message.csrf_failed')) . '</p>';
        exit;
    }
}
