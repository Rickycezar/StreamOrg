-- =====================================================================
-- The days and hours a user usually streams.
--
-- One row per streaming weekday (ISO: 1 = Monday ... 7 = Sunday), in the
-- user's own time zone. A day without a row is a day off. ends_at may be
-- earlier than starts_at for streams that run past midnight, and is
-- optional for people who only keep a start time.
--
-- Used as a default wherever the app needs a time the user did not give:
-- content dropped on a calendar day, or picked for today on the dashboard.
-- =====================================================================

CREATE TABLE user_stream_schedule (
    user_id   bigint   NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    weekday   smallint NOT NULL CHECK (weekday BETWEEN 1 AND 7),
    starts_at time     NOT NULL,
    ends_at   time,
    PRIMARY KEY (user_id, weekday)
);
