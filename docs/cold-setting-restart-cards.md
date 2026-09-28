# Cold-setting restart cards

How a save of a *cold* bot setting in the admin panel turns into an
`operator`-labelled restart card on the board — and why two-web is allowed to
hold a credential that can write to the board, but only in exactly one shape.

**Last checked:** 20 September 2026 · **Issues:** TOG-3537 (this filer) ·
TOG-3472 (Filament Bot settings admin section, which *calls* this filer) ·
TOG-3093 (owner directive / ADR that mandates the behaviour)

This is a decision record. The mechanism's rationale lives in the code
docblock once it lands (mirroring `App\Services\Bot\InternalActionClient`); this
file records *the decision and the operator contract*, which are board-relevant
and must not rot silently into a class comment.

---

## What a "cold" setting is

A **cold** setting is one the bot reads once, at boot, from its environment — so
changing it in the database or the admin UI does nothing until the bot process
is restarted. `TWO_AUTOMOD` (the automod master switch) is the first one. A
**warm** setting is read per-use and takes effect immediately; warm settings do
**not** file a card.

The TOG-3093 ADR (owner directive, 16 September 2026) is explicit: *every* save
of a cold setting must file an operator card carrying the **exact restart
command and its rollback**, so that a human with host access can make the change
actually take effect, and undo it. A cold setting whose save silently does
nothing is worse than a read-only one — it lies to the moderator who flipped it.

## Decision 1 — two-web files the card server-side, through one seam

two-web files the card itself, from the server, by calling the Paperclip
control-plane REST API (`POST {PAPERCLIP_API_URL}/api/companies/{companyId}/issues`).
It does **not** go through the bot, and it does **not** happen in the browser.

This mirrors the trust model already proven in
`App\Services\Bot\InternalActionClient`: **exactly one class** constructs the
request, so there is exactly one place to get the auth and the card template
right. A second constructor would be a second place to leak the token or to let
caller text into the card body. The new class is
`App\Services\Paperclip\RestartCardClient` and it is `final readonly`, built once
from config in `AppServiceProvider`, exactly like the bot client.

## Decision 2 — the credential is a server-only env var, never anything else

The token lives in `config('services.paperclip')`, read from environment:

| Env var | Purpose |
|---|---|
| `PAPERCLIP_API_TOKEN` | Paperclip agent API key with scope `task_bridge`. **Secret.** Server-only; never rendered, never logged. |
| `PAPERCLIP_API_URL` | Control-plane base URL. |
| `PAPERCLIP_COMPANY_ID` | Company the card is filed under (path-scoped). |
| `PAPERCLIP_OPERATOR_LABEL_ID` | UUID of the `operator` label (the API takes `labelIds`, not names). |
| `PAPERCLIP_PARENT_ISSUE_ID` | The fixed parent issue every restart card is filed under (the key's `parentIssueIds`). |
| `PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID` | The agent each card is assigned to (in the key's `allowedAssigneeAgentIds`) — DevOps & Reliability Engineer, who holds a Coolify deploy-scope token. |
| `PAPERCLIP_RESTART_BOT_ENVIRONMENT` | `staging` or `production`: which two-bot the restart command targets. No default. |

The token follows the same rules as `BOT_SHARED_SECRET`: it is never committed,
never sent to the browser, never written to a queue payload, failed-jobs row, or
log line. The client does not log request headers. This is the whole reason the
filer is a server call and not a browser `fetch` — a board-write credential in
front-end code would be readable by every visitor.

## Decision 3 — fail closed

If **any** config value is missing, or the bot environment is not a known one, `RestartCardClient` throws
`PaperclipNotConfiguredException` (mirroring `BotNotConfiguredException`), and the
settings-save action that called it **rejects the save**. The cold setting is
never written when the card cannot be filed.

A useful consequence: with no token provisioned (today's state), the whole
feature is inert and cold settings are effectively **read-only** — which is
exactly the guardrail TOG-3537 requires until this lands. Provisioning the token
(see the operator card) is what *turns the feature on*; merging the code does
not, on its own, change any user-facing behaviour.

## Decision 4 — no arbitrary content can reach the card

The card's title, body, label, and assignee are built from a **fixed
server-side template keyed by a `ColdSetting` enum allowlist**. The only variable
input is *which allowlisted cold setting changed* and *its new value* (a bool or
enum, validated). The restart command and its rollback are **constants in code**,
one per cold setting — there is exactly one right restart command, so it is
written down once and never re-improvised. No moderator-supplied free text ever
reaches the title, body, or labels. A setting not in the enum cannot file a card
at all.

Filed card shape (fixed):

- **title:** `Operator: restart TWO bot (<environment>) to apply <SETTING>=<new value>`
- **parent:** `PAPERCLIP_PARENT_ISSUE_ID`
- **label:** `operator` (by `PAPERCLIP_OPERATOR_LABEL_ID`)
- **assignee:** agent `PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID` (never a user — a
  `task_bridge` key is refused for `assigneeUserId`)
- **body:** the exact restart command for that setting and environment, **and** its rollback

The restart command was pinned by the operator card (TOG-3573), from the Coolify
API on the controller. It restarts the two-bot Coolify application for the
configured environment (`App\Services\Paperclip\BotEnvironment`: staging
`uy4d9ndeygjcem6lgayhxgub`, production `cangagerae31txrk2vfvzzyq`):

```
curl -fsS -X POST -H "Authorization: Bearer $COOLIFY_TOKEN" "$COOLIFY_URL/api/v1/applications/<uuid>/restart"
```

`$COOLIFY_URL` and `$COOLIFY_TOKEN` are the runner's own shell variables; no
secret is written on the card. Rollback: set the setting back to its previous
value in two-web, then run the same restart again. No image or deploy change is
involved.

## Decision 5 — idempotency

The API accepts an `idempotencyKey`
(`packages/shared/src/validators/issue.ts:488-531`). The key is derived from the
setting plus the config revision that produced the change, so a retry of the
same save — or a double-submit — collapses onto **one** operator card instead of
spamming the board. The key is stored with the operation by the caller (the save
action), never minted fresh per attempt, matching the idempotency rule the bot
client already documents.

## Trust boundary — why the public web server may write to the board

Granting two-web a board-write credential widens what the public-facing server
can do. It is authorised by the TOG-3093 owner directive, which mandates that a
cold-setting save files an operator card — the directive *is* the authorisation.
The token is scoped least-privilege: a Paperclip `task_bridge` agent key with
`parentIssueIds=[PAPERCLIP_PARENT_ISSUE_ID]` and
`allowedAssigneeAgentIds=[PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID]`. Paperclip
enforces that it can only create children of that parent, assign them only to
that agent, and read or change only the issues it created; it cannot list the
company, read agents or projects, wake agents, or touch secrets, and it cannot
assign to board users. Its only use here is this one fixed-template call.

## Not in scope here

The Filament settings UI that calls `fileRestartCard()` is TOG-3472. This filer
is deliberately standalone so TOG-3472 can wire the cold master switch to it
without re-deciding any of the above. The filer unblocks TOG-3472; it does not
depend on it.
