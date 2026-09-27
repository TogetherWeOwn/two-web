<?php

use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;
use App\Support\Profiles\Milestone;
use Illuminate\Support\Carbon;

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

it('only lets a member update the fields they own', function () {
    $member = User::factory()->create([
        'username' => 'before-name',
        'display_name' => 'Before Name',
        'discord_joined_at' => '2024-03-01 12:00:00',
        'is_moderator' => false,
    ]);

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => 'Now playing evenings.',
            'games' => ['  Minecraft ', 'Valorant', 'Minecraft'],
            'timezone' => 'America/New_York',
            'username' => 'forged-name',
            'display_name' => 'Forged Name',
            'discord_joined_at' => '2030-01-01 00:00:00',
            'is_moderator' => true,
        ])
        ->assertRedirect(route('profiles.show', $member));

    expect($member->fresh())
        ->username->toBe('before-name')
        ->display_name->toBe('Before Name')
        ->is_moderator->toBeFalse()
        ->and($member->fresh()?->discord_joined_at?->toDateTimeString())->toBe('2024-03-01 12:00:00')
        ->and($member->profile()->first())
        ->bio->toBe('Now playing evenings.')
        ->games->toBe(['Minecraft', 'Valorant'])
        ->timezone->toBe('America/New_York');
});

it('rejects invalid member-owned fields without changing the profile', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), [
            'bio' => str_repeat('a', 1001),
            'games' => [str_repeat('b', 81)],
            'timezone' => 'BST',
        ])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['bio', 'games.0', 'timezone']);

    expect($profile->fresh())
        ->bio->toBe('Before')
        ->games->toBe(['Minecraft'])
        ->timezone->toBe('Europe/London');
});

it('forbids editing another members profile', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create();

    $this->actingAs($viewer)
        ->patch(route('profiles.update', $member), [
            'bio' => 'Changed by somebody else.',
            'games' => [],
            'timezone' => 'UTC',
        ])
        ->assertForbidden();

    expect($member->profile()->exists())->toBeFalse();
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

it('accepts a profile update without the games key', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => 'Still here, new bio.',
        ])
        ->assertRedirect(route('profiles.show', $member));

    expect($member->profile()->first())
        ->bio->toBe('Still here, new bio.')
        ->games->toBe([])
        ->timezone->toBeNull();
});

it('accepts an explicit null games list', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => 'Bio stays.',
            'games' => null,
        ])
        ->assertRedirect(route('profiles.show', $member));

    expect($member->profile()->first()->games)->toBe([]);
});
