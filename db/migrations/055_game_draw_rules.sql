-- =====================================================================
-- Two StreamOrg rules for a creator's draws by a hosted game, kept with
-- their game preferences, both off by default:
--
-- sync_winners: start the game at as many winners as the giveaway has
-- free prizes (off: start at 1; the free prizes are still the most).
--
-- exclude_winners: keep who already won this giveaway out of its later
-- draws (off: they may enter and win again).
-- =====================================================================

ALTER TABLE game_preferences ADD COLUMN sync_winners boolean NOT NULL DEFAULT false;
ALTER TABLE game_preferences ADD COLUMN exclude_winners boolean NOT NULL DEFAULT false;
