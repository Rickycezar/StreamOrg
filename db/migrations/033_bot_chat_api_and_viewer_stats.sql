-- =====================================================================
-- The chat bot moves to Twitch's chat API (EventSub through a conduit,
-- replies sent with the app token, so it shows as a chat bot), and
-- records simple viewer statistics while a channel is live.
--
-- bot_account.conduit_id      the EventSub conduit the bot's socket serves.
-- bot_channels.access         how the bot may read the channel's chat:
--                             'permission' (the streamer granted channel:bot),
--                             'moderator' (the bot is a moderator there) or
--                             'none'; chat_subscription_id is the EventSub
--                             subscription; chatters_ok whether the chatter
--                             list (watch time) could be read last time.
--
-- twitch_broadcasts           one row per live broadcast the bot saw.
-- chat_viewers                Twitch viewers seen in a recorded chat.
-- chat_viewer_stats           per broadcast, viewer, live content (if any)
--                             and category: messages sent and seconds seen
--                             in the chatter list. Rows are added up, never
--                             one per message. stream_id names the content
--                             that was live; it is kept as it was even if
--                             that content is deleted later (no foreign key,
--                             which would merge rows on delete).
-- =====================================================================

ALTER TABLE bot_account ADD COLUMN conduit_id text;

ALTER TABLE bot_channels
    ADD COLUMN access               text NOT NULL DEFAULT 'unknown'
                                    CHECK (access IN ('unknown', 'permission', 'moderator', 'none')),
    ADD COLUMN access_checked_at    timestamptz,
    ADD COLUMN chat_subscription_id text,
    ADD COLUMN chatters_ok          boolean;

CREATE TABLE twitch_broadcasts (
    id               bigserial   PRIMARY KEY,
    user_id          bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    twitch_stream_id text        NOT NULL,
    started_at       timestamptz NOT NULL,
    ended_at         timestamptz,
    last_seen_at     timestamptz NOT NULL DEFAULT now(),
    title            text,
    peak_viewers     integer     NOT NULL DEFAULT 0,
    viewer_samples   integer     NOT NULL DEFAULT 0,
    viewer_total     bigint      NOT NULL DEFAULT 0,
    UNIQUE (user_id, twitch_stream_id)
);

CREATE INDEX twitch_broadcasts_open ON twitch_broadcasts (user_id) WHERE ended_at IS NULL;
CREATE INDEX twitch_broadcasts_started ON twitch_broadcasts (started_at);

CREATE TABLE chat_viewers (
    twitch_user_id text        PRIMARY KEY,
    login          text        NOT NULL,
    display_name   text,
    updated_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE chat_viewer_stats (
    id             bigserial   PRIMARY KEY,
    broadcast_id   bigint      NOT NULL REFERENCES twitch_broadcasts (id) ON DELETE CASCADE,
    user_id        bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    stream_id      bigint,
    category_id    text        NOT NULL DEFAULT '',
    category_name  text,
    viewer_id      text        NOT NULL REFERENCES chat_viewers (twitch_user_id) ON DELETE CASCADE,
    messages       integer     NOT NULL DEFAULT 0,
    watch_seconds  integer     NOT NULL DEFAULT 0,
    first_seen_at  timestamptz NOT NULL DEFAULT now(),
    last_seen_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE NULLS NOT DISTINCT (broadcast_id, viewer_id, stream_id, category_id)
);

CREATE INDEX chat_viewer_stats_user ON chat_viewer_stats (user_id, viewer_id);
CREATE INDEX chat_viewer_stats_stream ON chat_viewer_stats (stream_id) WHERE stream_id IS NOT NULL;
