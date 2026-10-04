-- =====================================================================
-- Embargo dates on keys.
--
-- Distinct from expires_at, which they are easy to confuse:
--   expires_at    — the last moment the key can be REDEEMED. Miss it and
--                   the key is worthless. Comes from the store or bundle.
--   embargo_until — the first moment coverage may be PUBLISHED. Publish
--                   before it and you break the deal with the publisher.
--                   Comes with review keys.
--
-- A key can have both, neither, or either.
-- =====================================================================

ALTER TABLE game_keys ADD COLUMN embargo_until timestamptz;

COMMENT ON COLUMN game_keys.embargo_until IS
    'Coverage must not be published before this instant. Typically set on review keys.';
COMMENT ON COLUMN game_keys.expires_at IS
    'Last moment the key can be redeemed. Unrelated to embargo_until.';

-- Supports "which of my keys are still under embargo" and the conflict
-- check between a stream''s scheduled_start and its key''s embargo.
CREATE INDEX game_keys_embargo_idx ON game_keys (user_id, embargo_until)
    WHERE embargo_until IS NOT NULL;

CREATE INDEX game_keys_expires_idx ON game_keys (user_id, expires_at)
    WHERE expires_at IS NOT NULL;

-- Content scheduled before the embargo on the key it uses, or relying on a
-- key that must be redeemed before the stream date. Both are things worth
-- being told about before the date arrives, not after.
CREATE VIEW content_key_warnings AS
SELECT s.id              AS stream_id,
       s.user_id,
       s.title           AS content_title,
       s.scheduled_start,
       g.id              AS game_id,
       g.title           AS game_title,
       k.id              AS game_key_id,
       k.embargo_until,
       k.expires_at,
       k.status          AS key_status,
       (s.scheduled_start IS NOT NULL
        AND k.embargo_until IS NOT NULL
        AND s.scheduled_start < k.embargo_until)            AS breaks_embargo,
       (s.scheduled_start IS NOT NULL
        AND k.expires_at IS NOT NULL
        AND k.status = 'available'
        AND k.expires_at < s.scheduled_start)               AS expires_before_use
  FROM streams s
  JOIN stream_games sg ON sg.stream_id = s.id
  JOIN games g         ON g.id = sg.game_id
  JOIN game_keys k     ON k.id = sg.game_key_id
 WHERE s.status IN ('planned', 'live');

COMMENT ON VIEW content_key_warnings IS
    'Planned content whose key is still embargoed, or expires before the stream.';
