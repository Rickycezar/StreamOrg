# Changelog

Every release of StreamOrg, newest first. Versions follow
[semantic versioning](https://semver.org/): `major.minor.patch`. The running
version is in the `VERSION` file, shown in the account menu and in
Administration, and each release is tagged in git as `v<version>`.

## 0.12.1 — 2026-11-06

### Changed
- E-mail goes only to confirmed addresses. The only exceptions are the
  two messages that confirm one: "confirm your e-mail" and the welcome
  link for accounts an administrator creates. Password resets, security
  notices ("your password changed", and "your e-mail changed" to the old
  address only if it was confirmed), sign-up answers and notification
  copies wait for a confirmed address. The "forgot your password" page
  says so. Accounts that exist when e-mail goes live confirm their
  address from the profile.

## 0.12.0 — 2026-11-05

This release has database migrations (060: e-mail; 061: key transfers; 062: game name limit).

### Added
- Keys → Add in batch: many games at once, one row per game with its
  codes, platform, source, type, status and dates; each new row starts
  with the previous row's choices. Saved together, with any problem
  shown on its row and nothing saved until all rows are right.
- The game field searches the catalog, Twitch and Steam at once: new
  games come in their own group and picking one adds it to the catalog
  (replaces the separate "new game" search on the key and content forms).
- Key transfers (Sponsorships → Transfers): send keys to another
  creator's vault by their Twitch channel or StreamOrg login, without
  anyone seeing the code. The code travels sealed; the sender's keys are
  locked until the receiver accepts (then the code leaves the sender's
  vault for good) or declines, cancels or lets it expire (14 days; then
  they come back). The receiver can pass a key on the same way.
- Received keys arrive sealed: turning off streamer mode never
  shows them; only Reveal does, after a warning, and each reveal is
  recorded for good in the key's history (who passed it on and who has
  seen the code, by handle). Only their status can change. The vault has
  a "Received from" column (another creator, or StreamOrg later on).
- Streamer mode (the old "Keys hidden" switch in the account menu):
  besides key codes, it now blurs real names and e-mails wherever they
  appear outside Administration (the dashboard greeting, the profile,
  IP addresses of your sessions, joint plans and their chat, support,
  contacts' e-mails and notes). The menu bar shows your Twitch handle
  (or login) and avatar initials come from it. Transfers show people by
  handle only; notifications about joint plans, bug reports and feedback
  name people by handle, and error notifications give only the entry's
  number.
- E-mail, ready to be plugged in but off (its screens stay hidden until it is live): Administration → E-mails has
  the system messages in each language (edit them, preview them as they
  would arrive, send yourself a test), the outbox, and the settings. In
  "Preview" everything is prepared and kept in the outbox and nothing is
  sent; "Live" needs a provider (Postmark) and a worker.
- The messages: welcome (choose your password), confirm your e-mail,
  your e-mail changed (to the old address), reset your password, your
  password changed, and e-mail copies of notifications (only to
  confirmed addresses, with a one-click way out). "Forgot your password"
  appears on the sign-in page while e-mail is on; over a private vault it
  works like an administrator's reset.
- The profile shows whether the e-mail is confirmed and has a switch for
  notification e-mails.
- Streamers can create their own account (only while e-mail is live):
  they confirm the address and an administrator approves or declines it
  in Administration → Users, with an e-mail either way. A switch there
  turns on auto-approval, so confirming the address is enough.
- The contact and privacy e-mails shown on the landing page, the privacy
  page and error pages are set in Administration → Settings.
- While e-mail is live, the landing page offers "Create a streamer
  account" and the sign-in page links to the sign-up.

### Changed
- Long game names no longer push tables and forms sideways: lists,
  cards and tables show at most two lines with "…" (the whole name on
  hover), pickers and fields one line, and modal titles two. Names are
  capped at 200 characters (real titles pass 130); a longer one from a
  provider is cut with "…", and typing one is refused.
- The battle draw (boss-draw 1.5.0): extra lives show as blue shields
  above the hearts, lost first, right over each hero's head; a personal
  setting hides fallen players; the entry hint moved to the top; draws
  without a showdown end with a short finisher scene. The game is ready
  for player looks chosen on StreamOrg (not offered yet: everyone keeps
  the usual heroes).

## 0.11.6 — 2026-11-01

This release has a database migration (059: a cache of Twitch's chat
badges).

### Added
- Two new looks for every overlay type, chosen in "Theme": the chat gets
  "Speech bubbles" and "Neon"; alerts, shoutouts and watch streaks get
  "Clean" (flat, rounded panels) and "Neon" (glowing outlines), besides
  their classic look. The shoutout's are layouts of their own: a profile
  card over the blurred box art, with the whole box art floating in front
  of it, and a slanted neon banner with the
  picture in a hexagon and a glitching name. The colours already set
  still apply.
- With "Show badges" on, the chat overlay shows each chatter's real
  Twitch badges (the channel's own, such as subscriber months and
  campaign badges, and Twitch's global ones), in OBS and in the preview.

## 0.11.5 — 2026-10-31

This release has a database migration (058: who may enter a
badge-gated giveaway without a badge).

### Added
- Badge-gated giveaways can let the channel's subscribers, VIPs or
  moderators in without the badge ("Can also enter without a badge"),
  judged by their status in chat rather than by the badge they show
  (boss-draw 1.3.1).

## 0.11.4 — 2026-10-31

This release has a database migration (057: badge-gated giveaways).

### Added
- Badge-gated giveaways: "Prizes only for badge holders" in the giveaway
  form lists the campaign badges of the streamer's Twitch channel, by
  name and icon, to choose one or more. Only viewers showing one of them
  can enter the giveaway's battle draws (boss-draw 1.3.0); the giveaway
  page shows them, with a link to change them.

## 0.11.3 — 2026-10-31

This release has a database migration (056: the order of a giveaway's
prizes).

### Added
- A giveaway's prizes have an order, changed by dragging them (or with
  the arrow keys): with a prize set per winner, winners get them in that
  order. Until now it was the order they were added in, which is where
  the order starts.
- Rounds for the battle draw, in giveaways with a prize set per winner:
  tick the prizes of the round on the giveaway page; the battle draws one
  winner per ticked prize and gives them in the list's order. The battle
  page lists the round's prizes, which can be dragged into another order
  for that battle. With none ticked, a confirmation says the prizes follow
  the list.

### Changed
- The cards view of a giveaway's prizes: each card opens with the game's
  art, its place in the order and the round checkbox on top, and the
  remove button beside the status.

## 0.11.2 — 2026-10-30

This release has a database migration (055: the battle draw's
StreamOrg rules).

### Changed
- The battle draw (boss-draw 1.1.7) starts at 1 winner, and you raise it
  in the game up to the prizes left (at most 10); "Start with one winner
  per prize left" brings back the old start. Recording a result no
  longer resets the winners, prize or options you set in the game.
- Battle winners are recorded in the order they entered the draw, so
  whoever entered first gets the first prize (before, the most hearts
  kept came first).
- Who already won a giveaway may enter its later battle draws and win
  another prize; "Leave out who already won this giveaway" keeps them
  out, as before. Both rules are on the battle page, off by default.

## 0.11.1 — 2026-10-30

This release has a database migration (054: posting winners' links in
chat).

### Added
- The chat bot can post each new winner's claim link in the chat,
  tagging them, as soon as the winner is recorded (confirmed in the
  battle, drawn, or added by hand). It is a switch on each giveaway, on
  for new ones, and the words are the giveaway command's new "winner"
  message. Several winners share a message, and a link only opens for
  its winner's Twitch account.
- The battle draw (boss-draw 1.1.5): a custom boss name, entrant lists
  where repeated names can count as extra hearts (with a minimum and a
  maximum), three new bosses, buttons on the game screen, quick music
  and effects toggles, a charged finishing blow in the showdown, and a
  confirmation before removing a player. A new rule decides whether
  extra hearts come back when a boss falls; it is off by default, so
  extra hearts once lost now stay lost. Inside a giveaway the result is
  confirmed from the winner screen; the public page only shows it. The
  giveaway's battle page has a "Reopen entries" button that keeps who
  already entered.

## 0.11.0 — 2026-10-30

This release has database migrations (051: the overlays' stop signal;
052: the texts edited in Administration → Languages; 053: the hosted
games' texts, options and draws).

### Added
- Giveaways can be drawn with a battle: "Draw with a battle" opens the
  boss battle game inside the giveaway. Viewers enter through the chat,
  the battle's options start from the creator's last draw, and the
  confirmed winners are recorded in the battle's order (with a prize set
  per winner, the first gets the first prize). Every result is kept so
  the draw can be replayed. The game also has a public page, with no
  sign-in, at /games/boss-draw/, which only shows the winners. Both can
  go full screen.
- Administration → Game texts: the hosted games' texts, apart from
  StreamOrg's own, changeable in any language (or in a new one) without
  a new build of the game; a text that drops or invents a {placeholder}
  is refused.
- Overlay tests can be interrupted: "Interrupt" stops what the preview
  and the overlay open in OBS are showing, what waits in their queue and
  their sound, for every overlay type.
- Shoutout and watch-streak overlays take an optional name above the test
  buttons (the channel or viewer the test shows), in the preview and in
  OBS, and each viewer's row in the per-viewer tab has a Test button that
  shows their own alert in the preview.

### Changed
- Administration → Languages keeps edits in the database, over the texts
  that ship with the code: each edited text shows its default, a blank (or
  the default) brings it back, a counter shows how many are edited, and
  "Download edits" gives them as JSON for bin/lang_overrides.php to make
  them the defaults in the code.

### Fixed
- The Play buttons beside the overlay sounds did nothing.
- Texts edited in Administration → Languages were written into the files
  and lost on the next deploy.

## 0.10.1 — 2026-10-26

### Changed
- The content length is one joined control (−60, −30, the minutes, +30,
  +60), with the length in hours under it, and "Today" is joined to the
  start field.

### Fixed
- Clicking the minutes of the length (or its label) also took an hour
  off it.

## 0.10.0 — 2026-10-26

This release has database migrations (048: game hashtags, the sponsor's
game-hashtag flag and the title order; 049: the joint plans' chat; 050:
draft joint plans).

### Added
- The content form is split into sections (content, sponsor and collab,
  game and key, when, notes), in the new form and the edit forms alike.
- Sponsor and key fill each other: a new key starts with the content's
  sponsor as its source, and choosing or saving a key from a sponsor
  makes it the content's sponsor (and swaps the title's tag).
- The order of a title's parts (prefix, your text, suffix, collab
  credit, game hashtag, developer/publisher tags, sponsor tag) can be
  changed by dragging the coloured chips under the title, which rewrites
  it; the default order is set under Profile → Defaults. Tags added by
  choosing a game, sponsor or collab go where the order says.
- Games have their own hashtag (#CastlevaniaBC), set by administrators.
  Admin → Games shows a guess (the name in CamelCase, only its first words
  when long) to confirm with one click or change; titles only use a saved
  one;
  key sites marked "adds the game's hashtag" (Keymailer) put it in the
  title of the content they sponsor.
- "Today" next to the start puts content in today's first free spot, and
  a length field (with −1 h, −30, +30 and +1 h) and an end time follow
  each other; the length is saved with the content.
- The title helper now works in the edit forms too.
- A new look for the content calendar: a rounded grid with softer lines,
  weekday headers and a highlighted today, segmented view buttons, a light
  shade on off-hours, and events as plain chips led by a dot in the colour
  of their status (month chips show the time and the game; on phones they
  become slim bars). The "Sem data" list matches the dashboard's: game
  art, game and title on a line each, and the deadline.
- Editing content can now add or change its game and key, and its embargo
  date, like the create form. The embargo is filled in with the game's most
  recent embargo.
- The content details window shows the game pictures, and has an Edit
  button that opens the edit form in place, from the calendar, the list and
  the dashboard.
- The key vault's add form has the Region field the edit form already had.

### Changed
- The plan-together page was redone: a header with the shared time (as a
  range, with "in 3 days"), the game art and where things stand (agreed,
  waiting for answers, invitation, no time yet, cancelled); a "needs your
  answer" box only when something waits for you, showing the old and the
  new time and who agreed; one card per participant with their game and
  title (your own with Edit); and the time changed in a small window
  with the content form's start, length and end.
- "Plan together" now opens the plan as a draft only the host sees: they
  set the date and time and can write a first message, and the
  invitations go out only when they send them, saying when. A draft can
  be discarded without telling anyone.
- Each joint plan has a chat for the people taking part, beside the
  plans, updated every few seconds while the page is open.
- Joint plans keep their notifications down to one unread per plan and
  person: further news (messages, proposals, answers) folds into "News in
  …" until the plan is opened, which marks it read.
- Content, the game catalog and Streamers are split into tabs: content
  starts on the calendar with the list in the second tab; the catalog and
  Streamers keep adding (from the APIs or Twitch) in a tab of their own.
  The content counters that filter the list sit in the List tab.
- The card views of the game catalog and of the content list show each game's picture.
- Filter bars apply themselves: results follow as you type (the box keeps
  its focus) and as soon as a choice changes. Status, platform, type and
  source filters in content, the key vault and the admin bug tracker and
  error log take several values at once, shown as chips.
- Long lists show 50 rows at a time and load more as you scroll (or with
  "Show more"); sorting still covers the whole list.
- The dashboard's "Needs attention" card became "Content without a date":
  each plan with its game art, game and title on a line each, and a button
  that puts it in today's first free spot (from your usual start time, or
  from now, after what is already planned and in a gap long enough).
- Cleaner tables: taller rows set apart from each other, row buttons as
  icons (coloured where it helps) with tooltips that show the label and its
  hint, and a fade at the edge that hints a table scrolls sideways. Rows of
  a game (content, keys, given keys, embargoes, the catalog, giveaway prizes
  and the admin games) show the game's art faded in behind the start of the
  row; games without art show a "no picture" image, also in card view.
- Collabs: streamers are picked by searching, with their pictures, and only
  the chosen ones are listed, on both the create and the edit forms.
- Content without a key is ordinary: no "no key" badge in the content list,
  the calendar or the dashboard, no "missing key" tile, reminder or filter,
  and the guides no longer suggest it needs attention. Choosing a key for
  content still leaves the key's status as it is.

### Fixed
- Saving linked content without changing its time (a new title, games
  or key) no longer starts a new round of answers in its joint plan.
- Times typed in the content forms were read in the server's time zone
  (UTC in production) instead of yours, so content started hours off on
  the calendar while the form still showed the typed time. The database
  now reads and shows times in the signed-in user's time zone.
- A giveaway's winner mode (winner picks, or a key set for each winner) can
  no longer be changed once it has winners, which left their claims
  inconsistent; the form shows it fixed.

## 0.9.7 — 2026-10-25

### Fixed
- Shoutout overlays could not look up channels ("404" on /overlay/lookup):
  the Twitch app token kept for overlays had stopped working, and a refused
  token was only forgotten in the visitor's session, so the dead one kept
  being reused. Now a token Twitch refuses is dropped everywhere and the
  call is made once more with a new one; saving the Twitch settings also
  drops it.

## 0.9.6 — 2026-10-23

### Security
- PHP updated to 8.2.35, which fixes two critical vulnerabilities that
  could allow remote code execution without signing in. The image is now
  pinned to that exact PHP release.

## 0.9.5 — 2026-10-21

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
