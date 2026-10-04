-- =====================================================================
-- Six further themes, widening the set to twelve.
--
-- Light family: 'moss' (green-grey paper), 'blush' (rose paper).
-- Mid-tone:     'dim' — neither light nor dark, a slate grey-blue.
-- Dark family:  'harbour' (navy + gold), 'ember' (coffee + copper),
--               'kelp' (teal-black + cyan).
--
-- Each carries its own chart pair, validated against its own surface
-- rather than inherited, so no theme borrows another's colours.
-- =====================================================================

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_theme_check;

ALTER TABLE users ADD CONSTRAINT users_theme_check
    CHECK (theme IN ('light', 'sepia', 'moss', 'blush', 'contrast', 'dim',
                     'dark', 'midnight', 'violet', 'harbour', 'ember', 'kelp'));
