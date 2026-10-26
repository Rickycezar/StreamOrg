-- =====================================================================
-- Titles: the order of their parts, and the game's own hashtag.
--
-- games.hashtag: the game's name as a hashtag (#CastlevaniaBC), set by
-- an administrator. Some key sites ask for it in the title of the
-- content they sponsor.
--
-- key_platforms.uses_game_tag: content sponsored by this site gets the
-- game's hashtag in its title (Keymailer does it).
--
-- users.title_order: the order a title's parts are written in (prefix,
-- text, suffix, collab credit, game hashtag, developer/publisher tags,
-- sponsor tags); null means the usual one.
-- =====================================================================

ALTER TABLE games
    ADD COLUMN hashtag text CHECK (hashtag IS NULL OR hashtag ~ '^#[A-Za-z0-9_]{1,80}$');

ALTER TABLE key_platforms
    ADD COLUMN uses_game_tag boolean NOT NULL DEFAULT false;

UPDATE key_platforms SET uses_game_tag = true WHERE code = 'keymailer';

ALTER TABLE users
    ADD COLUMN title_order text[];
