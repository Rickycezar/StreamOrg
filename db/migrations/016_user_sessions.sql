-- =====================================================================
-- Signed-in sessions, validated on every request
--
-- A PHP session alone cannot be listed or ended from elsewhere. Each
-- sign-in now also gets a row here, and a request is only authenticated
-- while its row is live: not revoked and not idle for longer than the
-- configured limit. That is what lets the security page show where you are
-- signed in, sign out any one of them, and lets a password change end the
-- others.
--
-- The PHP session holds a random token; only its SHA-256 is stored, so a
-- copy of this table cannot be replayed as a session.
-- =====================================================================

CREATE TABLE user_sessions (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id        bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash     text        NOT NULL UNIQUE,
    -- Where it was last used from, refreshed as the session is used.
    ip             text,
    user_agent     text,
    created_at     timestamptz NOT NULL DEFAULT now(),
    last_seen_at   timestamptz NOT NULL DEFAULT now(),
    revoked_at     timestamptz,
    -- lang key: session_end.<code>
    revoked_reason text
        CHECK (revoked_reason IN ('logout', 'revoked', 'password_changed', 'password_reset', 'expired')),
    CHECK ((revoked_at IS NULL) = (revoked_reason IS NULL))
);

CREATE INDEX user_sessions_live ON user_sessions (user_id) WHERE revoked_at IS NULL;
CREATE INDEX user_sessions_recent ON user_sessions (user_id, created_at DESC);
