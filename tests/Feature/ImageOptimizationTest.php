<?php

use App\Models\FeaturedContent;
use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;
use App\Support\Profiles\Milestone;
use Illuminate\Support\Carbon;

// TOG-5630: every <img> the site serves must carry its loading contract —
// lazy + async below the fold (featured content), eager + sized + retina above
// it (profile avatar) — with a box reserved up front so the image never shifts
// layout (no CLS). These tests pin the rendered attributes, not the CSS.

function imageOptimizationStats(string $discordId): MemberStats
{
    return MemberStats::available(
        discordId: $discordId,
        joinedAt: Carbon::parse('2024-03-01T12:00:00Z'),
        tenureDays: 900,
        rankKey: 'veteran',
        isCurrentMember: true,
        milestones: [
            new Milestone('joined', Carbon::parse('2024-03-01T12:00:00Z'), null),
        ],
    );
}

/** @return array{0: User, 1: User} */
function imageOptimizationProfile(string $avatar): array
{
    $viewer = User::factory()->create();
    $member = User::factory()->create([
        'display_name' => 'River',
        'avatar' => $avatar,
    ]);
    Profile::factory()->for($member)->create(['bio' => 'Usually in co-op after work.']);

    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->once()
        ->with($member->discord_id)
        ->andReturn(imageOptimizationStats($member->discord_id));
    app()->instance(MemberStatsSource::class, $source);

    return [$viewer, $member];
}

function imageTagFor(string $html, string $src): string
{
    expect($html)->toContain($src);

    $matched = preg_match(
        '/<img[^>]*src="'.preg_quote($src, '/').'"[^>]*>/',
        $html,
        $matches,
    );

    expect($matched)->toBe(1, "No <img> tag found for src {$src}");

    return $matches[0];
}

it('lazy-loads the homepage featured image with async decoding and a CLS-safe box', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Community night on Friday',
        'image_url' => 'https://example.org/photo.jpg',
    ]);

    $tag = imageTagFor($this->get('/')->assertOk()->getContent(), 'https://example.org/photo.jpg');

    expect($tag)->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        ->toContain('aspect-video');
});

it('lazy-loads the taste concept featured image with async decoding', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Community night on Friday',
        'image_url' => 'https://example.org/photo.jpg',
    ]);

    $tag = imageTagFor($this->get('/design-lab/taste')->assertOk()->getContent(), 'https://example.org/photo.jpg');

    expect($tag)->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        ->toContain('aspect-video');
});

it('lazy-loads the hallmark concept featured image with async decoding', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Community night on Friday',
        'image_url' => 'https://example.org/photo.jpg',
    ]);

    $tag = imageTagFor($this->get('/design-lab/hallmark')->assertOk()->getContent(), 'https://example.org/photo.jpg');

    expect($tag)->toContain('loading="lazy"')
        ->toContain('decoding="async"');
});

it('keeps the profile avatar eager with fixed dimensions and a retina srcset', function () {
    [$viewer, $member] = imageOptimizationProfile('https://cdn.discordapp.com/avatars/123/abc.jpg');

    $html = $this->actingAs($viewer)->get(route('profiles.show', $member))->assertOk()->getContent();

    $tag = imageTagFor($html, 'https://cdn.discordapp.com/avatars/123/abc.jpg');

    // Above the fold in the page header: eager, never lazy, or LCP slips.
    expect($tag)->not->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        ->toContain('width="96"')
        ->toContain('height="96"')
        ->toContain('sizes="96px"')
        ->toContain('srcset="https://cdn.discordapp.com/avatars/123/abc.jpg?size=256 2x"');
});

it('joins the avatar retina srcset with & when the URL already has a query string', function () {
    [$viewer, $member] = imageOptimizationProfile('https://example.org/a.png?v=1');

    $html = $this->actingAs($viewer)->get(route('profiles.show', $member))->assertOk()->getContent();

    $tag = imageTagFor($html, 'https://example.org/a.png?v=1');

    // Blade escapes the joining `&` to `&amp;` — correct HTML, and the browser
    // decodes it back before fetching.
    expect($tag)->toContain('srcset="https://example.org/a.png?v=1&amp;size=256 2x"');
});

it('renders the admin featured preview with lazy loading and async decoding', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $row = FeaturedContent::factory()->create([
        'title' => 'Community night on Friday',
        'image_url' => 'https://example.org/photo.jpg',
    ]);

    $html = $this->actingAs($moderator)
        ->get("/admin/featured-contents/{$row->id}/edit")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-testid="featured-preview"');

    $tag = imageTagFor($html, 'https://example.org/photo.jpg');

    expect($tag)->toContain('loading="lazy"')
        ->toContain('decoding="async"');
});
