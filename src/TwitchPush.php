<?php
declare(strict_types=1);

/**
 * Turns a planned stream into a channel update for Twitch.
 *
 * Title: the content title as written, whitespace collapsed to one line.
 * Category: the Twitch category of the stream's first game. A game still
 *   on the Just Chatting stand-in is looked up once more, an exact title
 *   match being remembered on the (shared) game; a category the user
 *   picked by hand is remembered for that user only. No game leaves it as
 *   it is.
 * Tags: only sponsor hashtags — a #Tag in the title that names a key site
 *   marked "tag in content titles". The channel's other tags are kept; any
 *   earlier sponsor tag is replaced, so sponsors do not pile up over time.
 *
 * plan() only reads; push() applies the same plan.
 */
final class TwitchPush
{
    /**
     * @return array<string,mixed> everything the confirmation screen shows
     * @throws UserError with a user-facing message
     */
    public static function plan(int $userId, int $streamId, ?string $pickedCategory = null): array
    {
        $stream = self::stream($userId, $streamId);

        if ($stream['platform_code'] !== 'twitch') {
            throw new UserError(__('ui.message.twitch_not_twitch'));
        }

        $connection = TwitchUser::connection($userId);

        if ($connection === null) {
            throw new UserError(__('ui.message.twitch_not_connected'));
        }

        $channel = TwitchUser::channel($userId);

        if ($channel === null) {
            throw new UserError(self::failure());
        }

        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $stream['title']));

        $category = ['state' => 'keep', 'id' => null, 'name' => $channel['game_name'], 'candidates' => [], 'save' => false];

        if ($stream['stream_category_id'] !== null && $pickedCategory !== 'keep') {
            $category = ['state' => 'set', 'id' => $stream['stream_category_id'], 'name' => $stream['stream_category_name'], 'candidates' => [], 'save' => false];
        } elseif ($stream['game_id'] !== null && $pickedCategory !== 'keep') {
            if ($pickedCategory !== null && $pickedCategory !== '') {
                $picked = TwitchUser::category($userId, $pickedCategory);

                if ($picked === null) {
                    throw new UserError(__('ui.message.invalid_input'));
                }

                $category = ['state' => 'set', 'id' => $picked['id'], 'name' => $picked['name'], 'candidates' => [], 'save' => 'user'];
            } elseif ($stream['user_category_id'] !== null) {
                $category = ['state' => 'set', 'id' => $stream['user_category_id'], 'name' => $stream['user_category_name'], 'candidates' => [], 'save' => false];
            } elseif ($stream['twitch_category_source'] !== TwitchCategories::DEFAULT_SOURCE) {
                $category = ['state' => 'set', 'id' => $stream['twitch_category_id'], 'name' => $stream['twitch_category_name'], 'candidates' => [], 'save' => false];
            } else {
                $found = TwitchUser::findCategory($userId, (string) $stream['game_title']);

                $category = $found['exact'] !== null
                    ? ['state' => 'set', 'id' => $found['exact']['id'], 'name' => $found['exact']['name'], 'candidates' => [], 'save' => 'game']
                    : ['state' => 'set', 'id' => $stream['twitch_category_id'], 'name' => $stream['twitch_category_name'], 'candidates' => [], 'save' => false];
            }
        }

        $sponsorKeys = self::sponsorKeys();
        $sponsorTags = [];

        preg_match_all('/(?<!\S)#([\p{L}\p{N}_]+)/u', $title, $m);

        foreach ($m[1] as $hashtag) {
            if (isset($sponsorKeys[self::normalise($hashtag)])) {
                $tag = mb_substr((string) preg_replace('/[^\p{L}\p{N}]/u', '', $hashtag), 0, TwitchUser::TAG_MAX);

                if ($tag !== '') {
                    $sponsorTags[mb_strtolower($tag)] = $tag;
                }
            }
        }

        $kept = [];

        foreach ($channel['tags'] as $tag) {
            if (!isset($sponsorKeys[self::normalise($tag)]) && !isset($sponsorTags[mb_strtolower($tag)])) {
                $kept[] = $tag;
            }
        }

        $result  = array_slice(array_merge(array_values($sponsorTags), $kept), 0, TwitchUser::TAGS_MAX);
        $lowered = static fn (array $tags): array => array_map('mb_strtolower', $tags);

        return [
            'stream_id'     => $streamId,
            'game_id'       => $stream['game_id'] === null ? null : (int) $stream['game_id'],
            'game_title'    => $stream['game_title'],
            'login'         => $connection['twitch_login'],
            'title'         => $title,
            'title_length'  => mb_strlen($title),
            'title_max'     => TwitchUser::TITLE_MAX,
            'too_long'      => mb_strlen($title) > TwitchUser::TITLE_MAX,
            'current_title' => $channel['title'],
            'current_category' => $channel['game_name'],
            'category'      => $category,
            'tags'          => [
                'current' => $channel['tags'],
                'result'  => $result,
                'added'   => array_values(array_filter($result, static fn ($t) => !in_array(mb_strtolower($t), $lowered($channel['tags']), true))),
                'removed' => array_values(array_filter($channel['tags'], static fn ($t) => !in_array(mb_strtolower($t), $lowered($result), true))),
            ],
        ];
    }

    /**
     * Applies the plan to the channel. Planned content becomes live, with
     * its actual start set to now unless it already had one: sending the
     * title is what a streamer does as they go on air.
     *
     * @throws UserError with a user-facing message
     */
    public static function push(int $userId, int $streamId, ?string $pickedCategory): array
    {
        $plan = self::plan($userId, $streamId, $pickedCategory);

        if ($plan['too_long']) {
            throw new UserError(sprintf(__('ui.message.twitch_title_too_long'), $plan['title_length'], $plan['title_max']));
        }

        if ($plan['category']['state'] === 'choose') {
            throw new UserError(__('ui.message.twitch_pick_category'));
        }

        $categoryId = $plan['category']['state'] === 'set' ? (string) $plan['category']['id'] : null;
        $tags       = $plan['tags']['result'] === $plan['tags']['current'] ? null : $plan['tags']['result'];

        if (!TwitchUser::updateChannel($userId, $plan['title'], $categoryId, $tags)) {
            throw new UserError(self::failure());
        }

        $pdo = Database::connection();

        if ($plan['category']['save'] === 'game' && $plan['game_id'] !== null) {
            TwitchCategories::store($pdo, $plan['game_id'], ['id' => $plan['category']['id'], 'name' => $plan['category']['name']], 'twitch');
        } elseif ($plan['category']['save'] === 'user' && $plan['game_id'] !== null) {
            $pdo->prepare(
                'INSERT INTO user_twitch_categories (user_id, game_id, category_id, category_name)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (user_id, game_id) DO UPDATE
                    SET category_id = EXCLUDED.category_id, category_name = EXCLUDED.category_name, updated_at = now()'
            )->execute([$userId, $plan['game_id'], $plan['category']['id'], $plan['category']['name']]);
        }

        $stmt = $pdo->prepare(
            "UPDATE streams
                SET twitch_pushed_at = now(),
                    actual_start = CASE WHEN status = 'planned' THEN coalesce(actual_start, now()) ELSE actual_start END,
                    status = CASE WHEN status = 'planned' THEN 'live' ELSE status END
              WHERE id = ? AND user_id = ?
          RETURNING status"
        );
        $stmt->execute([$streamId, $userId]);

        return $plan + ['status' => (string) $stmt->fetchColumn()];
    }

    /** @return array<string,mixed> */
    private static function stream(int $userId, int $streamId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.id, s.title, sp.code AS platform_code,
                    s.category_id AS stream_category_id, s.category_name AS stream_category_name,
                    g.id AS game_id, g.title AS game_title,
                    g.twitch_category_id, g.twitch_category_name, g.twitch_category_source,
                    uc.category_id AS user_category_id, uc.category_name AS user_category_name
               FROM streams s
               JOIN streaming_platforms sp ON sp.id = s.streaming_platform_id
          LEFT JOIN LATERAL (
                  SELECT g.* FROM stream_games sg JOIN games g ON g.id = sg.game_id
                   WHERE sg.stream_id = s.id
                ORDER BY sg.play_order, g.id LIMIT 1
              ) g ON true
          LEFT JOIN user_twitch_categories uc ON uc.user_id = s.user_id AND uc.game_id = g.id
              WHERE s.id = ? AND s.user_id = ?'
        );
        $stmt->execute([$streamId, $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new UserError(__('ui.message.not_found'));
        }

        return $row;
    }

    /**
     * Normalised names of every creditable key site, by code and by its
     * label in each locale, so #Keymailer matches however it is spelled.
     *
     * @return array<string,true>
     */
    private static function sponsorKeys(): array
    {
        $codes = Database::connection()
            ->query('SELECT code FROM key_platforms WHERE tags_content')
            ->fetchAll(PDO::FETCH_COLUMN);

        $keys = [];

        foreach ($codes as $code) {
            $keys[self::normalise((string) $code)] = true;
            $keys[self::normalise(code_label('key_platform', (string) $code))] = true;
        }

        unset($keys['']);

        return $keys;
    }

    /** "Press Engine", "#PressEngine", "press_engine" -> "pressengine" */
    private static function normalise(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $ascii === false ? $value : $ascii));
    }

    /** Turns TwitchUser's last error into something the user can act on. */
    private static function failure(): string
    {
        $error = TwitchUser::lastError();

        if ($error !== null && !in_array($error, ['not_connected', 'reconnect'], true)) {
            ErrorLog::note('Twitch: ' . $error);
        }

        return match ($error) {
            'not_connected' => __('ui.message.twitch_not_connected'),
            'reconnect'     => __('ui.message.twitch_reconnect'),
            null            => __('ui.message.provider_error'),
            default         => __('ui.message.twitch_failed'),
        };
    }
}
