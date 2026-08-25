# Member data access log

Who looked at member data through the admin panel, when, and at whose records.

**Last checked:** 25 August 2026 · **Issues:** TOG-355 (this) · TOG-106 (which
Discord role means moderator) · TOG-54 (the panel itself) · TOG-448 (review that
found the three evasions in §2)

---

## Why this exists

The ruling on TOG-106 is *configure the narrowest moderator role and widen it
later if needed*. That leans on an asymmetry:

- widening access later is cheap and reversible;
- narrowing access later **does not undo anything** — anyone who held the role in
  the interim may already have read member data.

The second half is only true if we can answer "who actually looked?" Without a
record, "we can narrow it later" is a claim with no evidence behind it, and a
role that turns out to have been too broad becomes an incident nobody can scope.
This log is what makes narrow-first *safe* rather than merely optimistic.

There is a second reason, and it is the one that does not go away once the role
ID is settled. `DISCORD_MODERATOR_ROLE_IDS` matches by role **ID**, which
correctly stops a *rename* in Discord from changing who gets the panel — but not
a *membership* change. Anyone with **Manage Roles** in the TWO Discord server can
add a person to the moderator role, and that person then sees the panel. Role
membership is mutable outside this system and outside our review. This table is
the only place that change becomes visible to us at all.

## The requirement

> **Before the moderator admin panel is real on anything but staging, reads of
> member data through it must be logged: who viewed, when, and what they viewed.**

It gates *shipping the panel beyond staging*, not building it. Staging today is
fine on its own terms: with no moderator role configured the site fails closed
and nobody sees a panel at all.

## What is built

Everything below is in the tree and covered by
`tests/Feature/MemberDataAccessLogTest.php` — twenty tests: one per clause of the
requirement, and one per way the control was found to be evadable in review. None
of it depends on Filament, which is not a dependency of this repo yet.

| Piece | File |
|---|---|
| Table | `database/migrations/2026_08_25_000100_create_member_data_access_logs_table.php` |
| Record | `app/Models/MemberDataAccessLog.php` |
| Collection | `app/Support/MemberDataAccess/AccessRecorder.php` |
| The control | `app/Http/Middleware/RecordMemberDataAccess.php` |
| Retention window / enforcement | `config/member_access_log.php` |
| Retention schedule | `routes/console.php` |

### What the panel has to do

One line, on the panel's middleware stack:

```php
->middleware(['auth', 'can:access-admin', 'member-access-log:member,view'])
```

The second parameter is `view` for a screen showing one record and `list` for a
screen showing a page of them. That is the whole integration. **Do not** ask each
resource to declare which members it displayed — see the next section for why.

Forgetting that line is not something anyone has to catch by eye. `it lets no
admin-gated route ship without the access log` walks the route table and fails,
naming the route, for anything carrying `can:access-admin` without
`member-access-log`. It passes vacuously today because no admin route exists yet;
it is the one test here whose job starts later.

---

## The three decisions worth defending

### 1. Subjects are collected from Eloquent, not declared per screen

The obvious design is for each panel screen to declare what it showed. It is also
the one that rots: every new resource, every relation later eager-loaded onto an
existing one, is another place somebody has to remember. The failure mode of
forgetting is *a read that happened with no record of it* — silent, and exactly
what this table exists to prevent.

So the recorder listens to Eloquent's `retrieved` event for `User` and `Profile`
instead. Hydrating a member row is not something a screen can do accidentally and
invisibly. A new resource is covered the day it is written, by nobody.

**This relocates the risk of forgetting rather than removing it,** and the claim
is worth making accurately. A new resource on a route group that already carries
the middleware is covered for free; a new route *group* is not, and forgetting
there takes out the whole control at once rather than one screen. What makes that
acceptable is that it is one place instead of dozens — and that the one place is
pinned by the route-table test above rather than by anyone remembering.

`AccessRecorder::note()` remains for reads Eloquent cannot see: raw queries, and
the bot's read-only views (TWO-23), which are member data that never becomes a
`User` row. **If a panel screen ever reads member data outside Eloquent, it must
call `note()`** — that is the one gap the automatic path has, and it is named
here so it is a known gap rather than a surprise.

A `Profile` is recorded against `user_id`, not against its own key: the profile
is data *about* that member, and an investigation asks about the member. A
partial select — `Profile::query()->select('id', 'bio')` — leaves `user_id`
unhydrated, so the owner is resolved from the profile's key on the query builder
rather than read off the model. Reading the property would return null and drop
the read silently, and what gets served in that case is a bio: contents, not an
identifier. A select carrying neither the key nor `user_id` cannot be resolved at
all, and is refused rather than dropped — see §2.

The viewer's own record is excluded. The authenticated user is hydrated on every
request; without the exclusion, every page view would log a moderator looking at
themselves and the real signal would drown in it.

### 2. A failed write refuses the read

The log row is written **after** the response is generated — so it knows which
records were read — and **before** the response is returned. If the write throws
and `member_access_log.enforce` is true, the request 503s and the member data
does not leave the server.

This is the control, not a nicety. If a failed write served the data anyway, the
log would be a best-effort record, and "who looked?" would be answerable only for
the days it happened to be working — which is not a property you can find out
about *after* you need it.

The trade is cheaper than it reads, and the reason is worth stating: **this
middleware is not on `web`.** A broken log table cannot take the member-facing
site down; the blast radius is admin-panel reads only.

Three things can make a read unrecordable, and all three get the same answer:

| | |
|---|---|
| The write throws | The log is down. 503. |
| The response body is produced after the middleware — `StreamedResponse`, `BinaryFileResponse`, `->download()`, `streamDownload()` — and nothing was recorded | We are past the last moment we could record, and the body has not been generated yet. 503. A screen that reads its members through Eloquent *before* returning the stream, or calls `note()`, is recorded and served normally — so a CSV export is still writable, it just has to be writable in that order. |
| A profile was read that cannot be attributed to a member | We would be writing a row that names some of the members whose data was served, which is a worse answer than none. 503. |

The failure log line carries the exception class and the SQLSTATE and **never the
exception message**. A `QueryException`'s message is the failed INSERT with its
bindings substituted in — the viewer's snowflake and every subject id — and it
would land in `storage/logs/laravel.log`, which has neither this table's
retention window nor its handling rules. `it never puts member identifiers in the
failure log line` pins that, and it breaks the write the way production breaks
it: table present, one column missing. Dropping the whole table fails at
statement *preparation* and produces a message with no bindings in it, which is
the one shape of failure that cannot leak and therefore the one shape that must
not be what the test uses.

`MEMBER_ACCESS_LOG_ENFORCE` defaults to true in **every** environment including
local, on purpose. A switch that is off by default is off in the one place it
mattered, and a developer who has never seen it fail closed does not know that it
does. Turning it off is a decision to serve member data with no record of who
read it.

### 3. The log is identifiers and shape, never contents

A log of who read member data must not become the second place member data
lives. The columns are:

| Column | Why |
|---|---|
| `viewer_discord_id` | The **snowflake**, same reasoning as matching roles by ID. A username is renameable in Discord at any time and the local row is deletable here; neither can be the thing an investigation hangs on. |
| `viewer_user_id` | Convenience join, nulled on delete — removing a member must not erase the record that somebody was looking at member data. |
| `resource`, `action` | The shape of the access. `member` / `view` \| `list`. |
| `subject_user_ids` | Internal `users.id` values. Enough to answer "was this member's data read?", and carrying nothing on their own if the log is ever disclosed. |
| `subject_count` | Denormalised so "who read 400 records in one request" is an `ORDER BY`, not a scan. |
| `route` | The route **name**, never the URL. A URL carries the query string, and a moderator searching for `dave` has put member data in it. |
| `occurred_at` | When. |

`it keeps no copy of member data beyond the identifiers` pins that column list
exactly. If somebody adds a username here to save a join, or a bio "for
context", that test fails and it has to be argued for on a ticket rather than
merged as a convenience.

One row per request, not one per record: a listing that shows forty members is
one act of looking, and forty rows would bury it.

`viewer_discord_id` can hold the literal `unauthenticated`. That should be
impossible — the middleware sits behind `auth` — and it is recorded rather than
dropped precisely because it is the shape of a real defect. **A row with that
value is worth investigating on sight.**

---

## Retention

Ninety days, `MEMBER_ACCESS_LOG_RETENTION_DAYS`. Long enough that something
noticed late can still be investigated; short enough that we are not maintaining
a permanent index of who read what. Shorter is a legitimate choice with a reason.

Pruning is `model:prune` on `MemberDataAccessLog`, scheduled daily in
`routes/console.php`. If the scheduler is not running the table grows forever
rather than losing data — the right way round for a log, but still worth
noticing.

## Append-only, and where that is actually enforced

The model throws on `update` and on `delete`. Retention is the single exception
and it runs as a **mass** delete that never loads a model, so it cannot be
repurposed into a general delete — it can only ever match rows older than the
window.

**That guard is a rail, not a boundary.** Anything holding the database
credentials can ignore it; it turns "somebody did it by accident in a tinker
session" into a stack trace. The boundary is the grant:

```sql
-- The durable version of append-only. Not yet applied — see "Still open".
REVOKE UPDATE, DELETE ON member_data_access_logs FROM two_web;
GRANT INSERT, SELECT ON member_data_access_logs TO two_web;
```

Retention then needs to run as a role that does hold `DELETE`. That is a
deployment change, not an application change.

## Still open

Things this does not do, all deliberate, all owned by whoever ships the panel
beyond staging rather than by this table:

1. **The database grant above is not applied.** Until it is, append-only is
   enforced by application code only. Worth doing before the panel is real off
   staging; not worth blocking the panel's *development* on.
2. **Nothing alerts.** Explicitly out of scope on TOG-355 — no alerting, no
   dashboards, no rate limiting. The log answers questions when someone asks
   them. If a "who read the whole member list this week" query ever gets written,
   `subject_count` and the GIN index on `subject_user_ids` are there for it.
3. **Reads outside Eloquent are not seen** — raw SQL, and the bot's read-only
   views (TWO-23). `note()` is the escape hatch and calling it is a thing to
   remember, which is the weakness of every escape hatch. Named again here
   because §1's automatic path is what the rest of this document leans on.
4. **`retrieved` is the only event listened to.** A model that reaches a screen
   without being hydrated from the database — cached, serialised into a job
   payload and back, constructed in memory — carries member data past the
   recorder. Not reachable today: nothing here caches models.

This list is meant to be exhaustive about the ways the control can be evaded, not
a sample. Three of the entries that used to belong on it — the failure-path leak,
streamed responses, and unattributable profile reads — are now behaviours with
tests instead, which is the direction things should move.

Not open, and not coming back without a reason on a ticket: this is not an
analytics system, and it does not log anything a member typed.
