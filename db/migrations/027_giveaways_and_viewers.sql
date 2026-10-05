-- =====================================================================
-- Content in any Twitch category, named giveaways with their own lock,
-- winners with claim links, and viewer profiles.
--
-- Content can carry a Twitch category of its own (Just Chatting, Special
-- Events, Marbles On Stream…), used before its games when sending to
-- Twitch and when live tracking matches a category.
--
-- A giveaway has a name and a short keyword (for the chat bot), rules, an
-- entry window, and may stay hidden until opened (a surprise). Its prizes
-- are keys from the streamer's vault marked "for giveaway": each code is
-- copied into the giveaway's own lock, a random key wrapped with the
-- application key, so the giveaway works whatever the vault mode and the
-- winner can redeem without the streamer's password. The vault key and the
-- prize stay linked and are marked given away together.
--
-- A winner is a Twitch account (by id, which survives renames). Each gets
-- a claim link; only the hash of its token is stored. Redeeming requires
-- signing in with that Twitch account, as a viewer.
--
-- Viewers are people who sign in with Twitch only, to redeem and see the
-- keys they won. They are not users and can reach nothing else. A user who
-- connects the same Twitch account absorbs the viewer profile.
-- =====================================================================

ALTER TABLE streams
    ADD COLUMN category_id   text,
    ADD COLUMN category_name text;

ALTER TABLE giveaways
    ADD COLUMN keyword        text,
    ADD COLUMN is_surprise    boolean  NOT NULL DEFAULT false,
    ADD COLUMN winner_mode    text     NOT NULL DEFAULT 'pick'
        CHECK (winner_mode IN ('pick', 'assigned')),
    ADD COLUMN entry_method   text     NOT NULL DEFAULT 'chat'
        CHECK (entry_method IN ('chat', 'external', 'manual')),
    ADD COLUMN claim_days     smallint NOT NULL DEFAULT 30
        CHECK (claim_days BETWEEN 1 AND 365),
    ADD COLUMN lock_key       text,
    ADD COLUMN drawn_at       timestamptz;

ALTER TABLE giveaways ADD CONSTRAINT giveaways_keyword_format
    CHECK (keyword IS NULL OR keyword ~ '^[a-z0-9_]{2,20}$');

CREATE UNIQUE INDEX giveaways_user_keyword ON giveaways (user_id, keyword) WHERE keyword IS NOT NULL;

CREATE TABLE viewers (
    id             bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    twitch_user_id text        NOT NULL UNIQUE,
    twitch_login   text        NOT NULL,
    display_name   text,
    avatar_url     text,
    user_id        bigint      UNIQUE REFERENCES users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    last_login_at  timestamptz
);

CREATE TABLE giveaway_winners (
    id               bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    giveaway_id      bigint      NOT NULL REFERENCES giveaways (id) ON DELETE CASCADE,
    twitch_user_id   text,
    twitch_login     text        NOT NULL,
    display_name     text,
    -- lang key: winner_method.<code>
    method           text        NOT NULL DEFAULT 'manual'
        CHECK (method IN ('draw', 'external', 'manual')),
    claim_token_hash text        NOT NULL UNIQUE,
    -- The token itself, encrypted with the application key, so the
    -- streamer can copy the link again.
    claim_token      text        NOT NULL,
    expires_at       timestamptz NOT NULL,
    viewer_id        bigint      REFERENCES viewers (id) ON DELETE SET NULL,
    claimed_at       timestamptz,
    cancelled_at     timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX giveaway_winners_giveaway ON giveaway_winners (giveaway_id);
CREATE INDEX giveaway_winners_viewer   ON giveaway_winners (twitch_user_id);

ALTER TABLE giveaway_prizes
    DROP COLUMN winner_handle,
    DROP COLUMN winner_platform_id,
    DROP COLUMN won_at,
    DROP COLUMN delivered_at,
    ADD COLUMN sealed_code text,
    ADD COLUMN winner_id   bigint REFERENCES giveaway_winners (id) ON DELETE SET NULL,
    ADD COLUMN claimed_at  timestamptz;

CREATE UNIQUE INDEX giveaway_prizes_one_per_winner ON giveaway_prizes (winner_id) WHERE winner_id IS NOT NULL;

-- People who typed the entry command. Kept only while the giveaway is open
-- and until its draw, then deleted.
CREATE TABLE giveaway_entries (
    giveaway_id    bigint      NOT NULL REFERENCES giveaways (id) ON DELETE CASCADE,
    twitch_user_id text        NOT NULL,
    twitch_login   text        NOT NULL,
    display_name   text,
    entered_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (giveaway_id, twitch_user_id)
);
