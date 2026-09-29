# Troubleshooting join failures — moderator guide

For moderators helping a member who can't get into the TWO Discord server
through the website. The member-facing side is the preview FAQ
(`content/faq-preview.md`, Q2 "How do I join?" and Q5 "I joined but I can't
post"); this is the other side of it: what the member saw, what it means,
and what to tell them.

Scope: the `/join` one-click flow, the `/discord` fallback invite, and
Discord sign-in. Sources: `app/Http/Controllers/JoinController.php`,
`app/Http/Controllers/DiscordInviteController.php`,
`app/Http/Controllers/Auth/DiscordLoginController.php`,
`lang/en/join.php`, `lang/en/auth-discord.php`.

## The 30-second triage

Ask the member two things: **which page were you on (`/join` or `/discord`),
and what exact sentence did it show you?** The wording is fixed, so match it
exactly against this table:

| Member saw (exact sentence) | Meaning | Tell them |
|---|---|---|
| "You are in. Finish Discord’s rules screening before you can post." | Join worked (`added`). | Accept the rules on Discord's membership screen — until then they're pending and can't post (FAQ Q5). |
| "You are already in the server." | They're already a member (`already_member`). | Same as above: check the rules screening / pending state. |
| "You cancelled the Discord approval, so we did not add you to the server. Nothing changed — try again whenever you like." | They pressed Cancel on Discord's approval screen (`denied`). Nothing is broken — this shows on a recovery page with a retry button, not a `/join` banner. | Use the retry button on the page, or the invite at `/discord`. |
| "That Discord approval expired. Try again or use the invite." | The approval took too long, or the callback was stale/replayed (`expired`). Nothing is broken. | Try `/join` once more, or use `/discord`. |
| "One-click join is unavailable right now. The Discord invite still works." | One-click is down: the bot is unreachable, Discord errored, or it isn't configured (`unavailable`). | Use the invite at `/discord` — it works even when everything else is down. |
| (at sign-in) "You need to be a member of the Together We Own Discord server to sign in. Join the server, then come back." | They're not in the server (`not_a_member`). | Join first via `/join` or `/discord`, then sign in. |
| (at sign-in) "Discord did not answer just now, so we could not sign you in. This is on Discord, not you — please try again in a minute." | Discord or the role lookup stalled (`unavailable`). | Wait a minute and try again. |

## Denied approvals (`denied`)

The member pressed Cancel on Discord's consent screen (`access_denied`),
or Discord answered the approval with an error instead of a code. That is
a no, not an error — no retry limit, no lockout, nothing to reset.

What the member actually sees is the deny/error **recovery page**
(`resources/views/oauth/recovery.blade.php`, rendered by
`JoinController::callback` for join and `DiscordLoginController::callback`
for sign-in), not a `/join` banner:

- Title "Join did not go through" with the exact sentence from the triage
  table above (deny vs generic error are different sentences).
- One retry button ("Try joining again" → `/join/redirect`) plus the
  static invite fallback (`/discord`). Discord's own `error_description`
  is never rendered.
- Sign-in renders the same page shape with "Sign-in did not go through"
  and a "Try signing in again" button (no invite link on that path).

Member retry path (tell them exactly this):

1. Press the retry button on the recovery page and approve on Discord.
2. If the retry also fails, use the invite at `/discord` — it skips
   one-click entirely and always works when the invite itself is alive.
3. After they land in the server, they still need Discord's rules
   screening before they can post (FAQ Q5) — a successful retry ends at
   "You are in. Finish Discord's rules screening", not at posting.

Moderator path: nothing to reset, no escalation for one member. Only
escalate a deny pattern when many members hit the generic (non-deny)
error sentence at once — that smells like a Discord-side refusal, not
members pressing Cancel.

## Expired approvals (`expired`)

The trip to Discord and back took too long, or the callback arrived stale or
replayed. Concretely that is one of: an `InvalidStateException` (lost or
replayed OAuth state), or Discord's token endpoint answering `400
invalid_grant` (the authorization code already expired) — see
`JoinController::isExpiredApproval`. Sign-in folds the same failures into
its `expired` banner ("That sign-in attempt took too long and expired").

Unlike deny, expired shows as a **`/join` banner**, not the recovery page:
"That Discord approval expired. Try again or use the invite."

Member retry path (tell them exactly this):

1. Press the one-click button on `/join` once more and approve promptly —
   one retry fixing it is the normal case. Don't double-click or
   back-button-replay the callback; a fresh `/join` → approve round trip
   is the fix.
2. If the second try also says expired, stop retrying one-click and use
   the invite at `/discord` — it skips OAuth entirely.
3. After they land in the server, same finish as always: Discord's rules
   screening before they can post (FAQ Q5).

Moderator path: one or two `expired` in a row is user-side timing, not a
bug — no escalation, no reset. If the **same member gets `expired` three
times in a row**, treat it as "still stuck" below (collect page, exact
sentence, time + timezone, whether `/discord` worked) rather than user
error: a stuck browser session, clock skew, or an extension eating the
OAuth state cookie are the usual culprits — have them try a fresh
private window once before escalating.

## Outages (`unavailable`) and the fallback invite

`/discord` never touches the database, the bot, or the session — it always
redirects to a Discord invite, falling back to a hardcoded code when config
is broken. So the invite is the answer and the diagnostic at once:

- One member failing one-click, invite works: their flow, not an outage.
  Send them down the invite path and move on.
- Many members failing one-click at once, invite works: site-or-bot problem.
  Check [Discord status](https://discordstatus.com) first to rule out Discord,
  then escalate with a time window and a headcount.
- The invite itself is dead: total funnel outage. Escalate immediately —
  a 404 or error page on `/discord` is the one failure this path is designed
  never to have.

## "I'm a moderator but the site doesn't show it"

Join (`/join`) only asks Discord for `identify` + `guilds.join`, which cannot
see roles — so join never touches the moderator flag, and a returning
moderator who re-joins via `/join` keeps whatever flag they had until their
next sign-in. Only sign-in re-reads roles from Discord. Fix: sign out and
sign back in with Discord. If the flag is still wrong after a fresh sign-in,
their moderator role itself is missing on Discord — that's a role problem,
not a website problem.

## Still stuck — what to collect before escalating

1. Which page they were on, the exact sentence shown, and roughly what time
   (with timezone).
2. Whether `/discord` worked for them.
3. Whether anyone else is failing right now — one member means their flow,
   many means an outage.
4. Never ask for: passwords (there are none — sign-in is Discord-only),
   tokens, or codes pasted out of the callback URL.

## Escalating to engineering

- One member, invite works: no escalation. Point them at the FAQ.
- Many members failing one-click, invite works: escalate with the time
  window, headcount, and which sentence they saw. The `JoinController`
  warnings in the logs carry the exception class, the join source, and the
  bot `request_id` — hand engineering those three and they can trace it.
- Invite dead, or sign-in `unavailable` for everybody for more than a few
  minutes: escalate immediately, same details plus the Discord status page
  result.
