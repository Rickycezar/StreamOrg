-- =====================================================================
-- E-mail, ready to be plugged in (see src/Mail/): nothing leaves the
-- server until an administrator sets the mode to "live" with a provider.
--
-- email_outbox: every e-mail StreamOrg wants to send, rendered when it is
-- queued (subject, HTML and text), and what became of it. In "preview"
-- mode e-mails stop at 'held'; the worker sends 'queued' ones when live.
--
-- email_tokens: the links e-mails carry (confirm an address, reset or
-- set a password), stored only as a hash, single-use and expiring.
--
-- email_suppressions: addresses never written to again (a hard bounce, a
-- spam complaint, or by hand).
--
-- email_template_overrides: the system messages' texts as changed in
-- Administration → E-mails, over the defaults in the language files.
--
-- users.email_verified_at: when the address was confirmed (notification
-- e-mails only go to confirmed ones). users.email_notifications: whether
-- the person wants e-mail copies of their notifications.
-- =====================================================================

ALTER TABLE users ADD COLUMN email_verified_at timestamptz;
ALTER TABLE users ADD COLUMN email_notifications boolean NOT NULL DEFAULT true;

CREATE TABLE email_outbox (
    id           bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id      bigint      REFERENCES users (id) ON DELETE SET NULL,
    to_address   citext      NOT NULL,
    locale       text        NOT NULL,
    template     text        NOT NULL,
    subject      text        NOT NULL,
    html         text,
    body_text    text,
    status       text        NOT NULL DEFAULT 'queued'
                 CHECK (status IN ('queued', 'held', 'sending', 'sent', 'failed', 'suppressed')),
    attempts     integer     NOT NULL DEFAULT 0,
    last_error   text,
    provider_id  text,
    send_after   timestamptz NOT NULL DEFAULT now(),
    sent_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX email_outbox_pending ON email_outbox (send_after) WHERE status = 'queued';
CREATE INDEX email_outbox_recent ON email_outbox (created_at DESC);
CREATE INDEX email_outbox_to ON email_outbox (to_address, created_at DESC);

CREATE TABLE email_tokens (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    purpose     text        NOT NULL CHECK (purpose IN ('verify', 'reset', 'welcome')),
    token_hash  text        NOT NULL UNIQUE,
    email       citext      NOT NULL,
    expires_at  timestamptz NOT NULL,
    used_at     timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX email_tokens_user ON email_tokens (user_id, purpose);

CREATE TABLE email_suppressions (
    address    citext      PRIMARY KEY,
    reason     text        NOT NULL CHECK (reason IN ('bounce', 'complaint', 'manual')),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE email_template_overrides (
    code       text        NOT NULL,
    locale     text        NOT NULL,
    field      text        NOT NULL,
    value      text        NOT NULL CHECK (length(value) <= 4000),
    updated_by bigint      REFERENCES users (id) ON DELETE SET NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (code, locale, field)
);

-- "Forgot password" requests and sign-ups are rate-limited like sign-ins.
ALTER TABLE auth_attempts DROP CONSTRAINT auth_attempts_scope_check;
ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_scope_check CHECK (scope IN ('login', 'vault', 'password', 'reset', 'signup'));

-- Streamers can sign up themselves (while e-mail is live): the account
-- waits, inactive, until its address is confirmed and an administrator
-- approves it. signup_status is 'pending' meanwhile, null otherwise.
ALTER TABLE users ADD COLUMN signup_status text CHECK (signup_status IN ('pending'));
CREATE INDEX users_signup_pending ON users (created_at) WHERE signup_status IS NOT NULL;
