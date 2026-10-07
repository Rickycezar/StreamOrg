<?php
declare(strict_types=1);

/**
 * GET /healthz — for the container platform's health check. Answers 200
 * when the app can reach its database, 503 otherwise. Public, and says
 * nothing beyond ok / not ok.
 */
final class HealthController
{
    public static function index(): void
    {
        header('Cache-Control: no-store');

        try {
            Database::connection()->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            ErrorLog::note('health check: ' . $e->getMessage());
            json_response(['ok' => false], 503);
        }

        json_response(['ok' => true]);
    }
}
