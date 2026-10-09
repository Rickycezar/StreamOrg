-- =====================================================================
-- Overlays: pages a streamer adds to OBS as browser sources, and the
-- media they use.
--
-- media_assets      sounds and images, each a user's own or shared with
--                   everyone (user_id NULL, managed by administrators).
--                   Files live in public/media/library/ under random
--                   names. A sound can be a sprite: segments names parts
--                   of the file ({key, name, start, duration} in
--                   seconds), each played on its own.
-- overlays          one per browser source: its type (alert, shoutout…),
--                   a name, its settings (checked against the type) and
--                   a version that changes on every save, so open
--                   overlays know to reload them. Its key is secret: the
--                   database keeps a hash to find it and an encrypted copy
--                   so the owner can copy the link again. In advanced
--                   mode the owner writes the settings as text (key =
--                   value lines, [blocks], # comments; kept as written in
--                   config_text) and may add CSS of their own. Lookups
--                   (a shoutout's channel details) are counted per ten
--                   minutes so a leaked link cannot drain the Twitch quota.
-- overlay_signals   things open overlays must react to (a test from the
--                   settings page, new settings): overlays fetch the ones
--                   they have not seen, and each is also sent as a
--                   NOTIFY on streamorg_overlay for a live connection.
--                   Kept for an hour.
-- twitch_lookups    channel details overlays asked for (a shoutout's
--                   picture, category, title), kept ten minutes so
--                   repeated shoutouts do not call Twitch again.
--
-- Global settings (who may use overlays, limits, addresses) are in
-- app_settings under overlay.* and media.*.
-- =====================================================================

CREATE TABLE media_assets (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     bigint      REFERENCES users (id) ON DELETE CASCADE,
    kind        text        NOT NULL CHECK (kind IN ('sound', 'image')),
    name        text        NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    path        text        NOT NULL UNIQUE,
    mime        text        NOT NULL,
    bytes       integer     NOT NULL,
    width       integer,
    height      integer,
    duration    numeric(8, 3),
    segments    jsonb       NOT NULL DEFAULT '[]'::jsonb,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX media_assets_owner ON media_assets (user_id, kind, created_at DESC);

CREATE TABLE overlays (
    id           bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id      bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    type         text        NOT NULL,
    name         text        NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    key_hash     text        NOT NULL UNIQUE,
    key_secret   text        NOT NULL,
    settings     jsonb       NOT NULL DEFAULT '{}'::jsonb,
    version      integer     NOT NULL DEFAULT 1,
    is_enabled   boolean     NOT NULL DEFAULT true,
    advanced     boolean     NOT NULL DEFAULT false,
    config_text  text        NOT NULL DEFAULT '',
    custom_css   text        NOT NULL DEFAULT '',
    lookups      integer     NOT NULL DEFAULT 0,
    lookups_from timestamptz,
    last_seen_at timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX overlays_owner ON overlays (user_id, created_at);

CREATE TABLE overlay_signals (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    overlay_id  bigint      NOT NULL REFERENCES overlays (id) ON DELETE CASCADE,
    kind        text        NOT NULL CHECK (kind IN ('test', 'settings', 'reload')),
    payload     jsonb       NOT NULL DEFAULT '{}'::jsonb,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX overlay_signals_overlay ON overlay_signals (overlay_id, id);

CREATE TABLE twitch_lookups (
    login       text        PRIMARY KEY,
    data        jsonb       NOT NULL,
    fetched_at  timestamptz NOT NULL DEFAULT now()
);
