-- =====================================================================
-- Catalogue games get their Twitch category automatically (by IGDB id,
-- exact name, or a close-enough search hit). This records when a game was
-- last looked up, so a "find categories" run moves on to the next games
-- instead of asking Twitch about the same ones again.
-- =====================================================================

ALTER TABLE games ADD COLUMN twitch_category_checked_at timestamptz;
