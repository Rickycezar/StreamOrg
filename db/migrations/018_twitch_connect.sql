-- =====================================================================
-- Twitch as the only streaming platform for now, and pushing a planned
-- stream's title, category and sponsor tags to the user's channel.
-- =====================================================================

-- Platforms can be switched off without deleting them or anything that
-- references them. Only enabled ones are offered in forms and accepted on
-- save; existing rows on a disabled platform still display.
ALTER TABLE streaming_platforms
    ADD COLUMN is_enabled boolean NOT NULL DEFAULT true;

UPDATE streaming_platforms SET is_enabled = (code = 'twitch');

-- Twitch identifies categories by its own id, not by name. Resolved the
-- first time a game is sent, then reused.
ALTER TABLE games
    ADD COLUMN twitch_category_id   text,
    ADD COLUMN twitch_category_name text;

-- A user's authorisation for StreamOrg to edit their channel
-- (scope channel:manage:broadcast). Tokens are encrypted with the
-- application key, like the other API credentials.
CREATE TABLE twitch_connections (
    user_id        bigint      PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    twitch_user_id text        NOT NULL,
    twitch_login   text        NOT NULL,
    access_token   text        NOT NULL,
    refresh_token  text        NOT NULL,
    expires_at     timestamptz NOT NULL,
    scopes         text        NOT NULL,
    connected_at   timestamptz NOT NULL DEFAULT now()
);

-- When this stream's details were last sent to the channel.
ALTER TABLE streams
    ADD COLUMN twitch_pushed_at timestamptz;
