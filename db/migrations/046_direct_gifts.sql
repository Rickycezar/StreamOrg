-- =====================================================================
-- A key given straight to one person, without a giveaway: kept as a
-- hidden giveaway (is_direct) with one prize assigned to one winner, so
-- the claim link, the private vault's copy and the claim work exactly as
-- in any giveaway. Direct gifts never show on the giveaways page; they
-- are listed with every given key (Keys → Given keys).
-- =====================================================================

ALTER TABLE giveaways ADD COLUMN is_direct boolean NOT NULL DEFAULT false;

CREATE INDEX giveaways_direct ON giveaways (user_id) WHERE is_direct;
