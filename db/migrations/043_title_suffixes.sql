-- =====================================================================
-- Title prefixes can also be suffixes: kind says where an item goes in
-- the title, at the start ('prefix') or at the end of the creator's own
-- text, before the collab credit and the hashtags ('suffix').
-- =====================================================================

ALTER TABLE user_title_prefixes
    ADD COLUMN kind text NOT NULL DEFAULT 'prefix' CHECK (kind IN ('prefix', 'suffix'));
