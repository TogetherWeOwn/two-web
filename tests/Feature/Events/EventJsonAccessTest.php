<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// The `/events.json` access contract: who sees which rows, and which fields.
// The route lives inside the `auth` group, so a guest must never reach the
// controller; drafts are moderator-only (the `viewDrafts` gate on the index,
// the `view` gate on show); and the resource carries the allowlisted fields
// only — never the autoincrement `id`, the `created_by` user id, or the raw
// `discord_event_id`. (TOG-6919)

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

/** One event in each status, so every visibility assertion has a row to find or miss. */
function seedAllStatuses(): void
{
    foreach (EventStatus::cases() as $status) {
        Event::factory()->create(['status' => $status]);
    }
}

it('refuses a guest over JSON with a 401 and writes nothing', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $this->getJson(route('events.json'))->assertUnauthorized();
});

it('redirects a guest in a browser to login instead of serving the collection', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $this->get(route('events.json'))->assertRedirect(route('login'));
});

it('shows a member published, cancelled and past events but no drafts', function () {
    seedAllStatuses();

    $keys = collect($this->actingAs($this->member)->getJson(route('events.json'))->assertOk()->json('data'))
        ->pluck('status')->sort()->values()->all();

    // Cancelled stays visible: members already RSVP'd need to see that it is
    // off, and the index only ever excludes drafts.
    expect($keys)->toBe([EventStatus::Cancelled->value, EventStatus::Past->value, EventStatus::Published->value]);
});

it('shows a moderator drafts alongside everything else', function () {
    seedAllStatuses();

    $keys = collect($this->actingAs($this->moderator)->getJson(route('events.json'))->assertOk()->json('data'))
        ->pluck('status')->sort()->values()->all();

    expect($keys)->toBe([
        EventStatus::Cancelled->value,
        EventStatus::Draft->value,
        EventStatus::Past->value,
        EventStatus::Published->value,
    ]);
});

it('refuses a member the draft show route while the moderator reads it', function () {
    $draft = Event::factory()->create(['status' => EventStatus::Draft]);

    $this->actingAs($this->member)->getJson(route('events.show', $draft))->assertForbidden();
    $this->actingAs($this->moderator)->getJson(route('events.show', $draft))->assertOk();
});

it('exposes only the allowlisted fields on every row', function () {
    $allowed = [
        'event_key', 'title', 'game', 'description',
        'starts_at', 'ends_at', 'starts_at_local', 'ends_at_local', 'timezone',
        'location', 'capacity', 'going_count', 'status', 'synced_to_discord',
    ];
    sort($allowed);

    seedAllStatuses();

    foreach (['member' => $this->member, 'moderator' => $this->moderator] as $role => $user) {
        $rows = $this->actingAs($user)->getJson(route('events.json'))->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            $keys = array_keys($row);
            sort($keys);

            // The autoincrement id, the creator user id and the raw Discord id
            // must never leave the server: routes and the bot key on
            // `event_key`, and clients only get the `synced_to_discord` boolean.
            expect($keys)->toBe($allowed, "leaked or missing field for {$role}");
        }
    }
});
