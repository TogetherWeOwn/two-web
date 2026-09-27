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
| "You are in. Finish Discord's rules screening before you can post." | Join worked (`added`). | Accept the rules on Discord's membership screen — until then they're pending and can't post (FAQ Q5). |
| "You are already in the server." | They're already a member (`already_member`). | Same as above: check the rules screening / pending state. |
| "You cancelled the Discord approval. You can still use the invite." | They pressed Cancel on Discord's approval screen (`denied`). Nothing is broken. | Go back to `/join` and approve, or use the invite at `/discord`. |
| "That Discord approval expired. Try again or use the invite." | The approval took too long, or the callback was stale/replayed (`expired`). Nothing is broken. | Try `/join` once more, or use `/discord`. |
| "One-click join is unavailable right now. The Discord invite still works." | One-click is down: the bot is unreachable, Discord errored, or it isn't configured (`unavailable`). | Use the invite at `/discord` — it works even when everything else is down. |
| (at sign-in) "You need to be a member of the Together We Own Discord server to sign in." | They're not in the server (`not_a_member`). | Join first via `/join` or `/discord`, then sign in. |
| (at sign-in) "Discord did not answer just now, so we could not sign you in." | Discord or the role lookup stalled (`unavailable`). | Wait a minute and try again. |

## Denied approvals (`denied`)

The member pressed Cancel on Discord's consent screen. That is a no, not an
error — no retry limit, no lockout, nothing to reset. They can approve again
whenever they like, or skip one-click entirely and use `/discord`.

## Expired approvals (`expired`)

The trip to Discord and back took too long, or the callback arrived stale or
replayed (bad state token, refused code exchange). From the member's side
every variant is the same answer: start again. One retry fixing it is the
normal case; if the same member gets `expired` three times in a row, treat it
as "still stuck" below rather than user error.

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
