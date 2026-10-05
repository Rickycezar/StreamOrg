-- =====================================================================
-- Every game has a Twitch category. Several games may share one, and a
-- game Twitch does not list streams as Just Chatting.
--
-- twitch_category_source says where the category came from:
--   twitch  — Twitch's own listing (picked when adding the game, or found
--             by IGDB id, exact name or a close-enough search);
--   manual  — chosen by an admin;
--   default — the Just Chatting stand-in, looked up again later in case
--             Twitch adds the game.
-- =====================================================================

ALTER TABLE games ADD COLUMN twitch_category_source text;

UPDATE games SET twitch_category_source = 'twitch' WHERE twitch_category_id IS NOT NULL;

UPDATE games
   SET twitch_category_id         = '509658',
       twitch_category_name       = 'Just Chatting',
       twitch_category_source     = 'default',
       twitch_category_checked_at = NULL
 WHERE twitch_category_id IS NULL;

ALTER TABLE games
    ALTER COLUMN twitch_category_id     SET DEFAULT '509658',
    ALTER COLUMN twitch_category_id     SET NOT NULL,
    ALTER COLUMN twitch_category_name   SET DEFAULT 'Just Chatting',
    ALTER COLUMN twitch_category_name   SET NOT NULL,
    ALTER COLUMN twitch_category_source SET DEFAULT 'default',
    ALTER COLUMN twitch_category_source SET NOT NULL,
    ADD CONSTRAINT games_twitch_category_source_check
        CHECK (twitch_category_source IN ('twitch', 'manual', 'default'));

CREATE INDEX games_twitch_category_idx ON games (twitch_category_id);
