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

### Key vault
- **Every key code is encrypted** (AES-256-GCM, a random data key per user).
  Codes are never rendered into pages; they are fetched one row at a time,
  only for their owner.
- **Optional private vault**: lock your data key with your password alone, so
  nobody else — administrators included — can ever read your codes.
- **Safe on stream**: codes are masked by default. Hold *peek* to see one,
  copy without revealing, or flip a global "show keys" switch.
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
- Register a missing game (from Steam) or key without leaving the form.

### Catalogue
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
  sponsor tags** to Twitch from its row, after a preview of exactly what will
  change.
- Look up streamers on Twitch to plan **collabs** and credit guests.

### Embargoes and coverage
- Per-game embargoes (optionally per platform), release-date embargoes set
  automatically, and a coverage pipeline that advances as content is planned
  and streamed.

### Account and security
- Profile tabs for personal data, password, appearance (twelve themes) and
  security.
- **See and end your sessions**: every sign-in is listed with its browser,
  address and last activity; sign any of them out, or all the others.
- Password changes sign out every other session; sessions expire after a
  period without activity set by an administrator.
- Protection against password guessing, a strict Content-Security-Policy,
  encrypted credentials and per-user data isolation throughout.

### Administration
- Shared catalogue management (games, publishers, developers, key sites),
  provider credentials with a connection test, the language files, and
  application settings such as session lifetime.
- A public landing page with a preview-access contact.

---

## Tech stack

| | |
|---|---|
| Server | PHP 8.2 (`pdo_pgsql`, `curl`, `gd`, `mbstring`, `openssl`), no framework |
| Database | PostgreSQL 15+ (uses the `citext` extension) |
| Front end | Server-rendered views, [Turbo Drive](https://turbo.hotwired.dev/) navigation, [Tom Select](https://tom-select.js.org/) pickers, [FullCalendar](https://fullcalendar.io/) — vendored, no build step |
| Tooling | Composer (autoloader, PHPUnit), npm (only to fetch the browser libraries) |
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
| `APP_KEY` | Base64 of 32 random bytes (`openssl rand -base64 32`). Encrypts provider credentials, Twitch tokens and recoverable key vaults. **Back it up; never change it.** |
| `APP_BASE_URL` | Public address, e.g. `https://streamorg.example.com` (also builds the Twitch redirect URL) |
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
- Two persistent volumes: `/var/www/html/public/media` (artwork) and
  `/var/www/html/tmp` (sessions).

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
- **Errors**: never shown in production; users see generic messages, details
  go to the log.

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
</details>

<details>
<summary>Content planner</summary>

The calendar (FullCalendar, loaded only on `/content`) works in wall-clock
time in the user's profile time zone: events are sent as local times without
an offset and come back the same way, so the browser's own zone never shifts
anything. Only `planned` content moves. Dropping on a day of the month view
schedules it at 20:00 and opens that day.
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

Users connect on *Profile → Personal data* (scope `channel:manage:broadcast`;
tokens encrypted and refreshed automatically). *Send to Twitch* sets the
title (up to 140 characters), the category (an exact title match is saved on
the shared game, a hand-picked one only for that user) and the tags — only
sponsor hashtags naming a key site; other channel tags are kept. The redirect
URL to register in the Twitch console is shown on *Admin → API settings*.
Only Twitch is offered as a streaming platform for now
(`streaming_platforms.is_enabled`).
</details>

<details>
<summary>Localization</summary>

Nothing translated is stored in the database: values are stable codes and
`lang/<locale>.php` maps them to labels (`Lang::t()`, `Lang::code()`). A
missing key falls back to English, then to the key itself. Run
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
public/assets/          app.css, app.js, theme.js and vendor/ (browser libraries)
src/                    Config, Database, Auth, sessions, vault, catalogue, helpers
src/Api/                catalogue providers, Twitch, HTTP client
src/Controllers/        one class per area
views/                  plain-PHP templates
lang/                   en.php, pt-BR.php
db/migrations/          numbered .sql files, applied in order
bin/                    command-line tools
tests/                  PHPUnit (Unit + Database suites)
docker/                 Apache, PHP and entrypoint for the image
config/                 config.example.php (config.php is git-ignored)
```

## Status

Working: key vault, content planner and calendar, catalogue with artwork,
Twitch connection, collabs and streamers, embargoes, sessions and account
security, administration, landing page. Planned: negotiations with publishers
(the page is a placeholder) and giveaways.

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
(MIT), Tom Select (Apache 2.0).

Required Notice: Copyright 2026 Henrique Barros (https://streamorg.com)
