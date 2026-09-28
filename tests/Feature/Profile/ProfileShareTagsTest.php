<?php

use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;

// TOG-6793: member profile pages carry the same share tags as the rest of the
// funnel (TOG-5624). Both profile routes render `profiles.show`, so both assert
// the same canonical: the shareable member URL. The tags carry nothing member-
// specific beyond the display name already in the page title — the description
// is a fixed generic line, never the bio. Guests are bounced to Discord login
// before any profile HTML renders, so no tags can leak to them.

// ProfileBackendTest defines availableMemberStats(); this file must not
// redeclare it, so the stats stub here has its own name.
function profileShareUnavailableStats(string $discordId): MemberStats
{
    return MemberStats::unavailable($discordId);
}

beforeEach(function () {
    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->andReturnUsing(fn (string $discordId) => profileShareUnavailableStats($discordId));
    app()->instance(MemberStatsSource::class, $source);
});

function assertProfileShareTags(string $html, string $canonical, string $title): void
{
    $description = 'A member of Together We Own.';
    $needles = [
        '<link rel="canonical" href="'.$canonical.'">' => 'canonical',
        '<meta property="og:type" content="website">' => 'og:type',
        '<meta property="og:url" content="'.$canonical.'">' => 'og:url',
        '<meta property="og:title" content="'.e($title).'">' => 'og:title',
        '<meta property="og:description" content="'.e($description).'">' => 'og:description',
        '<meta name="twitter:card" content="summary">' => 'twitter:card',
        '<meta name="twitter:title" content="'.e($title).'">' => 'twitter:title',
        '<meta name="twitter:description" content="'.e($description).'">' => 'twitter:description',
    ];

    // One assertion per needle: toContain() is variadic, so a second argument
    // would be asserted as expected content rather than a failure message.
    foreach ($needles as $needle => $label) {
        expect($html)->toContain($needle);
    }

    expect(substr_count($html, 'rel="canonical"'))->toBe(1, 'profile has the wrong canonical count');
}

it('tags the canonical member URL for sharing', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create(['display_name' => 'River']);
    Profile::factory()->for($member)->create([
        'bio' => 'Usually in co-op after work.',
        'games' => ['Helldivers 2'],
    ]);

    $html = (string) $this->actingAs($viewer)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->getContent();

    assertProfileShareTags($html, route('profiles.show', $member), 'River — Member profile');

    // The bio renders in the page body for members, but the share tags must not
    // carry it: scrapers cache tags, bodies stay behind login.
    expect($html)->toContain('Usually in co-op after work.');
    expect($html)->not->toContain('<meta property="og:description" content="Usually in co-op after work.">');
});

it('points the singular /profile page at the shareable member canonical', function () {
    $member = User::factory()->create(['display_name' => 'Rowan']);

    $html = (string) $this->actingAs($member)
        ->get(route('profile'))
        ->assertOk()
        ->getContent();

    // /profile and /members/{user} are one page; the canonical is always the
    // shareable URL so the two never present as duplicates.
    assertProfileShareTags($html, route('profiles.show', $member), 'Rowan — Member profile');
});

it('falls back to the username when the member has no display name', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create(['display_name' => null, 'username' => 'riverfox']);

    $html = (string) $this->actingAs($viewer)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->getContent();

    assertProfileShareTags($html, route('profiles.show', $member), 'riverfox — Member profile');
});

it('leaks no share tags to logged-out visitors', function () {
    $member = User::factory()->create(['display_name' => 'River']);

    foreach ([route('profiles.show', $member), route('profile')] as $url) {
        $response = $this->get($url)->assertRedirect(route('login'));
        $html = (string) $response->getContent();

        // Guests are bounced to Discord login before any profile HTML renders:
        // a redirect body must carry no canonical, no OG tags, no card tags —
        // and in particular no display name.
        expect($html)->not->toContain('rel="canonical"', "leaked canonical at {$url}");
        expect($html)->not->toContain('og:title', "leaked og:title at {$url}");
        expect($html)->not->toContain('twitter:title', "leaked twitter:title at {$url}");
        expect($html)->not->toContain('River', "leaked display name at {$url}");
    }
});
