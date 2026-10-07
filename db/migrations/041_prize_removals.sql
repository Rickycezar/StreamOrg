-- =====================================================================
-- Administrators can take a key back from a giveaway winner (a cheater,
-- a mistake, a key sent to the wrong person).
--
-- giveaway_winners.removed_*  marks the winner as removed: their link stops
--                             working and the key leaves their prizes.
-- prize_removals              what was taken, from whom, by whom and why,
--                             and what became of the key:
--                               freed     it was not claimed yet and goes
--                                         back to the giveaway's prizes
--                               returned  it was claimed; it goes back to
--                                         the streamer's vault for another
--                                         giveaway
--                               revoked   it was claimed and its code was
--                                         seen; it stays in the vault
--                                         marked revoked
-- =====================================================================

ALTER TABLE giveaway_winners
    ADD COLUMN removed_at     timestamptz,
    ADD COLUMN removed_by     bigint REFERENCES users (id) ON DELETE SET NULL,
    ADD COLUMN removed_reason text   CHECK (length(removed_reason) <= 500);

CREATE TABLE prize_removals (
    id             bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    winner_id      bigint      NOT NULL REFERENCES giveaway_winners (id) ON DELETE CASCADE,
    giveaway_id    bigint      NOT NULL REFERENCES giveaways (id) ON DELETE CASCADE,
    game_key_id    bigint      REFERENCES game_keys (id) ON DELETE SET NULL,
    game_title     text,
    platform_code  text,
    was_claimed    boolean     NOT NULL,
    key_outcome    text        NOT NULL CHECK (key_outcome IN ('freed', 'returned', 'revoked', 'none')),
    reason         text        NOT NULL CHECK (length(reason) BETWEEN 1 AND 500),
    removed_by     bigint      REFERENCES users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX prize_removals_created ON prize_removals (created_at DESC);
