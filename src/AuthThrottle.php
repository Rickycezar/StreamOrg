<?php
declare(strict_types=1);

/**
 * Slows down password guessing (see 020_auth_attempts.sql).
 *
 * Per account: after SUBJECT_LIMIT failures within the window, each further
 * attempt must wait — one minute, doubling per extra failure, up to the
 * whole window. Per address: IP_LIMIT failures within the window, across
 * any accounts, block that address until the window rolls over. A success
 * clears the account's failures.
 *
 * Call wait() before verifying a password and refuse while it is above
 * zero; call record() with the outcome afterwards.
 */
final class AuthThrottle
{
    private const WINDOW_MINUTES = 15;
    private const SUBJECT_LIMIT  = 5;
    private const IP_LIMIT       = 30;
    private const MAX_WAIT       = 900;

    /** Seconds until another attempt is allowed; 0 when it is allowed now. */
    public static function wait(string $scope, string $subject): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT count(*) AS failures,
                    extract(epoch FROM now() - max(attempted_at))::int AS since_last
               FROM auth_attempts
              WHERE scope = ? AND subject = ? AND NOT succeeded
                AND attempted_at > now() - make_interval(mins => ?)"
        );
        $stmt->execute([$scope, $subject, self::WINDOW_MINUTES]);
        $row = $stmt->fetch();

        $wait = 0;
        $failures = (int) $row['failures'];

        if ($failures >= self::SUBJECT_LIMIT) {
            $delay = min(60 * (2 ** ($failures - self::SUBJECT_LIMIT)), self::MAX_WAIT);
            $wait  = max(0, $delay - (int) $row['since_last']);
        }

        $ip = client_ip();

        if ($ip !== null) {
            $stmt = $pdo->prepare(
                "SELECT count(*) AS failures,
                        extract(epoch FROM min(attempted_at) + make_interval(mins => ?) - now())::int AS until_free
                   FROM auth_attempts
                  WHERE ip = ? AND NOT succeeded
                    AND attempted_at > now() - make_interval(mins => ?)"
            );
            $stmt->execute([self::WINDOW_MINUTES, $ip, self::WINDOW_MINUTES]);
            $byIp = $stmt->fetch();

            if ((int) $byIp['failures'] >= self::IP_LIMIT) {
                $wait = max($wait, (int) $byIp['until_free']);
            }
        }

        return $wait;
    }

    public static function record(string $scope, string $subject, bool $succeeded): void
    {
        $pdo = Database::connection();

        $pdo->prepare('INSERT INTO auth_attempts (scope, subject, ip, succeeded) VALUES (?, ?, ?, ?)')
            ->execute([$scope, $subject, client_ip(), $succeeded ? 'true' : 'false']);

        if ($succeeded) {
            $pdo->prepare('DELETE FROM auth_attempts WHERE scope = ? AND subject = ? AND NOT succeeded')
                ->execute([$scope, $subject]);
        }

        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM auth_attempts WHERE attempted_at < now() - interval '1 day'");
        }
    }

    /** "Try again in N minutes", rounded up. */
    public static function message(int $seconds): string
    {
        return sprintf(__('ui.message.too_many_attempts'), max(1, (int) ceil($seconds / 60)));
    }

    /** The username as it is counted: case and surrounding space do not matter. */
    public static function loginSubject(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}
