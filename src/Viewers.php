<?php
declare(strict_types=1);

/**
 * Viewers: people who sign in with Twitch only, to redeem and see the keys
 * they won in giveaways (see Giveaways).
 *
 * A viewer is not a user. Their sign-in lives under its own session key,
 * so Auth never treats them as signed in and no page of the app is reachable
 * with it. Creators cannot sign in through Twitch: their password also opens
 * a private key vault, so it stays their only way in.
 *
 * The Twitch sign-in asks for no scope at all — only who the person is —
 * and the token is revoked as soon as the identity is known.
 *
 * A user who connects the same Twitch account absorbs the viewer profile:
 * their prizes then show in their account too.
 */
final class Viewers
{
    private const SESSION = 'viewer_id';
    private const STATE   = 'viewer_oauth';

    private static ?array $current = null;

    /** The signed-in viewer, or null. */
    public static function current(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $id = $_SESSION[self::SESSION] ?? null;

        if (!is_int($id)) {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT * FROM viewers WHERE id = ?');
        $stmt->execute([$id]);

        return self::$current = $stmt->fetch() ?: null;
    }

    /** The viewer profile linked to a user account, or null. */
    public static function forUser(int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM viewers WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The viewer profile of a signed-in creator, through their connected
     * Twitch account: created and linked on first use. Connecting the
     * account already proved they own it. Null without a connection.
     */
    public static function forCreator(int $userId): ?array
    {
        $connection = TwitchUser::connection($userId);

        if ($connection === null) {
            return self::forUser($userId);
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO viewers (twitch_user_id, twitch_login, display_name)
             VALUES (?, ?, ?) ON CONFLICT (twitch_user_id) DO NOTHING'
        );
        $stmt->execute([$connection['twitch_user_id'], $connection['twitch_login'], $connection['twitch_login']]);

        self::linkMatchingUser($connection['twitch_user_id']);

        $stmt = $pdo->prepare('SELECT * FROM viewers WHERE twitch_user_id = ?');
        $stmt->execute([$connection['twitch_user_id']]);

        return $stmt->fetch() ?: null;
    }

    /** Where "Sign in with Twitch" goes, remembering the page to return to. */
    public static function authorizeUrl(string $back): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION[self::STATE] = ['state' => $state, 'back' => self::safeBack($back)];

        return 'https://id.twitch.tv/oauth2/authorize?' . http_build_query([
            'client_id'     => Twitch::clientId(),
            'redirect_uri'  => TwitchUser::redirectUri(),
            'response_type' => 'code',
            'scope'         => '',
            'state'         => $state,
            'force_verify'  => 'true',
        ]);
    }

    /** Whether a Twitch callback belongs to a viewer sign-in (not a creator connecting a channel). */
    public static function isViewerCallback(string $state): bool
    {
        $expected = $_SESSION[self::STATE]['state'] ?? '';

        return is_string($expected) && $expected !== '' && hash_equals($expected, $state);
    }

    /**
     * Finishes the viewer sign-in and returns the page to go back to.
     *
     * @throws UserError when Twitch does not confirm the identity
     */
    public static function complete(string $code): string
    {
        $back = (string) ($_SESSION[self::STATE]['back'] ?? '/prizes');
        unset($_SESSION[self::STATE]);

        $identity = TwitchUser::identify($code);

        if ($identity === null) {
            throw new UserError(__('ui.message.viewer_signin_failed'));
        }

        $profile = Twitch::user($identity['user_id']);
        $pdo     = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO viewers (twitch_user_id, twitch_login, display_name, avatar_url, last_login_at)
             VALUES (?, ?, ?, ?, now())
             ON CONFLICT (twitch_user_id) DO UPDATE
                SET twitch_login = EXCLUDED.twitch_login, display_name = EXCLUDED.display_name,
                    avatar_url = EXCLUDED.avatar_url, last_login_at = now()
             RETURNING id'
        );
        $stmt->execute([
            $identity['user_id'],
            $identity['login'],
            $profile['name'] ?? $identity['login'],
            isset($profile['avatar']) ? safe_url($profile['avatar']) : null,
        ]);
        $viewerId = (int) $stmt->fetchColumn();

        self::linkMatchingUser($identity['user_id']);

        session_regenerate_id(true);
        $_SESSION[self::SESSION] = $viewerId;
        self::$current = null;

        return $back;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION]);
        self::$current = null;
        session_regenerate_id(true);
    }

    /**
     * Links the viewer profile of a Twitch account to the user who connected
     * that same account — when a user connects Twitch, or a viewer signs in.
     */
    public static function linkMatchingUser(string $twitchUserId): void
    {
        Database::connection()->prepare(
            'UPDATE viewers v SET user_id = tc.user_id
               FROM twitch_connections tc
              WHERE v.twitch_user_id = ? AND tc.twitch_user_id = v.twitch_user_id
                AND v.user_id IS DISTINCT FROM tc.user_id
                AND NOT EXISTS (SELECT 1 FROM viewers o WHERE o.user_id = tc.user_id AND o.id <> v.id)'
        )->execute([$twitchUserId]);
    }

    /**
     * Deletes a viewer profile. Streamers keep the record that a prize was
     * given, without the person: the winner rows lose name and account, and
     * links not yet used are cancelled.
     */
    public static function delete(array $viewer): void
    {
        Database::transaction(static function (PDO $pdo) use ($viewer): void {
        $pdo->prepare(
            "UPDATE giveaway_winners
                SET cancelled_at = CASE WHEN claimed_at IS NULL THEN coalesce(cancelled_at, now()) ELSE cancelled_at END,
                    twitch_user_id = NULL, twitch_login = 'deleted', display_name = NULL, viewer_id = NULL
              WHERE twitch_user_id = ?"
        )->execute([$viewer['twitch_user_id']]);
        $pdo->prepare('DELETE FROM giveaway_entries WHERE twitch_user_id = ?')->execute([$viewer['twitch_user_id']]);
        $pdo->prepare('DELETE FROM viewers WHERE id = ?')->execute([$viewer['id']]);
        });

        unset($_SESSION[self::SESSION]);
        self::$current = null;
    }

    /** Only paths inside the app, never another site. */
    private static function safeBack(string $back): string
    {
        return preg_match('#^/(?![/\\\\])[^\s]*$#', $back) ? $back : '/prizes';
    }
}
