-- =====================================================================
-- One editable title template per content, instead of separate prefixes
-- and text (037): the title is typed freely, prefixes included, and a
-- counter appears in it as {name}. Content whose title uses no counter
-- keeps no template (title_template is NULL) and its title is just text.
--
-- Numbering is unchanged: a counter's number is the content's place in
-- time among the dated, not cancelled content using it, after where the
-- counter starts; undated content shows "?". The triggers now watch the
-- template.
-- =====================================================================

ALTER TABLE streams ADD COLUMN title_template text;

UPDATE streams
   SET title_template = btrim(array_to_string(title_prefixes, ' ') || ' ' || coalesce(title_body, ''))
 WHERE title_prefixes IS NOT NULL;

UPDATE streams SET title_template = NULL WHERE title_template IS NOT NULL AND position('{' IN title_template) = 0;

DROP TRIGGER streams_renumber_titles ON streams;

ALTER TABLE streams DROP COLUMN title_prefixes, DROP COLUMN title_body;

CREATE OR REPLACE FUNCTION streamorg_renumber_titles(p_user bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    s        record;
    c        record;
    rendered text;
    num      bigint;
BEGIN
    FOR s IN
        SELECT id, title, title_template, scheduled_start, status
          FROM streams
         WHERE user_id = p_user AND title_template IS NOT NULL
    LOOP
        rendered := s.title_template;

        FOR c IN SELECT name, value FROM user_counters WHERE user_id = p_user LOOP
            CONTINUE WHEN position('{' || c.name || '}' IN rendered) = 0;

            IF s.scheduled_start IS NULL OR s.status = 'cancelled' THEN
                num := NULL;
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

CREATE OR REPLACE FUNCTION streamorg_streams_renumber() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.title_template IS NOT NULL THEN
            PERFORM streamorg_renumber_titles(OLD.user_id);
        END IF;
        RETURN OLD;
    END IF;

    IF NEW.title_template IS NOT NULL
       OR (TG_OP = 'UPDATE' AND OLD.title_template IS NOT NULL) THEN
        PERFORM streamorg_renumber_titles(NEW.user_id);
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER streams_renumber_titles
    AFTER INSERT OR DELETE OR UPDATE OF scheduled_start, status, title_template ON streams
    FOR EACH ROW EXECUTE FUNCTION streamorg_streams_renumber();
