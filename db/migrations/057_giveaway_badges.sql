-- =====================================================================
-- Badge-gated giveaways: only viewers showing one of the chosen Twitch
-- badges of the streamer's channel (its campaign badges) may enter its
-- battle draws.
--
-- giveaways.badge_gate: the switch. giveaways.badges: the chosen badges,
-- [{"id": set_id, "title": shown name, "image": data: URI of the icon}],
-- copied from Twitch when chosen (the game cannot load Twitch's images).
--
-- The game no longer declares its own badge options (boss-draw 1.3.0):
-- their saved values are dropped.
-- =====================================================================

ALTER TABLE giveaways ADD COLUMN badge_gate boolean NOT NULL DEFAULT false;
ALTER TABLE giveaways ADD COLUMN badges jsonb NOT NULL DEFAULT '[]'::jsonb
    CHECK (jsonb_typeof(badges) = 'array' AND octet_length(badges::text) <= 200000);

UPDATE game_preferences SET options = options - 'badge_only' - 'badge_names' WHERE options ?| ARRAY['badge_only', 'badge_names'];
