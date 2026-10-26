-- =====================================================================
-- A short chat on each joint plan, for the people in it.
--
-- collab_session_messages: one row per message, kept while the plan
-- exists (deleted with it).
-- =====================================================================

CREATE TABLE collab_session_messages (
    id         bigserial   PRIMARY KEY,
    session_id bigint      NOT NULL REFERENCES collab_sessions (id) ON DELETE CASCADE,
    user_id    bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    body       text        NOT NULL CHECK (length(body) BETWEEN 1 AND 1000),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX collab_session_messages_session ON collab_session_messages (session_id, id);
