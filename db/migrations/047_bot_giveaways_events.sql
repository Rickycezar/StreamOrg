-- =====================================================================
-- The chat bot speaks each streamer's language, knows the giveaways and
-- the schedule, keeps the channel's events, and (when an administrator
-- allows it) notices who stopped following.
--
-- bot_commands: the default commands are now one row per built-in and
-- language (locale); a channel uses the defaults in its streamer's
-- language, or English. A streamer's own version has no locale. Besides
-- its reply, a command has further messages for other outcomes (no open
-- giveaway, ask which one…), by name in messages; an empty message means
-- the bot says nothing in that case.
--
-- giveaways.public_code names a giveaway's public prize page
-- (/giveaway?g=…), the link the bot gives in chat.
--
-- bot_outbox: messages the site asks the bot to post (the giveaway
-- page's "post in chat"), taken and deleted by the bot right away; a
-- message nobody posted within a few minutes is dropped.
--
-- channel_events: what happened in a channel the bot reads (follows,
-- subscriptions, gifts, bits, raids, watch streaks, unfollows), kept 90
-- days.
--
-- follower_tracking / channel_followers: a daily look at a channel's
-- follower list, to tell who stopped following. Only for channels an
-- administrator switched it on for, and only while the administrators'
-- setting unfollows.enabled is on.
-- =====================================================================

ALTER TABLE bot_commands
    ADD COLUMN locale   text,
    ADD COLUMN messages jsonb NOT NULL DEFAULT '{}'::jsonb;

UPDATE bot_commands SET locale = 'en' WHERE user_id IS NULL;

ALTER TABLE bot_commands ADD CONSTRAINT bot_commands_default_locale
    CHECK (user_id IS NOT NULL OR locale IS NOT NULL);

DROP INDEX bot_commands_default;
CREATE UNIQUE INDEX bot_commands_default ON bot_commands (code, locale) WHERE user_id IS NULL;

INSERT INTO bot_commands (code, locale, trigger, response, permission, cooldown_seconds, messages)
SELECT 'heartbeat', 'pt-BR', trigger, '💓 O StreamOrg está aqui, @{user}! No ar há {uptime}.', permission, cooldown_seconds, '{}'::jsonb
  FROM bot_commands WHERE user_id IS NULL AND code = 'heartbeat' AND locale = 'en';

INSERT INTO bot_commands (code, locale, trigger, response, permission, cooldown_seconds, messages) VALUES
    ('giveaway', 'en', 'giveaway', '🎁 Giveaways: {list}. See the prizes with {prizes} <name>', 'everyone', 30,
     '{"none": "No giveaway open right now.", "entry": "Type {command} to enter!", "announce": "🎁 {giveaway} is on! {entry} Prizes: {link}"}'),
    ('giveaway', 'pt-BR', 'sorteio', '🎁 Sorteios: {list}. Veja os prêmios com {prizes} <nome>', 'everyone', 30,
     '{"none": "Nenhum sorteio aberto agora.", "entry": "Digite {command} para participar!", "announce": "🎁 Sorteio {giveaway} no ar! {entry} Prêmios: {link}"}'),
    ('join', 'en', 'join', '@{user} you are in {giveaway}! Good luck 🍀', 'everyone', 0,
     '{"already": "", "which": "@{user} which giveaway? {list}", "unknown": "@{user} there is no open giveaway called \"{keyword}\". Open: {list}", "none": "@{user} no giveaway is taking entries right now."}'),
    ('join', 'pt-BR', 'participar', '@{user} você está participando de {giveaway}! Boa sorte 🍀', 'everyone', 0,
     '{"already": "", "which": "@{user} qual sorteio? {list}", "unknown": "@{user} não há sorteio aberto chamado \"{keyword}\". Abertos: {list}", "none": "@{user} nenhum sorteio está aceitando participações agora."}'),
    ('prizes', 'en', 'prizes', '🎁 Prizes of {giveaway}: {link}', 'everyone', 15,
     '{"ask": "Which giveaway? Type {command} <name>: {list}", "unknown": "No giveaway called \"{name}\". Try: {list}", "none": "No giveaway right now."}'),
    ('prizes', 'pt-BR', 'premios', '🎁 Prêmios de {giveaway}: {link}', 'everyone', 15,
     '{"ask": "Qual sorteio? Digite {command} <nome>: {list}", "unknown": "Não achei o sorteio \"{name}\". Tente: {list}", "none": "Nenhum sorteio no momento."}'),
    ('today', 'en', 'today', '📅 Today: {list}', 'everyone', 30,
     '{"none": "Nothing scheduled for today."}'),
    ('today', 'pt-BR', 'hoje', '📅 Hoje: {list}', 'everyone', 30,
     '{"none": "Nada agendado para hoje."}'),
    ('week', 'en', 'week', '📅 Next 7 days: {list}', 'everyone', 60,
     '{"none": "Nothing scheduled for the next 7 days."}'),
    ('week', 'pt-BR', 'semana', '📅 Próximos 7 dias: {list}', 'everyone', 60,
     '{"none": "Nada agendado para os próximos 7 dias."}'),
    ('watchtime', 'en', 'watchtime', '@{user} {target} has watched {time} here since {since}.', 'everyone', 10,
     '{"none": "@{user} no watch time for {target} yet."}'),
    ('watchtime', 'pt-BR', 'tempo', '@{user} {target} assistiu {time} aqui desde {since}.', 'everyone', 10,
     '{"none": "@{user} ainda não tenho tempo registrado de {target}."}');

ALTER TABLE giveaways
    ADD COLUMN public_code text NOT NULL DEFAULT substr(md5(random()::text || clock_timestamp()::text), 1, 12);

CREATE UNIQUE INDEX giveaways_public_code ON giveaways (public_code);

CREATE TABLE bot_outbox (
    id         bigserial   PRIMARY KEY,
    user_id    bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    message    text        NOT NULL CHECK (length(message) BETWEEN 1 AND 500),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE channel_events (
    id          bigserial   PRIMARY KEY,
    user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    at          timestamptz NOT NULL DEFAULT now(),
    -- lang key: channel_event.<code>
    type        text        NOT NULL CHECK (type IN (
                    'follow', 'unfollow', 'sub', 'resub', 'gift_sub', 'community_gift', 'gift_upgrade',
                    'prime_upgrade', 'cheer', 'raid', 'watch_streak', 'bits_badge', 'charity')),
    actor_id    text,
    actor_login text,
    actor_name  text,
    amount      integer,
    tier        text,
    message     text,
    data        jsonb       NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX channel_events_user ON channel_events (user_id, at DESC);
CREATE INDEX channel_events_type ON channel_events (user_id, type, at DESC);

CREATE TABLE follower_tracking (
    user_id        bigint      PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    is_enabled     boolean     NOT NULL DEFAULT false,
    checked_at     timestamptz,
    follower_count integer,
    last_error     text
);

CREATE TABLE channel_followers (
    user_id      bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    follower_id  text        NOT NULL,
    login        text        NOT NULL,
    display_name text,
    followed_at  timestamptz,
    PRIMARY KEY (user_id, follower_id)
);

INSERT INTO app_settings (key, value) VALUES ('unfollows.enabled', '0') ON CONFLICT (key) DO NOTHING;
