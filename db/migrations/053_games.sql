-- =====================================================================
-- Games hosted by StreamOrg (public/games/<game>/, such as the boss
-- battle draw), run in a giveaway or on their public page.
--
-- game_text_overrides: texts of a game changed in Administration → Game
-- texts, over the defaults the game ships (texts/<locale>.json); a
-- language the game does not ship can be added here.
--
-- game_preferences: per creator and game, the options last chosen on a
-- giveaway's draw page and the game's own saved settings (opaque to
-- StreamOrg, at most 8 KB).
--
-- giveaway_game_draws: every result a game confirmed for a giveaway, kept
-- whole as the draw's record (winners, seed to replay it, options used).
-- =====================================================================

CREATE TABLE game_text_overrides (
    game       text        NOT NULL CHECK (game ~ '^[a-z0-9-]{2,40}$'),
    locale     text        NOT NULL CHECK (locale ~ '^[A-Za-z]{2}(-[A-Za-z0-9]{2,8})?$'),
    key        text        NOT NULL CHECK (key ~ '^[a-z0-9_.]{1,80}$'),
    value      text        NOT NULL CHECK (length(value) BETWEEN 1 AND 500),
    updated_by bigint      REFERENCES users (id) ON DELETE SET NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (game, locale, key)
);

CREATE TABLE game_preferences (
    user_id    bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    game       text        NOT NULL CHECK (game ~ '^[a-z0-9-]{2,40}$'),
    options    jsonb       NOT NULL DEFAULT '{}'::jsonb,
    settings   jsonb,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, game),
    CHECK (settings IS NULL OR octet_length(settings::text) <= 8192)
);

CREATE TABLE giveaway_game_draws (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    giveaway_id bigint      NOT NULL REFERENCES giveaways (id) ON DELETE CASCADE,
    game        text        NOT NULL,
    version     text        NOT NULL,
    winners     integer     NOT NULL,
    result      jsonb       NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX giveaway_game_draws_giveaway ON giveaway_game_draws (giveaway_id, id);
