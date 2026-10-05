-- =====================================================================
-- Themes become families with a light and a dark variant, plus a mode:
-- light, dark, or auto (follow the device). The twelve former themes
-- pair up into seven families; Coral and Harbour gain a light variant.
--
--   classic   light / dark          ember     sepia / ember
--   contrast  contrast / midnight   moss      moss / kelp
--   blossom   blush / violet        coral     coral-light / dim
--   harbour   harbour-light / harbour
--
-- Each user keeps the look they had: their old theme becomes its family,
-- with the mode that matches it.
--
-- Users can also have an avatar: an image under public/media/avatars/.
-- =====================================================================

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_theme_check;

ALTER TABLE users ADD COLUMN theme_mode text NOT NULL DEFAULT 'auto';

UPDATE users
   SET theme_mode = CASE WHEN theme IN ('dark', 'midnight', 'ember', 'kelp', 'violet', 'dim', 'harbour')
                         THEN 'dark' ELSE 'light' END,
       theme = CASE theme
                   WHEN 'light'    THEN 'classic'
                   WHEN 'dark'     THEN 'classic'
                   WHEN 'contrast' THEN 'contrast'
                   WHEN 'midnight' THEN 'contrast'
                   WHEN 'sepia'    THEN 'ember'
                   WHEN 'ember'    THEN 'ember'
                   WHEN 'moss'     THEN 'moss'
                   WHEN 'kelp'     THEN 'moss'
                   WHEN 'blush'    THEN 'blossom'
                   WHEN 'violet'   THEN 'blossom'
                   WHEN 'dim'      THEN 'coral'
                   WHEN 'harbour'  THEN 'harbour'
                   ELSE 'classic'
               END;

ALTER TABLE users ALTER COLUMN theme SET DEFAULT 'classic';

ALTER TABLE users ADD CONSTRAINT users_theme_check
    CHECK (theme IN ('classic', 'contrast', 'ember', 'moss', 'blossom', 'coral', 'harbour'));

ALTER TABLE users ADD CONSTRAINT users_theme_mode_check
    CHECK (theme_mode IN ('light', 'dark', 'auto'));

ALTER TABLE users
    ADD COLUMN avatar_path       text,
    ADD COLUMN avatar_updated_at timestamptz;
