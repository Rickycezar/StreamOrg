-- =====================================================================
-- Counter numbers follow the timeline.
--
-- Content created with prefixes keeps them as templates
-- (streams.title_prefixes, e.g. {"[STREAM #{stream}]","[PT-BR]"}) apart
-- from the creator's own text (streams.title_body); streams.title stays
-- the finished title everything else reads.
--
-- A counter's value is now where its numbers start after: each dated,
-- not cancelled content using the counter gets value + its position in
-- time (earliest first). Undated content shows "?" until it has a place
-- on the calendar. Whenever content is added, moved, cancelled, given
-- new prefixes or text, or deleted, or a counter changes, the user's
-- titles are worked out again by the triggers below, so the numbers are
-- always in chronological order whatever changed them.
--
-- The numbers taken at creation by 036 (stream_counter_uses) are no
-- longer needed: titles written that way keep their numbers as plain
-- text.
-- =====================================================================

ALTER TABLE streams
    ADD COLUMN title_prefixes text[],
    ADD COLUMN title_body     text;

DROP TABLE stream_counter_uses;

CREATE FUNCTION streamorg_renumber_titles(p_user bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    s       record;
    c       record;
    rendered text;
    num     bigint;
BEGIN
    FOR s IN
        SELECT id, title, title_prefixes, coalesce(title_body, '') AS body, scheduled_start, status
          FROM streams
         WHERE user_id = p_user AND title_prefixes IS NOT NULL
    LOOP
        rendered := array_to_string(s.title_prefixes, ' ');

        FOR c IN SELECT name, value FROM user_counters WHERE user_id = p_user LOOP
            CONTINUE WHEN position('{' || c.name || '}' IN rendered) = 0;

            IF s.scheduled_start IS NULL OR s.status = 'cancelled' THEN
                num := NULL;
            ELSE
                SELECT c.value + count(*) INTO num
                  FROM streams o
                 WHERE o.user_id = p_user
                   AND o.title_prefixes IS NOT NULL
                   AND position('{' || c.name || '}' IN array_to_string(o.title_prefixes, ' ')) > 0
                   AND o.scheduled_start IS NOT NULL
                   AND o.status <> 'cancelled'
                   AND (o.scheduled_start, o.id) <= (s.scheduled_start, s.id);
            END IF;

            rendered := replace(rendered, '{' || c.name || '}', coalesce(num::text, '?'));
        END LOOP;

        rendered := btrim(rendered || ' ' || s.body);

        IF rendered IS DISTINCT FROM s.title THEN
            UPDATE streams SET title = rendered WHERE id = s.id;
        END IF;
    END LOOP;
END;
$$;

CREATE FUNCTION streamorg_streams_renumber() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.title_prefixes IS NOT NULL THEN
            PERFORM streamorg_renumber_titles(OLD.user_id);
        END IF;
        RETURN OLD;
    END IF;

    IF NEW.title_prefixes IS NOT NULL
       OR (TG_OP = 'UPDATE' AND OLD.title_prefixes IS NOT NULL) THEN
        PERFORM streamorg_renumber_titles(NEW.user_id);
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER streams_renumber_titles
    AFTER INSERT OR DELETE OR UPDATE OF scheduled_start, status, title_prefixes, title_body ON streams
    FOR EACH ROW EXECUTE FUNCTION streamorg_streams_renumber();

CREATE FUNCTION streamorg_counters_renumber() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM streamorg_renumber_titles(CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END);
    RETURN NULL;
END;
$$;

CREATE TRIGGER user_counters_renumber_titles
    AFTER INSERT OR DELETE OR UPDATE ON user_counters
    FOR EACH ROW EXECUTE FUNCTION streamorg_counters_renumber();
