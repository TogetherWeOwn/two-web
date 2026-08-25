<?php

namespace App\Services\Bot;

/**
 * What the bot did with an `event.upsert`.
 *
 * Worth keeping apart even though both are successes: "created" and "updated"
 * are different sentences to show a moderator, and a run of unexpected
 * "created"s is how we would notice the `event_key` mapping had been lost.
 */
enum EventUpsertOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
}
