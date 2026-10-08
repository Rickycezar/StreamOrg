# Changelog

Every release of StreamOrg, newest first. Versions follow
[semantic versioning](https://semver.org/): `major.minor.patch`. The running
version is in the `VERSION` file, shown in the account menu and in
Administration, and each release is tagged in git as `v<version>`.

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
