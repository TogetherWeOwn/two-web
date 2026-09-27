<!--
  TOG-5412 — TWO preview FAQ pack part 2 (questions 11-20, docs-only).
  Companion to the part-1 pack (TOG-5231, content/faq-preview.md, Q1-10):
  start there if you're brand new. Member-facing copy. Markdown only: no
  routes, no views, no bot changes.
  Sources: two-web EventsCalendar + rsvp-button copy, RsvpButton.php,
  StoreRsvpRequest.php, home.blade.php rank ladder, Rank.php,
  MemberProfile.php + member-profile copy, docs/member-data-model.md,
  two-bot docs/LEVELING.md, docs/ROUTING.md, docs/PRIVACY.md,
  src/discord/tickets.ts.
-->

# TWO preview FAQ — questions 11–20

The second ten. The first ten live in the top-10 pack — start there if you're
brand new. These go deeper: events, ranks, tickets, and your profile.

## Events on the site

### 11. Where do I find events, and do I need an account to look?

On the site's Events page: game nights, tournaments, whatever the community
puts on. Anyone can read it — including signed-out visitors arriving from a
Discord link. It opens as a list (there's a calendar view too), and if there's
nothing scheduled it says so plainly and names the last one that ran.

### 12. How do I RSVP, and what do the answers mean?

Log in with Discord first — signed-out visitors get a log-in prompt instead
of a button. Then it's one tap: **I'm in**. Your answer shows as **You're
in**, with a **Can't make it** option if plans change. One answer per member
per event; changing your mind updates the same answer.

The honest states: **This one's full** (the cap is named — retrying won't
help, though someone holding a seat can still stand down); **Cancelled** or
**This one has been and gone** for events that won't happen; **That RSVP
didn't save. Try once more** for a real failure (the button stays, so try
again); **Saved. Syncing to Discord**, then **Synced to Discord**, while the
bot catches up — your seat is saved here first, the sync follows within about
ten minutes. Sunday Squad itself needs no RSVP: showing up in voice *is* the
sign-up.

## Ranks, XP, and reward roles

### 13. What are the rank rungs?

Five, in order: **Prospect → Member → Soldier → Veteran → Legend**. Ranks
stack — a Veteran still holds everything below — and the landing page shows
your highest rung. Legend is still unclaimed, and the page says so in words
rather than printing a bare zero.

### 14. How do XP, /rank, and /leaderboard actually work?

Messages earn 15 XP (counted at most once a minute), voice earns 5 XP per
complete minute. Bots and DMs earn nothing — the ladder is humans only.
Levels follow the same cumulative curve as MEE6, so progress carries over.
Check yourself anytime with `/rank` (level, server rank, progress to next),
optionally naming another member; `/leaderboard` shows the top ten, ties
settled by member id so the order doesn't shuffle.

### 15. How do level role rewards work?

At certain levels the bot grants a role reward automatically — and it never
takes an earned reward away. It only grants roles the staff configured, never
invents new ones, and Discord's own permission and hierarchy rules still
apply. If you passed a reward level and the role never arrived, tell a
moderator: that failure is silent on your side and the fix is on staff's.

## Onboarding picker

### 16. How does the game picker work?

After you accept the rules, the welcome post in the landing channel mentions
you with a game picker attached. Pick your games and the bot grants the
matching roles — unticking removes them — then answers in a reply only you
can see, with direct links to the rooms those roles just opened. It never DMs
you. Changed your mind later? Pick again; it chooses tonight's destination,
not a forever label. A few games share the game hub by design rather than
having their own room.

### 17. I tapped the picker and nothing happened. What now?

Rooms are checked against your own permissions at tap time: if a destination
isn't open to you right now, you'll get a "not open to you" answer and
nothing changes. Wait a moment and try again — or just say hello in the
landing channel and a human will grab you. Nobody can fix permissions from
your side, so don't wrestle with it.

## Support tickets

### 18. How do I open a private support ticket?

Use the ticket or support button in the server: a private channel opens for
you and staff, and a staff member claims it. One active ticket at a time —
finish or close the open one before starting another. Talk in the channel
like anywhere else.

### 19. What happens to my ticket transcript?

Staff keep a text transcript for 90 days, staff-only, then it's deleted. Your
message bodies live only in that transcript table — nowhere else in the
database. The channel itself is closed and cleaned up when the ticket ends.

## Your site profile

### 20. How do I fill in my profile?

Sign in with Discord and open your profile. Three things are yours to write:
a short bio (a few sentences is plenty), your games (one per line, up to 20),
and your timezone. Everything else — name, avatar, join date, rank — comes
from Discord and shows read-only. Activity stats are read live from the bot;
if they're unavailable you'll see the empty state and the rest of the page
still loads. Profiles are members-only: signed-in members can view any
profile, logged-out visitors get a sign-in prompt. We never ask for or store
your email — there isn't even a column for it.

---

*Still stuck? Ask in general or DM a moderator — there are no stupid questions
in week one.*
