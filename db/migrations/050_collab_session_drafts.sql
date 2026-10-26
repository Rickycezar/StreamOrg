-- =====================================================================
-- Joint plans start as drafts: only the host sees one until they set the
-- date and time, write a first message and send the invitations, which
-- then carry that time.
-- =====================================================================

ALTER TABLE collab_sessions DROP CONSTRAINT IF EXISTS collab_sessions_status_check;
ALTER TABLE collab_sessions ADD CONSTRAINT collab_sessions_status_check CHECK (status IN ('draft', 'active', 'cancelled'));

DROP INDEX collab_sessions_one_active;
CREATE UNIQUE INDEX collab_sessions_one_active ON collab_sessions (collab_id) WHERE status IN ('draft', 'active') AND collab_id IS NOT NULL;
