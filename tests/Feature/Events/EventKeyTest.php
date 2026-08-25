<?php

use App\Exceptions\ImmutableAttributeException;
use App\Models\Event;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The bot keeps an `event_key -> discord_event_id` map and keys it on this string
// forever. If it were the autoincrement id, staging's event 7 and production's
// event 7 would be the same string, and a shared bot would quietly edit the wrong
// Discord event. So: a ULID, unique, and immutable once written.

it('generates a ULID event key when an event is created', function () {
    $event = Event::factory()->create();

    expect($event->event_key)->toBeString()
        ->and(Str::isUlid($event->event_key))->toBeTrue()
        ->and($event->event_key)->not->toBe((string) $event->id);
});

it('never changes the event key across an update', function () {
    $event = Event::factory()->create();
    $original = $event->event_key;

    $event->update(['title' => 'Renamed after the key was handed to the bot']);

    expect($event->fresh()?->event_key)->toBe($original);
});

it('refuses a deliberate write to the event key', function () {
    $event = Event::factory()->create();
    $original = $event->event_key;

    expect(fn () => $event->forceFill(['event_key' => (string) Str::ulid()])->save())
        ->toThrow(ImmutableAttributeException::class);

    expect($event->fresh()?->event_key)->toBe($original);
});

it('keeps event keys unique in the database, not just in the model', function () {
    $first = Event::factory()->create();
    $second = Event::factory()->create();

    expect(fn () => DB::table('events')->where('id', $second->id)
        ->update(['event_key' => $first->event_key]))
        ->toThrow(QueryException::class);
});
