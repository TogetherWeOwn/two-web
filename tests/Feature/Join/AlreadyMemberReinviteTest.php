<?php

use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;

// TOG-7318: the bot reports already_member whenever Discord already holds the
// account — including members who left, were kicked, or never finished
// screening — and one-click has nothing new to add. Both result banners must
// offer the database-free /discord funnel as the way back in.
//
// NOTE: helper names are file-prefixed. Pest loads every test file into one
// process, so a bare `stubMemberStats` here would fatal on redeclaration.

function reinviteMemberStats(User $member): void
{
    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->with($member->discord_id)
        ->andReturn(MemberStats::unavailable($member->discord_id));
    app()->instance(MemberStatsSource::class, $source);
}

it('offers the re-invite link on already_member join results', function () {
    $copy = __('join.reinvite');
    expect($copy)->not->toBe('join.reinvite');

    $this->withSession(['join_result' => 'already_member'])
        ->get(route('join'))
        ->assertOk()
        ->assertSeeHtml('data-testid="join-result"')
        ->assertSeeHtml('data-testid="reinvite-link"')
        ->assertSee(route('discord'), escape: false)
        ->assertSee($copy, escape: false);
});

it('shows no re-invite link for other join results', function (string $code) {
    $this->withSession(['join_result' => $code])
        ->get(route('join'))
        ->assertOk()
        ->assertSeeHtml('data-testid="join-result"')
        ->assertDontSee('data-testid="reinvite-link"', escape: false);
})->with(['added', 'unavailable', 'denied', 'expired']);

it('offers the re-invite link on the profile after an already_member join', function () {
    // One-click success lands on the profile, not /join — this is the banner
    // the member actually sees after the Discord app switch.
    $member = User::factory()->create();
    reinviteMemberStats($member);

    $this->actingAs($member)
        ->withSession(['join_result' => 'already_member'])
        ->get(route('profile'))
        ->assertOk()
        ->assertSeeHtml('data-testid="join-result"')
        ->assertSeeHtml('data-testid="reinvite-link"')
        ->assertSee(route('discord'), escape: false)
        ->assertSee(__('join.reinvite'), escape: false);
});
