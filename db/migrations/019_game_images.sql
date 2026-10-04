-- =====================================================================
-- Game artwork stored locally
--
-- Images used to be hot-linked from the provider's CDN. They are now
-- downloaded when a game is imported or refreshed and served from
-- public/media/games/<game id>/, one file per kind:
--
--   header    460x215 store header         catalogue, details
--   capsule   231x87 small capsule         compact lists
--   portrait  600x900 box art              calendar, cards
--   hero      wide library banner          detail headers (resized)
--   logo      transparent title logo
--   thumb     small WebP made from header  pickers
--
-- source_url remembers where a file came from: a refresh re-downloads only
-- when the provider's URL changed (Steam's carry a ?t= version stamp) or
-- the file has gone missing.
-- =====================================================================

CREATE TABLE game_images (
    game_id    bigint      NOT NULL REFERENCES games (id) ON DELETE CASCADE,
    kind       text        NOT NULL
        CHECK (kind IN ('header', 'capsule', 'portrait', 'hero', 'logo', 'thumb')),
    -- Relative to public/, e.g. media/games/12/header.jpg
    path       text        NOT NULL,
    source_url text,
    width      integer,
    height     integer,
    bytes      integer,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (game_id, kind)
);
