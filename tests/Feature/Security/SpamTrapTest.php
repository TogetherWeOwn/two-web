<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Livewire\MemberProfile;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\SpamTrap;
use Livewire\Livewire;

// Honeypot + minimum-fill-time trap on the member write forms (TOG-8715).
//
// The contract under test: a bot-speed submission with the decoy filled is
// swallowed — no row, no error, no hint — while a normal submission writes
// exactly as before. Every trap test below asserts BOTH faces: the success
// shape the caller sees AND the absent database row. A test that only checks
// the response could pass while the write still happens; a test that only
// checks the row could pass while the bot gets an error oracle.

function memberStatsStub(string $discordId): MemberStats
{
    return MemberStats::unavailable($discordId);
}

/* ---------------------------------------------------------------------------
   The trap primitives
   --------------------------------------------------------------------------- */

it('treats a blank decoy as human and any content as bot', function () {
    expect(SpamTrap::honeypotFilled(null))->toBeFalse()
        ->and(SpamTrap::honeypotFilled(''))->toBeFalse()
        ->and(SpamTrap::honeypotFilled('   '))->toBeFalse()
        ->and(SpamTrap::honeypotFilled('https://spam.example'))->toBeTrue()
        ->and(SpamTrap::honeypotFilled(['x']))->toBeTrue()
        ->and(SpamTrap::honeypotFilled([]))->toBeFalse();
});

it('calls an instant save too fast and a patient one human', function () {
    expect(SpamTrap::tooFast(now()->getTimestamp()))->toBeTrue()
        ->and(SpamTrap::tooFast(now()->getTimestamp() - SpamTrap::MIN_FILL_SECONDS))->toBeFalse()
        ->and(SpamTrap::tooFast(now()->getTimestamp() - 3600))->toBeFalse()
        // A stamp from the future reads as instant: fail closed, never open.
        ->and(SpamTrap::tooFast(now()->getTimestamp() + 3600))->toBeTrue()
        // A zero stamp (save without opening the form) reads as instant too.
        ->and(SpamTrap::tooFast(0))->toBeTrue();
});

/* ---------------------------------------------------------------------------
   Profile form (Livewire MemberProfile::save)
   --------------------------------------------------------------------------- */

it('swallows a bot-speed profile save with the decoy filled and writes nothing', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => memberStatsStub($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Buy cheap followers now.')
        ->set(SpamTrap::HONEY_FIELD, 'https://spam.example')
        ->call('save')
        // The no-oracle face: the exact success state a real save produces.
        ->assertSet('editing', false)
        ->assertSee('Profile saved.')
        ->assertDontSee('Check the highlighted fields');

    // The silent face: nothing was written.
    expect($member->profile()->exists())->toBeFalse();
});

it('swallows an instant profile save with no decoy and writes nothing', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => memberStatsStub($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Instant scripted save.')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->exists())->toBeFalse();
});

it('shows field errors on a fast invalid save instead of false success (TOG-9361)', function () {
    $member = User::factory()->create();

    // No time travel: the save lands instantly, and the decoy is filled — but
    // the bio is over the limit, so validation must win over the trap. A
    // false "Profile saved." here would both lie to members and teach bots
    // that invalid input is accepted.
    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => memberStatsStub($member->discord_id)])
        ->call('edit')
        ->set('bio', str_repeat('a', 1001))
        ->set(SpamTrap::HONEY_FIELD, 'https://spam.example')
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio'])
        ->assertSee('Check the highlighted fields');

    expect($member->profile()->exists())->toBeFalse();
});

it('saves a patient profile edit exactly as before', function () {
    $member = User::factory()->create();

    $edit = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => memberStatsStub($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Usually on after work.')
        ->set('gamesText', "Minecraft\nHelldivers 2")
        ->set('timezone', 'Europe/London');

    // A human fill takes longer than the floor: move the clock past it
    // between opening the form and saving. (The stamp is Locked, so the only
    // honest way to age it is elapsed time — same as a real member.)
    $this->travel(SpamTrap::MIN_FILL_SECONDS + 1)->seconds(); // @phpstan-ignore method.notFound

    $edit->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first())
        ->bio->toBe('Usually on after work.')
        ->games->toBe(['Minecraft', 'Helldivers 2'])
        ->timezone->toBe('Europe/London');
});

/* ---------------------------------------------------------------------------
   RSVP JSON writes
   --------------------------------------------------------------------------- */

it('answers a honeypot RSVP with the first-write success shape and stores nothing', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 4]);

    $this->actingAs($member)
        ->putJson(route('events.rsvp.update', $event), [
            'status' => RsvpStatus::Going->value,
            SpamTrap::HONEY_FIELD => 'https://spam.example',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.status', RsvpStatus::Going->value)
        ->assertJsonPath('data.synced_to_discord_at', null);

    expect(Rsvp::query()->count())->toBe(0);
});

it('answers a honeypot withdrawal with an empty 204 and keeps the row', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $rsvp = Rsvp::factory()->for($event)->for($member)->create(['status' => RsvpStatus::Going]);

    $this->actingAs($member)
        ->deleteJson(route('events.rsvp.destroy', $event), [
            SpamTrap::HONEY_FIELD => 'https://spam.example',
        ])
        ->assertNoContent();

    expect($rsvp->fresh()?->status)->toBe(RsvpStatus::Going)
        ->and(Rsvp::query()->count())->toBe(1);
});

it('leaves a normal RSVP write untouched by the trap', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 4]);

    $this->actingAs($member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertStatus(201)
        ->assertJsonPath('data.status', RsvpStatus::Going->value);

    expect(Rsvp::query()->where('user_id', $member->id)->count())->toBe(1);
});
