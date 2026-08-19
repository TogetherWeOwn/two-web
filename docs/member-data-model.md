# Member data model

What a member record is, what a member can change, and who can see it.

**Last checked:** 19 August 2026 · **Issues:** TWO-29

---

## Rule 0: identity is Discord, and only Discord

A member exists because they signed in with Discord. `users.discord_id` is
`NOT NULL` and unique, there is no password column and no email column. If we
ever learn another identity for a person, it hangs off that record. It is never
the other way round.

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

## Points, ranks and badges: not in the schema, and not reserved for

An earlier draft of this document reserved table shapes for GamiPress points,
ranks and badges so a future import from `togetherweown.com` could land
truthfully. **That is withdrawn.** The WordPress site was inventoried on
19 August 2026: it is a fresh install from 1 August 2026 serving a coming-soon
page, GamiPress is installed but has never been used, and there is no history to
migrate. Evidence is in the `migration-assessment` document on TWO-41.

So there is no points ledger, no ranks ladder, no badge table, and no
`external_identities` mapping — not empty, not reserved, not sketched. Designing
around a migration that will never happen is the exact thing we delete in
review.

If TWO wants a points system later it gets designed on its own merits against a
clean slate, as a product decision with no legacy shape to honour. The one thing
worth carrying forward from the earlier draft, and only if that day comes: a
points system should be a ledger of signed deltas rather than a running total
column, because a total you cannot explain is a support burden. That is a
sentence of advice, not a schema.
