-- =====================================================================
-- Counters for title prefixes ("[STREAM #{stream}]", "[CONTROL DAY
-- {control}]"), and several prefixes per title.
--
-- user_counters        a user's named counters; value is the last number
--                      given out, editable by hand.
-- stream_counter_uses  the number each content took from each counter
--                      when it was created with a prefix using it.
--                      Deleting that content gives the number back while
--                      it is still the counter's latest one.
--
-- Prefixes may now be several per title, so several can be preselected:
-- the one-default rule goes, and position orders them.
-- =====================================================================

CREATE TABLE user_counters (
    id         bigserial   PRIMARY KEY,
    user_id    bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name       text        NOT NULL CHECK (name ~ '^[a-z][a-z0-9_]{0,19}$'),
    value      integer     NOT NULL DEFAULT 0 CHECK (value BETWEEN 0 AND 999999),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, name)
);

CREATE TABLE stream_counter_uses (
    stream_id  bigint  NOT NULL REFERENCES streams (id) ON DELETE CASCADE,
    counter_id bigint  NOT NULL REFERENCES user_counters (id) ON DELETE CASCADE,
    value      integer NOT NULL,
    PRIMARY KEY (stream_id, counter_id)
);

DROP INDEX user_title_prefixes_one_default;
