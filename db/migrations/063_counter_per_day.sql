-- =====================================================================
-- Title counters can count days instead of contents (per_day, on by
-- default, also for the counters that already exist): every content on
-- the same day, in the creator's time zone, gets the same number, and
-- the next day with content gets the next one. Off, each content counts
-- on its own, as before.
--
-- streamorg_renumber_titles works the numbers out again: per day, a
-- content's number is the counter's value plus how many distinct days
-- with content using it there are up to its own day.
-- =====================================================================

ALTER TABLE user_counters ADD COLUMN per_day boolean NOT NULL DEFAULT true;

CREATE OR REPLACE FUNCTION streamorg_renumber_titles(p_user bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    s        record;
    c        record;
    rendered text;
    num      bigint;
    zone     text;
BEGIN
    SELECT coalesce((SELECT name FROM pg_timezone_names WHERE name = u.timezone LIMIT 1), 'UTC')
      INTO zone
      FROM users u WHERE u.id = p_user;

    zone := coalesce(zone, 'UTC');

    FOR s IN
        SELECT id, title, title_template, scheduled_start, status
          FROM streams
         WHERE user_id = p_user AND title_template IS NOT NULL
    LOOP
        rendered := s.title_template;

        FOR c IN SELECT name, value, per_day FROM user_counters WHERE user_id = p_user LOOP
            CONTINUE WHEN position('{' || c.name || '}' IN rendered) = 0;

            IF s.scheduled_start IS NULL OR s.status = 'cancelled' THEN
                num := NULL;
            ELSIF c.per_day THEN
                SELECT c.value + count(DISTINCT (o.scheduled_start AT TIME ZONE zone)::date) INTO num
                  FROM streams o
                 WHERE o.user_id = p_user
                   AND o.title_template IS NOT NULL
                   AND position('{' || c.name || '}' IN o.title_template) > 0
                   AND o.scheduled_start IS NOT NULL
                   AND o.status <> 'cancelled'
                   AND (o.scheduled_start AT TIME ZONE zone)::date <= (s.scheduled_start AT TIME ZONE zone)::date;
            ELSE
                SELECT c.value + count(*) INTO num
                  FROM streams o
                 WHERE o.user_id = p_user
                   AND o.title_template IS NOT NULL
                   AND position('{' || c.name || '}' IN o.title_template) > 0
                   AND o.scheduled_start IS NOT NULL
                   AND o.status <> 'cancelled'
                   AND (o.scheduled_start, o.id) <= (s.scheduled_start, s.id);
            END IF;

            rendered := replace(rendered, '{' || c.name || '}', coalesce(num::text, '?'));
        END LOOP;

        rendered := btrim(rendered);

        IF rendered IS DISTINCT FROM s.title THEN
            UPDATE streams SET title = rendered WHERE id = s.id;
        END IF;
    END LOOP;
END;
$$;

SELECT streamorg_renumber_titles(u.id)
  FROM users u
 WHERE EXISTS (SELECT 1 FROM user_counters c WHERE c.user_id = u.id);
