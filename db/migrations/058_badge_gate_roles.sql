-- =====================================================================
-- Exceptions to a badge-gated giveaway: the channel's subscribers, VIPs
-- and moderators may enter its battle draws without showing one of its
-- badges, when the streamer allows them (by their status in chat, not by
-- a badge). Values: 'subscriber', 'vip', 'moderator'.
-- =====================================================================

ALTER TABLE giveaways ADD COLUMN badge_roles text[] NOT NULL DEFAULT '{}'
    CHECK (badge_roles <@ ARRAY['subscriber', 'vip', 'moderator']::text[]);
