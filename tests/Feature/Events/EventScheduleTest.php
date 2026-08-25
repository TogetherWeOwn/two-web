<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// The bot requires `ends_at` on every `event.upsert`. A job that has to invent a
// value for a required field is a bug waiting for a Friday, so the gap is closed
// here — at the domain edge and in the column — rather than in the job.

it('will not store an event with no end time', function () {
    expect(fn () => DB::table('events')->insert([
        'event_key' => (string) Str::ulid(),
        'title' => 'Open-ended',
        'starts_at' => now(),
        'ends_at' => null,
        'timezone' => 'UTC',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a create with no end time before it reaches the database', function () {
    $host = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($host)
        ->postJson(route('events.store'), [
            'title' => 'Open-ended',
            'starts_at' => '2026-07-15 20:00',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ends_at');
});

it('rejects an end time that is not after the start', function () {
    $host = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($host)
        ->postJson(route('events.store'), [
            'title' => 'Backwards',
            'starts_at' => '2026-07-15 20:00',
            'ends_at' => '2026-07-15 20:00',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ends_at');
});

it('backfills a legacy row that has no end time, and the migration is reversible', function () {
    Artisan::call('migrate:rollback', ['--step' => 1]);

    // Rolled back, the shipped schema is what we get: nullable ends_at, no key.
    expect(Schema::hasColumn('events', 'event_key'))->toBeFalse()
        ->and(Schema::hasColumn('events', 'game'))->toBeFalse()
        ->and(Schema::hasColumn('events', 'timezone'))->toBeFalse();

    $id = DB::table('events')->insertGetId([
        'title' => 'Shipped before this migration existed',
        'starts_at' => '2026-07-15 19:00:00',
        'ends_at' => null,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('migrate');

    $row = DB::table('events')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and($row->event_key)->not->toBeNull()
        ->and(Str::isUlid($row->event_key))->toBeTrue()
        ->and($row->timezone)->toBe('UTC')
        ->and(Carbon::parse($row->ends_at)->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-15 21:00:00')
        ->and(Carbon::parse($row->starts_at)->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-15 19:00:00');
});

it('carries the game the card asked for', function () {
    $event = Event::factory()->create(['game' => 'Helldivers 2']);

    expect($event->fresh()?->game)->toBe('Helldivers 2');
});
