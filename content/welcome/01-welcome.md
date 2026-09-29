# Welcome to TWO (new-member welcome, post 1 of 3)

| | |
|---|---|
| Intended audience | New TWO members (just joined or about to join), reading on the website |
| Intended use | Website welcome page (suggested slice of `content/welcome/`; route TBD by engineering) |
| Sequence | Post 1 of 3: welcome → [how to verify](02-how-to-verify.md) → [first-event invite](03-first-event-invite.md) |
| Tone reference | Short, warm, zero-friction: no sign-up, no application, come-as-you-are |

## Post copy

```text
Welcome to Together We Own. We're a small gaming crew with one weekly ritual: Sunday Squad, every Sunday at 8pm Eastern in the Lobby voice room. One hour of Fall Guys — free on PC, PlayStation, Xbox, Switch and Android, and nothing to be rusty at.

Joining takes a minute: approve once with Discord on our [join page](/join) and you're in, or use the [invite link](/discord) if you prefer. Then accept the rules on Discord's membership screen — that's the gate, and nothing else works until it clears.

After that, the server says hi first: a welcome message and a game picker. Pick what you play, say hi once, and you're one of us.
```

*Word count: 114. "8pm Eastern" is 20:00 America/New_York (see post 3 for the wall-clock rule).*

## Placeholders — fill at render time, never ship raw

`[join page]` → `/join` · `[invite link]` → `/discord`. No member-specific values in this post.

## Links checked (reviewer-checkable)

- `/join` — one-click join page (`routes/web.php` → `JoinController@show`; copy in `lang/en/join.php`).
- `/discord` — database-free invite fallback (`routes/funnel.php`; answers even with Postgres down).
- Sunday Squad facts — Sundays 20:00 America/New_York, 60 min, Lobby voice `1175127344072118405`, Fall Guys (`src/onboarding/anchorEvent.ts` `SUNDAY_SQUAD` in two-bot; `docs/ANCHOR_EVENT.md`).

## Acceptance for this file

- [ ] Copy pass: warm first line, concrete second; no hype words; no exclamation chains; no @everyone/@here; no DMs promised.
- [ ] Links above resolve at render.
- [ ] Post body stays under 200 words (114 at time of writing).
- [ ] No live-guild action taken in this slice (this file is copy only).
