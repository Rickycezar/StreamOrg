-- =====================================================================
-- Streamers gain a provenance trail, the same way games did, so a profile
-- imported from Twitch can be refreshed later instead of going stale.
--
-- Streamers stay user-owned: who you collaborate with is personal, unlike
-- the game catalogue which everyone shares.
-- =====================================================================

ALTER TABLE streamers
    ADD COLUMN avatar_url        text,
    ADD COLUMN description       text,
    ADD COLUMN broadcaster_type  text,
    ADD COLUMN source_provider   citext,
    ADD COLUMN source_ref        text,
    ADD COLUMN source_synced_at  timestamptz;

COMMENT ON COLUMN streamers.source_ref IS
    'The provider''s own id — for Twitch the numeric user id, which survives a rename.';
COMMENT ON COLUMN streamers.broadcaster_type IS
    'Twitch: partner, affiliate, or empty for neither.';

-- The same person cannot be imported twice for one user. Matched on the
-- provider id rather than the handle, so a rename does not create a
-- duplicate.
CREATE UNIQUE INDEX streamers_source_idx
    ON streamers (user_id, source_provider, source_ref)
    WHERE source_provider IS NOT NULL AND source_ref IS NOT NULL;

-- ---------------------------------------------------------------------
-- A collab is planned first and becomes a stream later, so record which
-- platform and time it is heading for.
-- ---------------------------------------------------------------------
ALTER TABLE collabs
    ADD COLUMN streaming_platform_id bigint REFERENCES streaming_platforms (id) ON DELETE SET NULL,
    ADD COLUMN proposed_at           timestamptz;

COMMENT ON COLUMN collabs.proposed_at IS
    'Proposed start including a time. proposed_for keeps the date-only answer.';

CREATE INDEX collabs_status_idx ON collabs (user_id, status);
CREATE INDEX collab_streamers_collab_idx ON collab_streamers (collab_id);
