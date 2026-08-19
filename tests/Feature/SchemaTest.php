<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Profile;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Database\QueryException;

// These are the four promises the schema makes. Each one is a rule a later feature
// leans on, so each one gets a test that fails loudly if the migration changes.

it('keeps a member to one answer per event', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    Rsvp::factory()->for($event)->for($user)->create(['status' => RsvpStatus::Going]);

    expect(fn () => Rsvp::factory()->for($event)->for($user)->create(['status' => RsvpStatus::Maybe]))
        ->toThrow(QueryException::class);
});

it('keeps a profile when the Discord fields are re-synced', function () {
    // This is the whole reason profiles is its own table: login overwrites `users`
    // from Discord and must never touch what the member wrote about themselves.
    $user = User::factory()->create(['username' => 'old_handle']);
    Profile::factory()->for($user)->create(['bio' => 'I mostly play Helldivers.']);

    $user->update(['username' => 'new_handle', 'discord_synced_at' => now()]);

    expect($user->fresh()->profile->bio)->toBe('I mostly play Helldivers.');
});

it('stores the games list as an array', function () {
    $profile = Profile::factory()->create(['games' => ['Valorant', 'Minecraft']]);

    expect($profile->fresh()->games)->toBe(['Valorant', 'Minecraft']);
});

it('deletes a members RSVPs when the member is deleted', function () {
    $rsvp = Rsvp::factory()->create();

    $rsvp->user->delete();

    expect(Rsvp::query()->count())->toBe(0);
});

it('defaults a new event to draft so nothing publishes itself', function () {
    $event = Event::query()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addWeek(),
    ]);

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});
