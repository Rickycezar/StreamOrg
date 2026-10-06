-- =====================================================================
-- The word that introduces collab guests in a title ("ft. @a, @b"),
-- chosen per user: "ft.", "feat.", "com", "x"… or nothing, for just the
-- mentions.
-- =====================================================================

ALTER TABLE users ADD COLUMN collab_prefix text NOT NULL DEFAULT 'ft.'
    CONSTRAINT users_collab_prefix_check CHECK (length(collab_prefix) <= 20 AND collab_prefix !~ '[@#\n]');
