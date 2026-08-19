# Member data model, and how the old site's history lands in it

What a member record is, what a member can change, and where years of GamiPress
points and badges go if we ever import them.

**Last checked:** 19 August 2026 · **Issues:** TWO-29, TWO-41

---

## Rule 0: identity is Discord, and only Discord

A member exists because they signed in with Discord. `users.discord_id` is
`NOT NULL` and unique, there is no password column and no email column. Every
other identity we ever learn about a person — a WordPress account, a Woo
customer, whatever comes next — hangs off that record. It is never the other way
round.

This is the one part of the schema that other things are allowed to depend on,
so it does not get relaxed casually. See "Why we do not just add `wp_user_id`"
below.

---

## What exists today (Phase 1, shipped or in flight)

| Table | Who writes it | Survives a Discord re-sync? |
|---|---|---|
| `users` | Us, from the Discord OAuth + guild payload on every login | No — overwritten every login |
| `profiles` | The member, in the profile form | Yes — this is the point of the split |

`users` holds `discord_id`, `username`, `display_name`, `avatar`,
`discord_joined_at`, `is_moderator`, `discord_synced_at`. All of it is a cache
of what Discord told us. `profiles` holds `bio`, `games`, `timezone`. All of it
is the member's own words.

**The rule the UI has to show:** anything on `users` is *derived*, renders
read-only, and says where it came from ("from Discord"). Anything on `profiles`
is editable. Activity stats are neither — they are read live from the bot's
read-only views (TWO-23) and are not stored here at all.

If the bot views are unavailable, the stats section renders its designed empty
state and the rest of the profile still loads. The profile does not depend on
the bot being up.

---

## Who can see a profile — recommended default

Not yet built (TWO-29 is blocked on TWO-23 and TWO-27), but the decision is
made so nobody has to guess:

**Signed-in members can view any member's profile. Logged-out visitors cannot —
they get a sign-in prompt.**

Reasoning: the site exists to convert strangers into Discord members, and
nothing on somebody's profile page helps a stranger decide to join. A public
profile page, on the other hand, turns the site into a scrapeable index of who
is in the server, indexed by Google, forever. That is a real cost against no
benefit. Owners see their own profile in full; moderators see everything a
member sees plus the activity stats.

Deliberately **not** built: per-member visibility toggles, a "hide my stats"
switch, a member directory. None has been asked for. If a member asks to be
hidden, a moderator can handle it by hand until there are enough requests to
justify a setting.

This is a privacy call, so it is flagged for the CEO on TWO-29 rather than just
merged.

---

## The GamiPress succession

`togetherweown.com` runs GamiPress today: points, ranks, achievements. The new
site replaces it (decided on TWO-21, scoped on TWO-41). We are **not building
points, ranks or badges in Phase 1** and should not.

What follows is the shape those tables take *when* they are built, written down
now so that an import is possible later. It is a contract, not a backlog.

### We are not shipping the tables empty

An empty table with no reader is an abstraction with zero callers, and our own
review standard deletes those. Adding a table later is a ten-line migration —
that is not the expensive part. The expensive parts are (1) picking a shape that
cannot hold the history, and (2) throwing away the data needed to match old
accounts to new ones. This document fixes the first. TWO-41 has to answer the
second.

### Shape 1 — points are a ledger, never a running total

```
point_transactions
  id
  user_id        → users.id
  delta          integer, signed
  reason         short string, e.g. "voice_minutes", "legacy_import"
  source         "site" | "gamipress"
  external_ref   nullable, the GamiPress log row id
  occurred_at    when it happened, NOT when we wrote the row
  created_at
  unique (source, external_ref)
```

A balance is `sum(delta)`. If that is ever too slow — it will not be at our size
— it gets a cached column, not a redesign.

`occurred_at` separate from `created_at` is what makes an import truthful: a
point earned in 2024 has to sort as 2024 even though we wrote the row in 2026.

### Shape 2 — ranks

```
ranks            slug, name, threshold, sort_order
user_rank        user_id, rank_slug, attained_at, source, external_ref
```

Ranks are recorded as *attainments*, not as a column on the member. Same reason:
a member's rank history is a thing the old system has and a single column cannot
hold.

### Shape 3 — badges / achievements

```
badge_awards
  user_id, badge_slug, awarded_at, source, external_ref
  unique (source, external_ref)
```

`awarded_at` is the historical date, not the import date.

### The detail that matters more than the shapes

**Every succession table carries `source` + `external_ref` with a unique index.**
That is what makes an import re-runnable. The realistic failure is not "we chose
the wrong column type", it is "the import half-finished at 1am and now everyone
has double points". A unique key on the origin row makes the second run a no-op
instead of a disaster.

---

## Why we do not just add `wp_user_id` to `users`

Because the mapping is not one-to-one and not permanent. A member may have a
WordPress account, a Woo customer record, and later something else; some will
have none; some WordPress accounts belong to people who never join Discord. That
is a table:

```
external_identities
  user_id      → users.id
  provider     "wordpress" | "woocommerce" | ...
  external_id  their id in that system
  linked_at
  unique (provider, external_id)
```

A column would also force a decision we cannot make yet: what a `users` row
means for somebody who has GamiPress points but has never signed in with
Discord. Which is the actual hard problem —

## The one genuinely irreversible thing: unclaimed history

Suppose 400 members have GamiPress balances and 60 of them have signed into the
new site. The other 340 have no `users` row and cannot get one, because a
`users` row requires a Discord login.

Two ways out, and the choice matters:

1. **Make `users.discord_id` nullable** so the import can create placeholder
   members. This breaks the one guarantee authentication rests on, in order to
   serve a one-off migration. No.
2. **Land the import in a holding table keyed by the external identity, and
   claim it at first login.** `users.discord_id` stays `NOT NULL` and unique.
   The import is idempotent and can run before anybody has logged in. First time
   a member signs in with Discord, one lookup moves their history across.

**Recommendation: option 2.** It costs one small table and one lookup in the
login flow, and it keeps the import completely outside the part of the schema
that authentication depends on.

**The consequence worth stating plainly: none of this changes the Phase 1
schema.** Discord-keyed `users`, member-owned `profiles` split off from it, stats
read live from the bot. The succession lands entirely as new tables next to it.
The GamiPress note costs us zero migrations today.

---

## What we still need answered (TWO-41)

These are not schema questions. They decide whether an import is possible at
all, and no amount of careful table design substitutes for them.

1. **Does the WordPress install hold a Discord identifier per user?** A Discord
   connect plugin writing to `wp_usermeta` makes the match deterministic and
   silent. If there is no such field, the only honest options are a member-driven
   "link your old account" flow, or matching on email — and we deliberately store
   no email addresses, so that route is a privacy escalation needing CEO
   sign-off, not an implementation detail.
2. **Is the GamiPress points *log* exportable, or only current totals?** With the
   log, the ledger reconstructs real history. With totals only, every member gets
   one synthetic "opening balance" row dated at launch. Both are fine; we should
   know which before promising members their history.
3. **Does any of it gate anything real today** — a Discord role, a Woo discount,
   channel access? If points are decorative, launching without them is a
   non-event. If a rank unlocks something, that thing has to exist on day one or
   somebody loses access they paid for.
