-- =====================================================================
-- Twitch's chat badges, as the chat overlay shows them: the global set
-- (scope 'global') and each channel's own (scope = the channel's Twitch
-- id), kept for a few hours so overlays do not ask Twitch every time.
-- data: {"set_id/version": image URL}.
-- =====================================================================

CREATE TABLE twitch_badge_cache (
    scope      text        PRIMARY KEY,
    data       jsonb       NOT NULL,
    fetched_at timestamptz NOT NULL DEFAULT now()
);
