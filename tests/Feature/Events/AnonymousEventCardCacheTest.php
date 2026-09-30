<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\Events\DiscordEventsSource;

/**
 * TOG-9277: the anonymous fragment cache for calendar cards.
 *
 * The calendar is the hottest page and every guest render rebuilt every card.
 * Guests now get a cached static twin of the card (`partials.event-card-anon`):
 * the same markup, the count as a plain span, the RSVP control as the login
 * link — nothing that needs Livewire, so the whole card is cacheable HTML.
 *
 * These tests prove the two acceptance halves at the page level, as a guest:
 * a repeat render hits the cache (a write that bypasses model events does not
 * move the page), and a model save retires the fragment (the next render is
 * fresh). The Discord source is stubbed clean, same as EventsCalendarTest, so
 * no test touches the real bot connection.
 */
beforeEach(function () {
    $source = Mockery::mock(DiscordEventsSource::class);
    $source->shouldReceive('upcoming')->andReturn([]);
    $source->shouldReceive('lastReadFailed')->andReturn(false);
    app()->instance(DiscordEventsSource::class, $source);
});

/** A published event a guest can see, still to come. */
function cacheTestEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('serves guest cards with no Livewire controls to go stale', function () {
    cacheTestEvent();

    $html = (string) $this->get(route('events.index'))->assertOk()->getContent();

    // The guest's next action is to log in, with the way back attached.
    expect($html)->toContain('Log in with Discord')
        ->and($html)->toContain(route('login', ['next' => '/events']))
        // …and no live RSVP control: a cached fragment must never hand a
        // guest a per-card component snapshot that belongs to somebody
        // else's session. The page root is the single Livewire component
        // left, so exactly one `wire:id` may appear.
        ->and($html)->not->toContain('data-testid="rsvp-going"')
        ->and(substr_count($html, 'wire:id'))->toBe(1);
});

it('hits the guest fragment cache on repeat renders until the event is edited', function () {
    $event = cacheTestEvent();

    $first = (string) $this->get(route('events.index'))->assertOk()->getContent();
    expect($first)->toContain('Friday night Helldivers');

    // A write that bypasses model events leaves the cached fragment in place:
    // the hottest page must not re-render every card on every request.
    Event::query()->whereKey($event->getKey())->update(['title' => 'Renamed behind the cache']);

    $second = (string) $this->get(route('events.index'))->assertOk()->getContent();
    expect($second)->toContain('Friday night Helldivers')
        ->and($second)->not->toContain('Renamed behind the cache');

    // A moderator edit through the model retires the fragment, so the next
    // render is fresh.
    $event->refresh();
    $event->title = 'Renamed behind the cache';
    $event->save();

    $this->get(route('events.index'))->assertOk()->assertSee('Renamed behind the cache');
});

it('retires the cached guest card when an answer lands or leaves', function () {
    // The going badge is part of the card, so an answer that did not bump
    // would leave guests reading the old number until the TTL.
    $event = cacheTestEvent();
    $member = User::factory()->create(['is_moderator' => false]);

    $this->get(route('events.index'))->assertSee('0 going');

    $rsvp = Rsvp::factory()->create([
        'event_id' => $event->getKey(),
        'user_id' => $member->getKey(),
        'status' => RsvpStatus::Going,
    ]);

    $this->get(route('events.index'))->assertSee('1 going');

    $rsvp->delete();

    $this->get(route('events.index'))->assertSee('0 going');
});

it('caches guest cards on the past archive too', function () {
    $event = cacheTestEvent([
        'title' => 'Last week’s Valorant night',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
    ]);

    $first = (string) $this->get(route('events.past'))->assertOk()->getContent();
    expect($first)->toContain('Last week’s Valorant night');

    Event::query()->whereKey($event->getKey())->update(['title' => 'Renamed behind the cache']);

    $second = (string) $this->get(route('events.past'))->assertOk()->getContent();
    expect($second)->toContain('Last week’s Valorant night')
        ->and($second)->not->toContain('Renamed behind the cache');

    $event->refresh();
    $event->title = 'Renamed behind the cache';
    $event->save();

    $this->get(route('events.past'))->assertOk()->assertSee('Renamed behind the cache');
});
