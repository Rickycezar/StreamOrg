# Changelog

Every release of StreamOrg, newest first. Versions follow
[semantic versioning](https://semver.org/): `major.minor.patch`. The running
version is in the `VERSION` file, shown in the account menu and in
Administration, and each release is tagged in git as `v<version>`.

## Unreleased

### Added
- Searching for a game shows Twitch categories and Steam games together,
  each marked with where it comes from.
- Administration → Games: games added by hand can be converted to Steam —
  all the certain matches at once, or one by one from a Steam search —
  getting their publisher, developer, release date, description and
  artwork while keeping their keys, content and embargoes.
- Content and the key vault have an "Added" column (when each was added),
  to sort by; like the other columns it can be hidden or moved.

### Fixed
- Adding a game from its Twitch category no longer ends up "by hand", with
  no publisher or developer: a Twitch token that had stopped working is
  replaced instead of IGDB's answer being taken as "nothing found", and when
  IGDB has no Steam link the Steam game with exactly that name is used.
  Adding a game that was already added by hand fills it in.

### Changed
- The privacy policy says that winners' Twitch display names appear on the
  giveaway's public prize page, and that a viewer's watch time in a channel
  can be shown in its chat to anyone who asks the bot.

## 0.9.4 — 2026-10-14

### Changed
- The chat bot's `!today` and `!week` list each content's games (or its
  Twitch category when it has none) instead of its title (bot 0.5.3).
- In card view, the cards have a slightly different background from the
  page around them, so they stand out.

## 0.9.3 — 2026-10-14

### Changed
- `!heartbeat` tells the streamer and moderators which version of the chat
  bot answered (bot 0.5.2); its description and the bot guide say so.

## 0.9.2 — 2026-10-14

### Changed
- Entering a giveaway by chat (the chat bot's `!join` / `!participar`) is
  hidden until the giveaway page gets a name picker: the command is never
  answered nor listed, and the giveaway pages, the bot's announcement, the
  giveaway form (entering by chat, the entries, drawing from them) and the
  guides no longer mention it. Giveaways set to chat read as picked by
  hand.

## 0.9.1 — 2026-10-14

### Fixed
- Games without a portrait picture showed an empty frame on the public
  prize page, My prizes and claim pages; they now show their wide picture
  whole, over a blurred copy of it.

## 0.9.0 — 2026-10-14

### Added
- Give a key straight to someone, without a giveaway: from the vault (Give)
  or from Given keys. They get a claim link like a giveaway winner; the
  gift never shows among the giveaways.
  The key is picked by searching for the game, then choosing one of its
  keys.
- Sponsorships → Given keys: everyone a key was given or promised to, by
  giveaway or as a gift — the key, how they won, when the link was sent
  and when they took it — with links to copy or withdraw, and shortcuts
  to give a key or create a giveaway.
- The vault shows each key's winner (or the giveaway it is a prize in).
- Lists can be shown as cards: account menu → List view → Table / Cards.
  It switches at once, without reloading, and is remembered for the
  account. Each row becomes a card with its fields labelled, its actions
  at the bottom, and the column headings as sort buttons; in the vault the
  cards show the game's cover.
- Chat bot commands for giveaways: `!giveaway` lists the open ones and how
  to enter, `!join <keyword>` enters one that takes entries by chat (the
  entries the giveaway page already listed now actually come in), and
  `!prizes <name>` answers with the link to the giveaway's new public prize
  page — the keys still to win and the ones already won, never a code;
  without a name it asks which giveaway, naming the ones not closed.
- Chat bot commands for the schedule and watch time: `!today` and `!week`
  (the next 7 days, in the streamer's timezone, in more than one message
  when long) and `!watchtime` (yours, or someone's by name).
- "Post in chat" on a giveaway's page: the bot announces it in the
  streamer's chat, with how to enter and the prize page link.
- Commands and their messages follow the streamer's language: the
  administrators set the defaults per language (Administration → Chat bot),
  and each streamer can still make every command and message their own.
  An empty message means the bot says nothing in that case.
- Stream tools → Events: follows, subscriptions, resubs and gifts, bits,
  raids, watch streaks and charity in the channel, recorded by the bot,
  filtered by kind, refreshing by itself; kept 90 days. Follows need
  Twitch's permission (reconnect Twitch, or the bot as a moderator).
- Stream tools → Unfollows, for the channels an administrator switches it
  on for (Administration → Chat bot, off by default): the bot compares the
  follower list once a day and lists who is gone.

### Changed
- The landing page shows what StreamOrg does now: new cards for the chat
  bot, overlays and channel events, and giveaways with chat entries, the
  public prize page and direct gifts.
- The calendar no longer marks content without a key in red; it still
  says so when hovered. The red mark is kept for content scheduled before
  its embargo lifts or after its key expires.
- Connecting Twitch (and the bot account) also asks to read followers,
  for the Events and Unfollows pages; connections made before keep
  working without them.
- Available keys can be added to giveaways too (they become "for
  giveaway"); keys already marked for giveaway are listed first and
  highlighted.
- Adding a giveaway winner is a Twitch search (picture, name and login):
  the account picked is stored by its Twitch id, so it is exactly that
  person. Typing an exact login still finds viewers who never stream.

### Fixed
- Live tracking no longer starts content on its own when the stream
  switches to Just Chatting just because one of its games is not listed on
  Twitch (those games use Just Chatting as a stand-in); they are matched
  by their title instead. Started content is locked in the calendar, which
  is why it could not be dragged anymore.

## 0.8.2 — 2026-10-12

### Fixed
- The chat bot follows overlay changes at once (it used to take up to a
  minute), so a shoutout or alert right after saving gets its reply.

## 0.8.1 — 2026-10-10

### Fixed
- Editing a collab with several people (for example, to mark it scheduled)
  kept only one of them; every person now stays, with their role,
  confirmation and notes.
- Lives still planned from a collab follow its cast: their collaborators
  and the "ft." credit in their title change with it.

## 0.8.0 — 2026-10-09

Administration by subject, user data screens, and guides for the stream tools.

### Administration
- The administration menu is grouped by subject, and the overview shows
  what needs attention, how StreamOrg is used, the newest accounts and the
  latest additions.
- User data: every account's content, key vaults (never the codes or the
  keys' notes), collabs and streamers, read-only, with filters.

### Stream tools
- "How it works" guides for overlays, the media library and the chat bot.

## 0.7.0 — 2026-10-09

Overlays for OBS, and a new Stream tools section. Needs bot 0.4.0 for the
chat replies (release the site first).

### Overlays
- Stream tools in the account menu: overlays, media library and the chat
  bot (moved from the profile; old addresses redirect).
- Overlays: browser sources for OBS, set up beside a live preview, with
  secret links and test buttons. Types: custom alert, shoutout,
  watch-streak alert and chat, each with the size to give it in OBS.
- The chat bot answers custom alerts, shoutouts and watch streaks with
  messages set on those overlays (bot 0.4.0).
- Sounds per viewer for shoutouts and watch streaks, in a tab of their own.
- Advanced mode: the settings as text (key = value, comments, blocks for
  sounds and rules per streak or viewer) with skipped lines listed, and
  custom CSS.
- Media library for sounds and images, with sound sprites (parts marked
  while listening); a shared library managed by administrators.
- Administration → Overlays: switches, types, limits, link address and live
  connection, for administrators only.

## 0.6.0 — 2026-10-08

The first numbered release, covering everything built so far.

### Planning
- Dashboard with today's plans, shortcuts, what needs attention and the week.
- Content calendar and list: drag to schedule, resize to change the length,
  smart drops at the usual time or after the day's last item, and sending
  the schedule to Twitch.
- Title editor that colours prefixes, suffixes, counters, collab credits,
  studio and sponsor tags; counters numbered in calendar order; Twitch's
  140-character limit kept.
- Live tracking through Twitch EventSub.
- Collabs, and planning a collab together with other StreamOrg users: one
  shared, agreed time, each with their own content.

### Keys, sponsors and giveaways
- Key vault (recoverable or private), embargoes, coverage and deadlines.
- Giveaways with claim links, viewer profiles, and taking a key back from
  a winner.
- Twitch-first game catalogue filled from Steam or IGDB.

### Chat bot
- Node service on Twitch's chat API: custom commands, timed messages and
  viewer statistics, with per-channel permissions.

### Support
- Report a bug (screenshots with pins, steps, impact) and follow it until
  it is fixed; send messages to the team; answer questionnaires.
- Administration: bug tracker, feedback inbox, and a multilingual
  questionnaire builder with results and CSV export.
- Friendly error pages that open a pre-filled bug report.

### Everywhere
- Portuguese and English, with a fixed language per domain where chosen.
- Notifications with formatting, "How it works" guides, seven themes with
  light and dark versions.
- Tables with pinned actions, sorting, and columns you choose and reorder.
- Error log for administrators, with notifications for new problems.
