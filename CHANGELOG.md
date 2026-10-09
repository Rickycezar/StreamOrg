# Changelog

Every release of StreamOrg, newest first. Versions follow
[semantic versioning](https://semver.org/): `major.minor.patch`. The running
version is in the `VERSION` file, shown in the account menu and in
Administration, and each release is tagged in git as `v<version>`.

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
