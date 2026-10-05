<?php
declare(strict_types=1);

/**
 * The address Twitch delivers EventSub messages to. No session, no CSRF
 * token: the message signature is the authentication.
 */
final class TwitchEventController
{
    public static function receive(): void
    {
        $body   = (string) file_get_contents('php://input');
        $header = static fn (string $name): string => (string) ($_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_' . $name] ?? '');

        $id        = $header('ID');
        $timestamp = $header('TIMESTAMP');

        if (!TwitchEventSub::verify($id, $timestamp, $header('SIGNATURE'), $body)) {
            http_response_code(403);
            exit;
        }

        $payload = json_decode($body, true);

        [$status, $type, $content] = TwitchEventSub::handle($id, $header('TYPE'), $timestamp, is_array($payload) ? $payload : []);

        http_response_code($status);
        header('Content-Type: ' . $type);
        echo $content;
        exit;
    }
}
