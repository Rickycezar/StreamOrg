-- =====================================================================
-- Interface theme, per user.
--
-- A column on users rather than a row in user_preferences: the theme has
-- to be known before the first byte of every page is rendered, and
-- Auth::user() already loads this row. Reading it from a second table on
-- every request would buy nothing.
-- =====================================================================

ALTER TABLE users
    ADD COLUMN theme text NOT NULL DEFAULT 'light'
        CHECK (theme IN ('light', 'dark', 'contrast'));

COMMENT ON COLUMN users.theme IS
    'Interface theme. lang key: theme.<code>';
