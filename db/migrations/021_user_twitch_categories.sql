-- =====================================================================
-- A Twitch category picked by hand belongs to the user who picked it.
--
-- games.twitch_category_id is shared by everyone, so it is now only
-- written for an exact title match from Twitch — objective data. When a
-- user chooses among several candidates, that choice is remembered for
-- them alone, so one person's pick can never change another's stream.
-- =====================================================================

CREATE TABLE user_twitch_categories (
    user_id       bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    game_id       bigint      NOT NULL REFERENCES games (id) ON DELETE CASCADE,
    category_id   text        NOT NULL,
    category_name text        NOT NULL,
    updated_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, game_id)
);
