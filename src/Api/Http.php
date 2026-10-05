<?php
declare(strict_types=1);

/**
 * Minimal cURL wrapper for outbound provider calls.
 *
 * Every call is time-limited so a slow third party cannot hang a page load.
 */
final class Http
{
    public const TIMEOUT = 8;

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    public static function get(string $url, array $headers = [], int $timeout = self::TIMEOUT, ?int $maxBytes = null): array
    {
        return self::send('GET', $url, null, $headers, $timeout, $maxBytes);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    public static function post(string $url, string $body, array $headers = [], int $timeout = self::TIMEOUT): array
    {
        return self::send('POST', $url, $body, $headers, $timeout);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    public static function patch(string $url, string $body, array $headers = [], int $timeout = self::TIMEOUT): array
    {
        return self::send('PATCH', $url, $body, $headers, $timeout);
    }

    /** @return array{status:int, body:string, error:?string} */
    public static function delete(string $url, array $headers = [], int $timeout = self::TIMEOUT): array
    {
        return self::send('DELETE', $url, null, $headers, $timeout);
    }

    /** Decodes a JSON response body, returning null when it is not valid JSON. */
    public static function json(array $response): ?array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        $data = json_decode($response['body'], true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    private static function send(string $method, string $url, ?string $body, array $headers, int $timeout, ?int $maxBytes = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'The cURL extension is not available.'];
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'StreamOrg/1.0',
            CURLOPT_HTTPHEADER     => $formatted,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        if ($maxBytes !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($ch, $total, $downloaded) use ($maxBytes): int {
                return ($total > $maxBytes || $downloaded > $maxBytes) ? 1 : 0;
            });
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => is_string($responseBody) ? $responseBody : '',
            'error'  => $error,
        ];
    }
}
