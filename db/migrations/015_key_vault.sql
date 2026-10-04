-- =====================================================================
-- Encrypted key vault
--
-- Every user gets a random data key; key codes are encrypted with it.
-- The data key itself is stored wrapped, in exactly one of two ways:
--
--   managed  wrapped with the application key (config/config.php). The
--            server can always unwrap it, so an admin password reset keeps
--            the user's keys readable. Protects against a leaked database.
--
--   private  opt-in. Wrapped only with a key derived from the user's
--            password. Nobody without the password — the admin included —
--            can read the codes, and a lost password loses them for good.
--
-- The codes themselves are encrypted by bin/encrypt_keys.php, which must
-- run after this migration: SQL alone cannot do the encryption.
-- =====================================================================

ALTER TABLE users
    ADD COLUMN vault_mode text NOT NULL DEFAULT 'managed'
        CHECK (vault_mode IN ('managed', 'private')),
    -- Data key wrapped with the application key. Set only in managed mode.
    ADD COLUMN vault_key_app text,
    -- Data key wrapped with the password-derived key. Set only in private mode.
    ADD COLUMN vault_key_pw  text,
    ADD CONSTRAINT users_vault_wrap_matches_mode CHECK (
        (vault_mode = 'managed' AND vault_key_pw IS NULL)
     OR (vault_mode = 'private' AND vault_key_app IS NULL AND vault_key_pw IS NOT NULL)
    );

-- key_code now holds ciphertext, which differs every time the same code is
-- encrypted, so duplicates are detected through a keyed hash instead. The
-- hash is keyed with the user's data key, so it reveals nothing to someone
-- holding only the database.
ALTER TABLE game_keys
    ADD COLUMN key_hash text,
    -- Replaces matching key_code LIKE 'UNREVEALED%', which cannot work on
    -- ciphertext. Marks a code an importer made up for the user to fill in.
    ADD COLUMN is_placeholder boolean NOT NULL DEFAULT false;

UPDATE game_keys SET is_placeholder = true WHERE key_code LIKE 'UNREVEALED%';

ALTER TABLE game_keys DROP CONSTRAINT game_keys_user_id_game_platform_id_key_code_key;

-- Partial only until bin/encrypt_keys.php has filled key_hash for rows
-- stored before this migration; every write path sets it from now on.
CREATE UNIQUE INDEX game_keys_user_platform_hash_key
    ON game_keys (user_id, game_platform_id, key_hash)
    WHERE key_hash IS NOT NULL;
