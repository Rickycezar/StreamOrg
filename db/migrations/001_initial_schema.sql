-- =====================================================================
-- StreamOrg — initial schema
--
-- Conventions
--   * Surrogate keys: bigint GENERATED ALWAYS AS IDENTITY.
--   * All instants are timestamptz; all plain dates are date.
--   * Localizable values are stored as stable lowercase codes and are never
--     translated in the database. The UI resolves them through lang files
--     (see lang/*.php) using the key noted above each column.
--   * Ownership: the game catalogue (publishers, developers, games and their
--     lookups) is shared and has no user_id. Everything personal carries
--     user_id NOT NULL and cascades when the user is deleted.
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS citext;

CREATE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION set_updated_at() IS 'BEFORE UPDATE trigger: refreshes updated_at.';

-- =====================================================================
-- Shared catalogue — not owned by any user
-- =====================================================================

CREATE TABLE publishers (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text        NOT NULL,
    slug         citext      NOT NULL UNIQUE,
    website      text,
    country_code char(2),
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE developers (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text        NOT NULL,
    slug         citext      NOT NULL UNIQUE,
    website      text,
    country_code char(2),
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

-- lang key: genre.<code>
CREATE TABLE genres (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code       citext      NOT NULL UNIQUE,
    sort_order smallint    NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Where a game is played and where its key is redeemed. PC is split by
-- store, so entries read pc_steam, pc_gog, pc_epic… alongside console codes
-- like ps5 and switch2. Pure lookup: add a row and a label to extend it.
-- lang key: game_platform.<code>
CREATE TABLE game_platforms (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code       citext      NOT NULL UNIQUE,
    -- Groups the PC/* codes under one heading for <optgroup>.
    -- lang key: platform_family.<code>
    family     citext,
    sort_order smallint    NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Where a key came FROM — never where it is redeemed, which is
-- game_platforms. Creator key sites (keymailer, woovit, terminals, daredrop)
-- plus the two origins that are not sites at all: publisher_developer, for
-- keys sent to you directly, and purchased. Pure lookup, expansible.
-- lang key: key_platform.<code>
CREATE TABLE key_platforms (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code       citext      NOT NULL UNIQUE,
    website    text,
    sort_order smallint    NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- twitch, youtube, kick… lang key: streaming_platform.<code>
CREATE TABLE streaming_platforms (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code       citext      NOT NULL UNIQUE,
    website    text,
    sort_order smallint    NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE games (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title        text        NOT NULL,
    slug         citext      NOT NULL UNIQUE,
    publisher_id bigint      REFERENCES publishers (id) ON DELETE SET NULL,
    developer_id bigint      REFERENCES developers (id) ON DELETE SET NULL,
    release_date date,
    description  text,
    cover_url    text,
    store_url    text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE game_genres (
    game_id  bigint NOT NULL REFERENCES games (id)  ON DELETE CASCADE,
    genre_id bigint NOT NULL REFERENCES genres (id) ON DELETE CASCADE,
    PRIMARY KEY (game_id, genre_id)
);

CREATE TABLE game_platform_releases (
    game_id          bigint NOT NULL REFERENCES games (id)          ON DELETE CASCADE,
    game_platform_id bigint NOT NULL REFERENCES game_platforms (id) ON DELETE CASCADE,
    released_on      date,
    PRIMARY KEY (game_id, game_platform_id)
);

-- =====================================================================
-- Users
-- =====================================================================

CREATE TABLE users (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username      citext      NOT NULL UNIQUE,
    email         citext      NOT NULL UNIQUE,
    password_hash text        NOT NULL,
    display_name  text,
    -- Selects the lang file, e.g. 'en', 'pt-BR'.
    locale        text        NOT NULL DEFAULT 'en',
    timezone      text        NOT NULL DEFAULT 'UTC',
    is_active     boolean     NOT NULL DEFAULT true,
    last_login_at timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

-- =====================================================================
-- Streamers you collaborate with (user-owned)
-- =====================================================================

CREATE TABLE streamers (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name        text        NOT NULL,
    email       citext,
    is_favorite boolean     NOT NULL DEFAULT false,
    notes       text,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, name)
);

-- One streamer may broadcast on several platforms.
CREATE TABLE streamer_channels (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    streamer_id           bigint  NOT NULL REFERENCES streamers (id)           ON DELETE CASCADE,
    streaming_platform_id bigint  NOT NULL REFERENCES streaming_platforms (id) ON DELETE RESTRICT,
    handle                text    NOT NULL,
    url                   text,
    is_primary            boolean NOT NULL DEFAULT false,
    follower_count        integer CHECK (follower_count IS NULL OR follower_count >= 0),
    UNIQUE (streamer_id, streaming_platform_id, handle)
);

-- At most one primary channel per streamer.
CREATE UNIQUE INDEX streamer_channels_one_primary
    ON streamer_channels (streamer_id) WHERE is_primary;

-- =====================================================================
-- Contacts and negotiations
-- =====================================================================

-- A person at a publisher, developer or key platform.
CREATE TABLE contacts (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         bigint      NOT NULL REFERENCES users (id)          ON DELETE CASCADE,
    name            text        NOT NULL,
    email           citext,
    role_title      text,
    publisher_id    bigint      REFERENCES publishers (id)     ON DELETE SET NULL,
    developer_id    bigint      REFERENCES developers (id)     ON DELETE SET NULL,
    key_platform_id bigint      REFERENCES key_platforms (id)  ON DELETE SET NULL,
    notes           text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    -- A contact belongs to at most one organisation.
    CONSTRAINT contacts_single_org
        CHECK (num_nonnulls(publisher_id, developer_id, key_platform_id) <= 1)
);

-- One negotiation = one thread of contact about one or more games.
CREATE TABLE negotiations (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    subject           text        NOT NULL,

    -- Who is on the other side. lang key: counterpart_kind.<code>
    counterpart_kind  text        NOT NULL
        CHECK (counterpart_kind IN ('publisher', 'developer', 'key_platform', 'other')),
    publisher_id      bigint      REFERENCES publishers (id)    ON DELETE SET NULL,
    developer_id      bigint      REFERENCES developers (id)    ON DELETE SET NULL,
    key_platform_id   bigint      REFERENCES key_platforms (id) ON DELETE SET NULL,
    contact_id        bigint      REFERENCES contacts (id)      ON DELETE SET NULL,

    -- How you reached out. lang key: negotiation_channel.<code>
    channel           text        NOT NULL DEFAULT 'email'
        CHECK (channel IN ('email', 'web_form', 'discord', 'twitter_x',
                           'key_site', 'other')),

    -- lang key: negotiation_status.<code>
    status            text        NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'sent', 'awaiting_reply', 'replied',
                          'negotiating', 'approved', 'declined',
                          'no_response', 'closed')),

    -- lang key: negotiation_outcome.<code>
    outcome           text
        CHECK (outcome IS NULL OR outcome IN ('keys_received', 'partial',
                                              'rejected', 'ignored', 'withdrawn')),

    requested_at      timestamptz,
    first_response_at timestamptz,
    last_activity_at  timestamptz,
    closed_at         timestamptz,
    notes             text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),

    -- The counterpart column must match the declared kind.
    CONSTRAINT negotiations_counterpart_matches_kind CHECK (
        (counterpart_kind = 'publisher'    AND publisher_id    IS NOT NULL) OR
        (counterpart_kind = 'developer'    AND developer_id    IS NOT NULL) OR
        (counterpart_kind = 'key_platform' AND key_platform_id IS NOT NULL) OR
        (counterpart_kind = 'other')
    )
);

-- Several games can ride on a single negotiation, each tracked separately.
CREATE TABLE negotiation_games (
    negotiation_id      bigint  NOT NULL REFERENCES negotiations (id)   ON DELETE CASCADE,
    game_id             bigint  NOT NULL REFERENCES games (id)          ON DELETE CASCADE,
    game_platform_id    bigint  REFERENCES game_platforms (id)          ON DELETE SET NULL,
    quantity_requested  integer NOT NULL DEFAULT 1 CHECK (quantity_requested > 0),
    -- lang key: negotiation_game_status.<code>
    status              text    NOT NULL DEFAULT 'requested'
        CHECK (status IN ('requested', 'granted', 'partial', 'denied', 'pending')),
    note                text,
    PRIMARY KEY (negotiation_id, game_id)
);

-- Every message exchanged in the thread, in either direction.
CREATE TABLE negotiation_messages (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    negotiation_id bigint      NOT NULL REFERENCES negotiations (id) ON DELETE CASCADE,
    -- lang key: message_direction.<code>
    direction      text        NOT NULL CHECK (direction IN ('outbound', 'inbound')),
    subject        text,
    body           text,
    from_address   citext,
    to_address     citext,
    sent_at        timestamptz NOT NULL DEFAULT now(),
    -- Message-ID or provider reference, for de-duplication.
    external_ref   text,
    created_at     timestamptz NOT NULL DEFAULT now()
);

-- =====================================================================
-- Keys
-- =====================================================================

CREATE TABLE game_keys (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id          bigint      NOT NULL REFERENCES users (id)          ON DELETE CASCADE,
    game_id          bigint      NOT NULL REFERENCES games (id)          ON DELETE RESTRICT,
    -- Origin: a creator key site, the publisher/developer, or a purchase.
    key_platform_id  bigint      NOT NULL REFERENCES key_platforms (id)  ON DELETE RESTRICT,
    -- Redemption target: pc_steam, pc_gog, ps5…
    game_platform_id bigint      NOT NULL REFERENCES game_platforms (id) ON DELETE RESTRICT,
    -- Where the key came from, when it came from a tracked request.
    negotiation_id   bigint      REFERENCES negotiations (id)            ON DELETE SET NULL,

    key_code         text        NOT NULL,

    -- 'review' means the key can be redeemed and played before the game
    -- releases; 'common' is a normal post-release key. Kept separate from
    -- status, which tracks the key's lifecycle rather than its kind.
    -- lang key: key_type.<code>
    key_type         text        NOT NULL DEFAULT 'common'
        CHECK (key_type IN ('common', 'review')),

    -- Whether this key unlocks the base game or DLC for it. game_id points
    -- at the base game in both cases. lang key: content_type.<code>
    content_type     text        NOT NULL DEFAULT 'game'
        CHECK (content_type IN ('game', 'dlc')),

    -- Lifecycle. 'for_giveaway' reserves a key for an upcoming giveaway;
    -- 'given_away' means it was awarded; 'used' means you redeemed it.
    -- lang key: key_status.<code>
    status           text        NOT NULL DEFAULT 'available'
        CHECK (status IN ('available', 'reserved', 'for_giveaway',
                          'used', 'given_away', 'expired', 'revoked')),

    region           text,
    source_note      text,
    received_at      timestamptz,
    activated_at     timestamptz,
    expires_at       timestamptz,
    notes            text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),

    -- The same code cannot be stored twice for one redemption platform.
    UNIQUE (user_id, game_platform_id, key_code)
);

-- =====================================================================
-- Collaborations and streams
--
-- A collab is the *planning* record and may exist long before any stream.
-- A stream is "a collab" when it has at least one row in
-- stream_collaborators — derived, so it cannot drift out of sync.
-- =====================================================================

CREATE TABLE collabs (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id      bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    title        text        NOT NULL,
    -- lang key: collab_status.<code>
    status       text        NOT NULL DEFAULT 'idea'
        CHECK (status IN ('idea', 'proposed', 'agreed', 'scheduled',
                          'done', 'cancelled')),
    proposed_for date,
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE collab_streamers (
    collab_id    bigint NOT NULL REFERENCES collabs (id)   ON DELETE CASCADE,
    streamer_id  bigint NOT NULL REFERENCES streamers (id) ON DELETE CASCADE,
    -- lang key: collab_role.<code>
    role         text   NOT NULL DEFAULT 'guest'
        CHECK (role IN ('host', 'co_host', 'guest', 'raid')),
    -- lang key: confirmation.<code>
    confirmation text   NOT NULL DEFAULT 'invited'
        CHECK (confirmation IN ('invited', 'tentative', 'confirmed', 'declined')),
    note         text,
    PRIMARY KEY (collab_id, streamer_id)
);

CREATE TABLE streams (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id               bigint      NOT NULL REFERENCES users (id)               ON DELETE CASCADE,
    streaming_platform_id bigint      NOT NULL REFERENCES streaming_platforms (id) ON DELETE RESTRICT,
    collab_id             bigint      REFERENCES collabs (id)                      ON DELETE SET NULL,
    title                 text        NOT NULL,
    -- lang key: stream_status.<code>
    status                text        NOT NULL DEFAULT 'planned'
        CHECK (status IN ('planned', 'live', 'done', 'cancelled')),
    scheduled_start       timestamptz,
    actual_start          timestamptz,
    ended_at              timestamptz,
    vod_url               text,
    peak_viewers          integer CHECK (peak_viewers IS NULL OR peak_viewers >= 0),
    avg_viewers           integer CHECK (avg_viewers  IS NULL OR avg_viewers  >= 0),
    notes                 text,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT streams_ends_after_start
        CHECK (ended_at IS NULL OR actual_start IS NULL OR ended_at >= actual_start)
);

-- Which games were covered in a stream, and on which key.
CREATE TABLE stream_games (
    stream_id   bigint  NOT NULL REFERENCES streams (id)    ON DELETE CASCADE,
    game_id     bigint  NOT NULL REFERENCES games (id)      ON DELETE RESTRICT,
    game_key_id bigint  REFERENCES game_keys (id)           ON DELETE SET NULL,
    play_order  smallint NOT NULL DEFAULT 1,
    note        text,
    PRIMARY KEY (stream_id, game_id)
);

-- Presence of any row here makes the stream a collab.
CREATE TABLE stream_collaborators (
    stream_id    bigint NOT NULL REFERENCES streams (id)   ON DELETE CASCADE,
    streamer_id  bigint NOT NULL REFERENCES streamers (id) ON DELETE CASCADE,
    -- lang key: collab_role.<code>
    role         text   NOT NULL DEFAULT 'guest'
        CHECK (role IN ('host', 'co_host', 'guest', 'raid')),
    -- lang key: confirmation.<code>
    confirmation text   NOT NULL DEFAULT 'invited'
        CHECK (confirmation IN ('invited', 'tentative', 'confirmed', 'declined')),
    note         text,
    PRIMARY KEY (stream_id, streamer_id)
);

-- =====================================================================
-- Giveaways
-- =====================================================================

CREATE TABLE giveaways (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id               bigint      NOT NULL REFERENCES users (id)     ON DELETE CASCADE,
    stream_id             bigint      REFERENCES streams (id)            ON DELETE SET NULL,
    streaming_platform_id bigint      REFERENCES streaming_platforms (id) ON DELETE SET NULL,
    title                 text        NOT NULL,
    -- lang key: giveaway_status.<code>
    status                text        NOT NULL DEFAULT 'planned'
        CHECK (status IN ('planned', 'open', 'closed', 'cancelled')),
    starts_at             timestamptz,
    ends_at               timestamptz,
    rules_note            text,
    notes                 text,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT giveaways_ends_after_start
        CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at)
);

-- One prize = one key. The winner is recorded by handle; entrants are not
-- stored (see docs/schema.md for why).
CREATE TABLE giveaway_prizes (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    giveaway_id       bigint      NOT NULL REFERENCES giveaways (id)          ON DELETE CASCADE,
    -- A key can only ever be the prize of one giveaway.
    game_key_id       bigint      NOT NULL UNIQUE REFERENCES game_keys (id)   ON DELETE RESTRICT,
    winner_handle     text,
    winner_platform_id bigint     REFERENCES streaming_platforms (id)         ON DELETE SET NULL,
    won_at            timestamptz,
    delivered_at      timestamptz,
    notes             text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);

-- =====================================================================
-- Coverage pipeline — the personal backlog of games to cover
-- =====================================================================

CREATE TABLE game_coverage (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    game_id     bigint      NOT NULL REFERENCES games (id) ON DELETE CASCADE,
    -- lang key: coverage_status.<code>
    status      text        NOT NULL DEFAULT 'wishlist'
        CHECK (status IN ('wishlist', 'key_requested', 'key_received',
                          'scheduled', 'streamed', 'covered', 'dropped')),
    -- 1 = highest.
    priority    smallint    NOT NULL DEFAULT 3 CHECK (priority BETWEEN 1 AND 5),
    target_date date,
    notes       text,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, game_id)
);

-- =====================================================================
-- Indexes
--
-- PostgreSQL indexes primary keys and UNIQUE constraints automatically but
-- never foreign keys, so every FK used for lookup or cascade gets one here.
-- =====================================================================

CREATE INDEX games_publisher_idx             ON games (publisher_id);
CREATE INDEX games_developer_idx             ON games (developer_id);
CREATE INDEX games_title_idx                 ON games (lower(title));
CREATE INDEX game_genres_genre_idx           ON game_genres (genre_id);
CREATE INDEX game_platform_releases_plat_idx ON game_platform_releases (game_platform_id);
CREATE INDEX game_platforms_family_idx       ON game_platforms (family);

CREATE INDEX streamers_user_idx              ON streamers (user_id);
CREATE INDEX streamer_channels_streamer_idx  ON streamer_channels (streamer_id);
CREATE INDEX streamer_channels_platform_idx  ON streamer_channels (streaming_platform_id);

CREATE INDEX contacts_user_idx               ON contacts (user_id);
CREATE INDEX contacts_publisher_idx          ON contacts (publisher_id);
CREATE INDEX contacts_developer_idx          ON contacts (developer_id);
CREATE INDEX contacts_key_platform_idx       ON contacts (key_platform_id);

CREATE INDEX negotiations_user_idx           ON negotiations (user_id);
CREATE INDEX negotiations_status_idx         ON negotiations (user_id, status);
CREATE INDEX negotiations_publisher_idx      ON negotiations (publisher_id);
CREATE INDEX negotiations_developer_idx      ON negotiations (developer_id);
CREATE INDEX negotiations_key_platform_idx   ON negotiations (key_platform_id);
CREATE INDEX negotiations_contact_idx        ON negotiations (contact_id);
-- Open threads sorted by staleness: the "who owes me a reply" view.
CREATE INDEX negotiations_awaiting_idx       ON negotiations (user_id, last_activity_at)
    WHERE status IN ('sent', 'awaiting_reply', 'negotiating');

CREATE INDEX negotiation_games_game_idx      ON negotiation_games (game_id);
CREATE INDEX negotiation_messages_thread_idx ON negotiation_messages (negotiation_id, sent_at);

CREATE INDEX game_keys_user_idx              ON game_keys (user_id);
CREATE INDEX game_keys_game_idx              ON game_keys (game_id);
CREATE INDEX game_keys_key_platform_idx      ON game_keys (key_platform_id);
CREATE INDEX game_keys_game_platform_idx     ON game_keys (game_platform_id);
CREATE INDEX game_keys_negotiation_idx       ON game_keys (negotiation_id);
CREATE INDEX game_keys_status_idx            ON game_keys (user_id, status);

CREATE INDEX collabs_user_idx                ON collabs (user_id);
CREATE INDEX collab_streamers_streamer_idx   ON collab_streamers (streamer_id);

CREATE INDEX streams_user_idx                ON streams (user_id);
CREATE INDEX streams_platform_idx            ON streams (streaming_platform_id);
CREATE INDEX streams_collab_idx              ON streams (collab_id);
CREATE INDEX streams_schedule_idx            ON streams (user_id, scheduled_start DESC);
CREATE INDEX stream_games_game_idx           ON stream_games (game_id);
CREATE INDEX stream_games_key_idx            ON stream_games (game_key_id);
CREATE INDEX stream_collaborators_str_idx    ON stream_collaborators (streamer_id);

CREATE INDEX giveaways_user_idx              ON giveaways (user_id);
CREATE INDEX giveaways_stream_idx            ON giveaways (stream_id);
CREATE INDEX giveaway_prizes_giveaway_idx    ON giveaway_prizes (giveaway_id);

CREATE INDEX game_coverage_user_status_idx   ON game_coverage (user_id, status, priority);
CREATE INDEX game_coverage_game_idx          ON game_coverage (game_id);

-- =====================================================================
-- updated_at triggers
-- =====================================================================

CREATE TRIGGER publishers_touch      BEFORE UPDATE ON publishers      FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER developers_touch      BEFORE UPDATE ON developers      FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER games_touch           BEFORE UPDATE ON games           FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER users_touch           BEFORE UPDATE ON users           FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER streamers_touch       BEFORE UPDATE ON streamers       FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER contacts_touch        BEFORE UPDATE ON contacts        FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER negotiations_touch    BEFORE UPDATE ON negotiations    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER game_keys_touch       BEFORE UPDATE ON game_keys       FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER collabs_touch         BEFORE UPDATE ON collabs         FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER streams_touch         BEFORE UPDATE ON streams         FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER giveaways_touch       BEFORE UPDATE ON giveaways       FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER giveaway_prizes_touch BEFORE UPDATE ON giveaway_prizes FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER game_coverage_touch   BEFORE UPDATE ON game_coverage   FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- =====================================================================
-- Views
-- =====================================================================

-- "Available keys" as a live projection of game_keys, so availability can
-- never disagree with the key's own status.
CREATE VIEW available_keys AS
SELECT k.id,
       k.user_id,
       k.game_id,
       g.title        AS game_title,
       g.release_date,
       k.key_platform_id,
       kp.code        AS key_platform_code,
       k.game_platform_id,
       gp.code        AS game_platform_code,
       gp.family      AS game_platform_family,
       k.key_type,
       k.content_type,
       k.status,
       k.region,
       k.received_at,
       k.expires_at
  FROM game_keys k
  JOIN games          g  ON g.id  = k.game_id
  JOIN key_platforms  kp ON kp.id = k.key_platform_id
  JOIN game_platforms gp ON gp.id = k.game_platform_id
 WHERE k.status IN ('available', 'for_giveaway')
   AND (k.expires_at IS NULL OR k.expires_at > now());

COMMENT ON VIEW available_keys IS
    'Keys still usable: available or reserved for a giveaway, not past expiry.';

-- A stream is a collab when anyone else is on it.
CREATE VIEW streams_with_collab_flag AS
SELECT s.*,
       EXISTS (SELECT 1 FROM stream_collaborators sc WHERE sc.stream_id = s.id)
           AS is_collab,
       (SELECT count(*) FROM stream_collaborators sc WHERE sc.stream_id = s.id)
           AS collaborator_count
  FROM streams s;

COMMENT ON VIEW streams_with_collab_flag IS
    'streams plus a derived is_collab flag; avoids a denormalised column.';
