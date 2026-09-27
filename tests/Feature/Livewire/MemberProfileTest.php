<?php

use App\Livewire\MemberProfile;
use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\Milestone;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function profileStats(string $discordId): MemberStats
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

it('renders a complete member profile from Discord and member-owned data', function () {
    $member = User::factory()->create([
        'display_name' => 'River',
        'username' => 'river-two',
        'discord_joined_at' => '2024-03-01 12:00:00',
    ]);
    Profile::factory()->for($member)->create([
        'bio' => 'Usually in co-op after work.',
        'games' => ['Helldivers 2', 'Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => profileStats($member->discord_id),
        ])
        ->assertSee('River')
        ->assertSee('@river-two')
        ->assertSee('Usually in co-op after work.')
        ->assertSee('Helldivers 2')
        ->assertSee('Europe/London')
        ->assertSee('Veteran')
        ->assertSee('Rank Changed');
});

it('makes a brand-new members sparse profile look intentional', function () {
    $member = User::factory()->create(['display_name' => 'Wren', 'avatar' => null]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => MemberStats::available(
                discordId: $member->discord_id,
                joinedAt: null,
                tenureDays: null,
                rankKey: null,
                isCurrentMember: true,
                milestones: [],
            ),
        ])
        ->assertSeeHtml('data-testid="profile-new-member"')
        ->assertSee('Your profile has room to grow.')
        ->assertSee('Add a bio, a few games and your timezone so people know when to find you.')
        ->assertSee('You haven\'t RSVP\'d to anything yet.', false)
        ->assertSeeHtml('data-testid="profile-avatar-fallback"')
        ->assertDontSee('No games added yet.');
});

it('keeps the rest of the profile available while bot stats are down', function () {
    $member = User::factory()->create(['display_name' => 'Rowan']);
    Profile::factory()->for($member)->create(['bio' => 'Here for the people.']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => MemberStats::unavailable($member->discord_id),
        ])
        ->assertSee('Rowan')
        ->assertSee('Here for the people.')
        ->assertSeeHtml('data-testid="profile-stats-unavailable"')
        ->assertSee('Activity is taking a breather.')
        ->assertSee('The rest of this profile is still here.');
});

it('exposes an accessible edit flow only to the profile owner', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['bio' => 'Before']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->assertSeeHtml('data-testid="profile-edit-form"')
        ->assertSeeHtml('for="bio"')
        ->assertSeeHtml('for="games"')
        ->assertSeeHtml('for="timezone"')
        ->assertSee('Your name, avatar, join date and rank come from Discord.');

    $viewer = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->assertDontSeeHtml('data-testid="profile-edit"')
        ->assertDontSeeHtml('data-testid="profile-edit-form"');
});

it('saves member-owned fields and returns to the profile', function () {
    $member = User::factory()->create(['display_name' => 'Wren']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Usually on after work.')
        ->set('gamesText', "Minecraft\nHelldivers 2\nMinecraft")
        ->set('timezone', 'Europe/London')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.')
        ->assertSee('Usually on after work.')
        ->assertSee('Helldivers 2');

    expect($member->profile()->first())
        ->bio->toBe('Usually on after work.')
        ->games->toBe(['Minecraft', 'Helldivers 2'])
        ->timezone->toBe('Europe/London');
});

it('keeps the form open when the profile write fails and does not leak the reason', function () {
    $member = User::factory()->create();
    MemberProfile::$profileWriter = static fn () => throw new RuntimeException('host=10.0.0.4');

    try {
        Livewire::actingAs($member)
            ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
            ->call('edit')
            ->set('bio', 'Still here.')
            ->call('save')
            ->assertSet('editing', true)
            ->assertSet('saveFailed', true)
            ->assertSee('That profile did not save.')
            ->assertSee('Your changes are still here. Try once more.')
            ->assertDontSee('10.0.0.4');
    } finally {
        MemberProfile::$profileWriter = null;
    }
});

it('keeps the form open and identifies fields when an edit fails validation', function () {
    $member = User::factory()->create();

    $component = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', str_repeat('a', 1001))
        ->set('timezone', 'BST')
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio', 'timezone'])
        ->assertSee('Check the highlighted fields and try again.');

    expect($component->html())
        ->toContain('role="alert"')
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="bio-error"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('Saving…');
});

it('saves an empty games list without errors', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('gamesText', '')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first()->games)->toBe([]);
});

/* ---------------------------------------------------------------------------
   Focus after the re-render (TOG-6957). Every profile state change unmounts
   the focused control — opening the form removes the Edit button, saving or
   cancelling removes the form — which drops keyboard focus to <body>. The
   component dispatches to itself so the view's listener can move focus to
   the new state: the form heading on open, the saved confirmation on a valid
   save, the error alert on an invalid save, the Edit button on cancel.
   --------------------------------------------------------------------------- */

it('dispatches a focus event to itself when the edit form opens', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->assertDispatched('profile-state-changed');
});

it('dispatches a focus event to itself when the edit is cancelled', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->call('cancel')
        ->assertSet('editing', false)
        ->assertDispatched('profile-state-changed');
});

it('dispatches a focus event to itself on a valid save', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Usually on after work.')
        ->call('save')
        ->assertSet('editing', false)
        ->assertDispatched('profile-state-changed');
});

it('dispatches a focus event to itself even when validation fails', function () {
    // The dispatch runs BEFORE $this->validate(), because a failed validate
    // throws ValidationException and aborts the method — anything dispatched
    // after it would never run. The listener moves focus to the error alert.
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', str_repeat('a', 1001))
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio'])
        ->assertDispatched('profile-state-changed');
});

it('makes the focus targets focusable so keyboard focus can move there', function () {
    // tabindex="-1": out of the tab order, but focus() works after the swap.
    $member = User::factory()->create();

    $open = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->html();

    expect($open)->toContain('id="edit-profile-heading" tabindex="-1"');

    $failed = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', str_repeat('a', 1001))
        ->call('save')
        ->html();

    expect($failed)->toContain('tabindex="-1"')
        ->toContain('data-testid="profile-edit-failed"');

    $saved = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Usually on after work.')
        ->call('save')
        ->html();

    expect($saved)->toContain('tabindex="-1"')
        ->toContain('data-testid="profile-saved"');
});
