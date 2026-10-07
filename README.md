# StreamOrg

**A workspace for streamers who cover games.** StreamOrg keeps the keys that
publishers and key sites send you safe, plans what you will stream on a
calendar, keeps track of embargoes and deadlines, and updates your Twitch
channel without leaving the page.

It is a server-rendered PHP 8.2 + PostgreSQL application with no framework,
localized in English and Brazilian Portuguese — served in English on
streamorg.com and in Portuguese on streamorg.com.br — and currently in a
private preview. Preview access: streamorg@outlook.com.

---

## Features

### Dashboard
- **Shortcuts** to plan content, add keys, start a collab or an embargo, and
  find a game.
- **What is for today**: cards for the content planned for the day, with the
  game's artwork, the expected time range, a live countdown and a *Send to
  Twitch* button. On a day off, a picker schedules undated content for today
  at your usual start time.
- The next seven days at a glance, and the loose ends that need a decision.

### Key vault
- **Every key code is encrypted** (AES-256-GCM, a random data key per user).
  Codes are never rendered into pages; they are fetched one row at a time,
  only for their owner.
- **Optional private vault**: lock your data key with your password alone, so
  nobody else — administrators included — can ever read your codes.
- **Safe on stream**: codes are masked by default. Hold *peek* to see one,
  copy without revealing, or flip the global "show keys" switch in the account
  menu — a badge on your avatar turns red while keys are visible.
- A plain-language **"How your keys are protected"** page, opened from the
  vault and the security settings, with links to the technical references.
- Paste many codes at once (one per line); duplicates are skipped.
- Status tracking (available, reserved, for giveaway, used, given away,
  expired, revoked), key type (common / review), DLC vs base game, redemption
  platform, origin (key site, publisher, purchase) and redeem-by dates.

### Content planner
- **A calendar of your streams and videos.** Open a day to see its hours,
  drag undated content onto a time, move it through the day or to another
  day, or drag it back to the backlog to clear its date.
- **Warnings that matter**: content without a key, streams scheduled before
  an embargo lifts, keys that expire before the stream, missed deadlines.
- **Title helper**: choosing a game, sponsor or collab adds the right
  hashtags and credits to the title, which stays freely editable.
- Register a missing game (picked from Twitch) or key without leaving the form.
- **Any Twitch category**: content can have its own category — Just
  Chatting, Special Events, Marbles On Stream… — used before its games when
  sending to Twitch and when live tracking matches a category.
- **Your stream schedule** (days and usual hours, under *Profile → Defaults*)
  gives content dropped on an empty day its start time and shades your
  off-hours on the calendar; content dropped on a busy day goes right after
  the day's last item.
- **Content length**: how long you usually give sponsored content, set once
  under *Profile → Defaults*; resize an item on the calendar to change just
  that one.
- **Title prefixes and counters**: prefixes such as "[STEAM DECK]" or
  "[STREAM #{stream}]" are picked as chips (several, in any order, the usual
  ones preselected); a counter in braces numbers content in calendar order
  (undated content shows "?"), and the database renumbers whenever a plan is
  moved, cancelled or deleted. The title field works like a small code
  editor: typed freely, with prefixes, collab credits ("ft. @guest", the
  word chosen in your defaults), developer/publisher
  tags and sponsor tags coloured as you type, and the whole title kept
  within Twitch's 140 characters.

### Catalogue
- **Twitch first**: with Twitch connected, a game is added by picking its
  Twitch category; its store page, release date, studios, genres and artwork
  are then filled in from Steam (found exactly through IGDB) or IGDB itself,
  using the same Twitch credentials. Non-game categories keep Twitch's name
  and box art.
- **Every game has a Twitch category** — several may share one, and a game
  Twitch does not list streams as Just Chatting until it is found. Imports
  look the category up on their own; admins can set it by hand or look up
  the rest in batches.
- **Import games from providers** — Steam out of the box; IGDB, RAWG and OMDb
  with credentials — with release dates, studios, genres and store links.
- **Real artwork, stored locally**: header, capsule, box art, hero banner and
  logo for each game, refreshed whenever the catalogue entry is updated, and
  shown in pickers, lists and detail views.
- Searchable pickers with cover art for games, publishers and developers.
- Vague release dates ("Q1 2027") are recorded as such and can be refreshed
  until the store announces a real date.

### Twitch
- **Connect your channel** (OAuth) and **send a stream's title, category and
  sponsor tags** to Twitch, after a preview of exactly what will change.
  Sending marks the planned content as live.
- **Send your schedule** to the Schedule tab of your channel page: planned
  Twitch content becomes one-off segments with its title, time, length and
  category; later sends update what changed and remove what was unplanned.
  Segments you made on Twitch are left alone (affiliates and partners only).
- **Live tracking** (Twitch EventSub): when the channel goes live, changes
  category or goes offline, StreamOrg follows along — finishing the live
  content, starting today's plan for the new game, and returning plans that
  never happened to the undated backlog. Every step is shown in a recent
  activity list.
- Look up streamers on Twitch to plan **collabs** and credit guests.
- **Plan collabs together**: guests who use StreamOrg (matched by their
  connected Twitch account) are invited through notifications. Each joins
  with their own linked content — own title, games, keys and sponsors —
  while the time is shared: moving it proposes the change to the others,
  and it applies to everyone once they all agree.

### Giveaways
- **Named giveaways** with a short keyword for the chat, rules, an entry
  window, and an optional surprise mode that hides them until opened.
  Several can run at once — a month-long one and a quick surprise.
- **Prizes from the vault, copied only when needed**: a prize just points at
  a key marked "for giveaway". With a recoverable vault the key is opened
  only when its winner claims it; with a private vault the streamer makes the
  prizes claimable when ready, and can take the copies back. The vault key is
  marked given away when its prize is claimed.
- **Finishing** a giveaway ends the links not used yet, returns unclaimed
  keys to the vault and deletes their copies.
- **Winners by Twitch account**: drawn from chat entries, typed in after an
  external game such as Marbles, or picked by hand. Either the winner picks a
  key from the giveaway, or a key is assigned to them.
- **Claim links only the winner can open**: the link works for that Twitch
  account only, signed in, until it expires (30 days by default).

### Chat bot
- **One bot for every channel** (`bot/`, a small Node service): it joins the
  Twitch chat of streamers who add it from *Profile → Chat bot* (or whom an
  admin adds) and answers commands. For now there is one, `!heartbeat`, to
  check it is listening.
- **A real Twitch chat bot**: it uses Twitch's chat API (EventSub through a
  conduit, replies with the app token), so it carries the Chat Bot badge and
  is listed under *Chat Bots*. The streamer either gives it permission (one
  reconnect, no moderator powers) or makes it a moderator; either works.
- **Viewer statistics while live** (in channels whose chat the bot can read):
  per broadcast, viewer, live content and stream category, the messages sent and the time spent in chat (from the
  chatter list, once a minute). Counted in memory and written in one batch
  per channel per minute; kept for 12 months.
- **Commands made your own**: each streamer can rename a command, rewrite its
  reply (with placeholders such as `{user}`), choose who may use it
  (everyone, subscribers, VIPs, moderators or only the streamer), set a
  cooldown, switch it off, or go back to the default.
- **Custom commands and timed messages**: streamers write their own commands
  (`{target}` is the first word after the command, for shout-outs) and
  messages the bot posts while they are live, each at most every so many
  minutes and only after so many viewer messages, so it never talks into an
  empty chat. Profile → Chat bot is organised in tabs: overview, commands,
  timed messages and the bot's log for that channel.
- **Managed from the administration**: the Twitch account the bot speaks as,
  on/off, the command prefix, the default commands, blocking a channel, and
  the bot's live status and recent activity. Changes reach the bot within
  seconds. No chat messages are stored.

### Viewers
- Winners sign in **as viewers, with Twitch only**, and see **My prizes**:
  every key they won, by streamer. Viewers have no access to anything else.
- A creator who connects the same Twitch account sees their prizes in their
  own account. Viewers can delete their profile themselves.
- A **privacy policy** page describes everything kept about creators and
  viewers.

### Embargoes and coverage
- Per-game embargoes (optionally per platform), release-date embargoes set
  automatically, and a coverage pipeline that advances as content is planned
  and streamed.

### Account and security
- Profile tabs for personal data, password, appearance, defaults (stream
  schedule) and security.
- **Two ways in**: creators sign in with username and password — never with
  Twitch, since the password also opens a private vault; viewers sign in with
  Twitch only.
- **Seven themes, each with a light and a dark version**, and a light / dark /
  auto switch that can follow the device — also in the account menu.
- **Profile picture**: upload one, or copy it from your Twitch channel.
- **Notifications**: a bell in the top bar with the unread count, a panel
  with the latest messages and a page with all of them, each in the user's
  own language.
- **"How it works" guides** for the dashboard, the key vault, content, the
  content defaults, giveaways, embargoes, collabs and planning a collab
  together:
  plain-language walkthroughs with small drawings, opened in their own window
  from a button beside the page title.
- **See and end your sessions**: every sign-in is listed with its browser,
  address and last activity; sign any of them out, or all the others.
- Password changes sign out every other session; sessions expire after a
  period without activity set by an administrator.
- Protection against password guessing, a strict Content-Security-Policy,
  encrypted credentials and per-user data isolation throughout.

### Administration
- **Users**: add accounts (with a generated password shown once), edit them,
  deactivate them, reset passwords and delete them, with guard rails for your
  own account and the last administrator.
- Shared catalogue management (games, publishers, developers, key sites),
  provider credentials with a connection test, and application settings such
  as session lifetime.
- **Languages**: the interface strings, and labels per language for key
  sites, platforms and genres added at runtime.
- **Notifications**: write one in each language — with light formatting
  (bold, italic, links, lists, headings, highlights) from a toolbar or
  shortcuts, and a preview — choose its kind and an optional link, send it to everyone or to chosen users, see how many read
  it, and take it back at any time.
- **Winners**: search giveaway winners across every streamer and take a key
  back with a reason — an unclaimed key returns to the giveaway, a claimed
  one is marked revoked or returned to the vault — with a history of
  removals; the streamer is notified and the winner's link stops working.
- **Errors**: crashes, failed requests, PHP warnings and problems the code
  handled (a Twitch call that failed, an image that would not download),
  grouped by kind and counted, with the last request, user and a stack trace
  without argument values. New problems notify the administrators; problems
  can be resolved, and come back as open if they happen again. Older errors
  can be read back from the server logs with `bin/errors_backfill.php`.
- **Testimonials** for the landing page, managed as a JSON file that can be
  uploaded, edited or downloaded, with a switch per testimonial and for the
  whole section.
- A public landing page with a preview-access contact.

---

## Tech stack

| | |
|---|---|
| Server | PHP 8.2 (`pdo_pgsql`, `curl`, `gd`, `mbstring`, `openssl`), no framework |
| Database | PostgreSQL 15+ (uses the `citext` extension) |
| Front end | Server-rendered views, [Turbo Drive](https://turbo.hotwired.dev/) navigation, [Tom Select](https://tom-select.js.org/) pickers, [FullCalendar](https://fullcalendar.io/), Plus Jakarta Sans on the landing page — vendored, no build step |
| Chat bot | Node 22+ with `pg` only; Twitch EventSub over Node's built-in WebSocket, Helix over `fetch` |
| Tooling | Composer (autoloader, PHPUnit), npm (the browser libraries and the bot's one dependency) |
| Deployment | Docker image, built and run by [Coolify](https://coolify.io/) on every `git push` |

---

## Running locally

Requirements: PHP 8.2+ with the extensions above, PostgreSQL 15+, Composer.
Node is only needed to update the vendored browser libraries.

```bash
composer install
cp config/config.example.php config/config.php   # then fill it in
php bin/setup_db.php                             # creates the role + database (needs a superuser)
php bin/migrate.php                              # creates the tables
php bin/create_user.php yourname --admin         # prints a generated password once
php -S 127.0.0.1:8099 -t public                  # http://127.0.0.1:8099
```

For the Twitch integration you need HTTPS even locally (Twitch only accepts
`https` redirect addresses). A reverse proxy such as Caddy does it in one line:

```bash
caddy reverse-proxy --from localhost:8443 --to 127.0.0.1:8099 --disable-redirects
```

Set `app.base_url` to `https://localhost:8443` and use that address.

### Configuration

Settings come from `config/config.php` when it exists (git-ignored; start
from `config/config.example.php`). Otherwise they are read from environment
variables, which is how the container is configured:

| Variable | Meaning |
|---|---|
| `DATABASE_URL` | `postgres://user:password@host:5432/dbname?sslmode=…` (or `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSLMODE`) |
| `APP_KEY` | Base64 of 32 random bytes (`openssl rand -base64 32`). Encrypts provider credentials, Twitch tokens and recoverable key vaults, and signs Twitch live-tracking messages. **Back it up; never change it.** |
| `APP_BASE_URL` | Public address, e.g. `https://streamorg.example.com` (also builds the Twitch redirect URL and the live-tracking address, which must be public HTTPS) |
| `APP_ENV` | `production` (default) or `development` |
| `APP_DEBUG` | `1` to display errors — honoured only when `APP_ENV=development` |
| `TRUSTED_PROXIES` | Comma-separated IPs/CIDRs of the reverse proxy in front of the app, whose `X-Forwarded-*` headers are believed (loopback is always trusted) |
| `APP_DOMAIN_LOCALES` | Language per public domain, e.g. `streamorg.com.br=pt-BR,streamorg.com=en`. These are also the hosts the Twitch sign-in may return to |
| `APP_TIMEZONE`, `APP_LOCALE` | Defaults for users without their own (`UTC`, `en`) |
| `CONTACT_EMAIL` | Preview-access address on the landing page |

### Commands

| | |
|---|---|
| `php bin/migrate.php [--status]` | Apply pending migrations from `db/migrations` (or list them) |
| `php bin/create_user.php <name> [--admin] [--password=…]` | Create a user, or update one (a password reset) |
| `php bin/setup_db.php` | Create the application role and database |
| `php bin/sync_images.php [--all] [--force] [--game=ID]` | Download artwork for games that have none |
| `php bin/encrypt_keys.php [--status]` | Encrypt key codes stored before the vault existed |
| `php bin/check_lang.php` | Check that the locales agree with each other and with the database |
| `composer test` | Run the PHPUnit suite (`--testsuite unit` skips the database) |
| `npm install` / `npm run vendor` | Fetch / re-copy the browser libraries into `public/assets/vendor/` |

Classes are found through Composer's classmap: **after adding a class file,
run `composer dump-autoload`**.

---

## Deployment

The repository is ready for **Coolify** (a self-hosted, Heroku-like platform):
connect the Git repository, choose the *Dockerfile* build pack, and every push
to the deployed branch builds and releases a new version.

What the image does:

- PHP 8.2 + Apache serving only `public/`; production `php.ini`, OPcache,
  logs to the container output.
- On every start, `docker/entrypoint.sh` refuses to run without `APP_KEY` or
  database settings, waits for the database and **applies pending
  migrations** — a deploy needs no manual step.
- A health check at `/healthz` (200 when the database answers).
- Two persistent volumes: `/var/www/html/public/media` (game artwork and
  profile pictures) and `/var/www/html/tmp` (sessions).
- Data managed from the administration (testimonials, labels for runtime
  codes) lives in the database, so it survives deploys and is in the backups.
  Edits to the language files made on a deployed server do not survive the
  next deploy: change them in the project.

### The chat bot

The bot is a second Coolify application from the same repository:

1. *New resource → Application* from the same Git repository and branch,
   build pack *Dockerfile*, **base directory `/bot`**, and **watch paths
   `bot/**`** so it only rebuilds when the bot changes. Pushes that only
   touch `bot/` also redeploy the PHP app unless its watch paths exclude
   `bot/`, which is harmless.
2. No domain or public port: it only talks out, to Twitch and the database.
   Its health check (`/health` on port 8080) is built into the image.
3. The same `APP_KEY` and database settings (`DATABASE_URL`, or `DB_HOST`,
   `DB_NAME`…) as the PHP app — it reads the tokens the app encrypted.
4. In StreamOrg, *Administration → Chat bot*: sign in to Twitch as the bot
   account (in a private window, for instance), *Connect the bot account*,
   then switch it on. The status turns *Online* within a minute.

The bot waits for the app's migrations, reloads when the app announces a
change (`NOTIFY streamorg_bot`), refreshes its token, and reconnects on its
own. It keeps one EventSub conduit for the app (its id is stored), points the
conduit's shard at its WebSocket on every start, and holds one chat
subscription per channel. Streamers' tokens, which it uses for the chatter
list, are refreshed under the same row lock as the app's, so the two never
spend a refresh token twice. `cd bot && npm test` runs its tests; `npm start` runs it locally with the
same environment variables.

Personal one-off import scripts (`bin/import_*.php`) are kept out of both git
and the image. `docker-compose.yml` runs the same image with PostgreSQL for a
local trial. Detailed, security-focused server setup notes are kept outside
the repository.

---

## Security model

- **Isolation**: every query on personal data is scoped to the signed-in user;
  the shared catalogue is the only thing users have in common.
- **Sessions**: database-backed (`user_sessions`), validated on every request,
  revocable from the security tab; strict session ids, HttpOnly + SameSite
  cookies, Secure over HTTPS.
- **Passwords**: Argon2id where available (bcrypt otherwise), constant-time
  failures, and throttling of sign-in, vault unlock and password checks per
  account and per address (`auth_attempts`).
- **Encryption**: AES-256-GCM everywhere — credentials and Twitch tokens with
  the application key; key codes with per-user data keys bound to their
  owner; private vaults with a PBKDF2-derived key only the password produces.
- **Browser**: CSRF tokens on every state change, a Content-Security-Policy
  with no inline script, `frame-ancestors 'none'`, `nosniff`, HSTS over HTTPS,
  and only `http(s)` links ever rendered from stored data.
- **Uploads**: profile pictures are decoded and re-encoded (256×256 WebP)
  before they are stored; nothing uploaded is served as sent.
- **Webhooks**: Twitch live-tracking messages are accepted only with a valid
  HMAC-SHA256 signature, less than ten minutes old and never twice.
- **Giveaways**: nothing leaves the vault when a prize is added. A
  recoverable vault is read at claim time; a private vault's prizes are copied
  into a lock per giveaway (a random key wrapped with `APP_KEY`) only when the
  streamer makes them claimable, and the copies can be taken back. Claim links
  carry a random token of which only the SHA-256 is looked up; claiming needs
  the winner's own Twitch account, and the prize and vault key are updated in
  one locked transaction so a key is never given twice.
- **Viewers**: a separate session from creators — a viewer is never a signed
  in user — and a Twitch sign-in with no scope whose token is revoked as soon
  as the identity is read.
- **Errors**: never shown in production; users see generic messages, details
  go to the log.

Found a vulnerability? Please report it privately, as described in
[SECURITY.md](SECURITY.md).

---

## How it works

<details>
<summary>Key vault encryption</summary>

Key codes are encrypted with a random 256-bit data key per user; duplicates
are detected through a keyed hash (`key_hash`), never the code. The data key
is wrapped in one of two ways, chosen on the security tab:

- **Recoverable** (default) — wrapped with `APP_KEY`. Protects against a
  leaked database or backup; an admin password reset keeps the codes.
- **Private** (opt-in) — wrapped only with a key derived from the password
  (PBKDF2-SHA256, 600k rounds). While signed in, the data key is kept in the
  session encrypted with a key held only in an HttpOnly cookie, so neither the
  session files nor the cookie alone opens it.

Changing your password re-wraps the key; codes are never re-encrypted.
`bin/create_user.php` refuses to reset a private user's password without
`--reset-private-vault`, since that makes their stored codes unreadable.
</details>

<details>
<summary>Front end</summary>

- **Turbo Drive** turns links and form posts into in-place page swaps.
  `app.js` loads once per tab; handlers on `document` survive a swap, and
  anything bound to a page's own elements is registered with `onPage()`.
  Before Turbo snapshots a page for the back button, loaded key codes are
  cleared. Form posts answer with a redirect (303).
- Page data reaches `app.js` as `<script type="application/json"
  data-globals>` blocks, never inline script, so the CSP can forbid it.
- **Tom Select** powers `<select data-picker="games|publishers|developers">`,
  rendered with only the current choice and searching `/pickers/*` as you
  type. A key's edit form is fetched from `/keys/edit` on first use.
- Assets are versioned by modification time and tracked by Turbo, so an
  updated `app.js` makes open tabs reload fully on their next visit.
- **Themes** are families with a light and a dark variant (`Themes`). The
  server renders the variant for the chosen mode; for *auto*, `theme.js`
  (loaded blocking in `<head>`) switches to the dark variant before the first
  paint when the device prefers it, and follows later changes.
</details>

<details>
<summary>Content planner</summary>

The calendar (FullCalendar, loaded only on `/content`) works in wall-clock
time in the user's profile time zone: events are sent as local times without
an offset and come back the same way, so the browser's own zone never shifts
anything. Only `planned` content moves. Dropping on a day of the month view
schedules it at that weekday's start time from the user's stream schedule
(20:00 when there is none) and opens that day; hours outside the schedule are
shaded.
</details>

<details>
<summary>Game artwork</summary>

Imports and refreshes download images into `public/media/games/<id>/`,
recorded in `game_images`. A refresh downloads every image again (at most
every few minutes per game); an import only fetches what changed. Downloads
are limited to `http(s)`, capped in size and checked to really be images; a
failed download keeps the previous file.
</details>

<details>
<summary>Twitch</summary>

Users connect on *Profile → Personal data* (scopes `channel:manage:broadcast`
and `channel:manage:schedule`; tokens encrypted and refreshed automatically;
connections made before the schedule existed are asked to reconnect once). *Send to Twitch* sets the
title (up to 140 characters), the category (an exact title match is saved on
the shared game, a hand-picked one only for that user) and the tags — only
sponsor hashtags naming a key site; other channel tags are kept. The redirect
URL to register in the Twitch console is shown on *Admin → API settings*.
Only Twitch is offered as a streaming platform for now
(`streaming_platforms.is_enabled`).

**Live tracking** subscribes, with the app token, to `stream.online`,
`stream.offline` and `channel.update` for the user's channel, delivered to
`/twitch/eventsub` (so only on a public HTTPS address). New connections are
subscribed automatically; existing ones turn it on in *Profile*. `LiveTracker`
applies the rules: a category change finishes live content whose games do not
include it and starts the plan for the session that matches it (by the
remembered category id, else by a close-enough title — "Hades II" is not
"Hades"); going offline finishes live content and unschedules plans for that
session that never started. Each action goes to `twitch_live_log`.
</details>

<details>
<summary>Giveaways, claim links and viewers</summary>

`Giveaways` holds the logic. A prize is a link to a vault key; nothing is
copied when it is added. With a recoverable vault the key is decrypted at the
moment its winner claims it. A private vault cannot be opened without its
password, so *make claimable* (vault open) seals the giveaway's unclaimed
codes with its lock (AES-256-GCM bound to the giveaway and prize ids; the lock
key is random per giveaway and stored encrypted with `APP_KEY`), and *take
back* deletes those copies. An assigned prize of a private vault is sealed
when its winner is set, while the streamer is there. A claimed prize keeps a
sealed copy so its winner can always see it; finishing a giveaway deletes the
unclaimed prizes and their copies.

A winner is a Twitch user id (resolved from the name, so renames do not
matter) with a claim link. The token's SHA-256 is used to find the winner,
and an encrypted copy lets the streamer copy the link again. Claiming locks
the winner and prize rows, checks the account, the expiry and any embargo
on the game, then marks the prize claimed and the vault key `given_away`.
Chat entries (`giveaway_entries`) are kept through closing, so winners can
still be drawn, and deleted when the giveaway is finished.

`Viewers` signs people in with Twitch through the same redirect address as
channel connections, told apart by the OAuth state, under its own session
key. A user whose connected Twitch account matches a viewer profile absorbs
it (`viewers.user_id`).
</details>

<details>
<summary>Stream schedule and the dashboard</summary>

`user_stream_schedule` holds a start and optional end time per weekday in the
user's time zone. The planner uses it for drops on a day and to shade
off-hours; the dashboard uses it for the expected end of today's content and
the start time offered when picking content for today.
</details>

<details>
<summary>Localization</summary>

Values in the database are stable codes, and `lang/<locale>.php` maps them
to labels (`Lang::t()`, `Lang::code()`). Codes added at runtime to the lookup
tables (key sites, platforms, genres) can be given labels from the
administration, stored in `code_labels` and used only where a file has none.
A missing label falls back to English, then to the key itself. Run
`bin/check_lang.php` after touching a lang file.

The language of a request is, most specific first: the signed-in user's own
setting; a visitor's choice in the English · Português switcher (a cookie);
the domain (`streamorg.com.br` → Portuguese, `streamorg.com` → English, via
`app.domain_locales`); the browser's Accept-Language on any other host.
</details>

<details>
<summary>Data model</summary>

Two zones: the **shared catalogue** (publishers, developers, games, artwork
and lookups) has no owner; everything **personal** — keys, content, collabs,
streamers, embargoes, coverage, sessions — carries `user_id` and cascades on
user deletion. Keys record origin (`key_platforms`) and redemption platform
(`game_platforms`) separately. See `docs/schema.md` for the reasoning behind
the schema.
</details>

---

## Project layout

```
public/index.php        front controller and routes; the only PHP file served
public/assets/          app.css, app.js, theme.js, the landing page and pop-up assets,
                        and vendor/ (browser libraries and the landing font)
src/                    Config, Database, Auth, sessions, vault, catalogue, helpers
src/Api/                catalogue providers, Twitch, HTTP client
src/Controllers/        one class per area
views/                  plain-PHP templates
lang/                   en.php, pt-BR.php
db/migrations/          numbered .sql files, applied in order
bin/                    command-line tools
tests/                  PHPUnit (Unit + Database suites)
docker/                 Apache, PHP and entrypoint for the image
bot/                    the chat bot (Node): src/, test/, its own Dockerfile
config/                 config.example.php (config.php is git-ignored)
```

## Status

Working: dashboard, key vault, content planner and calendar with a stream
schedule and Twitch categories, catalogue with artwork, Twitch connection and
live tracking, giveaways with claim links, viewer profiles, collabs and
streamers, embargoes, sessions and account security, themes and profile
pictures, administration (users, languages, testimonials), landing page and
privacy policy, and the chat bot's foundation (channels, personalised
commands, a heartbeat command). Planned: chat bot giveaway entries, more
commands and statistics, and negotiations with publishers (the page is a
placeholder).

## License

StreamOrg is **source-available** under the
[PolyForm Noncommercial License 1.0.0](LICENSE.md): you may download, run,
self-host and modify it for **personal and other noncommercial use**. Selling
it, offering it as a paid service or any other commercial use is not
permitted without a separate license — write to streamorg@outlook.com.

The **StreamOrg name, logo and domains are not covered by the license** — see
[TRADEMARKS.md](TRADEMARKS.md). A modified copy you share or host for others
must be rebranded.

Contributions are welcome under the Contributor License Agreement in
[CONTRIBUTING.md](CONTRIBUTING.md).

Bundled browser libraries keep their own licenses: Turbo and FullCalendar
(MIT), Tom Select (Apache 2.0), and the Plus Jakarta Sans font (SIL Open Font
License 1.1).

Required Notice: Copyright 2026 Henrique Barros (https://streamorg.com)
