-- =====================================================================
-- Publication deadline on content.
--
-- Three dates now bracket a piece of content, and they are all different:
--   game_coverage.embargo_until  earliest you may PUBLISH   (publisher's rule)
--   streams.deadline             latest you should PUBLISH  (your commitment)
--   streams.scheduled_start      when you intend to go live (your plan)
--
-- scheduled_start stays nullable on purpose: content you intend to make but
-- have not dated yet is a real state, and the point of a backlog.
-- =====================================================================

ALTER TABLE streams ADD COLUMN deadline timestamptz;

COMMENT ON COLUMN streams.deadline IS
    'Latest this content should be published. Defaults to 30 days out when left blank.';
COMMENT ON COLUMN streams.scheduled_start IS
    'When the stream is planned to start. Null means undated — planned but not yet scheduled.';

CREATE INDEX streams_deadline_idx ON streams (user_id, deadline)
    WHERE deadline IS NOT NULL;

-- Undated planned content, which is the backlog view.
CREATE INDEX streams_undated_idx ON streams (user_id)
    WHERE scheduled_start IS NULL AND status = 'planned';

DROP VIEW IF EXISTS content_key_warnings;

-- Now a LEFT JOIN on stream_games: deadline problems apply to content with
-- no game attached too, and the old inner join hid those rows entirely.
CREATE VIEW content_key_warnings AS
SELECT s.id           AS stream_id,
       s.user_id,
       s.title        AS content_title,
       s.scheduled_start,
       s.deadline,
       g.id           AS game_id,
       g.title        AS game_title,
       sg.game_key_id,
       c.embargo_until,
       k.expires_at,
       k.status       AS key_status,

       -- Scheduled before the publisher lets you publish.
       (s.scheduled_start IS NOT NULL
        AND c.embargo_until IS NOT NULL
        AND s.scheduled_start < c.embargo_until)          AS breaks_embargo,

       -- The key must be redeemed before the stream date or it is lost.
       (s.scheduled_start IS NOT NULL
        AND k.expires_at IS NOT NULL
        AND k.status = 'available'
        AND k.expires_at < s.scheduled_start)             AS expires_before_use,

       -- Planned to go out after the date you committed to.
       (s.scheduled_start IS NOT NULL
        AND s.deadline IS NOT NULL
        AND s.scheduled_start > s.deadline)               AS misses_deadline,

       -- Undated and the deadline has already gone by.
       (s.scheduled_start IS NULL
        AND s.deadline IS NOT NULL
        AND s.deadline < now())                           AS overdue,

       -- Embargo lifts after the deadline: there is no legal window at all.
       (c.embargo_until IS NOT NULL
        AND s.deadline IS NOT NULL
        AND c.embargo_until > s.deadline)                 AS impossible_window

  FROM streams s
  LEFT JOIN stream_games sg ON sg.stream_id = s.id
  LEFT JOIN games g         ON g.id = sg.game_id
  LEFT JOIN game_keys k     ON k.id = sg.game_key_id
  LEFT JOIN game_coverage c ON c.game_id = sg.game_id AND c.user_id = s.user_id
 WHERE s.status IN ('planned', 'live');

COMMENT ON VIEW content_key_warnings IS
    'Scheduling problems on planned content: embargo breaches, keys expiring first, missed or impossible deadlines.';
