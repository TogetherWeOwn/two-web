<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Carbon\CarbonImmutable;

// `EventResource` is the wire contract for the calendar, the bot mirror keyed on
// `event_key`, and every future consumer. This file pins the field set, the types,
// `going_count`, the status values and the listing order, so a rename, a removal
// or a reordered page shows up here instead of in a broken calendar or a bot that
// cannot find its event. Adding a field means updating `eventJsonKeys()` below —
// that edit is the announcement.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
});

/** Every key the events JSON may return, in the order the resource renders. */
function eventJsonKeys(): array
{
    return [
        'event_key',
        'title',
        'game',
        'description',
        'starts_at',
        'ends_at',
        'starts_at_local',
        'ends_at_local',
        'timezone',
        'location',
        'capacity',
        'going_count',
        'status',
        'synced_to_discord',
    ];
}

it('returns exactly the contracted keys in order, and never the database id', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $data = $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->json('data');

    // Strict equality: a rename, a removal, an addition and a reorder all fail.
    expect(array_keys($data))->toBe(eventJsonKeys())
        ->and($data)->not->toHaveKey('id');
});

it('keeps the field types the consumers parse against', function () {
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'game' => 'Helldivers 2',
        'description' => 'Bring stims.',
        'timezone' => 'Europe/London',
        'location' => 'Voice: General',
        'capacity' => 4,
        'status' => EventStatus::Published,
    ]);

    $data = $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->json('data');

    expect($data['event_key'])->toBe($event->event_key)
        ->and($data['title'])->toBeString()
        ->and($data['game'])->toBeString()
        ->and($data['description'])->toBeString()
        ->and($data['timezone'])->toBe('Europe/London')
        ->and($data['location'])->toBeString()
        ->and($data['capacity'])->toBe(4)
        ->and($data['going_count'])->toBeInt()
        ->and($data['status'])->toBe(EventStatus::Published->value)
        ->and($data['synced_to_discord'])->toBeFalse();

    // Both readings of the time: an instant a client can do arithmetic on, and a
    // wall time it can render without knowing the zone. The local readings
    // carry the abbreviation and offset (TOG-6806) so the two sides of an
    // autumn fold do not render identically.
    expect(fn () => CarbonImmutable::parse($data['starts_at']))->not->toThrow(Exception::class)
        ->and(fn () => CarbonImmutable::parse($data['ends_at']))->not->toThrow(Exception::class)
        ->and($data['starts_at_local'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} [A-Z]{3,5} \([+-]\d{2}:\d{2}\)$/')
        ->and($data['ends_at_local'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} [A-Z]{3,5} \([+-]\d{2}:\d{2}\)$/');
});

it('renders nulls as nulls, not as omissions', function () {
    // A sparse event: everything nullable left empty. The keys must still be
    // there, because a consumer doing `$row['game']` must not become an
    // "undefined index" the week a host leaves a field blank.
    $event = Event::factory()->create([
        'game' => null,
        'description' => null,
        'location' => null,
        'capacity' => null,
        'status' => EventStatus::Published,
    ]);

    $data = $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->json('data');

    expect(array_keys($data))->toBe(eventJsonKeys())
        ->and($data['game'])->toBeNull()
        ->and($data['description'])->toBeNull()
        ->and($data['location'])->toBeNull()
        ->and($data['capacity'])->toBeNull();
});

it('counts only going answers in going_count, on one row and on the listing', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->count(2)->create(['event_id' => $event->id, 'status' => RsvpStatus::Going]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::Maybe]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::NotGoing]);

    // "maybe" is not a seat, and neither is a no.
    $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->assertJsonPath('data.going_count', 2);

    $listing = $this->actingAs($this->member)
        ->getJson(route('events.json'))
        ->assertOk()
        ->json('data');

    expect(collect($listing)->firstWhere('event_key', $event->event_key)['going_count'])->toBe(2);
});

it('reports zero going, not null, when nobody has answered', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->assertJsonPath('data.going_count', 0);
});

it('exposes exactly the status values the bot maps on', function () {
    // The bot decides mirror/no-mirror per status. A fifth value, or a renamed
    // one, is a bot behaviour change wearing a backend diff — it fails here first.
    expect(array_map(fn (EventStatus $s) => $s->value, EventStatus::cases()))
        ->toBe(['draft', 'published', 'cancelled', 'past']);
});

it('keeps drafts out of the member listing but visible to moderators', function () {
    Event::factory()->create(['title' => 'Unannounced raid', 'status' => EventStatus::Draft]);
    Event::factory()->create(['title' => 'Friday night Helldivers', 'status' => EventStatus::Published]);

    $memberKeys = $this->actingAs($this->member)
        ->getJson(route('events.json'))
        ->assertOk()
        ->json('data.*.title');

    $moderatorKeys = $this->actingAs($this->moderator)
        ->getJson(route('events.json'))
        ->assertOk()
        ->json('data.*.title');

    expect($memberKeys)->toBe(['Friday night Helldivers'])
        ->and($moderatorKeys)->toContain('Unannounced raid', 'Friday night Helldivers');
});

it('lists events earliest first with a stable tiebreak', function () {
    Event::factory()->create([
        'title' => 'Later night',
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5)->addHours(2),
        'status' => EventStatus::Published,
    ]);
    Event::factory()->create([
        'title' => 'Sooner night',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    // A double-header: same start, so without a tiebreak the two rows can drift
    // between pages.
    $tiedAt = now()->addDays(3);
    Event::factory()->create([
        'title' => 'Tied A',
        'starts_at' => $tiedAt,
        'ends_at' => $tiedAt->copy()->addHours(2),
        'status' => EventStatus::Published,
    ]);
    Event::factory()->create([
        'title' => 'Tied B',
        'starts_at' => $tiedAt,
        'ends_at' => $tiedAt->copy()->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $titles = fn () => $this->actingAs($this->member)
        ->getJson(route('events.json', ['per_page' => 100]))
        ->assertOk()
        ->json('data.*.title');

    expect($titles())->toBe(['Sooner night', 'Tied A', 'Tied B', 'Later night'])
        ->and($titles())->toBe($titles());
});

it('derives synced_to_discord from the mirror column, not from status', function () {
    $unsynced = Event::factory()->create(['status' => EventStatus::Published, 'discord_event_id' => null]);
    $synced = Event::factory()->create(['status' => EventStatus::Published, 'discord_event_id' => '123']);

    $this->actingAs($this->member)
        ->getJson(route('events.show', $unsynced))
        ->assertOk()
        ->assertJsonPath('data.synced_to_discord', false);

    $this->actingAs($this->member)
        ->getJson(route('events.show', $synced))
        ->assertOk()
        ->assertJsonPath('data.synced_to_discord', true);
});
