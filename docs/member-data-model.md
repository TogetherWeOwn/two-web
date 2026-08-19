# Member data model

What a member record is, what a member can change, and who can see it.

**Last checked:** 19 August 2026 · **Issues:** TWO-29 · TWO-61 (WordPress
retirement) · TWO-41 (migration assessment, now closed)

---

## Rule 0: identity is Discord, and only Discord

A member exists because they signed in with Discord. `users.discord_id` is
`NOT NULL` and unique, there is no password column and no email column. If we
ever learn another identity for a person, it hangs off that record. It is never
the other way round.

**No email addresses.** CEO decision, 19 August 2026 (TWO-41): the site stores
no member email, ever. The only reason anyone proposed storing one was to match
members against a legacy WordPress population, and that population does not
exist. This is enforced in three places, so it cannot rot back in by accident:

| Where | What stops it |
|---|---|
| `DiscordLoginController::SCOPES` | `setScopes(['identify','guilds.members.read'])` — `setScopes`, not `scopes`, because `scopes()` merges with Socialite's defaults and the Discord driver defaults to asking for `email` |
| `create_users_table` | No `email` column to write to |
| `DiscordLoginTest` | "never stores an email, because we never ask for one" — feeds Socialite a member *with* an email and asserts it lands nowhere |

Adding an email column is therefore a CEO conversation, not a migration.

**Two scopes on login, and the test asserts the exact set.** `guilds` came off
alongside `email` on 19 August 2026: it unlocks "list every server this person is
in", which nothing here calls, and the per-guild role lookup we do call needs
`guilds.members.read` on its own. Every line on a consent screen is a reason to
press Cancel. The one-click join flow (TWO-57) needs `guilds.join` and gets its
own entry point and its own consent screen — it is deliberately not bolted onto
login, so a returning member checking their profile is never asked for permission
to add them to servers.

---

## The two tables

| Table | Who writes it | Survives a Discord re-sync? |
|---|---|---|
| `users` | Us, from the Discord OAuth + guild payload on every login | No — overwritten every login |
| `profiles` | The member, in the profile form | Yes — this is the point of the split |

`users` holds `discord_id`, `username`, `display_name`, `avatar`,
`discord_joined_at`, `is_moderator`, `discord_synced_at`. All of it is a cache
of what Discord told us. `profiles` holds `bio`, `games`, `timezone`. All of it
is the member's own words.

That split is the whole design. Everything Discord owns lives in a table we
overwrite without asking; everything the member owns lives in a table we never
touch. A re-sync can never eat somebody's bio.

**The rule the UI has to show:** anything on `users` is *derived*, renders
read-only, and says where it came from ("from Discord"). Anything on `profiles`
is editable. Activity stats are neither — they are read live from the bot's
read-only views (TWO-23) and are not stored here at all.

If the bot views are unavailable, the stats section renders its designed empty
state and the rest of the profile still loads. The profile does not depend on
the bot being up.

---

## Who can see a profile — decided

**Decided 19 August 2026, and told to the CEO on TWO-29 the same day.** Not yet
built — TWO-29 is blocked on TWO-23 and TWO-27 — but the call is made so nobody
has to guess when it unblocks.

**Profiles are members-only. Signed in with Discord: you can view any member's
profile. Logged out: you get a sign-in prompt, not a profile.**

There are four tiers and nothing else:

| Who | Sees |
|---|---|
| Logged out | Nothing. Sign-in prompt. |
| Any signed-in member | Any profile: Discord identity, bio, games, timezone, activity stats |
| The owner | The same, plus the edit form |
| Moderator | The same as a member, plus whatever the Filament admin shows |

Reasoning: the site exists to convert strangers into Discord members, and
nothing on somebody's profile page helps a stranger decide to join — the landing
page does that job. A public profile page, on the other hand, turns the site into
a scrapeable index of who is in the server, joined to when they are active,
indexed by Google, forever. Real cost, no benefit. Members-only is also the
setting we can loosen later without asking anyone; public is not — once it is
crawled it is public for good.

Stats are visible to any signed-in member rather than owner-only, because the
same information is already visible in Discord to anyone in the server. We are
not creating a new disclosure, we are reflecting one — which is also the line
that tells us when to stop: **if the bot views ever expose something Discord
itself does not show other members, it does not go on the profile page.**

Deliberately **not** built: per-member visibility toggles, a "hide my stats"
switch, a member directory. None has been asked for. If a member asks to be
hidden, a moderator can handle it by hand until there are enough requests to
justify a setting.

---

## Points, ranks and badges: decided, and deliberately unbuilt

An earlier draft reserved table shapes for GamiPress points, ranks and badges so
a future import from `togetherweown.com` could land truthfully. **The import is
withdrawn**, and it is now settled twice over:

- **Measured.** The WordPress site was inventoried on 19 August 2026: fresh
  install, coming-soon page, GamiPress installed but never switched on — zero
  points types, which is decisive, because a points type *is* the currency.
  There are no balances to move. Evidence: `migration-assessment` rev 2 on
  TWO-41.
- **Confirmed by the founder**, same day, on TWO-21: zero paying subscribers,
  *"none of that is being used, we are starting from scratch on everything."*
  Retirement is tracked on TWO-61.

So **no member records are imported at all** — not points, not ranks, not badge
history, not accounts. A member record on this site is created the first time
that person signs in with Discord, and never any other way. The profile is
designed against exactly two sources: what the bot's event views give us, and
what the member types into the form. There are **no import tables, no `source` /
`external_ref` reconciliation columns, and no link-your-old-account flow**, now
or later. There is no old account to link.

Two design decisions survive that, and they live here as decisions rather than
as migrations:

- **If points ever happen, they are a ledger of signed deltas, not a running
  total column.** A total nobody can reconstruct becomes a moderator support
  burden the first time a member disputes it. A ledger answers "why is it 400?"
  by itself.
- **If a second identity ever needs storing** (Steam, Twitch, a tournament
  handle), it goes in an `external_identities` child table keyed on `user_id`,
  never as extra columns on `users`. `users` is a cache of what Discord told us
  and is overwritten on every login; anything not from Discord cannot live there.

Neither table exists in `database/migrations`, and neither should until
something writes to it. An empty table is not free: it ships in every migration
run, every backup and every schema diff, and it invites the next engineer to
build against a shape nobody has validated against a real requirement. Writing
the decision down costs nothing and buys the same thing — nobody re-litigates it
from scratch on the day it matters.
