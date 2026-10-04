-- =====================================================================
-- Which key sources deserve a hashtag in a content title.
--
-- A creator key site is a sponsor and gets credited: #Keymailer,
-- #PressEngine, #Terminals. The non-site origins are not sponsors and
-- crediting them would be meaningless — nobody tags "#Purchased".
--
-- Kept as a column rather than a list in code: new key sites get added
-- regularly, and whether one is creditable is a property of the site.
-- =====================================================================

ALTER TABLE key_platforms
    ADD COLUMN tags_content boolean NOT NULL DEFAULT true;

COMMENT ON COLUMN key_platforms.tags_content IS
    'Offer this source as a sponsor hashtag when composing a content title.';

UPDATE key_platforms SET tags_content = false
 WHERE code IN ('email', 'purchased', 'publisher_developer', 'other');
