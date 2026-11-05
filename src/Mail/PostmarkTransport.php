<?php
declare(strict_types=1);

/**
 * Sends through Postmark's API (https://postmarkapp.com), the provider
 * suggested for StreamOrg: transactional mail only, so its reputation
 * stays good. Needs the server's API token (Administration → E-mails,
 * kept encrypted) and a sender address on a domain confirmed there with
 * SPF and DKIM. Notification copies carry List-Unsubscribe headers, as
 * Gmail and Yahoo ask of bulk senders.
 */
final class PostmarkTransport implements MailTransport
{
    private const URL = 'https://api.postmarkapp.com/email';

    public function __construct(#[\SensitiveParameter] private readonly string $token, private readonly string $stream = 'outbound')
    {
    }

    /** The transport set up in Administration → E-mails, or null when it is not. */
    public static function fromSettings(): ?self
    {
        $token = trim((string) Crypto::decrypt(Settings::get('mail.postmark_token')));

        if ($token === '' || Mailer::sender()['address'] === '') {
            return null;
        }

        return new self($token, (string) Settings::get('mail.postmark_stream', 'outbound') ?: 'outbound');
    }

    public function send(array $message): array
    {
        $sender  = $message['sender'];
        $headers = [];

        if (!empty($message['unsubscribe'])) {
            $headers[] = ['Name' => 'List-Unsubscribe', 'Value' => '<' . $message['unsubscribe'] . '>'];
            $headers[] = ['Name' => 'List-Unsubscribe-Post', 'Value' => 'List-Unsubscribe=One-Click'];
        }

        $body = [
            'From'          => self::address((string) $sender['name'], (string) $sender['address']),
            'To'            => (string) $message['to_address'],
            'Subject'       => (string) $message['subject'],
            'HtmlBody'      => (string) $message['html'],
            'TextBody'      => (string) $message['body_text'],
            'Tag'           => (string) $message['template'],
            'MessageStream' => $this->stream,
            'TrackOpens'    => false,
            'TrackLinks'    => 'None',
            'Headers'       => $headers,
        ];

        if (($sender['reply_to'] ?? '') !== '') {
            $body['ReplyTo'] = (string) $sender['reply_to'];
        }

        $response = Http::post(self::URL, (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
            'Accept'                  => 'application/json',
            'Content-Type'            => 'application/json',
            'X-Postmark-Server-Token' => $this->token,
        ]);
        $data = json_decode((string) $response['body'], true);
        $data = is_array($data) ? $data : [];

        if ($response['status'] === 200 && (int) ($data['ErrorCode'] ?? 0) === 0) {
            return ['ok' => true, 'id' => isset($data['MessageID']) ? (string) $data['MessageID'] : null];
        }

        return [
            'ok'        => false,
            'error'     => 'Postmark ' . $response['status'] . ': ' . (string) ($data['Message'] ?? $response['error'] ?? ''),
            'permanent' => in_array((int) ($data['ErrorCode'] ?? 0), [300, 406], true),
        ];
    }

    /** "Name <address>", the name quoted when it needs to be. */
    private static function address(string $name, string $address): string
    {
        $name = trim(str_replace(['"', "\r", "\n"], '', $name));

        return $name === '' ? $address : '"' . $name . '" <' . $address . '>';
    }
}
