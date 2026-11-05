-- =====================================================================
-- Keys passed from one creator's vault to another's without anyone
-- seeing the code (KeyTransfers).
--
-- key_transfers: one sending of one or more keys to one creator,
-- pending until the receiver accepts or declines it, the sender cancels
-- it or it expires. While pending, each code waits sealed with the
-- transfer's own lock (lock_key, wrapped with the application key);
-- once decided, the lock and the sealed copies are deleted.
--
-- key_transfer_items: one key of a transfer. from_key_id is the
-- sender's key (locked while pending: game_keys.transfer_item_id),
-- to_key_id the receiver's new key once accepted. The game and
-- platforms are kept here too, so the history survives either key
-- being deleted. outcome: moved, returned (declined, cancelled,
-- expired) or duplicate (the receiver already had that code).
--
-- game_keys:
--   lineage           the same code through every vault it passes;
--                     a new code (edited) starts a new one.
--   origin            own (entered by the owner), transfer (from
--                     another creator) or streamorg (handed out by
--                     StreamOrg itself, for later). Received keys are
--                     sealed and only their status can change.
--   code_seen         false while the owner has never seen the code:
--                     the global reveal skips it, and only an explicit,
--                     logged reveal shows it.
--   transfer_item_id  the pending transfer the key is locked in.
--   received_item_id  the transfer the key arrived by.
--   status            "transferred": the sender's key after the
--                     receiver accepted it; its code is wiped.
--
-- key_reveals: who has seen a code, per lineage, and how: entered (had
-- it in their vault from the start), revealed (an explicit reveal of a
-- sealed key) or claimed (a viewer took it as a prize). Append-only:
-- rows are never changed or deleted, so there is no foreign key to
-- users (which would rewrite them) and each row keeps the handle it
-- had then.
-- =====================================================================

CREATE TABLE key_transfers (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    from_user_id  bigint REFERENCES users(id) ON DELETE SET NULL,
    to_user_id    bigint REFERENCES users(id) ON DELETE SET NULL,
    from_handle   text NOT NULL,
    to_handle     text NOT NULL,
    status        text NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending', 'accepted', 'declined', 'cancelled', 'expired')),
    message       text CHECK (char_length(message) <= 300),
    lock_key      text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    expires_at    timestamptz NOT NULL,
    decided_at    timestamptz,
    CHECK (from_user_id IS NULL OR to_user_id IS NULL OR from_user_id <> to_user_id),
    CHECK ((status = 'pending') = (decided_at IS NULL))
);

CREATE INDEX key_transfers_to ON key_transfers (to_user_id, status);
CREATE INDEX key_transfers_from ON key_transfers (from_user_id, created_at DESC);
CREATE INDEX key_transfers_pending ON key_transfers (expires_at) WHERE status = 'pending';

CREATE TABLE key_transfer_items (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_id       bigint NOT NULL REFERENCES key_transfers(id) ON DELETE CASCADE,
    from_key_id       bigint REFERENCES game_keys(id) ON DELETE SET NULL,
    to_key_id         bigint REFERENCES game_keys(id) ON DELETE SET NULL,
    lineage           uuid NOT NULL,
    game_id           bigint NOT NULL REFERENCES games(id) ON DELETE RESTRICT,
    game_platform_id  bigint NOT NULL REFERENCES game_platforms(id) ON DELETE RESTRICT,
    key_platform_id   bigint NOT NULL REFERENCES key_platforms(id) ON DELETE RESTRICT,
    key_type          text NOT NULL,
    content_type      text NOT NULL,
    sealed_code       text,
    outcome           text CHECK (outcome IN ('moved', 'returned', 'duplicate'))
);

CREATE INDEX key_transfer_items_transfer ON key_transfer_items (transfer_id);
CREATE INDEX key_transfer_items_lineage ON key_transfer_items (lineage);
CREATE INDEX key_transfer_items_from ON key_transfer_items (from_key_id);
CREATE INDEX key_transfer_items_to ON key_transfer_items (to_key_id);

ALTER TABLE game_keys
    ADD COLUMN lineage uuid NOT NULL DEFAULT gen_random_uuid(),
    ADD COLUMN origin text NOT NULL DEFAULT 'own' CHECK (origin IN ('own', 'transfer', 'streamorg')),
    ADD COLUMN code_seen boolean NOT NULL DEFAULT true,
    ADD COLUMN transfer_item_id bigint REFERENCES key_transfer_items(id) ON DELETE SET NULL,
    ADD COLUMN received_item_id bigint REFERENCES key_transfer_items(id) ON DELETE SET NULL;

ALTER TABLE game_keys DROP CONSTRAINT game_keys_status_check;
ALTER TABLE game_keys ADD CONSTRAINT game_keys_status_check
    CHECK (status IN ('available', 'reserved', 'for_giveaway', 'used', 'given_away', 'expired', 'revoked', 'transferred'));

CREATE INDEX game_keys_lineage ON game_keys (lineage);
CREATE UNIQUE INDEX game_keys_in_transfer ON game_keys (transfer_item_id) WHERE transfer_item_id IS NOT NULL;

CREATE TABLE key_reveals (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    lineage      uuid NOT NULL,
    user_id      bigint,
    handle       text NOT NULL,
    kind         text NOT NULL CHECK (kind IN ('entered', 'revealed', 'claimed')),
    happened_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX key_reveals_lineage ON key_reveals (lineage, happened_at);

CREATE FUNCTION key_reveals_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'key_reveals is append-only';
END;
$$;

CREATE TRIGGER key_reveals_no_change BEFORE UPDATE OR DELETE ON key_reveals
    FOR EACH ROW EXECUTE FUNCTION key_reveals_append_only();

CREATE TRIGGER key_reveals_no_truncate BEFORE TRUNCATE ON key_reveals
    FOR EACH STATEMENT EXECUTE FUNCTION key_reveals_append_only();
