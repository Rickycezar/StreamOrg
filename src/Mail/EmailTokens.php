<?php
declare(strict_types=1);

/**
 * The links e-mails carry: confirm an address ("verify"), reset a
 * forgotten password ("reset") or set the first one ("welcome"). Like a
 * giveaway's claim link, the token is long and random, only its hash is
 * stored, it works once, for a limited time, and only for the address it
 * was sent to. Issuing a new one retires the older ones of that purpose.
 */
final class EmailTokens
{
    public const PURPOSES = ['verify', 'reset', 'welcome'];

    /** @return string the raw token, for the link */
    public static function issue(int $userId, string $purpose, string $email, int $minutes): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Unknown token purpose.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $pdo   = Database::connection();

        $pdo->prepare('UPDATE email_tokens SET used_at = now() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$userId, $purpose]);
        $pdo->prepare('INSERT INTO email_tokens (user_id, purpose, token_hash, email, expires_at) VALUES (?, ?, ?, ?, now() + make_interval(mins => ?))')
            ->execute([$userId, $purpose, hash('sha256', $token), $email, $minutes]);

        return $token;
    }

    /**
     * The user a token still works for, or null: unknown, used, expired,
     * or the account's address changed since it was sent. Inactive
     * accounts have none, but a sign-up waiting for approval can confirm
     * its address.
     *
     * @param list<string> $purposes
     */
    public static function find(array $purposes, string $token): ?array
    {
        if ($token === '' || strlen($token) > 100) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            "SELECT t.id AS token_id, t.purpose, u.* FROM email_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > now() AND t.email = u.email
                AND (u.is_active OR (u.signup_status = 'pending' AND t.purpose = 'verify'))"
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        return $row !== false && in_array($row['purpose'], $purposes, true) ? $row : null;
    }

    /** Marks a token used; false when it was used meanwhile. */
    public static function consume(int $tokenId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE email_tokens SET used_at = now() WHERE id = ? AND used_at IS NULL');
        $stmt->execute([$tokenId]);

        return $stmt->rowCount() === 1;
    }
}
