<?php
declare(strict_types=1);

/**
 * The error log behind Administration → Errors.
 *
 * install() catches what goes wrong in a request: uncaught exceptions,
 * fatal errors (at shutdown) and PHP warnings and notices. note() is for
 * problems the code handles itself (a Twitch call that failed, an image
 * that would not download). Everything is still written to the server log
 * as before, and also kept in app_errors, grouped by kind (see
 * 042_app_errors.sql). A new kind of problem, or one that comes back after
 * being resolved, is told to the administrators through a notification.
 *
 * Writing goes through a connection of its own, so an error inside a
 * transaction that is rolled back is still kept. Logging never throws: if
 * the database cannot be reached, the server log is all there is.
 */
final class ErrorLog
{
    public const LEVELS = ['fatal', 'error', 'warning', 'notice'];

    private const MESSAGE_MAX = 2000;
    private const TRACE_MAX   = 8000;
    private const KEEP_DAYS   = 90;

    private static ?PDO $pdo = null;
    private static bool $busy = false;
    private static bool $installed = false;

    /** Catches uncaught exceptions, fatal errors, warnings and notices from here on. */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;

        set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $type) !== 0) {
                $level = in_array($type, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_RECOVERABLE_ERROR], true) ? 'warning' : 'notice';
                self::record($level, $message, $file, $line, self::frames(array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1)));
            }

            return false;
        });

        set_exception_handler(static function (Throwable $e): void {
            self::exception($e, 'fatal');

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, (string) $e . PHP_EOL);
                exit(255);
            }

            if (!headers_sent()) {
                http_response_code(500);
            }
        });

        register_shutdown_function(static function (): void {
            $last = error_get_last();

            if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                self::record('fatal', (string) $last['message'], (string) $last['file'], (int) $last['line']);
            }
        });
    }

    /** An exception the request could not recover from: logged, and kept. */
    public static function exception(Throwable $e, string $level = 'error'): void
    {
        error_log('StreamOrg: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        self::record($level, get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), self::frames($e->getTrace()));
    }

    /** A problem the code handled itself: written to the server log and kept, at the "notice" level. */
    public static function note(string $message): void
    {
        error_log('StreamOrg ' . $message);

        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        self::record('notice', $message, (string) ($caller['file'] ?? ''), (int) ($caller['line'] ?? 0));
    }

    /**
     * Keeps one occurrence: a new row for a new kind of problem, otherwise
     * the count goes up and the latest details replace the old ones.
     *
     * @param array{at?:string, source?:string, method?:?string, path?:?string, referer?:?string, notify?:bool} $extra
     * @return int|null the row's id, or null when it could not be kept
     */
    public static function record(string $level, string $message, ?string $file = null, ?int $line = null, ?string $trace = null, array $extra = []): ?int
    {
        if (self::$busy) {
            return null;
        }

        self::$busy = true;

        try {
            $level   = in_array($level, self::LEVELS, true) ? $level : 'error';
            $message = mb_substr(trim($message), 0, self::MESSAGE_MAX) ?: '(no message)';
            $file    = $file !== null && $file !== '' ? self::relative($file) : null;
            $line    = $line ?: null;
            $request = PHP_SAPI === 'cli' ? [] : [
                'method'  => $_SERVER['REQUEST_METHOD'] ?? null,
                'path'    => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: null,
                'referer' => isset($_SERVER['HTTP_REFERER']) ? (parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?: null) : null,
            ];
            $request = array_merge($request, array_intersect_key($extra, array_flip(['method', 'path', 'referer'])));
            $at      = $extra['at'] ?? null;
            $userId  = isset($_SESSION) && is_int($_SESSION[Auth::SESSION_KEY] ?? null) ? $_SESSION[Auth::SESSION_KEY] : null;

            $pdo  = self::connection();
            $stmt = $pdo->prepare(
                "INSERT INTO app_errors AS a (fingerprint, level, source, message, file, line, trace, method, path, referer, user_id, first_seen, last_seen)
                 VALUES (:fingerprint, :level, :source, :message, :file, :line, :trace, :method, :path, :referer, :user,
                         coalesce(CAST(:at AS timestamptz), now()), coalesce(CAST(:at AS timestamptz), now()))
                 ON CONFLICT (fingerprint) DO UPDATE SET
                     count = a.count + 1,
                     message = CASE WHEN excluded.last_seen >= a.last_seen THEN excluded.message ELSE a.message END,
                     trace = CASE WHEN excluded.last_seen >= a.last_seen THEN coalesce(excluded.trace, a.trace) ELSE a.trace END,
                     method = CASE WHEN excluded.last_seen >= a.last_seen THEN excluded.method ELSE a.method END,
                     path = CASE WHEN excluded.last_seen >= a.last_seen THEN excluded.path ELSE a.path END,
                     referer = CASE WHEN excluded.last_seen >= a.last_seen THEN excluded.referer ELSE a.referer END,
                     user_id = CASE WHEN excluded.last_seen >= a.last_seen THEN excluded.user_id ELSE a.user_id END,
                     first_seen = least(a.first_seen, excluded.first_seen),
                     last_seen = greatest(a.last_seen, excluded.last_seen),
                     resolved_at = CASE WHEN excluded.last_seen > a.resolved_at THEN NULL ELSE a.resolved_at END,
                     resolved_by = CASE WHEN excluded.last_seen > a.resolved_at THEN NULL ELSE a.resolved_by END
                 RETURNING id, count, (xmax = 0) AS created, (SELECT resolved_at FROM app_errors WHERE id = a.id) AS was_resolved"
            );
            $stmt->execute([
                'fingerprint' => self::fingerprint($level, $message, $file, $line),
                'level'       => $level,
                'source'      => $extra['source'] ?? 'php',
                'message'     => $message,
                'file'        => $file,
                'line'        => $line,
                'trace'       => $trace !== null ? mb_substr($trace, 0, self::TRACE_MAX) : null,
                'method'      => $request['method'] ?? null,
                'path'        => isset($request['path']) ? mb_substr((string) $request['path'], 0, 300) : null,
                'referer'     => isset($request['referer']) ? mb_substr((string) $request['referer'], 0, 300) : null,
                'user'        => $userId,
                'at'          => $at,
            ]);
            $row = $stmt->fetch();

            if (random_int(1, 50) === 1) {
                $pdo->exec('DELETE FROM app_errors WHERE last_seen < now() - interval \'' . self::KEEP_DAYS . ' days\'');
            }

            if (($extra['notify'] ?? true) && $level !== 'notice' && ($row['created'] || $row['was_resolved'] !== null)) {
                try {
                    self::tellAdmins((int) $row['id'], $level, $message, (bool) $row['created']);
                } catch (Throwable $e) {
                    error_log('StreamOrg error log could not tell the administrators: ' . $e->getMessage());
                }
            }

            return (int) $row['id'];
        } catch (Throwable $e) {
            error_log('StreamOrg error log unavailable: ' . $e->getMessage());

            return null;
        } finally {
            self::$busy = false;
        }
    }

    /**
     * The problems kept, newest first, for the administration page.
     *
     * @return list<array>
     */
    public static function list(string $show = 'open', string $level = '', string $query = '', int $limit = 200): array
    {
        $where  = [match ($show) {
            'resolved' => 'e.resolved_at IS NOT NULL',
            'all'      => 'true',
            default    => 'e.resolved_at IS NULL',
        }];
        $params = [];

        if (in_array($level, self::LEVELS, true)) {
            $where[] = 'e.level = :level';
            $params['level'] = $level;
        }

        if (trim($query) !== '') {
            $where[] = '(e.message ILIKE :q OR e.file ILIKE :q OR e.path ILIKE :q)';
            $params['q'] = '%' . strtr(trim($query), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }

        $stmt = Database::connection()->prepare(
            'SELECT e.*, coalesce(u.display_name, u.username) AS user_name, coalesce(r.display_name, r.username) AS resolved_by_name
               FROM app_errors e
          LEFT JOIN users u ON u.id = e.user_id
          LEFT JOIN users r ON r.id = e.resolved_by
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY e.last_seen DESC, e.id DESC
              LIMIT ' . max(1, min($limit, 500))
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * How many open problems there are, by level.
     *
     * @return array<string, int>
     */
    public static function openCounts(): array
    {
        $rows = Database::connection()->query(
            'SELECT level, count(*) FROM app_errors WHERE resolved_at IS NULL GROUP BY level'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map('intval', $rows) + array_fill_keys(self::LEVELS, 0);
    }

    /** Marks problems resolved (or open again); returns how many changed. */
    public static function resolve(array $ids, int $adminId, bool $resolved = true): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return 0;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));

        if ($resolved) {
            $stmt = Database::connection()->prepare("UPDATE app_errors SET resolved_at = now(), resolved_by = ? WHERE id IN ({$marks}) AND resolved_at IS NULL");
            $stmt->execute([$adminId, ...$ids]);
        } else {
            $stmt = Database::connection()->prepare("UPDATE app_errors SET resolved_at = NULL, resolved_by = NULL WHERE id IN ({$marks}) AND resolved_at IS NOT NULL");
            $stmt->execute($ids);
        }

        return $stmt->rowCount();
    }

    /** Deletes problems already resolved; returns how many. */
    public static function clearResolved(): int
    {
        return (int) Database::connection()->exec('DELETE FROM app_errors WHERE resolved_at IS NOT NULL');
    }

    /** Uses this connection instead of a separate one (the tests, which roll everything back). */
    public static function useConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** The same problem, whatever ids, counts or times appear in its message. */
    public static function fingerprint(string $level, string $message, ?string $file, ?int $line): string
    {
        $shape = (string) preg_replace(
            ['/[0-9a-f]{8,}/i', '/\d+/', '/\'[^\']{0,200}\'/', '/"[^"]{0,200}"/'],
            ['H', 'N', "'S'", '"S"'],
            mb_substr($message, 0, 300)
        );

        return sha1($level . '|' . $shape . '|' . $file . '|' . $line);
    }

    /** A stack trace as file:line and function, one frame per line, never with argument values. */
    private static function frames(array $trace): ?string
    {
        $lines = [];

        foreach (array_slice($trace, 0, 30) as $i => $frame) {
            $where = isset($frame['file']) ? self::relative((string) $frame['file']) . ':' . ($frame['line'] ?? '?') : '[internal]';
            $call  = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $lines[] = "#{$i} {$where}" . ($call !== '' ? " {$call}()" : '');
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private static function relative(string $file): string
    {
        $root = dirname(__DIR__) . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private static function connection(): PDO
    {
        if (self::$pdo === null) {
            $db = Config::get('db', []);
            self::$pdo = Database::connect(
                host:     (string) ($db['host'] ?? '127.0.0.1'),
                port:     (int)    ($db['port'] ?? 5432),
                dbname:   (string) ($db['name'] ?? ''),
                user:     (string) ($db['user'] ?? ''),
                password: $db['password'] ?? null,
                sslmode:  (string) ($db['sslmode'] ?? 'prefer'),
            );
        }

        return self::$pdo;
    }

    /** A notification to every active administrator about a new (or returning) problem. */
    private static function tellAdmins(int $id, string $level, string $message, bool $new): void
    {
        $admins = Database::connection()->query("SELECT id FROM users WHERE role = 'admin' AND is_active")->fetchAll(PDO::FETCH_COLUMN);

        Notifications::system(
            array_map('intval', $admins),
            ($new ? 'error_new_' : 'error_back_') . $level,
            [mb_strimwidth((string) strtok($message, "\n"), 0, 160, '…')],
            '/admin/errors?id=' . $id,
            'warning'
        );
    }
}
