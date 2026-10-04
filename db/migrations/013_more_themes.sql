-- =====================================================================
-- Three further themes.
--
-- 'dark' is a soft charcoal; 'midnight' goes to true black for OLED
-- panels and dim rooms. 'sepia' is a warm reading theme, and 'violet'
-- a dark purple.
-- =====================================================================

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_theme_check;

ALTER TABLE users ADD CONSTRAINT users_theme_check
    CHECK (theme IN ('light', 'dark', 'contrast', 'midnight', 'sepia', 'violet'));
