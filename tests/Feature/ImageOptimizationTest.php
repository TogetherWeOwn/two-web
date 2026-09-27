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

// TOG-6784: the gap list assumed a home hero image that does not exist. The
// hero is an h1 plus copy (home.blade.php:41-45) — deliberately no stock or
// generated imagery — so the LCP element is text and there is nothing to give
// `fetchpriority="high"`. The only preload the LCP path is owed is the Archivo
// font the headline renders in. This pins that invariant both ways: a future
// hero image cannot slip in without LCP discipline, and nobody "fixes" this
// card by preloading the lazy featured image beside the hero.
it('keeps the home hero imageless with only the font preload in the LCP path', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('id="hero-heading"');

    $matched = preg_match(
        '/<section[^>]*aria-labelledby="hero-heading"[^>]*>(.*?)<\/section>/s',
        $html,
        $matches,
    );

    expect($matched)->toBe(1, 'No hero section found on the homepage');
    expect($matches[1])->not->toContain('<img');

    expect($html)->toContain('<link rel="preload" href="/fonts/archivo-latin.woff2"')
        ->not->toContain('as="image"');

    // No image promotion is owed on a page with no images. Scoped to <img>
    // tags on purpose: the deferred Livewire runtime carries
    // fetchpriority="low" by design (AppServiceProvider) and Livewire's
    // asset-injection state can leak between tests sharing one process, so a
    // whole-page fetchpriority assertion would pin test ordering, not the LCP
    // contract.
    preg_match_all('/<img[^>]*>/', $html, $imgTags);

    expect($imgTags[0])->toBeEmpty('home renders no images without featured content');
});

it('keeps the homepage featured image out of the LCP path when present', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Community night on Friday',
        'image_url' => 'https://example.org/photo.jpg',
    ]);

    $html = $this->get('/')->assertOk()->getContent();
    $tag = imageTagFor($html, 'https://example.org/photo.jpg');

    // Lazy and boxed (asserted above); additionally never promoted: a preload
    // or fetchpriority="high" here would contend with the text LCP paint.
    expect($tag)->not->toContain('fetchpriority');
    expect($html)->not->toContain('as="image"');
});
