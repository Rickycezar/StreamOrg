# Schema notes

Reasoning behind the non-obvious choices in `db/migrations/001_initial_schema.sql`.

## Ownership

Two zones:

- **Shared catalogue** — `publishers`, `developers`, `games`, `genres`,
  `game_platforms`, `key_platforms`, `streaming_platforms` and their junctions.
  No `user_id`. Facts about the world; two users describing the same game
  should describe one row.
- **Private** — everything else carries `user_id NOT NULL` with
  `ON DELETE CASCADE`. Your keys, negotiations, streams, contacts and backlog
  are yours.

Isolation is enforced in the application layer: every query against a private
table must filter on `user_id`. The database does not enforce this today.
If you want it enforced by Postgres itself, enable row-level security later —
that is a migration, not a redesign, because the `user_id` columns are already
in place.

## Localization

No translated text is stored in the database. Localizable values are stable
lowercase codes (`for_giveaway`, `awaiting_reply`, `roguelike`), and
`lang/<locale>.php` maps them to labels. Each such column carries a
`lang key: <group>.<code>` comment in the migration.

Adding a language means adding one file and touching zero rows.
`bin/check_lang.php` fails if a locale drifts or a seeded code has no label.

Statuses use `CHECK` constraints rather than lookup tables: the set of valid
values is part of the application's logic, so it belongs in a migration where
it is reviewable, not in a data row that can be edited at runtime. Taxonomies
that genuinely grow — genres, platforms — *are* tables.

## Collabs

A collab is not a flag. Two tables cover two different things:

- `collabs` — the planning record. Exists from "idea" onward and may never
  become a stream.
- `stream_collaborators` — who actually appeared on a given stream.

A stream is a collab when it has at least one collaborator. The view
`streams_with_collab_flag` derives `is_collab` on read, so the flag cannot
contradict the participant list. `streams.collab_id` optionally links a
stream back to the plan that produced it.

## Keys

Two different platform questions, two different columns:

- `key_platforms` — where the key **came from**. Creator key sites
  (Keymailer, Woovit, Terminals, DareDrop, Lurkit) plus the two origins that
  are not sites: `publisher_developer` for keys sent to you directly, and
  `purchased` for ones you bought.
- `game_platforms` — where the key is **redeemed and played**. PC is split by
  store (`pc_steam`, `pc_gog`, `pc_epic`…) because a PC key is only good on
  one of them. Consoles are single codes. The `family` column groups the
  PC/* entries for `<optgroup>`.

Both are plain lookup tables: add a row plus a label to extend them, no
schema migration needed.

Two more columns describe the key itself, both readable as raw values:

- `key_type` — `common` or `review`. A `review` key can be redeemed and
  played before the game releases; `common` is a normal post-release key.
  Separate from `status`, because they answer different questions: status is
  where the key sits in its lifecycle, type is what kind of key it is.
- `content_type` — `game` or `dlc`. `game_id` points at the base game either
  way, so a DLC key stays attached to the title it belongs to.

There is no derived pre-release flag. `key_type = 'review'` already carries
that meaning, and a computed column restating it would be one more thing to
keep in agreement with no extra information.

`available_keys` is a view, not a table. Availability is a function of
`game_keys.status` plus expiry, so deriving it removes the possibility of a
key being listed as available while marked used.

`for_giveaway` reserves a key; `given_away` records that it was awarded.
`giveaway_prizes.game_key_id` is `UNIQUE`, so the same key cannot be the
prize of two giveaways, and `ON DELETE RESTRICT` blocks deleting a key that
was awarded.

Uniqueness is `(user_id, game_platform_id, key_code)` — the same code cannot
be stored twice for one redemption platform, but an identical string on
Steam and on GOG is two distinct keys.

## Embargo vs redemption deadline

Two dates that look alike and belong in different places.

`game_keys.expires_at` is the last moment a key can be **redeemed**. It is a
property of that individual token — it comes from the bundle or order the key
arrived in, so two keys for one game from different sources genuinely differ.
It stays on the key.

`game_coverage.embargo_until` is the first moment coverage may be
**published**. It is one fact per game: the publisher embargoes a title, not a
token. It lives on `game_coverage`, which is already `UNIQUE (user_id,
game_id)` and private to the user.

It was briefly on `game_keys` and that was wrong. A game with nine keys needed
the same date entered nine times, free to drift apart; and an embargo with no
key attached — a preview build, a press invite, a copy bought under agreement —
could not be recorded at all. Migration `005` moved it.

The `content_key_warnings` view joins both: content scheduled before its
game's embargo lifts, or relying on a key that expires before the stream date.
Because embargo now comes from the game, the first warning fires for content
with no key, which is the case the old shape could not see.

## Negotiations

`negotiations` is one thread with one counterpart. Because a counterpart can
be a publisher, a developer or a key platform, there are three nullable FKs
plus `counterpart_kind`, and a `CHECK` keeping them consistent — a row
declaring `'publisher'` must have `publisher_id`.

`negotiation_games` carries a per-game `status`, so a single request covering
five titles can end with three granted and two denied. `negotiation_messages`
stores each message with a direction, which is what makes "did they ever
reply" answerable.

The partial index `negotiations_awaiting_idx` covers open threads ordered by
`last_activity_at` — the "who owes me a reply" query.

## Giveaways

Entrants are not stored, per the chosen scope: `giveaways` plus
`giveaway_prizes` records what was given and to whom, not who entered. Adding
a `giveaway_entries` table later is additive and breaks nothing.

## Types

`bigint GENERATED ALWAYS AS IDENTITY` for surrogate keys — SQL-standard, and
`ALWAYS` prevents accidental manual inserts. `timestamptz` for every instant
so a stream scheduled across a DST change stays correct. `citext` for
usernames, e-mails and slugs, making uniqueness case-insensitive.
