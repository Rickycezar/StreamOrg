-- =====================================================================
-- Planning a collab together with other StreamOrg users.
--
-- collab_sessions          a joint plan started from a host's collab. Its
--                          time is shared: agreed_start/agreed_minutes once
--                          everyone taking part agreed, and at most one
--                          open proposal (proposed_*) waiting for them.
-- collab_session_members   who is in it: the host, and guests found in the
--                          host's cast (streamers whose Twitch account is a
--                          StreamOrg user's). A guest is invited, then
--                          accepts or declines, and may leave later;
--                          approves says whether they agreed to the open
--                          proposal.
-- streams.collab_session_id each participant's own content for the plan:
--                          title, games, keys and sponsors stay theirs, the
--                          time is the plan's.
-- =====================================================================

CREATE TABLE collab_sessions (
    id               bigserial   PRIMARY KEY,
    host_user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    collab_id        bigint      REFERENCES collabs (id) ON DELETE SET NULL,
    title            text        NOT NULL CHECK (length(title) BETWEEN 1 AND 140),
    agreed_start     timestamptz,
    agreed_minutes   integer     CHECK (agreed_minutes BETWEEN 15 AND 1440),
    proposed_start   timestamptz,
    proposed_minutes integer     CHECK (proposed_minutes BETWEEN 15 AND 1440),
    proposed_by      bigint      REFERENCES users (id) ON DELETE SET NULL,
    proposed_at      timestamptz,
    status           text        NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'cancelled')),
    created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX collab_sessions_one_active ON collab_sessions (collab_id) WHERE status = 'active' AND collab_id IS NOT NULL;

CREATE TABLE collab_session_members (
    session_id   bigint      NOT NULL REFERENCES collab_sessions (id) ON DELETE CASCADE,
    user_id      bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role         text        NOT NULL CHECK (role IN ('host', 'guest')),
    state        text        NOT NULL DEFAULT 'invited' CHECK (state IN ('invited', 'accepted', 'declined', 'left')),
    approves     boolean     NOT NULL DEFAULT false,
    invited_at   timestamptz NOT NULL DEFAULT now(),
    responded_at timestamptz,
    PRIMARY KEY (session_id, user_id)
);

CREATE INDEX collab_session_members_user ON collab_session_members (user_id);

ALTER TABLE streams ADD COLUMN collab_session_id bigint REFERENCES collab_sessions (id) ON DELETE SET NULL;

CREATE UNIQUE INDEX streams_one_per_session ON streams (collab_session_id, user_id) WHERE collab_session_id IS NOT NULL;
