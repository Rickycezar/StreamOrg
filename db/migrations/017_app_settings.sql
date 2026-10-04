-- =====================================================================
-- Application-wide settings an administrator can change at runtime
--
-- Values that used to need an edit to config/config.php live here instead,
-- so they can be changed from /admin/settings. One row per setting; the
-- value is stored as text and parsed by the code that owns it.
-- =====================================================================

CREATE TABLE app_settings (
    key        text        PRIMARY KEY,
    value      text        NOT NULL,
    updated_by bigint      REFERENCES users (id) ON DELETE SET NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- How long a sign-in survives without activity: 3 days.
INSERT INTO app_settings (key, value) VALUES ('session_idle_minutes', '4320');
