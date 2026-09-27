<?php

use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;

// TOG-6926: copy-profile-link button on member profiles. The button carries the
// canonical member URL in `data-copy-link` (the contract with
// resources/js/profile-copy-link.js) and a toast slot lives in the page for the
// confirmation. Clipboard behaviour itself is browser-only, so it is covered by
// tests/Browser/ProfileCopyLinkTest.php — including the execCommand fallback.
//
// ProfileBackendTest defines availableMemberStats() and ProfileShareTagsTest
// defines profileShareUnavailableStats(); this file must redeclare neither, so
// the stats stub here has its own name.
function profileCopyLinkUnavailableStats(string $discordId): MemberStats
{
    return MemberStats::unavailable($discordId);
}

beforeEach(function () {
    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->andReturnUsing(fn (string $discordId) => profileCopyLinkUnavailableStats($discordId));
    app()->instance(MemberStatsSource::class, $source);
});

function assertCopyLinkButton(string $html, string $canonical): void
{
    // The button carries the shareable URL, not whatever route rendered the
    // page — /profile and /members/{user} are one page (TOG-6793) and the
    // copied link must be the canonical one either way.
    expect($html)->toContain('data-testid="profile-copy-link"', escape: false);
    expect($html)->toContain('data-copy-link="'.$canonical.'"', escape: false);
}

function assertCopyLinkToast(string $html): void
{
    // Hidden until the page script fills and reveals it; role="status"
    // announces the confirmation without stealing focus.
    expect($html)->toContain('data-testid="profile-copy-toast"', escape: false);
    expect($html)->toContain('role="status"', escape: false);
}

it('renders the copy button with the canonical URL on the member route', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create(['display_name' => 'River']);

    $html = (string) $this->actingAs($viewer)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->getContent();

    assertCopyLinkButton($html, route('profiles.show', $member));
    assertCopyLinkToast($html);
});

it('copies the canonical URL even from the singular /profile page', function () {
    $member = User::factory()->create(['display_name' => 'Rowan']);

    $html = (string) $this->actingAs($member)
        ->get(route('profile'))
        ->assertOk()
        ->getContent();

    // Not route('profile'): /profile is the private mirror; the shareable link
    // is the member URL.
    assertCopyLinkButton($html, route('profiles.show', $member));
    assertCopyLinkToast($html);
});

it('shows the copy button to non-owner viewers without the edit button', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create(['display_name' => 'Wren']);

    $html = (string) $this->actingAs($viewer)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->getContent();

    // Copy-link is a sharing affordance, not an owner action — every signed-in
    // member gets it. Edit stays owner-only.
    assertCopyLinkButton($html, route('profiles.show', $member));
    expect($html)->not->toContain('data-testid="profile-edit"');
});

it('shows both copy and edit buttons to the owner', function () {
    $member = User::factory()->create(['display_name' => 'Ash']);

    $html = (string) $this->actingAs($member)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->getContent();

    assertCopyLinkButton($html, route('profiles.show', $member));
    expect($html)->toContain('data-testid="profile-edit"', escape: false);
});

it('leaks no copy affordance to logged-out visitors', function () {
    $member = User::factory()->create(['display_name' => 'River']);

    foreach ([route('profiles.show', $member), route('profile')] as $url) {
        $response = $this->get($url)->assertRedirect(route('login'));
        $html = (string) $response->getContent();

        expect($html)->not->toContain('data-copy-link', "leaked copy button at {$url}");
        expect($html)->not->toContain('profile-copy-toast', "leaked copy toast at {$url}");
    }
});
