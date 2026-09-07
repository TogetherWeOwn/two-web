<?php

use App\Models\Event;
use App\Models\User;
use App\Services\EventService;
use App\Support\EventInput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// "8pm London" is 19:00Z in July and 20:00Z in December. A naive local timestamp
// gets exactly one of those two wrong, and only for half the year — which is why
// it survives every test written in the wrong season. Both sides, both directions.

dataset('eight pm in London', [
    'BST — clocks forward' => ['2026-07-15 20:00', '2026-07-15 19:00:00'],
    'GMT — clocks back' => ['2026-12-15 20:00', '2026-12-15 20:00:00'],
]);

function londonEvent(string $localWallTime): Event
{
    $host = User::factory()->create(['is_moderator' => true]);

    return app(EventService::class)->create($host, new EventInput(
        title: 'Friday night Helldivers',
        game: 'Helldivers 2',
        description: null,
        startsAt: EventInput::instant($localWallTime, 'Europe/London'),
        endsAt: EventInput::instant($localWallTime, 'Europe/London')->addHours(2),
        timezone: 'Europe/London',
        location: 'Voice: General',
        capacity: null,
    ));
}

it('stores event times as timestamptz, not a naive local timestamp', function (string $column) {
    $type = DB::selectOne(
        'select data_type from information_schema.columns where table_name = ? and column_name = ?',
        ['events', $column],
    );

    expect($type)->not->toBeNull()
        ->and($type->data_type)->toBe('timestamp with time zone');
})->with(['starts_at', 'ends_at']);

it('talks to Postgres in UTC whatever the server was initdb-ed with', function () {
    // Laravel sends times as bare 'Y-m-d H:i:s' strings, and Postgres reads those
    // into a timestamptz using the session time zone. Left unpinned that is a
    // property of the machine, so the same code stores a different instant on two
    // hosts. config/database.php pins it; this is the assertion that it took.
    expect(DB::selectOne('show TimeZone')?->TimeZone)->toBe('Etc/UTC');
});

it('stores the correct UTC instant for a local wall time', function (string $local, string $utc) {
    $event = londonEvent($local)->fresh();

    expect($event)->not->toBeNull()
        ->and($event->starts_at->utc()->format('Y-m-d H:i:s'))->toBe($utc);
})->with('eight pm in London');

it('renders back as 20:00 in the events own zone on both sides of a DST boundary', function (string $local) {
    $event = londonEvent($local)->fresh();

    expect($event)->not->toBeNull()
        ->and($event->timezone)->toBe('Europe/London')
        ->and($event->startsAtLocal()->format('Y-m-d H:i'))->toBe($local)
        ->and($event->startsAtLocal()->format('H:i'))->toBe('20:00');
})->with('eight pm in London');

it('keeps the instant right no matter what the PHP process thinks local time is', function () {
    // The suite runs with APP_TIMEZONE=UTC. A machine set to something else must not
    // move the stored instant, so pin the process clock somewhere awkward and repeat.
    date_default_timezone_set('America/Denver');

    try {
        $event = londonEvent('2026-07-15 20:00')->fresh();

        expect($event)->not->toBeNull()
            ->and($event->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-15 19:00:00');
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('rejects a timezone that is not an IANA identifier', function () {
    $host = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($host);

    $response = $this->postJson(route('events.store'), [
        'title' => 'Bad zone',
        'starts_at' => '2026-07-15 20:00',
        'ends_at' => '2026-07-15 22:00',
        'timezone' => 'BST',
        'location' => 'Voice: General',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('timezone');
});

it('keeps a real IANA identifier', function () {
    $host = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($host);

    $response = $this->postJson(route('events.store'), [
        'title' => 'Good zone',
        'starts_at' => '2026-07-15 20:00',
        'ends_at' => '2026-07-15 22:00',
        'timezone' => 'Europe/London',
        'location' => 'Voice: General',
    ]);

    $response->assertStatus(201);

    $event = Event::query()->firstOrFail();

    expect($event->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-15 19:00:00')
        ->and(Carbon::parse($event->ends_at)->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-15 21:00:00');
});
