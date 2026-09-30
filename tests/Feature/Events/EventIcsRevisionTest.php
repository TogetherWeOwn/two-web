<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\EventService;
use App\Support\EventIcs;
use App\Support\EventInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read one unfolded VEVENT property from a single-event calendar. */
function revisionProperty(string $body, string $property): string
{
    preg_match('/^'.preg_quote($property, '/').':([^\r\n]*)/m', str_replace("\r\n ", '', $body), $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    return $matches[1];
}

it('advances revision identity for fixed-clock edits and publish/cancel in both exports', function (string $route) {
    Bus::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $event = Event::factory()->create(['status' => EventStatus::Draft]);
    $service = app(EventService::class);
    $draftSequence = (int) revisionProperty(EventIcs::for($event), 'SEQUENCE');
    $service->publish($event);
    expect((int) revisionProperty(EventIcs::for($event), 'SEQUENCE'))->toBeGreaterThan($draftSequence);
    $url = $route === 'events.ics' ? route($route, $event) : route($route);
    $before = $this->get($url)->assertOk()->getContent();
    $uid = revisionProperty($before, 'UID');
    $sequence = (int) revisionProperty($before, 'SEQUENCE');

    foreach (['First rapid edit', 'Second rapid edit'] as $title) {
        $service->update($event, new EventInput(
            title: $title,
            game: $event->game,
            description: 'Latest description',
            startsAt: $event->starts_at->addHour(),
            endsAt: $event->ends_at->addHour(),
            timezone: $event->timezone,
            location: 'Latest room',
            capacity: $event->capacity,
        ));
        $body = $this->get($url)->assertOk()->getContent();
        $next = (int) revisionProperty($body, 'SEQUENCE');

        expect($next)->toBeGreaterThan($sequence)
            ->and(revisionProperty($body, 'UID'))->toBe($uid)
            ->and(revisionProperty($body, 'SUMMARY'))->toBe($title)
            ->and(revisionProperty($body, 'DESCRIPTION'))->toBe('Latest description')
            ->and(revisionProperty($body, 'LOCATION'))->toBe('Latest room')
            ->and(revisionProperty($body, 'DTSTART'))->toBe($event->starts_at->utc()->format('Ymd\THis\Z'))
            ->and(revisionProperty($body, 'DTEND'))->toBe($event->ends_at->utc()->format('Ymd\THis\Z'))
            ->and(revisionProperty($body, 'STATUS'))->toBe('CONFIRMED')
            ->and(revisionProperty(EventIcs::for($event), 'SEQUENCE'))->toBe((string) $next);
        $sequence = $next;
    }

    $service->cancel($event);
    $cancelled = $this->get($url)->assertOk()->getContent();
    expect((int) revisionProperty($cancelled, 'SEQUENCE'))->toBeGreaterThan($sequence)
        ->and(revisionProperty($cancelled, 'UID'))->toBe($uid)
        ->and(revisionProperty($cancelled, 'STATUS'))->toBe('CANCELLED')
        ->and(revisionProperty($cancelled, 'SUMMARY'))->toBe('Second rapid edit')
        ->and($event->updated_at->getTimestamp())->toBe(1790769600);

    $this->travel(5)->minutes();
    expect($this->get($url)->assertOk()->getContent())->toBe($cancelled);
})->with(['single download' => 'events.ics', 'subscription feed' => 'events.feed']);

it('uses the persisted revision for stale model saves, bulk writes and a clock moving backwards', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $stale = Event::findOrFail($event->id);
    $sequence = (int) revisionProperty(EventIcs::for($event), 'SEQUENCE');

    $event->update(['title' => 'First writer']);
    $first = (int) revisionProperty(EventIcs::for($event), 'SEQUENCE');
    $stale->update(['title' => 'Stale writer']);
    $second = (int) revisionProperty(EventIcs::for($stale), 'SEQUENCE');
    expect($first)->toBeGreaterThan($sequence)->and($second)->toBeGreaterThan($first);

    $this->travel(-1)->hours();
    DB::table('events')->where('id', $event->id)->update([
        'title' => 'Bulk writer',
        'updated_at' => now(),
    ]);
    $latest = EventIcs::for($event->refresh());
    expect((int) revisionProperty($latest, 'SEQUENCE'))->toBeGreaterThan($second)
        ->and(revisionProperty($latest, 'SUMMARY'))->toBe('Bulk writer');

    DB::table('events')->where('id', $event->id)->update(['status' => EventStatus::Cancelled->value]);
    $cancelled = EventIcs::for($event->refresh());
    expect((int) revisionProperty($cancelled, 'SEQUENCE'))->toBeGreaterThan((int) revisionProperty($latest, 'SEQUENCE'))
        ->and(revisionProperty($cancelled, 'STATUS'))->toBe('CANCELLED');

    DB::table('events')->where('id', $event->id)->update(['title' => 'Bulk writer']);
    expect(EventIcs::for($event->refresh()))->toBe($cancelled);
});

it('preserves legacy revisions on migration and removes its trigger on rollback', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $migration = require database_path('migrations/2026_09_30_000100_add_ics_sequence_to_events_table.php');
    $migration->down();
    DB::table('events')->where('id', $event->id)->update(['updated_at' => '2026-07-01 10:00:00']);
    $legacy = EventIcs::for($event->refresh());
    $legacySequence = (int) revisionProperty($legacy, 'SEQUENCE');

    $migration->up();
    expect($event->refresh()->ics_sequence)->toBe($legacySequence)
        ->and(EventIcs::for($event))->toBe($legacy);
    DB::table('events')->where('id', $event->id)->update(['title' => 'First migrated edit']);
    expect($event->refresh()->ics_sequence)->toBe($legacySequence + 1);

    $migration->down();
    expect(DB::selectOne("SELECT COUNT(*) AS count FROM pg_trigger WHERE tgname = 'events_ics_sequence'")->count)->toBe(0);
    // Restore the schema inside this test's transaction for the remaining suite.
    $migration->up();
});

it('advances quiet writes without accepting a caller-supplied revision', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $event = Event::withoutEvents(fn () => Event::factory()->create([
        'event_key' => (string) Str::ulid(),
        'status' => EventStatus::Published,
    ]));
    $event->refresh();
    $sequence = $event->ics_sequence;
    expect($sequence)->toBe($event->updated_at->getTimestamp());

    $event->title = 'Quiet edit';
    $event->saveQuietly();
    expect($event->refresh()->ics_sequence)->toBe($sequence + 1);
    DB::table('events')->where('id', $event->id)->update(['ics_sequence' => 0]);
    expect($event->refresh()->ics_sequence)->toBe($sequence + 2);
});
