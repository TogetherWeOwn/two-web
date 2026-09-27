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

// TOG-6804: CarbonImmutable::parse honors an embedded offset over the explicit
// $timezone argument, so POSTing `2026-07-15T20:00:00+02:00` with
// `Europe/London` stored 18:00Z while the 20:00 London wall the host typed is
// 19:00Z — and the page then rendered 19:00 for a host who typed 20:00. The
// fix is on the reject side: an offset-bearing string is a 422 the host can
// fix, never a silently stored wrong instant. Stripping the offset instead
// would just swap one silent wrong answer for another, because the two
// readings disagree about which instant was meant.

it('rejects a create whose wall time carries its own offset', function (string $startsAt) {
    $host = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($host);

    $response = $this->postJson(route('events.store'), [
        'title' => 'Offset trap',
        'starts_at' => $startsAt,
        'ends_at' => '2026-07-15 22:00',
        'timezone' => 'Europe/London',
        'location' => 'Voice: General',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('starts_at');

    expect(Event::query()->count())->toBe(0);
})->with([
    'numeric offset' => '2026-07-15T20:00:00+02:00',
    'zulu' => '2026-07-15T20:00:00Z',
    'named zone' => '2026-07-15 20:00 Europe/London',
]);

it('rejects an update whose wall time carries its own offset', function () {
    $host = User::factory()->create(['is_moderator' => true]);
    $event = Event::factory()->create(['timezone' => 'Europe/London']);
    $original = $event->starts_at->utc()->format('Y-m-d H:i:s');
    $this->actingAs($host);

    $response = $this->patchJson(route('events.update', $event), [
        'title' => $event->title,
        'starts_at' => '2026-07-15T20:00:00+02:00',
        'ends_at' => '2026-07-15 22:00',
        'timezone' => 'Europe/London',
        'location' => $event->location ?? 'Voice: General',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('starts_at');

    expect($event->fresh()?->starts_at->utc()->format('Y-m-d H:i:s'))->toBe($original);
});

it('refuses an offset-bearing wall time at the domain edge, not just in HTTP validation', function () {
    // The Filament panel calls fromValidated() directly and never sees the
    // NaiveWallTime rule, so EventInput::instant is the backstop for that path.
    expect(fn () => EventInput::instant('2026-07-15T20:00:00+02:00', 'Europe/London'))
        ->toThrow(InvalidArgumentException::class);
});

it('still accepts a naive wall time at the domain edge', function () {
    expect(EventInput::instant('2026-07-15 20:00', 'Europe/London')->format('Y-m-d H:i:s'))
        ->toBe('2026-07-15 19:00:00');
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
