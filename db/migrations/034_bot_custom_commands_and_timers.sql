-- =====================================================================
-- A streamer's own chat bot commands and timed messages.
--
-- bot_custom_commands   commands a streamer writes from scratch (the
--                       built-in ones stay in bot_commands): a trigger and
--                       a reply, who may use it, a cooldown, on/off.
-- bot_timers            messages the bot posts in the streamer's chat
--                       while they are live: at most every
--                       interval_minutes, and only once min_messages chat
--                       messages were sent since its last post, so it
--                       never talks into an empty chat. last_sent_at is
--                       written by the bot, so a restart does not repeat
--                       one early.
--
-- Also removes the broadcasts recorded so far in channels where the bot
-- could not read chat: they hold no viewer statistics, and the bot now
-- only records channels it can read.
-- =====================================================================

CREATE TABLE bot_custom_commands (
    id               bigserial   PRIMARY KEY,
    user_id          bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    trigger          text        NOT NULL CHECK (trigger ~ '^[a-z0-9_]{1,25}$'),
    response         text        NOT NULL CHECK (length(response) BETWEEN 1 AND 450),
    is_enabled       boolean     NOT NULL DEFAULT true,
    permission       text        NOT NULL DEFAULT 'everyone'
                                 CHECK (permission IN ('everyone', 'subscriber', 'vip', 'moderator', 'broadcaster')),
    cooldown_seconds integer     NOT NULL DEFAULT 10 CHECK (cooldown_seconds BETWEEN 0 AND 3600),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, trigger)
);

CREATE TABLE bot_timers (
    id               bigserial   PRIMARY KEY,
    user_id          bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    message          text        NOT NULL CHECK (length(message) BETWEEN 1 AND 450),
    interval_minutes integer     NOT NULL DEFAULT 15 CHECK (interval_minutes BETWEEN 5 AND 1440),
    min_messages     integer     NOT NULL DEFAULT 5 CHECK (min_messages BETWEEN 0 AND 1000),
    is_enabled       boolean     NOT NULL DEFAULT true,
    last_sent_at     timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX bot_timers_user ON bot_timers (user_id);

DELETE FROM twitch_broadcasts b
 WHERE NOT EXISTS (SELECT 1 FROM chat_viewer_stats s WHERE s.broadcast_id = b.id)
   AND NOT EXISTS (SELECT 1 FROM bot_channels c WHERE c.user_id = b.user_id AND c.access IN ('permission', 'moderator'));
