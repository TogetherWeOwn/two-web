<?php

use App\Enums\EventStatus;
use App\Enums\RecurrenceFrequency;
use App\Models\Event;
use App\Models\User;
use App\Services\EventService;
use App\Support\EventInput;
use App\Support\RecurrenceInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * Recurring events (TOG-8399): the Sunday Squad is one series, not four
 * hand-made copies.
 *
 * Acceptance: a moderator creates one weekly event with 4 occurrences; all 4
 * render on the calendar and have shareable pages; cancelling the series 410s
 * all instances.
 *
 * Time is frozen: weekly spacing across the October clocks-change weekend is
 * the DST case that matters, and a floating `now()` would let these tests rot
 * into a different season.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-28T12:00:00Z');
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The Sunday Squad's first meeting: 20:00 Europe/London, every Sunday. */
function sundaySquadInput(): EventInput
{
    return EventInput::fromValidated([
        'title' => 'Sunday Squad',
        'game' => 'Fall Guys',
        'description' => 'An hour every Sunday.',
        'starts_at' => '2026-10-04 20:00',
        'ends_at' => '2026-10-04 21:00',
        'timezone' => 'Europe/London',
        'location' => 'Voice: Lobby',
        'capacity' => null,
    ]);
}

function weeklyFour(): RecurrenceInput
{
    return new RecurrenceInput(RecurrenceFrequency::Weekly, 4, null);
}

it('creates one weekly series as four draft rows, parent first', function () {
    Queue::fake();

    $parent = app(EventService::class)->createSeries($this->moderator, sundaySquadInput(), weeklyFour());

    expect($parent->isSeriesParent())->toBeTrue()
        ->and($parent->recurrence_index)->toBe(1)
        ->and($parent->status)->toBe(EventStatus::Draft);

    $children = $parent->childEvents()->orderBy('recurrence_index')->get();

    expect($children)->toHaveCount(3)
        ->and($children->pluck('recurrence_index')->all())->toBe([2, 3, 4]);

    // Weekly in the host's zone: the fourth meeting is past the clocks-change
    // weekend but still 20:00 London wall time, not 20:00 UTC.
    $locals = $children->map(fn (Event $child): string => $child->starts_at->setTimezone('Europe/London')->format('Y-m-d H:i'))->all();

    expect($locals)->toBe(['2026-10-11 20:00', '2026-10-18 20:00', '2026-10-25 20:00']);

    // Every instance is an ordinary row: its own key, its own page, its own
    // RSVPs. Nothing downstream learns what a series is.
    $keys = $children->pluck('event_key')->prepend($parent->event_key)->all();

    expect(array_unique($keys))->toHaveCount(4);

    // Drafts announce nothing: creating a series must not touch Discord.
    Queue::assertNothingPushed();
});

it('renders all four instances on the calendar with shareable pages, and 410s them all on series cancel', function () {
    $service = app(EventService::class);
    $parent = $service->createSeries($this->moderator, sundaySquadInput(), weeklyFour());
    $service->publish($parent->refresh());

    $instances = Event::query()->whereKey($parent->getKey())
        ->orWhere('parent_event_id', $parent->getKey())
        ->orderBy('recurrence_index')
        ->get();

    expect($instances)->toHaveCount(4)
        ->and($instances->pluck('status')->unique()->all())->toBe([EventStatus::Published]);

    // All four on the calendar for a signed-out guest, each under its own key.
    $page = $this->get(route('events.index'))->assertOk()->getContent();

    foreach ($instances as $instance) {
        expect($page)->toContain($instance->event_key);
    }

    // Each instance has its own shareable page.
    foreach ($instances as $instance) {
        $this->get(route('events.page', $instance))->assertOk()->assertSee($instance->title);
    }

    // Cancelling the series calls off every instance: each page goes 410.
    $service->cancel($parent->refresh());

    foreach ($instances as $instance) {
        expect($instance->fresh()?->status)->toBe(EventStatus::Cancelled);
        $this->get(route('events.page', $instance))->assertStatus(410);
    }
});

it('lets the reconcile command materialise a series created anywhere else', function () {
    Queue::fake();

    // A parent row with a rule but no children yet — e.g. the rule was set by
    // a path that does not materialise. The command is the backstop.
    $parent = Event::factory()->create([
        'title' => 'Sunday Squad',
        'starts_at' => CarbonImmutable::parse('2026-10-04 20:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-04 21:00', 'Europe/London')->utc(),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
        'recurrence_frequency' => RecurrenceFrequency::Weekly,
        'recurrence_count' => 4,
        'recurrence_ends_on' => null,
        'recurrence_index' => 1,
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    expect($parent->childEvents()->count())->toBe(3);

    // Idempotent: a second pass creates nothing.
    $this->artisan('events:reconcile')->assertSuccessful();

    expect($parent->childEvents()->count())->toBe(3);
});

it('never resurrects a week a moderator skipped', function () {
    $service = app(EventService::class);
    $parent = $service->createSeries($this->moderator, sundaySquadInput(), weeklyFour());
    $service->publish($parent->refresh());

    // Week 3 is off — the moderator cancelled that one instance.
    $skipped = $parent->childEvents()->where('recurrence_index', 3)->sole();
    $service->cancel($skipped);

    $this->artisan('events:reconcile')->assertSuccessful();

    expect($parent->childEvents()->count())->toBe(3)
        ->and($parent->childEvents()->where('recurrence_index', 3)->sole()->status)
        ->toBe(EventStatus::Cancelled);
});

it('publishes later-materialised children from their own rows, never silently', function () {
    Queue::fake();

    $service = app(EventService::class);
    $parent = $service->createSeries($this->moderator, sundaySquadInput(), weeklyFour());
    $service->publish($parent->refresh());

    // A child created after the publish — e.g. the rule grew — starts as a
    // draft, so extending a live series never announces a meeting nobody saw.
    $late = $parent->childEvents()->orderBy('recurrence_index')->get()->last();
    $late->delete();

    $this->artisan('events:reconcile')->assertSuccessful();

    $restored = $parent->childEvents()->where('recurrence_index', 4)->sole();

    expect($restored->status)->toBe(EventStatus::Draft);

    // And it answers its own page as a draft preview for moderators, not 410.
    $this->actingAs($this->moderator)->get(route('events.page', $restored))->assertOk();
});
