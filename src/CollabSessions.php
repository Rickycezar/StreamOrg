<?php
declare(strict_types=1);

/**
 * Planning a collab together with other StreamOrg users (see
 * 040_collab_sessions.sql).
 *
 * The host starts a joint plan from one of their collabs; every streamer
 * in its cast whose Twitch account belongs to a StreamOrg user is invited.
 * Whoever accepts gets their own content linked to the plan: the title,
 * prefixes, games, keys and sponsors are theirs; the start and length are
 * shared. Changing the time of a linked content (on the calendar, the
 * dashboard or its form) does not move it: it proposes the new time to
 * the others, and it applies to everyone at once when all of them agree.
 * Each step is told to the people concerned through notifications.
 */
final class CollabSessions
{
    public const LINK = '/collabs/session?id=';

    /**
     * The streamers among these who are StreamOrg users (other than the
     * owner), matched by Twitch account.
     *
     * @param list<int> $streamerIds
     * @return array<int, array{user_id:int, name:string, login:string}> by streamer id
     */
    public static function linkedUsers(int $ownerId, array $streamerIds): array
    {
        $streamerIds = array_values(array_filter(array_map('intval', $streamerIds)));

        if ($streamerIds === []) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            "SELECT s.id AS streamer_id, u.id AS user_id, coalesce(u.display_name, u.username) AS name, t.twitch_login AS login
               FROM streamers s
               JOIN twitch_connections t ON s.source_provider = 'twitch' AND t.twitch_user_id = s.source_ref
               JOIN users u ON u.id = t.user_id AND u.is_active
              WHERE s.user_id = ? AND s.id = ANY(CAST(? AS bigint[])) AND u.id <> ?"
        );
        $stmt->execute([$ownerId, '{' . implode(',', $streamerIds) . '}', $ownerId]);

        $linked = [];

        foreach ($stmt->fetchAll() as $row) {
            $linked[(int) $row['streamer_id']] = ['user_id' => (int) $row['user_id'], 'name' => (string) $row['name'], 'login' => (string) $row['login']];
        }

        return $linked;
    }

    /**
     * Starts a joint plan from a collab and invites the StreamOrg users in
     * its cast. The host's own content for the collab is linked (or made).
     *
     * @return int the plan's id
     * @throws UserError
     */
    public static function invite(int $hostId, int $collabId): int
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM collabs WHERE id = ? AND user_id = ?');
        $stmt->execute([$collabId, $hostId]);
        $collab = $stmt->fetch();

        if ($collab === false) {
            throw new UserError(__('ui.message.not_found'));
        }

        $existing = $pdo->prepare("SELECT id FROM collab_sessions WHERE collab_id = ? AND status = 'active'");
        $existing->execute([$collabId]);

        if (($id = $existing->fetchColumn()) !== false) {
            return (int) $id;
        }

        $cast = $pdo->prepare('SELECT streamer_id FROM collab_streamers WHERE collab_id = ?');
        $cast->execute([$collabId]);
        $guests = self::linkedUsers($hostId, $cast->fetchAll(PDO::FETCH_COLUMN));

        if ($guests === []) {
            throw new UserError(__('ui.message.together_no_users'));
        }

        $minutes = ContentDefaults::minutes($hostId);

        $sessionId = Database::transaction(static function (PDO $pdo) use ($hostId, $collab, $guests, $minutes): int {
            $stmt = $pdo->prepare(
                'INSERT INTO collab_sessions (host_user_id, collab_id, title, proposed_start, proposed_minutes, proposed_by, proposed_at)
                 VALUES (?, ?, ?, ?, ?, ?, CASE WHEN CAST(? AS timestamptz) IS NULL THEN NULL ELSE now() END) RETURNING id'
            );
            $stmt->execute([$hostId, $collab['id'], mb_substr((string) $collab['title'], 0, 140), $collab['proposed_at'], $collab['proposed_at'] ? $minutes : null, $collab['proposed_at'] ? $hostId : null, $collab['proposed_at']]);
            $sessionId = (int) $stmt->fetchColumn();

            $member = $pdo->prepare('INSERT INTO collab_session_members (session_id, user_id, role, state, approves, responded_at) VALUES (?, ?, ?, ?, ?, ?)');
            $member->execute([$sessionId, $hostId, 'host', 'accepted', 'true', date(DATE_ATOM)]);

            foreach ($guests as $guest) {
                $member->execute([$sessionId, $guest['user_id'], 'guest', 'invited', 'false', null]);
            }

            $own = $pdo->prepare(
                "SELECT id FROM streams WHERE user_id = ? AND collab_id = ? AND status = 'planned' AND collab_session_id IS NULL ORDER BY id DESC LIMIT 1"
            );
            $own->execute([$hostId, $collab['id']]);
            $streamId = $own->fetchColumn();

            if ($streamId !== false) {
                $pdo->prepare('UPDATE streams SET collab_session_id = ? WHERE id = ?')->execute([$sessionId, $streamId]);
            } else {
                self::createContent($pdo, $sessionId, $hostId, (int) $collab['id']);
            }

            self::agreeIfEveryoneDid($pdo, $sessionId, false);

            return $sessionId;
        });

        $host = self::name($hostId);
        Notifications::system(array_column($guests, 'user_id'), 'together_invited', [$host, $collab['title']], self::LINK . $sessionId, 'news');

        return $sessionId;
    }

    /**
     * A guest's answer to the invitation. Accepting creates their own
     * content for the plan and agrees to the time on the table.
     *
     * @throws UserError
     */
    public static function respond(int $userId, int $sessionId, bool $accept): void
    {
        $session = self::sessionFor($userId, $sessionId);

        if ($session['member_state'] !== 'invited' || $session['status'] !== 'active') {
            throw new UserError(__('ui.message.together_not_invited'));
        }

        Database::transaction(static function (PDO $pdo) use ($userId, $sessionId, $accept): void {
            $pdo->prepare('UPDATE collab_session_members SET state = ?, approves = ?, responded_at = now() WHERE session_id = ? AND user_id = ?')
                ->execute([$accept ? 'accepted' : 'declined', $accept ? 'true' : 'false', $sessionId, $userId]);

            if ($accept) {
                self::createContent($pdo, $sessionId, $userId, null);
                self::agreeIfEveryoneDid($pdo, $sessionId, true, $userId);
            }
        });

        Notifications::system(
            self::participants($sessionId, $userId),
            $accept ? 'together_accepted' : 'together_declined',
            [self::name($userId), $session['title']],
            self::LINK . $sessionId,
            $accept ? 'success' : 'info'
        );
    }

    /**
     * Puts a new time on the table. With nobody else taking part yet, it
     * applies at once; otherwise the others are asked.
     *
     * @return bool whether the time applied at once
     * @throws UserError
     */
    public static function propose(int $userId, int $sessionId, ?DateTimeImmutable $start, int $minutes): bool
    {
        $session = self::sessionFor($userId, $sessionId);

        if ($session['member_state'] !== 'accepted' || $session['status'] !== 'active') {
            throw new UserError(__('ui.message.together_not_member'));
        }

        if ($minutes < ContentDefaults::MIN_MINUTES || $minutes > ContentDefaults::MAX_MINUTES) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        $applied = Database::transaction(static function (PDO $pdo) use ($userId, $sessionId, $start, $minutes): bool {
            $pdo->prepare(
                'UPDATE collab_sessions SET proposed_start = ?, proposed_minutes = ?, proposed_by = ?, proposed_at = now() WHERE id = ?'
            )->execute([$start?->format(DATE_ATOM), $minutes, $userId, $sessionId]);
            $pdo->prepare("UPDATE collab_session_members SET approves = (user_id = ?) WHERE session_id = ? AND state = 'accepted'")
                ->execute([$userId, $sessionId]);

            return self::agreeIfEveryoneDid($pdo, $sessionId, false);
        });

        if (!$applied) {
            Notifications::system(self::participants($sessionId, $userId, true), 'together_proposed', [self::name($userId), $session['title']], self::LINK . $sessionId);
        }

        return $applied;
    }

    /**
     * Agrees to, or turns down, the time on the table.
     *
     * @throws UserError
     */
    public static function answerProposal(int $userId, int $sessionId, bool $agree): void
    {
        $session = self::sessionFor($userId, $sessionId);

        if ($session['member_state'] !== 'accepted' || $session['proposed_at'] === null) {
            throw new UserError(__('ui.message.together_no_proposal'));
        }

        if ($agree) {
            Database::transaction(static function (PDO $pdo) use ($userId, $sessionId): void {
                $pdo->prepare('UPDATE collab_session_members SET approves = true WHERE session_id = ? AND user_id = ?')->execute([$sessionId, $userId]);
                self::agreeIfEveryoneDid($pdo, $sessionId, true, $userId);
            });

            return;
        }

        Database::connection()->prepare(
            'UPDATE collab_sessions SET proposed_start = NULL, proposed_minutes = NULL, proposed_by = NULL, proposed_at = NULL WHERE id = ?'
        )->execute([$sessionId]);

        Notifications::system(self::participants($sessionId, $userId, true), 'together_rejected', [self::name($userId), $session['title']], self::LINK . $sessionId, 'warning');
    }

    /**
     * A guest leaves (their content stays theirs, unlinked); the host
     * leaving cancels the plan for everyone.
     *
     * @throws UserError
     */
    public static function leave(int $userId, int $sessionId): void
    {
        $session = self::sessionFor($userId, $sessionId);

        if ($session['status'] !== 'active' || !in_array($session['member_state'], ['accepted', 'invited'], true)) {
            throw new UserError(__('ui.message.together_not_member'));
        }

        $others = self::participants($sessionId, $userId);
        $host   = $session['member_role'] === 'host';

        Database::transaction(static function (PDO $pdo) use ($userId, $sessionId, $host): void {
            if ($host) {
                $pdo->prepare("UPDATE collab_sessions SET status = 'cancelled' WHERE id = ?")->execute([$sessionId]);
                $pdo->prepare('UPDATE streams SET collab_session_id = NULL WHERE collab_session_id = ?')->execute([$sessionId]);

                return;
            }

            $pdo->prepare("UPDATE collab_session_members SET state = 'left', approves = false, responded_at = now() WHERE session_id = ? AND user_id = ?")
                ->execute([$sessionId, $userId]);
            $pdo->prepare('UPDATE streams SET collab_session_id = NULL WHERE collab_session_id = ? AND user_id = ?')->execute([$sessionId, $userId]);
            self::agreeIfEveryoneDid($pdo, $sessionId, true);
        });

        Notifications::system($others, $host ? 'together_cancelled' : 'together_left', [self::name($userId), $session['title']], $host ? '/collabs' : self::LINK . $sessionId, 'warning');
    }

    /**
     * Before a linked content's time changes elsewhere (calendar, form,
     * dashboard): with others taking part, the change becomes a proposal
     * and the message to show is returned; on their own, the plan's time
     * follows and null is returned (go ahead).
     */
    public static function guardTimeChange(int $userId, int $streamId, ?DateTimeImmutable $start, ?int $minutes): ?string
    {
        $stmt = Database::connection()->prepare(
            "SELECT s.collab_session_id, coalesce(s.planned_minutes, u.content_minutes) AS minutes,
                    (SELECT count(*) FROM collab_session_members m WHERE m.session_id = s.collab_session_id AND m.state = 'accepted') AS taking_part
               FROM streams s JOIN users u ON u.id = s.user_id
               JOIN collab_sessions c ON c.id = s.collab_session_id AND c.status = 'active'
              WHERE s.id = ? AND s.user_id = ?"
        );
        $stmt->execute([$streamId, $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $applied = self::propose($userId, (int) $row['collab_session_id'], $start, $minutes ?? (int) $row['minutes']);

        return $applied ? null : __('ui.message.together_time_proposed');
    }

    /** @return list<array<string,mixed>> the plans a user takes part in (or is invited to), soonest first */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.*, m.state AS member_state, m.role AS member_role, m.approves,
                    coalesce(h.display_name, h.username) AS host_name,
                    (SELECT string_agg(coalesce(u.display_name, u.username), ', ' ORDER BY u.username)
                       FROM collab_session_members o JOIN users u ON u.id = o.user_id
                      WHERE o.session_id = c.id AND o.user_id <> :user AND o.user_id <> c.host_user_id AND o.state IN ('accepted', 'invited')) AS others
               FROM collab_session_members m
               JOIN collab_sessions c ON c.id = m.session_id AND c.status = 'active'
               JOIN users h ON h.id = c.host_user_id
              WHERE m.user_id = :user AND m.state IN ('invited', 'accepted')
           ORDER BY coalesce(c.agreed_start, c.proposed_start) NULLS LAST, c.id"
        );
        $stmt->execute(['user' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * One plan as a participant sees it, with everyone in it and the
     * participant's own content.
     *
     * @throws UserError when the user is not part of it
     */
    public static function show(int $userId, int $sessionId): array
    {
        $session = self::sessionFor($userId, $sessionId);

        $stmt = Database::connection()->prepare(
            "SELECT m.user_id, m.role, m.state, m.approves, coalesce(u.display_name, u.username) AS name,
                    u.display_name, u.username, u.id, u.avatar_path, u.avatar_updated_at, t.twitch_login
               FROM collab_session_members m
               JOIN users u ON u.id = m.user_id
          LEFT JOIN twitch_connections t ON t.user_id = m.user_id
              WHERE m.session_id = ?
           ORDER BY m.role DESC, m.invited_at, u.username"
        );
        $stmt->execute([$sessionId]);
        $session['members'] = $stmt->fetchAll();

        $own = Database::connection()->prepare('SELECT id, title, status FROM streams WHERE collab_session_id = ? AND user_id = ?');
        $own->execute([$sessionId, $userId]);
        $session['own'] = $own->fetch() ?: null;

        return $session;
    }

    /** Invitations and proposals waiting for this user's answer. */
    public static function waitingFor(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT count(*) FROM collab_session_members m
               JOIN collab_sessions c ON c.id = m.session_id AND c.status = 'active'
              WHERE m.user_id = ? AND (m.state = 'invited' OR (m.state = 'accepted' AND c.proposed_at IS NOT NULL AND NOT m.approves))"
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * When everyone taking part agreed to the time on the table, it
     * becomes the plan's time and every linked content moves to it.
     *
     * @return bool whether a time was agreed now
     */
    private static function agreeIfEveryoneDid(PDO $pdo, int $sessionId, bool $notify, ?int $lastToAgree = null): bool
    {
        $stmt = $pdo->prepare(
            "SELECT c.proposed_at IS NOT NULL AS open, c.proposed_start, c.proposed_minutes, c.title,
                    bool_and(m.approves) FILTER (WHERE m.state = 'accepted') AS everyone
               FROM collab_sessions c
               JOIN collab_session_members m ON m.session_id = c.id
              WHERE c.id = ?
           GROUP BY c.id"
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();

        if ($row === false || !$row['open'] || !$row['everyone']) {
            return false;
        }

        $pdo->prepare(
            'UPDATE collab_sessions
                SET agreed_start = proposed_start, agreed_minutes = proposed_minutes,
                    proposed_start = NULL, proposed_minutes = NULL, proposed_by = NULL, proposed_at = NULL
              WHERE id = ?'
        )->execute([$sessionId]);
        $pdo->prepare('UPDATE streams SET scheduled_start = ?, planned_minutes = ? WHERE collab_session_id = ?')
            ->execute([$row['proposed_start'], $row['proposed_minutes'], $sessionId]);

        if ($notify) {
            Notifications::system(self::participants($sessionId, $lastToAgree ?? 0, true), 'together_agreed', [$row['title']], self::LINK . $sessionId, 'success');
        }

        return true;
    }

    /** Makes a participant's own content for the plan, at its agreed time (or undated until there is one). */
    private static function createContent(PDO $pdo, int $sessionId, int $userId, ?int $collabId): int
    {
        $session = $pdo->prepare('SELECT * FROM collab_sessions WHERE id = ?');
        $session->execute([$sessionId]);
        $plan = $session->fetch();

        $others = $pdo->prepare(
            "SELECT t.twitch_login FROM collab_session_members m
               JOIN twitch_connections t ON t.user_id = m.user_id
              WHERE m.session_id = ? AND m.user_id <> ? AND m.state IN ('accepted', 'invited')
           ORDER BY m.role DESC, t.twitch_login"
        );
        $others->execute([$sessionId, $userId]);
        $logins = $others->fetchAll(PDO::FETCH_COLUMN);

        $word   = ContentDefaults::collabPrefix($userId);
        $credit = $logins === [] ? '' : ' ' . trim(($word !== '' ? $word . ' ' : '') . implode(', ', array_map(static fn (string $l): string => '@' . $l, $logins)));
        $title  = trim(ContentDefaults::withDefaults(ContentDefaults::prefixes($userId), (string) $plan['title']) . $credit);

        $platform = $pdo->prepare(
            "SELECT coalesce(u.channel_platform_id, (SELECT id FROM streaming_platforms WHERE code = 'twitch')) FROM users u WHERE u.id = ?"
        );
        $platform->execute([$userId]);

        $stmt = $pdo->prepare(
            "INSERT INTO streams (user_id, streaming_platform_id, collab_id, collab_session_id, title, title_template, status,
                                  scheduled_start, planned_minutes, deadline)
             VALUES (?, ?, ?, ?, ?, ?, 'planned', ?, ?, ?) RETURNING id"
        );
        $stmt->execute([
            $userId,
            $platform->fetchColumn(),
            $collabId,
            $sessionId,
            $title,
            TitleCounters::templateFor($userId, $title),
            $plan['agreed_start'],
            $plan['agreed_minutes'],
            (new DateTimeImmutable('+30 days'))->format(DATE_ATOM),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed> the plan with the user's membership
     * @throws UserError when the user is not part of it
     */
    private static function sessionFor(int $userId, int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, m.state AS member_state, m.role AS member_role, m.approves
               FROM collab_sessions c
               JOIN collab_session_members m ON m.session_id = c.id AND m.user_id = ?
              WHERE c.id = ?'
        );
        $stmt->execute([$userId, $sessionId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new UserError(__('ui.message.not_found'));
        }

        return $row;
    }

    /**
     * Who else is in the plan: those taking part (and, unless only them,
     * those still invited).
     *
     * @return list<int>
     */
    private static function participants(int $sessionId, int $except, bool $onlyTakingPart = false): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id FROM collab_session_members WHERE session_id = ? AND user_id <> ? AND state = ANY(CAST(? AS text[]))'
        );
        $stmt->execute([$sessionId, $except, $onlyTakingPart ? '{accepted}' : '{accepted,invited}']);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function name(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT coalesce(display_name, username) FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }
}
