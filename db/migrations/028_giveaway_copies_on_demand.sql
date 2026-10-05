-- =====================================================================
-- Giveaway prizes are copied out of the vault only when needed.
--
-- A prize now just points at its key. A recoverable vault is opened by the
-- server anyway, so the key is read from it at the moment a winner claims
-- and is never copied. A private vault cannot be opened without its
-- password, so the streamer copies the giveaway's keys when they are ready
-- for winners, and can take the copies back. Finishing a giveaway returns
-- its unclaimed keys to the vault and deletes their copies.
--
-- Copies made before this change are removed where they are not needed:
-- unclaimed prizes of recoverable vaults.
-- =====================================================================

-- When the streamer last took the copies back, so a winner who comes by in
-- the meantime is told their prize is being kept safe.
ALTER TABLE giveaways ADD COLUMN copies_taken_back_at timestamptz;

ALTER TABLE giveaways DROP CONSTRAINT IF EXISTS giveaways_status_check;

ALTER TABLE giveaways ADD CONSTRAINT giveaways_status_check
    CHECK (status IN ('planned', 'open', 'closed', 'finished', 'cancelled'));

UPDATE giveaway_prizes p
   SET sealed_code = NULL
  FROM giveaways g JOIN users u ON u.id = g.user_id
 WHERE g.id = p.giveaway_id AND p.claimed_at IS NULL AND u.vault_mode = 'managed';
