-- =====================================================================
-- The chat bot (the Node service in bot/), managed from the admin page.
--
-- bot_account     the one Twitch account the bot speaks as, its encrypted
--                 tokens, and the switches an admin sets; the bot process
--                 writes its own status back here (seen_at, state…).
-- bot_channels    the channels it joins: a streamer opts in, an admin may
--                 block; the bot records when it joined or why it failed.
-- bot_commands    what it answers. A row without user_id is the default
--                 every channel starts from; a streamer's own row for the
--                 same code personalises it for their channel. code names
--                 the built-in behaviour behind the command.
-- bot_log         recent activity for the admin and the streamer, kept for
--                 a couple of weeks. No chat messages are stored.
--
-- Changes made in the app are announced with NOTIFY streamorg_bot, so the
-- bot reloads at once instead of waiting for its next poll.
-- =====================================================================

CREATE TABLE bot_account (
    id             smallint    PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    is_enabled     boolean     NOT NULL DEFAULT false,
    command_prefix text        NOT NULL DEFAULT '!' CHECK (command_prefix ~ '^[!?.#$%&*+~-]{1,2}$'),
    twitch_user_id text,
    twitch_login   text,
    access_token   text,
    refresh_token  text,
    expires_at     timestamptz,
    scopes         text,
    connected_at   timestamptz,
    seen_at        timestamptz,
    started_at     timestamptz,
    state          text,
    state_detail   text,
    version        text
);

INSERT INTO bot_account DEFAULT VALUES;

CREATE TABLE bot_channels (
    user_id    bigint      PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    is_enabled boolean     NOT NULL DEFAULT true,
    is_blocked boolean     NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    joined_at  timestamptz,
    last_error text
);

CREATE TABLE bot_commands (
    id               bigserial   PRIMARY KEY,
    user_id          bigint      REFERENCES users (id) ON DELETE CASCADE,
    code             text        NOT NULL,
    trigger          text        NOT NULL CHECK (trigger ~ '^[a-z0-9_]{1,25}$'),
    response         text        NOT NULL CHECK (length(response) BETWEEN 1 AND 450),
    is_enabled       boolean     NOT NULL DEFAULT true,
    permission       text        NOT NULL DEFAULT 'everyone'
                                 CHECK (permission IN ('everyone', 'subscriber', 'vip', 'moderator', 'broadcaster')),
    cooldown_seconds integer     NOT NULL DEFAULT 10 CHECK (cooldown_seconds BETWEEN 0 AND 3600),
    updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX bot_commands_default ON bot_commands (code) WHERE user_id IS NULL;
CREATE UNIQUE INDEX bot_commands_personal ON bot_commands (user_id, code) WHERE user_id IS NOT NULL;

INSERT INTO bot_commands (code, trigger, response, permission, cooldown_seconds)
VALUES ('heartbeat', 'heartbeat', '💓 StreamOrg is here, @{user}! Up for {uptime}.', 'everyone', 10);

CREATE TABLE bot_log (
    id      bigserial   PRIMARY KEY,
    at      timestamptz NOT NULL DEFAULT now(),
    user_id bigint      REFERENCES users (id) ON DELETE CASCADE,
    level   text        NOT NULL DEFAULT 'info' CHECK (level IN ('info', 'warn', 'error')),
    message text        NOT NULL
);

CREATE INDEX bot_log_at ON bot_log (at DESC);
CREATE INDEX bot_log_user ON bot_log (user_id, at DESC) WHERE user_id IS NOT NULL;
