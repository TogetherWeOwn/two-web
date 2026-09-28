# Moderator runbook: export and deletion queue

For moderators handling a member's "give me my data" or "delete me" request.
The member-facing side is the `/profile` data section (ships in
[TOG-8705](/TOG/issues/TOG-8705): download-my-data JSON, request-deletion
button, signed-in only); this is the other side of it: how to verify the
requester, what approve/reject does, and what deletion actually removes.

Queue UI: the [data-request queue in the TWO Moderation panel](/admin)
(Filament `admin` panel — `App\Providers\Filament\AdminPanelProvider::panel()`,
`->path('admin')`). Member side: `/profile` (`ProfileController::mine`,
route name `profile`). Sources: `app/Models/User.php`,
`app/Models/Profile.php`, `app/Models/Rsvp.php`, `app/Models/MemberDataAccessLog.php`,
`docs/member-data-model.md`, `docs/member-data-access-log.md`.

> Status note: the self-service flow and queue resource land in TOG-8705.
> This runbook is written against that spec and merges after it — if the
> queue section is not in `/admin` yet, the request was filed before the
> queue shipped. Handle it by hand (verify + export via the panel, delete
> via engineering escalation) and link the TOG-8705 card in your reply.

## The 30-second triage

Ask two things: **which request is it (export or deletion), and does the
requester's Discord identity match the record?** Everything else follows.

| Situation | Meaning | Do this |
|---|---|---|
| Export request, identity matches | Member wants their JSON. | Approve: point them at `/profile` download. No moderator download needed. |
| Export request, identity does NOT match | Someone asks for another member's data. | Reject. Never export one member's data to another person. |
| Deletion request, identity matches | Member wants their site record gone. | Verify (below), then approve. |
| Deletion request, identity does NOT match | Someone asks to delete another member. | Reject. Deletion is self-only, no exceptions. |
| Logged-out visitor asks for either | Both flows are signed-in only (`auth` + Discord). | Tell them to sign in first — logged-out access redirects to login, by design. |

## Verify identity first — Discord is the only identity

Rule 0 (`docs/member-data-model.md`): a member exists because they signed in
with Discord. `users.discord_id` is NOT NULL and unique. There is no email
column, no password column, no link-your-old-account flow. Verification is:

1. Open the request in the [queue](/admin) and read its `discord_id`
   (the snowflake — the stable identifier, not the renameable username).
2. Compare it against the `users.discord_id` on the member row. Match on the
   snowflake, never on display name or username — both are renameable.
3. If they do not match, reject with the copy below. Do not ask for
   passwords (there are none), tokens, or callback codes.

Only sign-in re-reads moderator/member flags from Discord roles; `/join`
never touches them. If a moderator's own flag looks wrong, sign out and
back in before assuming the queue is broken
(`docs/troubleshooting-join.md`, "I'm a moderator but the site doesn't show it").

## Approve / reject

Who can decide: moderators and nobody else — the panel asks
`User::canAccessPanel()`, which is the `access-admin` gate
(`AppServiceProvider`, `is_moderator === true`), recomputed from
`DISCORD_MODERATOR_ROLE_IDS` at every login. A signed-in non-moderator gets
403, not a login loop.

- **Export / approve:** no moderator action on the data itself. The member
  downloads their own JSON at `/profile` (profile fields + RSVPs). Do not
  download it for them and send it over Discord — that creates a second copy
  outside the access log.
- **Export / reject (identity mismatch):** reject in the queue with reason
  `identity-mismatch`. Use the member copy below.
- **Deletion / approve:** approve in the queue only after identity matches.
  Approval queues the actual delete (engineering-owned job in TOG-8705).
  Tell the member what goes and what stays (next section) — send the copy
  below *before* the delete runs so they can save anything they want.
- **Deletion / reject:** identity mismatch, obviously someone else's
  record, or the requester cancels. Reject with reason; a rejected request
  changes nothing.

Every queue read is access-logged (`RecordMemberDataAccess` on the panel's
`authMiddleware` + `member-access-log` on the member routes). The log keeps
identifiers and shape, never contents
(`docs/member-data-access-log.md` §3). Never paste member data into the
request notes, the log message, or Discord.

### Member-facing copy (paste as-is)

- Export approved: "Your download is ready at /profile — it holds your
  profile (bio, games, timezone) and your event RSVPs. Nobody else can
  download it for you."
- Export rejected: "We couldn't match that request to your Discord account,
  so we didn't release anything. Sign in with the Discord account you want
  the data for and try again."
- Deletion approved: "Approved — your site profile and RSVPs will be
  removed. Your Discord server membership is untouched; leaving the server
  itself is separate. Download anything you want to keep at /profile first."
- Deletion rejected: "We didn't delete anything — the request didn't match
  your signed-in Discord account. Deletion is self-only. Sign in with the
  right account and request again if it was yours."

## What deletion removes — and what it keeps

Deletion removes the member's own site record. Verified against the
migrations (so a moderator promise matches what the database does):

Goes (cascades off `users`):

- `profiles` row (`user_id` → `cascadeOnDelete`) — bio, games, timezone.
- `rsvps` rows (`user_id` → `cascadeOnDelete`) — the member's event RSVPs.

Stays (by design — say so up front, do not promise otherwise):

- `events.created_by` and `featured_contents.created_by` are
  `nullOnDelete` — events and homepage features the member created stay up,
  unattributed.
- `member_data_access_logs` rows survive: `viewer_user_id` is nulled on
  delete, the snowflake + subject ids stay. Removing a member must not erase
  the record that somebody was looking at member data.
- `join_attempts.discord_id` is a plain string with no user FK — funnel rows
  keep the snowflake after the user row is gone.
- Discord server membership, roles, and messages are untouched. The site
  cannot remove anyone from the Discord server and never tries.

## Retention

- **Access log:** 90 days (`MEMBER_ACCESS_LOG_RETENTION_DAYS`,
  `config/member_access_log.php`), pruned daily by `model:prune` on
  `MemberDataAccessLog::prunable()` (`routes/console.php`). The model throws
  on `update`/`delete` — retention's mass-by-age delete is the only way a row
  leaves.
- **Queue request rows:** keep 90 days after close (approved/rejected), same
  window as the access log, then prune. Request rows carry identifiers and
  decision metadata only — never a copy of the exported JSON.
- **Exports:** the member's download is generated on demand at `/profile`;
  the site keeps no stored copy. Do not save exports "for later."

## Still stuck — escalate to engineering

- Member disputes export contents (missing RSVP, wrong games): collect the
  request id, the member's snowflake, what they expected, and roughly when
  (with timezone). Do not re-send them someone else's export to compare.
- Delete approved but the row is still there after a day: collect the
  request id + snowflake and escalate — likely the TOG-8705 job or a FK the
  runbook's table above doesn't cover. Do not delete rows by hand in tinker.
- Queue section missing from `/admin`: the TOG-8705 resource hasn't shipped
  yet. Handle by hand, link TOG-8705, and do not invent a parallel queue in
  Discord threads.
