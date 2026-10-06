-- =====================================================================
-- Notifications an administrator sends to everyone or to chosen users,
-- written in each of the app's languages.
--
-- notifications             one per message: who it is for ('everyone',
--                           or 'users' listed in notification_recipients),
--                           its kind (info, news, success, warning) and an
--                           optional link.
-- notification_texts        title and body per language; a user reads
--                           their own language, else English, else any.
-- notification_recipients   the users a 'users' notification is for.
-- notification_reads        who has read what. A notification sent before
--                           someone's account existed never counts as
--                           unread for them.
-- =====================================================================

CREATE TABLE notifications (
    id         bigserial   PRIMARY KEY,
    audience   text        NOT NULL CHECK (audience IN ('everyone', 'users')),
    level      text        NOT NULL DEFAULT 'info' CHECK (level IN ('info', 'news', 'success', 'warning')),
    link_url   text,
    created_by bigint      REFERENCES users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX notifications_created ON notifications (created_at DESC);

CREATE TABLE notification_texts (
    notification_id bigint NOT NULL REFERENCES notifications (id) ON DELETE CASCADE,
    locale          text   NOT NULL,
    title           text   NOT NULL CHECK (length(title) BETWEEN 1 AND 120),
    body            text   NOT NULL DEFAULT '' CHECK (length(body) <= 2000),
    PRIMARY KEY (notification_id, locale)
);

CREATE TABLE notification_recipients (
    notification_id bigint NOT NULL REFERENCES notifications (id) ON DELETE CASCADE,
    user_id         bigint NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    PRIMARY KEY (notification_id, user_id)
);

CREATE INDEX notification_recipients_user ON notification_recipients (user_id);

CREATE TABLE notification_reads (
    notification_id bigint      NOT NULL REFERENCES notifications (id) ON DELETE CASCADE,
    user_id         bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    read_at         timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (notification_id, user_id)
);

CREATE INDEX notification_reads_user ON notification_reads (user_id);
