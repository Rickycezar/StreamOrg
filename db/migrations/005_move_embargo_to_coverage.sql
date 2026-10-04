-- =====================================================================
-- Move embargo_until from game_keys to game_coverage.
--
-- An embargo is one fact per game: the publisher says "no coverage before
-- this date". Holding it on the key duplicated it once per key — nine
-- times over for a game with nine keys — and made it impossible to record
-- an embargo for a game you have no key for (a preview build, a press
-- invite, a copy you bought under agreement).
--
-- game_coverage is already UNIQUE (user_id, game_id), is private to the
-- user, and is the record of your intent to cover a title — which is
-- exactly what an embargo constrains.
--
-- expires_at stays on game_keys: the redemption deadline belongs to the
-- individual token, and two keys for one game can genuinely differ.
-- =====================================================================

ALTER TABLE game_coverage ADD COLUMN embargo_until timestamptz;

COMMENT ON COLUMN game_coverage.embargo_until IS
    'Coverage of this game must not be published before this instant.';

-- Carry across anything already recorded, newest embargo winning, creating
-- the coverage row where one does not exist yet.
INSERT INTO game_coverage (user_id, game_id, status, embargo_until)
SELECT k.user_id, k.game_id, 'key_received', max(k.embargo_until)
  FROM game_keys k
 WHERE k.embargo_until IS NOT NULL
 GROUP BY k.user_id, k.game_id
ON CONFLICT (user_id, game_id) DO UPDATE
   SET embargo_until = EXCLUDED.embargo_until;

DROP VIEW IF EXISTS content_key_warnings;
DROP INDEX IF EXISTS game_keys_embargo_idx;
ALTER TABLE game_keys DROP COLUMN embargo_until;

CREATE INDEX game_coverage_embargo_idx ON game_coverage (user_id, embargo_until)
    WHERE embargo_until IS NOT NULL;

-- Rebuilt against the new home. Embargo now comes from the game, so a
-- warning can fire for content with no key at all — which was impossible
-- before and is the main reason for the move.
CREATE VIEW content_key_warnings AS
SELECT s.id           AS stream_id,
       s.user_id,
       s.title        AS content_title,
       s.scheduled_start,
       g.id           AS game_id,
       g.title        AS game_title,
       sg.game_key_id,
       c.embargo_until,
       k.expires_at,
       k.status       AS key_status,
       (s.scheduled_start IS NOT NULL
        AND c.embargo_until IS NOT NULL
        AND s.scheduled_start < c.embargo_until)        AS breaks_embargo,
       (s.scheduled_start IS NOT NULL
        AND k.expires_at IS NOT NULL
        AND k.status = 'available'
        AND k.expires_at < s.scheduled_start)           AS expires_before_use
  FROM streams s
  JOIN stream_games sg  ON sg.stream_id = s.id
  JOIN games g          ON g.id = sg.game_id
  LEFT JOIN game_keys k ON k.id = sg.game_key_id
  LEFT JOIN game_coverage c
         ON c.game_id = sg.game_id AND c.user_id = s.user_id
 WHERE s.status IN ('planned', 'live');

COMMENT ON VIEW content_key_warnings IS
    'Planned content published before its game''s embargo lifts, or relying on a key that expires first.';
