-- =====================================================================
-- The error log: problems the application ran into, kept so
-- administrators can see them in Administration → Errors.
--
-- One row per kind of problem (fingerprint: level, message with its
-- numbers blanked, file and line), counted each time it happens again,
-- with the latest occurrence's details (request, user, stack trace
-- without argument values). A resolved problem that happens again is
-- opened again. Rows not seen for 90 days are deleted.
--
-- source: php (caught by the application), import (read back from the
-- server logs written before this table existed).
-- =====================================================================

CREATE TABLE app_errors (
    id           bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fingerprint  text        NOT NULL UNIQUE,
    level        text        NOT NULL CHECK (level IN ('fatal', 'error', 'warning', 'notice')),
    source       text        NOT NULL DEFAULT 'php' CHECK (source IN ('php', 'import')),
    message      text        NOT NULL,
    file         text,
    line         integer,
    trace        text,
    method       text,
    path         text,
    referer      text,
    user_id      bigint      REFERENCES users (id) ON DELETE SET NULL,
    count        integer     NOT NULL DEFAULT 1,
    first_seen   timestamptz NOT NULL DEFAULT now(),
    last_seen    timestamptz NOT NULL DEFAULT now(),
    resolved_at  timestamptz,
    resolved_by  bigint      REFERENCES users (id) ON DELETE SET NULL
);

CREATE INDEX app_errors_open ON app_errors (last_seen DESC) WHERE resolved_at IS NULL;
CREATE INDEX app_errors_last_seen ON app_errors (last_seen DESC);
