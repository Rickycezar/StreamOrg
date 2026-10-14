# Bot changelog

Releases of the StreamOrg chat bot, newest first. The bot has its own
version (in `package.json`), separate from the site's, and each release is
tagged in git as `bot-v<version>`.

`package.json` → `streamorg.requiresMigration` names the newest StreamOrg
migration this version needs. Migrations only run when the site is
released, so a bot change that needs a database change is released
together with the site, site first; until the migration is applied the bot
waits (and says so in its health check and logs) instead of running.

## Unreleased
- Speaks each streamer's language: the default commands are per language,
  English filling any gap. A custom command keeps its trigger when a new
  built-in comes with the same one.
- New commands: giveaways (`!giveaway`, `!join <keyword>` records chat
  entries, `!prizes <name>` links the public prize page), schedule
  (`!today`, `!week`) and `!watchtime`. Each has further messages for the
  other outcomes; an empty one says nothing.
- Everything it says goes through a queue per channel: at most one
  message a second and 20 per 30 seconds, a command typed many times at
  once answers once, and replies that waited over a minute are dropped.
  Long lists go in several messages.
- Posts what the site asks it to (the giveaway page's "Post in chat")
  within a second (NOTIFY streamorg_bot, 'outbox').
- Records the channel's events (subscriptions, gifts, bits, raids, watch
  streaks, charity, and follows where the streamer or the bot as a
  moderator may see them) for Stream tools → Events; chat notifications are
  now subscribed in every channel it reads.
- Once a day, for the channels an administrator chose, compares the
  follower list to notice unfollows.
- Needs the site's 047_bot_giveaways_events migration.

## 0.4.1 — 2026-10-12
- Lead Moderators count as moderators (Twitch gives them a
  lead_moderator badge instead of moderator), for commands, shoutouts
  and alerts.
- Shoutouts it does not answer are logged with the reason (not one of the
  overlay's roles, no channel with that name).

## 0.4.0 — 2026-10-09
- Answers custom alerts fired from chat (each alert overlay's command and
  roles, at most every 5 seconds per command) with the alert's reply.
- Answers shoutouts (the shoutout overlay's commands, by its roles) and
  watch streaks shared in chat with the replies set on the streamer's
  overlays; watch streaks come from chat notifications, subscribed to only
  in channels with such a reply. Needs the site's 045_overlays migration.
- Waits for the migration it needs (`requiresMigration`) before starting,
  and says which one, instead of running against an older database.

## 0.3.0 — 2026-10-06
- Runs on Twitch's chat API (EventSub conduit and the Send Chat Message
  endpoint) with the Chat Bot badge; in each channel the streamer either
  grants channel:bot or makes the bot a moderator.
- Built-in heartbeat command, per-streamer custom commands with
  cooldowns and permissions, and timed messages (an interval and a
  minimum number of chat messages between posts).
- Viewer statistics while a channel is live: chat messages and
  estimated watch time per viewer, kept for 12 months.
- Re-checks a channel after its owner reconnects Twitch; admins can add
  the bot to a channel.
