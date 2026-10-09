# Bot changelog

Releases of the StreamOrg chat bot, newest first. The bot has its own
version (in `package.json`), separate from the site's, and each release is
tagged in git as `bot-v<version>`.

`package.json` → `streamorg.requiresMigration` names the newest StreamOrg
migration this version needs. Migrations only run when the site is
released, so a bot change that needs a database change is released
together with the site, site first; until the migration is applied the bot
waits (and says so in its health check and logs) instead of running.

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
