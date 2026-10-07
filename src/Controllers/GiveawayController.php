<?php
declare(strict_types=1);

/**
 * Sponsorships → Giveaways: named giveaways with a keyword for the chat
 * bot, their prizes (keys copied from the vault) and their winners.
 * The logic lives in Giveaways; this is the streamer's screen for it.
 */
final class GiveawayController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $stmt = Database::connection()->prepare(
            "SELECT g.*, s.title AS content_title,
                    (SELECT count(*) FROM giveaway_prizes p WHERE p.giveaway_id = g.id) AS prize_count,
                    (SELECT count(*) FROM giveaway_prizes p WHERE p.giveaway_id = g.id AND p.claimed_at IS NOT NULL) AS claimed_count,
                    (SELECT count(*) FROM giveaway_winners w WHERE w.giveaway_id = g.id AND w.cancelled_at IS NULL) AS winner_count,
                    (SELECT count(*) FROM giveaway_entries e WHERE e.giveaway_id = g.id) AS entry_count
               FROM giveaways g
          LEFT JOIN streams s ON s.id = g.stream_id
              WHERE g.user_id = ?
           ORDER BY CASE g.status WHEN 'open' THEN 0 WHEN 'closed' THEN 1 WHEN 'planned' THEN 2 ELSE 3 END,
                    coalesce(g.ends_at, g.starts_at, g.created_at) DESC"
        );
        $stmt->execute([Auth::id()]);

        View::render('giveaways/index', [
            'giveaways' => $stmt->fetchAll(),
            'content'   => self::contentOptions(),
        ], __('ui.nav.giveaways'));
    }

    public static function show(): void
    {
        Auth::requireLogin();

        $userId   = (int) Auth::id();
        $giveaway = Giveaways::find($userId, (int) ($_GET['id'] ?? 0));

        if ($giveaway === null) {
            flash('error', __('ui.message.not_found'));
            redirect('/giveaways');
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT p.id, p.claimed_at, p.winner_id, k.id AS key_id, g.title AS game_title, gp.code AS platform_code,
                    k.expires_at, w.twitch_login AS winner_login, (p.sealed_code IS NOT NULL) AS ready
               FROM giveaway_prizes p
               JOIN game_keys k ON k.id = p.game_key_id
               JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
          LEFT JOIN giveaway_winners w ON w.id = p.winner_id
              WHERE p.giveaway_id = ?
           ORDER BY p.claimed_at NULLS FIRST, g.title, p.id'
        );
        $stmt->execute([$giveaway['id']]);
        $prizes = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT k.id, g.title, gp.code AS platform_code, k.expires_at, k.is_placeholder
               FROM game_keys k
               JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
              WHERE k.user_id = ? AND k.status = 'for_giveaway'
                AND NOT EXISTS (SELECT 1 FROM giveaway_prizes p WHERE p.game_key_id = k.id)
           ORDER BY g.title, k.id"
        );
        $stmt->execute([$userId]);
        $available = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT w.*, p.id AS prize_id, g.title AS prize_title
               FROM giveaway_winners w
          LEFT JOIN giveaway_prizes p ON p.winner_id = w.id
          LEFT JOIN game_keys k ON k.id = p.game_key_id
          LEFT JOIN games g ON g.id = k.game_id
              WHERE w.giveaway_id = ?
           ORDER BY w.created_at DESC'
        );
        $stmt->execute([$giveaway['id']]);
        $locale  = (string) (Auth::user()['locale'] ?? '');
        $winners = array_map(static function (array $w) use ($locale): array {
            $token     = Giveaways::tokenOf($w);
            $w['link'] = $token !== null ? Giveaways::claimUrl($token, $locale) : null;
            $w['state'] = PrizeRemovals::stateOf($w);
            unset($w['claim_token'], $w['claim_token_hash']);

            return $w;
        }, $stmt->fetchAll());

        $stmt = $pdo->prepare('SELECT twitch_login, display_name, entered_at FROM giveaway_entries WHERE giveaway_id = ? ORDER BY entered_at DESC LIMIT 200');
        $stmt->execute([$giveaway['id']]);
        $entries = $stmt->fetchAll();

        View::render('giveaways/show', [
            'giveaway'    => $giveaway,
            'prizes'      => $prizes,
            'available'   => $available,
            'winners'     => $winners,
            'entries'     => $entries,
            'content'     => self::contentOptions(),
            'vaultLocked' => !Vault::isUnlocked($userId),
            'needsCopies' => Giveaways::needsCopies($userId),
            'waitingWinners' => $waitingWinners = Giveaways::waitingWinners((int) $giveaway['id']),
            'nudge'       => Giveaways::needsCopies($userId) && $waitingWinners['locked'] > 0
                && ($_SESSION['giveaway_nudge_later'][(int) $giveaway['id']] ?? -1) < $waitingWinners['locked'],
            'takeBackWarn' => !empty($_SESSION['giveaway_takeback_warn'][(int) $giveaway['id']]) && $waitingWinners['waiting'] > 0,
            'twitchReady' => Twitch::isConfigured(),
        ], $giveaway['title']);
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $input = self::input('/giveaways');

        $stmt = Database::connection()->prepare(
            'INSERT INTO giveaways (user_id, title, keyword, rules_note, starts_at, ends_at, is_surprise,
                                    winner_mode, entry_method, claim_days, stream_id, notes, streaming_platform_id)
             VALUES (:user, :title, :keyword, :rules, :starts, :ends, :surprise, :mode, :method, :days, :stream, :notes,
                     (SELECT id FROM streaming_platforms WHERE code = \'twitch\'))
             RETURNING id'
        );
        $stmt->execute(['user' => Auth::id()] + $input);

        flash('success', __('ui.message.giveaway_created'));
        redirect('/giveaways/show?id=' . (int) $stmt->fetchColumn());
    }

    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $back     = '/giveaways/show?id=' . (int) $giveaway['id'];
        $input    = self::input($back, (int) $giveaway['id']);

        Database::connection()->prepare(
            'UPDATE giveaways SET title = :title, keyword = :keyword, rules_note = :rules, starts_at = :starts,
                    ends_at = :ends, is_surprise = :surprise, winner_mode = :mode, entry_method = :method,
                    claim_days = :days, stream_id = :stream, notes = :notes, updated_at = now()
              WHERE id = :id'
        )->execute(['id' => $giveaway['id']] + $input);

        flash('success', __('ui.message.saved'));
        redirect($back);
    }

    /** Which status may follow which: closing ends entries, finishing (or cancelling) concludes. */
    public const NEXT = [
        'planned'   => ['open', 'cancelled'],
        'open'      => ['closed', 'cancelled'],
        'closed'    => ['open', 'finished'],
        'finished'  => [],
        'cancelled' => ['planned'],
    ];

    /**
     * Moves a giveaway along. Finishing or cancelling concludes it: unused
     * links stop working and unclaimed keys go back to the vault.
     */
    public static function status(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $status   = (string) ($_POST['status'] ?? '');
        $back     = '/giveaways/show?id=' . (int) $giveaway['id'];

        if (!in_array($status, self::NEXT[$giveaway['status']] ?? [], true)) {
            flash('error', __('ui.message.invalid_input'));
            redirect($back);
        }

        if (in_array($status, Giveaways::ENDED, true)) {
            $returned = Giveaways::finish((int) Auth::id(), (int) $giveaway['id'], $status);
            flash('success', sprintf(__('ui.message.giveaway_status'), code_label('giveaway_status', $status))
                . ($returned > 0 ? ' ' . sprintf(__('ui.message.giveaway_keys_returned'), $returned) : ''));
            redirect($back);
        }

        Database::connection()->prepare('UPDATE giveaways SET status = ?, updated_at = now() WHERE id = ?')
            ->execute([$status, $giveaway['id']]);

        flash('success', sprintf(__('ui.message.giveaway_status'), code_label('giveaway_status', $status)));
        redirect($back);
    }

    /** Private vaults: copies the prizes so winners can claim them. */
    public static function makeClaimable(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $back     = '/giveaways/show?id=' . (int) $giveaway['id'];

        try {
            $count = Giveaways::makeClaimable((int) Auth::id(), (int) $giveaway['id']);
        } catch (VaultLocked) {
            flash('error', __('ui.message.vault_locked'));
            redirect($back);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        flash('success', sprintf(__('ui.message.giveaway_made_claimable'), $count));
        redirect($back);
    }

    /**
     * Private vaults: deletes the copies of the unclaimed prizes. With
     * winners still waiting, it first asks — they would have to wait.
     */
    public static function takeBack(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $id       = (int) $giveaway['id'];

        if (empty($_POST['confirm']) && Giveaways::waitingWinners($id)['waiting'] > 0) {
            $_SESSION['giveaway_takeback_warn'][$id] = true;
            redirect('/giveaways/show?id=' . $id);
        }

        unset($_SESSION['giveaway_takeback_warn'][$id]);
        $count = Giveaways::takeBack((int) Auth::id(), $id);

        flash('success', sprintf(__('ui.message.giveaway_taken_back'), $count));
        redirect('/giveaways/show?id=' . (int) $giveaway['id']);
    }

    /** Deletes a giveaway with no claimed prizes; its keys stay in the vault. */
    public static function delete(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $pdo      = Database::connection();

        $stmt = $pdo->prepare('SELECT count(*) FROM giveaway_prizes WHERE giveaway_id = ? AND claimed_at IS NOT NULL');
        $stmt->execute([$giveaway['id']]);

        if ((int) $stmt->fetchColumn() > 0) {
            flash('error', __('ui.message.giveaway_has_claims'));
            redirect('/giveaways/show?id=' . (int) $giveaway['id']);
        }

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM giveaway_prizes WHERE giveaway_id = ?')->execute([$giveaway['id']]);
        $pdo->prepare('DELETE FROM giveaways WHERE id = ?')->execute([$giveaway['id']]);
        $pdo->commit();

        flash('success', __('ui.message.deleted'));
        redirect('/giveaways');
    }

    public static function addPrizes(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $back     = '/giveaways/show?id=' . (int) $giveaway['id'];
        $keyIds   = array_filter(array_map('intval', (array) ($_POST['keys'] ?? [])));

        if ($keyIds === []) {
            flash('error', __('ui.message.giveaway_pick_keys'));
            redirect($back);
        }

        try {
            $result = Giveaways::addPrizes((int) Auth::id(), (int) $giveaway['id'], $keyIds);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        flash('success', sprintf(__('ui.message.giveaway_prizes_added'), $result['added'])
            . ($result['skipped'] !== [] ? ' ' . sprintf(__('ui.message.giveaway_prizes_skipped'), implode(', ', $result['skipped'])) : ''));
        redirect($back);
    }

    /** "I'll do it in a bit": hides the locked-keys nudge until another winner is waiting. */
    public static function nudgeLater(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $id       = (int) $giveaway['id'];
        $_SESSION['giveaway_nudge_later'][$id] = Giveaways::waitingWinners($id)['locked'];

        redirect('/giveaways/show?id=' . $id);
    }

    /** "Let them unwrap first": keeps the keys open after all. */
    public static function keepOpen(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        unset($_SESSION['giveaway_takeback_warn'][(int) $giveaway['id']]);

        flash('success', __('ui.message.giveaway_kept_open'));
        redirect('/giveaways/show?id=' . (int) $giveaway['id']);
    }

    public static function removePrize(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $ok = Giveaways::removePrize((int) Auth::id(), (int) ($_POST['prize_id'] ?? 0));

        flash($ok ? 'success' : 'error', __($ok ? 'ui.message.giveaway_prize_removed' : 'ui.message.not_found'));
        redirect('/giveaways/show?id=' . (int) $giveaway['id']);
    }

    /** Records a winner by Twitch name (external result or picked by hand). */
    public static function addWinner(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $back     = '/giveaways/show?id=' . (int) $giveaway['id'];
        $prizeId  = filter_input(INPUT_POST, 'prize_id', FILTER_VALIDATE_INT) ?: null;

        try {
            Giveaways::addWinner((int) Auth::id(), (int) $giveaway['id'], (string) ($_POST['login'] ?? ''),
                (string) ($_POST['method'] ?? 'manual'), $prizeId);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        flash('success', __('ui.message.winner_added'));
        redirect($back);
    }

    /** Draws a winner among the chat entries. */
    public static function draw(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $back     = '/giveaways/show?id=' . (int) $giveaway['id'];

        try {
            $result = Giveaways::draw((int) Auth::id(), (int) $giveaway['id']);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }

        flash('success', sprintf(__('ui.message.winner_drawn'), $result['login']));
        redirect($back);
    }

    public static function cancelWinner(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $ok = Giveaways::cancelWinner((int) Auth::id(), (int) ($_POST['winner_id'] ?? 0));

        flash($ok ? 'success' : 'error', __($ok ? 'ui.message.winner_cancelled' : 'ui.message.not_found'));
        redirect('/giveaways/show?id=' . (int) $giveaway['id']);
    }

    public static function extendWinner(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $giveaway = self::posted();
        $ok = Giveaways::extendWinner((int) Auth::id(), (int) ($_POST['winner_id'] ?? 0));

        flash($ok ? 'success' : 'error', __($ok ? 'ui.message.winner_extended' : 'ui.message.not_found'));
        redirect('/giveaways/show?id=' . (int) $giveaway['id']);
    }

    /** The giveaway named by the posted id, or back to the list. */
    private static function posted(): array
    {
        $giveaway = Giveaways::find((int) Auth::id(), (int) ($_POST['id'] ?? 0));

        if ($giveaway === null) {
            flash('error', __('ui.message.not_found'));
            redirect('/giveaways');
        }

        return $giveaway;
    }

    /**
     * Reads and checks the giveaway form; redirects back with a message on
     * a problem.
     *
     * @return array<string, mixed> parameters for the INSERT / UPDATE
     */
    private static function input(string $back, ?int $giveawayId = null): array
    {
        $title   = trim((string) ($_POST['title'] ?? ''));
        $keyword = strtolower(trim((string) ($_POST['keyword'] ?? '')));
        $starts  = trim((string) ($_POST['starts_at'] ?? '')) ?: null;
        $ends    = trim((string) ($_POST['ends_at'] ?? '')) ?: null;
        $days    = filter_input(INPUT_POST, 'claim_days', FILTER_VALIDATE_INT);
        $stream  = filter_input(INPUT_POST, 'stream_id', FILTER_VALIDATE_INT) ?: null;

        $error = match (true) {
            $title === '' || mb_strlen($title) > 120                       => 'ui.message.giveaway_bad_title',
            !preg_match('/^[a-z0-9_]{2,20}$/', $keyword)                     => 'ui.message.giveaway_bad_keyword',
            $starts !== null && $ends !== null && strtotime($ends) < strtotime($starts) => 'ui.message.giveaway_bad_dates',
            !is_int($days) || $days < 1 || $days > 365                       => 'ui.message.giveaway_bad_days',
            !in_array($_POST['winner_mode'] ?? '', Giveaways::WINNER_MODES, true),
            !in_array($_POST['entry_method'] ?? '', Giveaways::ENTRY_METHODS, true) => 'ui.message.invalid_input',
            default                                                          => null,
        };

        $pdo = Database::connection();

        if ($error === null) {
            $stmt = $pdo->prepare('SELECT 1 FROM giveaways WHERE user_id = ? AND keyword = ? AND id IS DISTINCT FROM ?');
            $stmt->execute([Auth::id(), $keyword, $giveawayId]);

            if ($stmt->fetchColumn()) {
                $error = 'ui.message.giveaway_keyword_taken';
            }
        }

        if ($error === null && $stream !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM streams WHERE id = ? AND user_id = ?');
            $stmt->execute([$stream, Auth::id()]);
            $stream = $stmt->fetchColumn() ? $stream : null;
        }

        if ($error !== null) {
            flash('error', __($error));
            redirect($back);
        }

        return [
            'title'    => $title,
            'keyword'  => $keyword,
            'rules'    => trim((string) ($_POST['rules_note'] ?? '')) ?: null,
            'starts'   => $starts,
            'ends'     => $ends,
            'surprise' => !empty($_POST['is_surprise']) ? 'true' : 'false',
            'mode'     => $_POST['winner_mode'],
            'method'   => $_POST['entry_method'],
            'days'     => $days,
            'stream'   => $stream,
            'notes'    => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
    }

    /** @return list<array{id:int, title:string, scheduled_start:?string}> planned and live content to link to */
    private static function contentOptions(): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, title, scheduled_start FROM streams
              WHERE user_id = ? AND status IN ('planned', 'live')
           ORDER BY scheduled_start NULLS LAST, id DESC LIMIT 200"
        );
        $stmt->execute([Auth::id()]);

        return $stmt->fetchAll();
    }
}
