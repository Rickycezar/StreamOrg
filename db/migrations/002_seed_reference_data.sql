-- =====================================================================
-- Reference data for the lookup tables.
--
-- Codes only — every label lives in lang/*.php under the key noted in
-- 001_initial_schema.sql. All four tables are plain lookups: add a row and
-- the matching label to extend them, no migration to the schema required.
-- Re-runnable: ON CONFLICT DO NOTHING throughout.
-- =====================================================================

-- Where a game is played and where its key is redeemed.
-- PC is divided by store, since a PC key is only good on one of them.
INSERT INTO game_platforms (code, family, sort_order) VALUES
    ('pc_steam',     'pc',          10),
    ('pc_gog',       'pc',          20),
    ('pc_epic',      'pc',          30),
    ('pc_itch',      'pc',          40),
    ('pc_microsoft', 'pc',          50),
    ('pc_ubisoft',   'pc',          60),
    ('pc_ea',        'pc',          70),
    ('pc_battlenet', 'pc',          80),
    ('pc_other',     'pc',          90),
    ('ps5',          'playstation', 100),
    ('ps4',          'playstation', 110),
    ('xbox_series',  'xbox',        120),
    ('xbox_one',     'xbox',        130),
    ('switch',       'nintendo',    140),
    ('switch2',      'nintendo',    150),
    ('android',      'mobile',      160),
    ('ios',          'mobile',      170)
ON CONFLICT (code) DO NOTHING;

-- Where a key came from. Creator key sites, plus the two origins that are
-- not sites: straight from the publisher/developer, and bought yourself.
INSERT INTO key_platforms (code, website, sort_order) VALUES
    ('keymailer',            'https://www.keymailer.co', 10),
    ('woovit',               'https://woovit.com',       20),
    ('terminals',            'https://terminals.io',     30),
    ('daredrop',             'https://daredrop.com',     40),
    ('lurkit',               'https://www.lurkit.com',   50),
    ('publisher_developer',  NULL,                       60),
    ('purchased',            NULL,                       70),
    ('other',                NULL,                       99)
ON CONFLICT (code) DO NOTHING;

INSERT INTO streaming_platforms (code, website, sort_order) VALUES
    ('twitch',  'https://www.twitch.tv',   10),
    ('youtube', 'https://www.youtube.com', 20),
    ('kick',    'https://kick.com',        30),
    ('tiktok',  'https://www.tiktok.com',  40),
    ('trovo',   'https://trovo.live',      50),
    ('other',   NULL,                      99)
ON CONFLICT (code) DO NOTHING;

INSERT INTO genres (code, sort_order) VALUES
    ('action', 10), ('adventure', 20), ('rpg', 30), ('jrpg', 40),
    ('strategy', 50), ('simulation', 60), ('puzzle', 70), ('platformer', 80),
    ('shooter', 90), ('fps', 100), ('horror', 110), ('survival', 120),
    ('roguelike', 130), ('metroidvania', 140), ('racing', 150), ('sports', 160),
    ('fighting', 170), ('visual_novel', 180), ('sandbox', 190), ('mmo', 200),
    ('card', 210), ('rhythm', 220), ('point_and_click', 230), ('indie', 240)
ON CONFLICT (code) DO NOTHING;
