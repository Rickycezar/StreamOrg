-- =====================================================================
-- Authentication roles, the owner's own channel, and API provider settings.
-- =====================================================================

-- Role gates the admin section. lang key: user_role.<code>
ALTER TABLE users
    ADD COLUMN role text NOT NULL DEFAULT 'user'
        CHECK (role IN ('user', 'admin'));

-- The account holder's own channel. Distinct from the streamers table,
-- which lists other people you collaborate with.
ALTER TABLE users
    ADD COLUMN channel_platform_id bigint REFERENCES streaming_platforms (id) ON DELETE SET NULL,
    ADD COLUMN channel_handle      text;

CREATE INDEX users_channel_platform_idx ON users (channel_platform_id);

-- ---------------------------------------------------------------------
-- External catalogue providers (Steam, IGDB, RAWG, OMDb…).
--
-- One row per provider the code knows about. A provider is usable when it
-- is enabled AND either needs no credentials or has the ones it needs.
-- Which fields a provider needs is defined in PHP, not here; this table
-- only stores the values the admin types in.
-- ---------------------------------------------------------------------
CREATE TABLE api_settings (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- Matches the provider's code() in src/Api/. lang key: api_provider.<code>
    provider      citext      NOT NULL UNIQUE,
    is_enabled    boolean     NOT NULL DEFAULT false,
    -- Credential fields, named generically so one table serves every
    -- provider: Steam uses none, RAWG/OMDb use api_key, IGDB uses
    -- client_id + client_secret.
    api_key       text,
    client_id     text,
    client_secret text,
    -- Anything provider-specific that does not fit above.
    extra         jsonb       NOT NULL DEFAULT '{}'::jsonb,
    -- Result of the last "test connection" run in the admin screen.
    last_tested_at timestamptz,
    last_test_ok   boolean,
    last_test_note text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TRIGGER api_settings_touch BEFORE UPDATE ON api_settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Rows for the providers shipped with the app. Disabled until configured;
-- steam is enabled because it needs no credentials.
INSERT INTO api_settings (provider, is_enabled) VALUES
    ('steam', true),
    ('igdb',  false),
    ('rawg',  false),
    ('omdb',  false)
ON CONFLICT (provider) DO NOTHING;

-- ---------------------------------------------------------------------
-- Provenance: which external catalogue a game/publisher/developer came
-- from, so re-importing updates the same row instead of duplicating it.
-- ---------------------------------------------------------------------
ALTER TABLE games      ADD COLUMN source_provider citext, ADD COLUMN source_ref text;
ALTER TABLE publishers ADD COLUMN source_provider citext, ADD COLUMN source_ref text;
ALTER TABLE developers ADD COLUMN source_provider citext, ADD COLUMN source_ref text;

CREATE UNIQUE INDEX games_source_idx      ON games      (source_provider, source_ref)
    WHERE source_provider IS NOT NULL AND source_ref IS NOT NULL;
CREATE UNIQUE INDEX publishers_source_idx ON publishers (source_provider, source_ref)
    WHERE source_provider IS NOT NULL AND source_ref IS NOT NULL;
CREATE UNIQUE INDEX developers_source_idx ON developers (source_provider, source_ref)
    WHERE source_provider IS NOT NULL AND source_ref IS NOT NULL;
