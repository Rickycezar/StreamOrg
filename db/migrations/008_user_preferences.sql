-- =====================================================================
-- Per-user interface preferences.
--
-- Kept in the database rather than localStorage so a preference follows
-- the account rather than the browser. One row per (user, key); the value
-- is jsonb because each preference has its own shape — a list of hidden
-- columns today, something else tomorrow.
-- =====================================================================

CREATE TABLE user_preferences (
    user_id    bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    -- Namespaced by screen, e.g. 'columns.keys', 'columns.content'.
    pref_key   text        NOT NULL CHECK (pref_key ~ '^[a-z0-9_.]{1,64}$'),
    value      jsonb       NOT NULL DEFAULT '{}'::jsonb,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, pref_key)
);

CREATE TRIGGER user_preferences_touch BEFORE UPDATE ON user_preferences
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON TABLE user_preferences IS
    'Interface preferences that belong to the account, not the browser.';
