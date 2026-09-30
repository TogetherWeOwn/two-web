# How to verify (new-member welcome, post 2 of 3)

| | |
|---|---|
| Intended audience | New TWO members who joined but cannot post yet, reading on the website |
| Intended use | Website verify explainer (suggested slice of `content/welcome/`; route TBD by engineering) |
| Sequence | Post 2 of 3: [welcome](01-welcome.md) → how to verify → [first-event invite](03-first-event-invite.md) |
| Tone reference | Checklist-plain: numbered steps, one troubleshooting line, two promises |

## Post copy

```text
Here's the whole verify path — four steps, about five minutes.

1. Join. Approve once with Discord on our [join page](/join) and the bot adds you to the server, or use the [invite link](/discord) instead.
2. Accept the rules. Discord shows a membership screening when you arrive. Until you accept, you're pending: you can't post, click, or react, and that's normal.
3. Pick your games. The landing channel has a game picker. Your picks grant roles and open the matching channels, and you can change them any time.
4. Say hi. Drop a line in introduce-yourself or hop into voice — someone will answer. A fast first human reply is one of our headline numbers, so saying hi genuinely counts.

If one-click join is unavailable, the invite still works. If you cancelled the approval or it expired, just try again or use the invite.

Two promises: we never DM, and we store IDs, timestamps and channel IDs — never message content or email.
```

*Word count: 163.*

## Placeholders — fill at render time, never ship raw

`[join page]` → `/join` · `[invite link]` → `/discord` · `introduce-yourself` → `#introduce-yourself`
(channel `1087198966346690570`, guild `326474832151838730`). No member-specific values in this post.

## Links checked (reviewer-checkable)

- `/join` results copy (`lang/en/join.php`): "added" still requires rules screening; "unavailable"/"denied"/"expired" all fall back to the invite — matches the troubleshooting lines above.
- Rules gate: arrive `pending`, accept rules → gate clears (two-bot `docs/ROUTING.md` §journey, `docs/CONTRIBUTOR_ONBOARDING.md` §1).
- Picker: welcome post + game picker in landing channel, roles granted, changeable any time (two-bot `src/discord/onboarding.ts`, `src/onboarding/catalog.ts`; `INTRO_CHANNEL_ID = '1087198966346690570'`).
- First-reply metric + voice counting toward it (two-bot `docs/CONTRIBUTOR_ONBOARDING.md` §1).
- Privacy line: IDs/timestamps/channel IDs only, never content or email (two-bot `docs/PRIVACY.md`, `docs/CONTRIBUTOR_ONBOARDING.md` §privacy).
- No-DM promise (two-bot `src/discord/onboarding.ts` header: nothing calls `.send()` on a User).

## Acceptance for this file

- [ ] Copy pass: steps match the real flow in order; troubleshooting matches the real `/join` outcomes; no step the product cannot perform.
- [ ] Links above resolve at render.
- [ ] Post body stays under 200 words (163 at time of writing).
- [ ] No live-guild action taken in this slice (this file is copy only).
