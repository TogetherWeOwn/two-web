<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Models\Rsvp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * The scheduled task: keep event state fresh, and clean up past events.
 *
 * This is the half of "degrades, never white-screens" that nobody sees. A write-back
 * can exhaust its retries while the bot is having a bad hour, and when that happens
 * the RSVP is still committed and the Discord mirror is stale — with nothing left on
 * a queue to fix it. This command is what notices, so the recovery does not depend on
 * a member happening to change their answer again.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-10T12:00:00Z');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('re-dispatches a write-back for a published event whose rsvps never synced', function () {
    Queue::fake();

    $event = Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHours(2),
    ]);
    Rsvp::factory()->create([
        'event_id' => $event->id,
        'status' => RsvpStatus::Going,
        'synced_to_discord_at' => null,
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    Queue::assertPushed(SyncEventToDiscord::class, fn ($job) => $job->eventKey === $event->event_key);
});

it('leaves an event alone when every answer is already mirrored', function () {
    Queue::fake();

    $event = Event::factory()->create([
        'status' => EventStatus::Published,
        'discord_event_id' => '1234567890',
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHours(2),
    ]);
    Rsvp::factory()->create([
        'event_id' => $event->id,
        'synced_to_discord_at' => Carbon::now()->subHour(),
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    // Re-upserting a healthy event spends the bot's 60-per-minute budget on nothing.
    Queue::assertNothingPushed();
});

it('re-dispatches a published event that discord has never confirmed at all', function () {
    Queue::fake();

    $event = Event::factory()->create([
        'status' => EventStatus::Published,
        'discord_event_id' => null,
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHours(2),
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    Queue::assertPushed(SyncEventToDiscord::class, fn ($job) => $job->eventKey === $event->event_key);
});

it('does not chase an event that has already finished', function () {
    Queue::fake();

    Event::factory()->create([
        'status' => EventStatus::Published,
        'discord_event_id' => null,
        'starts_at' => Carbon::now()->subDays(3),
        'ends_at' => Carbon::now()->subDays(3)->addHours(2),
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    // Discord deletes its own past events. Upserting one is an error, not a fix.
    Queue::assertNothingPushed();
});

it('does not chase a draft or a cancelled event', function (EventStatus $status) {
    Queue::fake();

    Event::factory()->create([
        'status' => $status,
        'discord_event_id' => null,
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHours(2),
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    Queue::assertNothingPushed();
})->with([
    'draft' => EventStatus::Draft,
    'cancelled' => EventStatus::Cancelled,
]);

// ---------------------------------------------------------------------------
// Cleanup. Past events stop being "upcoming" without anybody editing them.
// ---------------------------------------------------------------------------

it('marks a finished event as past so the calendar stops listing it', function () {
    Queue::fake();

    $finished = Event::factory()->create([
        'status' => EventStatus::Published,
        'discord_event_id' => '1234567890',
        'starts_at' => Carbon::now()->subDays(1),
        'ends_at' => Carbon::now()->subHours(2),
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    expect($finished->fresh()->status)->toBe(EventStatus::Past);
});

it('leaves an event that is happening right now alone', function () {
    Queue::fake();

    $running = Event::factory()->create([
        'status' => EventStatus::Published,
        'discord_event_id' => '1234567890',
        'starts_at' => Carbon::now()->subHour(),
        'ends_at' => Carbon::now()->addHour(),
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    // Ends in the future: still on. Closing it here would hide a live event.
    expect($running->fresh()->status)->toBe(EventStatus::Published);
});

it('does not resurrect a cancelled event as past', function () {
    Queue::fake();

    $cancelled = Event::factory()->create([
        'status' => EventStatus::Cancelled,
        'starts_at' => Carbon::now()->subDays(1),
        'ends_at' => Carbon::now()->subHours(2),
    ]);

    $this->artisan('events:reconcile')->assertSuccessful();

    expect($cancelled->fresh()->status)->toBe(EventStatus::Cancelled);
});

it('is registered on the schedule, because a command nobody runs fixes nothing', function () {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'events:reconcile'));

    expect($events)->not->toBeEmpty();
});
