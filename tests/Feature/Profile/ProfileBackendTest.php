<?php

use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;
use App\Support\Profiles\Milestone;
use Illuminate\Support\Carbon;

// TOG-8440: PATCH /members/{user} (`profiles.update`) is deleted per the
// TOG-8433 spec — MemberProfile::save() is the single writer. This file keeps
// the read-path coverage (GET /members/{user}); write-path coverage lives in
// tests/Feature/Livewire/MemberProfileTest.php.

function availableMemberStats(string $discordId): MemberStats
{
    return MemberStats::available(
        discordId: $discordId,
        joinedAt: Carbon::parse('2024-03-01T12:00:00Z'),
        tenureDays: 900,
        rankKey: 'veteran',
        isCurrentMember: true,
        milestones: [
            new Milestone('joined', Carbon::parse('2024-03-01T12:00:00Z'), null),
            new Milestone('rank_changed', Carbon::parse('2025-04-02T18:30:00Z'), 'veteran'),
        ],
    );
}

it('requires Discord sign-in before showing a member profile', function () {
    $member = User::factory()->create();

    $this->get(route('profiles.show', $member))
        ->assertRedirect(route('login'));
});

it('lets any signed-in member view another member profile', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create([
        'display_name' => 'River',
        'discord_joined_at' => '2024-03-01 12:00:00',
    ]);
    Profile::factory()->for($member)->create([
        'bio' => 'Usually in co-op after work.',
        'games' => ['Helldivers 2', 'Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->once()
        ->with($member->discord_id)
        ->andReturn(availableMemberStats($member->discord_id));
    app()->instance(MemberStatsSource::class, $source);

    $this->actingAs($viewer)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->assertSee('River')
        ->assertSee('Usually in co-op after work.')
        ->assertSee('Helldivers 2')
        ->assertSee('From Discord')
        ->assertDontSee('data-testid="profile-edit-form"', escape: false);
});

it('still renders the profile when member stats are unavailable', function () {
    $member = User::factory()->create(['display_name' => 'Rowan']);
    Profile::factory()->for($member)->create(['bio' => 'Here for the people.']);

    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->once()
        ->with($member->discord_id)
        ->andReturn(MemberStats::unavailable($member->discord_id));
    app()->instance(MemberStatsSource::class, $source);

    $this->actingAs($member)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->assertSee('Rowan')
        ->assertSee('Here for the people.')
        ->assertSee('Activity is taking a breather')
        ->assertDontSee('SQLSTATE');
});
