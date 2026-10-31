-- =====================================================================
-- The order of a giveaway's prizes, which the streamer can change: a
-- giveaway with a prize set per winner gives them in this order (the
-- first winner gets the first free prize). It starts as the order the
-- prizes were added in, which is how they were given until now.
-- =====================================================================

ALTER TABLE giveaway_prizes ADD COLUMN position integer NOT NULL DEFAULT 0;

UPDATE giveaway_prizes p
   SET position = o.n
  FROM (SELECT id, row_number() OVER (PARTITION BY giveaway_id ORDER BY id) AS n FROM giveaway_prizes) o
 WHERE o.id = p.id;

CREATE INDEX giveaway_prizes_order ON giveaway_prizes (giveaway_id, position, id);
