-- =====================================================================
-- The chat bot posts each new winner's claim link in the streamer's chat,
-- tagging the winner, when the giveaway asks for it.
--
-- giveaways.post_winner_link: on for giveaways created from now on, off
-- for the existing ones (they keep working as before until switched on).
--
-- The giveaway built-in gains the "winner" message ({user}, {giveaway},
-- {link}): in the defaults, and in the streamers' own versions of that
-- command, in their language, so nobody's is left blank (blank = say
-- nothing).
-- =====================================================================

ALTER TABLE giveaways ADD COLUMN post_winner_link boolean NOT NULL DEFAULT false;
ALTER TABLE giveaways ALTER COLUMN post_winner_link SET DEFAULT true;

UPDATE bot_commands
   SET messages = messages || jsonb_build_object('winner', '🎉 @{user}, you won in {giveaway}! Claim your prize, signed in with your Twitch account: {link}')
 WHERE user_id IS NULL AND code = 'giveaway' AND locale = 'en';

UPDATE bot_commands
   SET messages = messages || jsonb_build_object('winner', '🎉 @{user}, você ganhou em {giveaway}! Resgate o prêmio entrando com a sua conta da Twitch: {link}')
 WHERE user_id IS NULL AND code = 'giveaway' AND locale = 'pt-BR';

UPDATE bot_commands c
   SET messages = c.messages || jsonb_build_object('winner', coalesce(
           (SELECT d.messages->>'winner' FROM bot_commands d
             WHERE d.user_id IS NULL AND d.code = 'giveaway' AND d.locale = u.locale),
           (SELECT d.messages->>'winner' FROM bot_commands d
             WHERE d.user_id IS NULL AND d.code = 'giveaway' AND d.locale = 'en')))
  FROM users u
 WHERE c.user_id = u.id AND c.code = 'giveaway' AND NOT (c.messages ? 'winner');
