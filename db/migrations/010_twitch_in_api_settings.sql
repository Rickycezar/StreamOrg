-- =====================================================================
-- Twitch joins the other integrations in api_settings, and credential
-- columns are documented as holding ciphertext.
--
-- api_settings already had the right shape — provider, enabled flag, a
-- credential triplet and the last-test result — so Twitch is a row here
-- rather than a second place to look. It is not a game catalogue, so it
-- has no Provider class; the admin screen lists it separately.
-- =====================================================================

INSERT INTO api_settings (provider, is_enabled)
VALUES ('twitch', false)
ON CONFLICT (provider) DO NOTHING;

COMMENT ON COLUMN api_settings.api_key IS
    'Encrypted at rest (AES-256-GCM, see src/Crypto.php). Values written before encryption was introduced are stored as plain text and still read correctly.';
COMMENT ON COLUMN api_settings.client_id IS
    'Encrypted at rest. Not strictly a secret, but kept alongside the secret it pairs with.';
COMMENT ON COLUMN api_settings.client_secret IS
    'Encrypted at rest (AES-256-GCM, see src/Crypto.php).';
