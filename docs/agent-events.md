# Agent events API — bot consumer guide

One endpoint for the admitted bot caller to manage its staging proof event.
Staging only. If you can use this doc to send one valid event, it did its job.

- Endpoint: `POST /api/agent-events` (JSON only; no session, no CSRF, no user)
- Base URL (staging): `https://staging.togetherweown.com`
- Full URL: `https://staging.togetherweown.com/api/agent-events`
- Production and unconfigured environments answer `404 ingress_disabled`.
  A grant bound to the production guild is always refused (`403 production_guild`).

## Auth

Bearer token over HTTPS. The credential alone identifies your grant; there is
no caller-supplied agent id and no `guild_id` needed.

```http
Authorization: Bearer <AGENT_EVENTS_CREDENTIAL>
Content-Type: application/json
```

- Missing/unknown credential → `401 unauthenticated`, no side effects.
- Expired grant → `403 grant_expired`. Disabled grant → `403 grant_disabled`.
- A credential for any other agent → `403 wrong_caller`.
- A grant not bound to the staging guild → `403 wrong_audience`
  (production-bound grants get `403 production_guild`).
- Keep the credential in the environment (e.g. `AGENT_EVENTS_CREDENTIAL`), never
  in the doc, the repo, or a log. Every attempt — including denials — is audited
  with a reason code and no secrets.

## Envelope

Every request is a JSON object with exactly two required keys:

| Key | Type | Rule |
| --- | ---- | ---- |
| `op` | string | One of `create`, `read`, `update`, `publish`, `cancel`. Anything else → `403 forbidden_action`. |
| `idempotency_key` | string | Non-empty, max 255 chars. A UUID per operation is the recommended shape. Missing/non-string → `422 validation_failed` before auth side effects. |

Optional:

| Key | Type | Rule |
| --- | ---- | ---- |
| `event_key` | string | Addresses an event. Omitted on `read`/`update`/`publish`/`cancel`, the grant's single owned event answers. Unknown key → `404 event_not_found`; known but not yours → `403 foreign_event`. |
| `guild_id` | string | Optional and must equal your grant's guild. Anything else — production included — is `403 wrong_guild`. Omit it unless you have a reason. |
| `fields` | object | Required on `create` and `update`. See field rules below. |
| `version` | integer | Required on `update`: the `agent_version` from your last `read`. Missing/non-integer → `422 validation_failed`. |

Every response — success or denial — carries a `request_id`, except `409 stale_version`, `409 event_not_open`, and `429 rate_limited`, which carry none. Quote it when asking for help.

## Field rules (`fields` on create/update)

Same rules as the human event form. Times are local wall times in `timezone`
(`YYYY-MM-DD HH:MM`), never with an embedded offset (`2026-10-01T20:00:00+01:00`
is a `422`; the offset would silently beat `timezone`).

| Field | Required | Rule |
| --- | --- | ---- |
| `title` | yes | string, max 100 (bot `name` ceiling lives here) |
| `game` | no | string, max 100 |
| `description` | no | string, max 1000 |
| `starts_at` | yes | `date`, naive wall time, must really exist (spring-forward gaps are `422`) |
| `ends_at` | yes | `date`, after `starts_at`, same wall-time rules |
| `timezone` | yes | IANA string, e.g. `Europe/London` |
| `location` | yes | string, max 255, e.g. `Voice: General` |
| `capacity` | no | integer, min 1 |

Field failures answer `422 validation_failed` with per-field `errors`.

## Operations

### `create` — claim your one proof event

Quota: one event per grant. A second `create` is `409 quota_exceeded`, usually naming the
existing `event_key`; updates reuse it.

Request:

```json
{
  "op": "create",
  "idempotency_key": "11111111-1111-4111-8111-111111111110",
  "fields": {
    "title": "Agent proof event",
    "game": "Helldivers 2",
    "description": "One uniquely labelled staging proof.",
    "starts_at": "2026-10-01 20:00",
    "ends_at": "2026-10-01 22:00",
    "timezone": "Europe/London",
    "location": "Voice: General",
    "capacity": 4
  }
}
```

Success `201`:

```json
{
  "event_key": "01JAGENT0123456789",
  "status": "draft",
  "agent_version": 1,
  "proof_marker": "agent-proof-01JAGENT0123456789",
  "request_id": "01JREQUEST0123456789"
}
```

### `read` — state, Discord observation, receipts

Request:

```json
{ "op": "read", "idempotency_key": "11111111-1111-4111-8111-111111111111" }
```

Success `200` returns `{ event, local, discord, owned_event_count,
proof_marker_matches, receipts, request_id }`. `event` carries only proof-owned
fields (`event_key`, `title`, `game`, `description`, `starts_at`, `ends_at`,
`timezone`, `location`, `capacity`, `status`, `agent_version`, `proof_marker`,
`discord_event_id`). `local` is the local receipt (`status`,
`synced_to_discord`) — not an independent observation. `discord` is either the
independent bot observation or the marked absence
`{"unavailable": "verification_unavailable", "reason": "..."}` (`never_mirrored`,
`bot_unreachable`, `mirror_mismatch`, or a bot code) — never a local receipt
dressed up as one. `receipts` lists up to 50 recent audit rows for the event.

### `update` — edit with optimistic version

```json
{
  "op": "update",
  "idempotency_key": "11111111-1111-4111-8111-111111111112",
  "event_key": "01JAGENT0123456789",
  "version": 1,
  "fields": { "title": "Agent proof event (v2)", "game": "Helldivers 2", "starts_at": "2026-10-01 20:00", "ends_at": "2026-10-01 22:00", "timezone": "Europe/London", "location": "Voice: General" }
}
```

Success `200` returns `{ event_key, status, agent_version, request_id }` with the
bumped version. Stale `version` → `409 stale_version` with the current
`agent_version`; re-`read` and retry.

### `publish` / `cancel` — lifecycle moves

```json
{ "op": "publish", "idempotency_key": "11111111-1111-4111-8111-111111111113", "event_key": "01JAGENT0123456789" }
```

Success `200` returns `{ event_key, status, request_id }`. A move the event is
not in a position to make (e.g. publishing a cancelled event) →
`409 event_not_open`. Cancelled stays terminal.

## Idempotency — retries are safe

- New operation → new `idempotency_key` (UUID). Retry of the same operation →
  same key *and* same payload.
- Same key + byte-identical payload (key order ignored) → the stored answer
  replays with the same status/body, plus `"replayed": true` and a fresh
  `request_id`. One event is never created twice; replays skip the rate budget.
- Same key + different payload → `409 idempotency_conflict`. A key names one
  operation; mint a new key for a new operation.
- Contention → `503 operation_busy`: retry with the same key, do not mint a new one.

## Throttle limits

Per grant: **10 mutating** (`create`/`update`/`publish`/`cancel`) and **30 reads**
per minute. Service ceiling across grants: **60 mutating / 300 reads** per minute.
Replays answer before the limiter and spend nothing. Over the limit:

```json
{ "reason": "rate_limited", "message": "Too many requests. Try again in 12 seconds.", "retry_after": 12 }
```

HTTP `429` with `Retry-After` and `X-RateLimit-*` headers (the same envelope as
every other throttle in the app). Wait `retry_after`, retry with the same key.

## Error codes

| HTTP | `reason` | Meaning / fix |
| ---- | -------- | ------------- |
| 404 | `ingress_disabled` | Not staging / ingress off. Call staging. |
| 422 | `validation_failed` | Missing `op`/`idempotency_key`, bad `fields` (per-field `errors` attached), or missing `version` on update. Fix the payload; retries use the same key only if the payload is unchanged, else a new key. |
| 401 | `unauthenticated` | Bad/missing bearer credential. |
| 403 | `forbidden_action` | Unknown `op`. Use the five verbs. |
| 403 | `grant_expired` / `grant_disabled` | Grant dead; ask the provisioning owner. |
| 403 | `wrong_caller` / `wrong_audience` / `production_guild` / `wrong_guild` | Wrong grant audience. Omit `guild_id` and use the staging credential. |
| 403 | `foreign_event` | That `event_key` is not yours. |
| 404 | `event_not_found` | Unknown key (or no owned event yet). |
| 409 | `idempotency_conflict` | Key reused with a different payload. Mint a new key. |
| 409 | `quota_exceeded` | Grant already owns its event. `read`/`update` it. |
| 409 | `stale_version` | `update` raced; re-`read`, retry with fresh `version`. |
| 409 | `event_not_open` | Illegal lifecycle move for current `status`. |
| 429 | `rate_limited` | Over budget; honour `retry_after`, same key. |
| 503 | `operation_busy` | Lock contention; retry with the same key. |

## Worked example (staging)

Create, then read back. Replace the credential placeholder with the real one
from your environment — it never goes in a file or a log.

```bash
export BASE=https://staging.togetherweown.com
export TOKEN="$AGENT_EVENTS_CREDENTIAL"
export KEY_CREATE="$(uuidgen)"
export KEY_READ="$(uuidgen)"

# 1. Create the proof event (201 on first send)
curl -sS -X POST "$BASE/api/agent-events" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "$(cat <<JSON
{
  "op": "create",
  "idempotency_key": "$KEY_CREATE",
  "fields": {
    "title": "Agent proof event",
    "game": "Helldivers 2",
    "description": "One uniquely labelled staging proof.",
    "starts_at": "2026-10-01 20:00",
    "ends_at": "2026-10-01 22:00",
    "timezone": "Europe/London",
    "location": "Voice: General",
    "capacity": 4
  }
}
JSON
)"

# 2. Safe retry: same key + same payload replays (same event_key, "replayed": true)
#    Re-run the exact command above unchanged.

# 3. Read it back (200)
curl -sS -X POST "$BASE/api/agent-events" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "{\"op\":\"read\",\"idempotency_key\":\"$KEY_READ\"}"
```

If step 1 answers `409 quota_exceeded`, the grant already owns an event — the
body names its `event_key`; `read`/`update` that event instead of creating.
