-- =====================================================================
-- Embargoes become a table; release dates gain precision; providers gain
-- an administrator-chosen default.
--
-- One embargo per game was wrong for the same reason one embargo per key
-- was: a title can carry several at once. A PC release embargo and a
-- console one lift at different times, and a 1.0 or DLC update brings its
-- own. They are also personal — the arrangement is between you and the
-- publisher — which is why this table is user-owned while games are not.
-- =====================================================================

CREATE TABLE game_embargoes (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id          bigint      NOT NULL REFERENCES users (id)  ON DELETE CASCADE,
    game_id          bigint      NOT NULL REFERENCES games (id)  ON DELETE CASCADE,

    -- Null means "every platform". A row naming a platform applies only to
    -- content redeemed there, which is how a staggered console release is
    -- expressed. lang key: game_platform.<code>
    game_platform_id bigint      REFERENCES game_platforms (id)  ON DELETE CASCADE,

    -- What this embargo is about. lang key: embargo_kind.<code>
    kind             text        NOT NULL DEFAULT 'release'
        CHECK (kind IN ('release', 'review', 'update', 'dlc', 'other')),

    -- Free text for the specific occasion: "1.0 launch", "Switch port".
    label            text,

    lifts_at         timestamptz NOT NULL,

    -- Where the date came from, so an automatic one can be refreshed
    -- without clobbering something typed by hand.
    -- lang key: embargo_source.<code>
    source           text        NOT NULL DEFAULT 'manual'
        CHECK (source IN ('manual', 'release_date', 'api')),

    note             text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);

-- One row per occasion. COALESCE gives the "all platforms" row its own
-- slot rather than letting NULL defeat the constraint.
CREATE UNIQUE INDEX game_embargoes_slot_idx
    ON game_embargoes (user_id, game_id, kind, COALESCE(game_platform_id, 0));

CREATE INDEX game_embargoes_game_idx   ON game_embargoes (user_id, game_id);
CREATE INDEX game_embargoes_active_idx ON game_embargoes (user_id, lifts_at);

CREATE TRIGGER game_embargoes_touch BEFORE UPDATE ON game_embargoes
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Carry across what game_coverage held, as an all-platforms release embargo.
INSERT INTO game_embargoes (user_id, game_id, kind, lifts_at, source, note)
SELECT user_id, game_id, 'release', embargo_until, 'manual',
       'Migrated from game_coverage'
  FROM game_coverage
 WHERE embargo_until IS NOT NULL;

DROP VIEW IF EXISTS content_key_warnings;
DROP INDEX IF EXISTS game_coverage_embargo_idx;
ALTER TABLE game_coverage DROP COLUMN embargo_until;

-- ---------------------------------------------------------------------
-- Release dates are often partial. "2027" and "Q1 2027" are real answers
-- from a store front, and a date column cannot say which part is known.
-- ---------------------------------------------------------------------
ALTER TABLE games
    ADD COLUMN release_precision text NOT NULL DEFAULT 'unknown'
        CHECK (release_precision IN ('day', 'month', 'year', 'unknown')),
    ADD COLUMN release_raw       text,
    ADD COLUMN source_synced_at  timestamptz;

COMMENT ON COLUMN games.release_precision IS
    'How much of release_date is real. Only ''day'' is safe to treat as an exact date.';
COMMENT ON COLUMN games.release_raw IS
    'What the provider actually said, kept verbatim for re-parsing later.';
COMMENT ON COLUMN games.source_synced_at IS
    'When this row was last refreshed from its source provider.';

-- Everything imported so far came from a full Steam date.
UPDATE games SET release_precision = 'day' WHERE release_date IS NOT NULL;

-- Games worth re-querying: no date, or a date too vague to act on.
CREATE INDEX games_needs_release_idx ON games (id)
    WHERE release_date IS NULL OR release_precision <> 'day';

-- ---------------------------------------------------------------------
-- Choosing a provider is administrative. Non-admins import through
-- whichever one is marked default; they never see the choice.
-- ---------------------------------------------------------------------
ALTER TABLE api_settings ADD COLUMN is_default boolean NOT NULL DEFAULT false;

CREATE UNIQUE INDEX api_settings_one_default_idx ON api_settings (is_default)
    WHERE is_default;

UPDATE api_settings SET is_default = true WHERE provider = 'steam';

-- ---------------------------------------------------------------------
-- Rebuilt against game_embargoes.
--
-- Several embargoes can apply to one game at once, and all of them have
-- to be respected, so the binding one is simply the latest that applies.
-- An embargo naming no platform applies everywhere; one naming a platform
-- applies only when the key redeems there. With no key the platform is
-- unknown, so every embargo on the game is considered — erring toward
-- warning rather than staying quiet.
-- ---------------------------------------------------------------------
CREATE VIEW content_key_warnings AS
SELECT s.id           AS stream_id,
       s.user_id,
       s.title        AS content_title,
       s.scheduled_start,
       s.deadline,
       g.id           AS game_id,
       g.title        AS game_title,
       sg.game_key_id,
       emb.lifts_at   AS embargo_until,
       emb.kind       AS embargo_kind,
       emb.label      AS embargo_label,
       k.expires_at,
       k.status       AS key_status,

       (s.scheduled_start IS NOT NULL
        AND emb.lifts_at IS NOT NULL
        AND s.scheduled_start < emb.lifts_at)             AS breaks_embargo,

       (s.scheduled_start IS NOT NULL
        AND k.expires_at IS NOT NULL
        AND k.status = 'available'
        AND k.expires_at < s.scheduled_start)             AS expires_before_use,

       (s.scheduled_start IS NOT NULL
        AND s.deadline IS NOT NULL
        AND s.scheduled_start > s.deadline)               AS misses_deadline,

       (s.scheduled_start IS NULL
        AND s.deadline IS NOT NULL
        AND s.deadline < now())                           AS overdue,

       (emb.lifts_at IS NOT NULL
        AND s.deadline IS NOT NULL
        AND emb.lifts_at > s.deadline)                    AS impossible_window

  FROM streams s
  LEFT JOIN stream_games sg ON sg.stream_id = s.id
  LEFT JOIN games g         ON g.id = sg.game_id
  LEFT JOIN game_keys k     ON k.id = sg.game_key_id
  LEFT JOIN LATERAL (
      SELECT e.lifts_at, e.kind, e.label
        FROM game_embargoes e
       WHERE e.user_id = s.user_id
         AND e.game_id = sg.game_id
         AND (e.game_platform_id IS NULL
              OR k.game_platform_id IS NULL
              OR e.game_platform_id = k.game_platform_id)
       ORDER BY e.lifts_at DESC
       LIMIT 1
  ) emb ON true
 WHERE s.status IN ('planned', 'live');

COMMENT ON VIEW content_key_warnings IS
    'Scheduling problems on planned content, against the latest applicable embargo.';
