-- =====================================================================
-- Live tracking: Twitch tells StreamOrg when a connected channel goes
-- live, goes offline or changes category (EventSub webhooks), and the
-- planned content follows along.
-- =====================================================================

-- The channel as last reported by Twitch.
ALTER TABLE twitch_connections
    ADD COLUMN is_live       boolean NOT NULL DEFAULT false,
    ADD COLUMN live_since    timestamptz,
    ADD COLUMN category_id   text,
    ADD COLUMN category_name text;

-- The EventSub subscriptions created for a user's channel, so they can be
-- shown, and deleted when the user turns tracking off or disconnects.
CREATE TABLE twitch_eventsub_subscriptions (
    id         text        PRIMARY KEY,
    user_id    bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    type       text        NOT NULL,
    status     text        NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX twitch_eventsub_subscriptions_user ON twitch_eventsub_subscriptions (user_id);

-- Message ids already handled. Twitch retries a delivery it thinks
-- failed, so the same notification can arrive more than once.
CREATE TABLE twitch_eventsub_messages (
    id          text        PRIMARY KEY,
    received_at timestamptz NOT NULL DEFAULT now()
);

-- What the tracker did, newest first, so the user can see and trust it.
-- lang key: live_event.<kind>
CREATE TABLE twitch_live_log (
    id        bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id   bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind      text        NOT NULL
        CHECK (kind IN ('online', 'offline', 'category', 'started', 'finished', 'unscheduled', 'chatting')),
    stream_id bigint      REFERENCES streams (id) ON DELETE SET NULL,
    detail    text,
    at        timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX twitch_live_log_user_at ON twitch_live_log (user_id, at DESC);
