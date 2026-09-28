# Your first event (new-member welcome, post 3 of 3)

| | |
|---|---|
| Intended audience | New TWO members deciding whether to show up, reading on the website |
| Intended use | Website first-event invite (suggested slice of `content/welcome/`; route TBD by engineering) |
| Sequence | Post 3 of 3: [welcome](01-welcome.md) → [how to verify](02-how-to-verify.md) → first-event invite |
| Tone reference | Invitation, not announcement: "come anyway" line required, no skill or attendance implied |

## Post copy

```text
Your first event: Sunday Squad. Every Sunday at 8pm Eastern (20:00 America/New_York) in the Lobby voice room. One hour of Fall Guys — free on PC, PlayStation, Xbox, Switch and Android — and nothing to be rusty at.

No sign-up, no need to say you're coming. Drop into voice whenever; the host pulls arrivals into the party so nobody sits in silence. Haven't got Fall Guys installed? Come anyway — there's always something playable in the room itself.

Stay to the last round if you can; saying goodnight by name is half the ritual. Bring a friend — it's free and there's nothing to be rusty at.

Check the [events calendar](/events) for the schedule. Joining less than two hours before start? It's live now — just join the voice room and say hi.
```

*Word count: 133.*

## Placeholders — fill at render time, never ship raw

`Lobby voice room` → Lobby voice channel `1175127344072118405` (guild `326474832151838730`) ·
`[events calendar]` → `/events`. Next-occurrence timestamp (if shown) must render in the reader's
timezone; the recurrence is wall-clock 20:00 America/New_York, never a fixed UTC instant or
"+7 days" step (DST trap documented in two-bot `docs/ANCHOR_EVENT.md`).

## Links checked (reviewer-checkable)

- `/events` — public calendar page, deliberately outside login (two-web `routes/web.php` → `EventsCalendar`; signed-out visitors land on the calendar, not OAuth).
- Sunday Squad facts — Sundays 20:00 America/New_York, 60 min, Lobby voice `1175127344072118405`, Fall Guys, no sign-up, come-anyway line (two-bot `src/onboarding/anchorEvent.ts` `SUNDAY_SQUAD` + `anchorWelcomeText()`; `docs/ANCHOR_EVENT.md`).
- Near-event voice ("live now" under 2h) matches `NEAR_EVENT_MS` (two-bot `src/onboarding/anchorEvent.ts`).

## Acceptance for this file

- [ ] Copy pass: "come anyway" inclusivity present; no skill/attendance requirement implied; time carries its timezone; max one exclamation mark (zero used).
- [ ] Links above resolve at render.
- [ ] Post body stays under 200 words (133 at time of writing).
- [ ] No live-guild action taken in this slice (this file is copy only).
