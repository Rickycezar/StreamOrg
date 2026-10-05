-- =====================================================================
-- How long content is planned to run, title prefixes, and the content
-- sent to the Twitch channel schedule.
--
-- users.content_minutes is how long the streamer usually gives sponsored
-- content; streams.planned_minutes overrides it for one item (resized on
-- the calendar). Title prefixes ("[STEAM DECK]") are offered when writing
-- a title, one of them preselected.
--
-- twitch_schedule_segments remembers the Twitch schedule segments
-- StreamOrg created, so a later send updates or removes them; the content
-- may be gone by then, hence SET NULL.
-- =====================================================================

ALTER TABLE users ADD COLUMN content_minutes integer NOT NULL DEFAULT 120
    CONSTRAINT users_content_minutes_check CHECK (content_minutes BETWEEN 15 AND 1440);

ALTER TABLE streams ADD COLUMN planned_minutes integer
    CONSTRAINT streams_planned_minutes_check CHECK (planned_minutes BETWEEN 15 AND 1440);

CREATE TABLE user_title_prefixes (
    id         bigserial   PRIMARY KEY,
    user_id    bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    prefix     text        NOT NULL CHECK (length(trim(prefix)) BETWEEN 1 AND 60),
    is_default boolean     NOT NULL DEFAULT false,
    position   integer     NOT NULL DEFAULT 0,
    UNIQUE (user_id, prefix)
);

CREATE UNIQUE INDEX user_title_prefixes_one_default
    ON user_title_prefixes (user_id) WHERE is_default;

CREATE TABLE twitch_schedule_segments (
    user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    segment_id  text        NOT NULL,
    stream_id   bigint      REFERENCES streams (id) ON DELETE SET NULL,
    fingerprint text        NOT NULL,
    synced_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, segment_id)
);

CREATE UNIQUE INDEX twitch_schedule_segments_stream ON twitch_schedule_segments (stream_id) WHERE stream_id IS NOT NULL;
