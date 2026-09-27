<?php

namespace App\Services\Bot;

/**
 * What the bot did with an `event.cancel`.
 *
 * A single case today — the contract's result is always
 * `{ "outcome": "cancelled", "event_id": "…" }` — kept as an enum rather than
 * a string so an outcome this release does not know fails loudly in the client
 * instead of passing silently. That is the same guarantee EventUpsertOutcome
 * gives `event.upsert`, and it is the assertion that catches the bot growing a
 * second outcome without us.
 */
enum EventCancelOutcome: string
{
    case Cancelled = 'cancelled';
}
