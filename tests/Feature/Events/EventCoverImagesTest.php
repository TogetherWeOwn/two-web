<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\User;
use App\Support\Events\DiscordEventsSource;

// TOG-7331: no dimensionless content images on event pages (CLS).
//
// The audit found the Event model has no image/cover column at all — no
// fillable, no migration, no request rule (the 'accepts no image or file input
// on the event write path' pin in tests/Unit/ImageUploadSurfaceTest.php guards
// the write side) — and none of the event views render an <img>:
// events/show, events/gone, partials/event-card(-anon), and the
// calendar/archive Livewire views are text-only. The only image-bearing
// surfaces adjacent to events are the FeaturedContent slots (home, design-lab
// concepts, admin preview), which reserve a 16:9 box; the admin preview was
// the one dimensionless straggler and now carries the same ratio inline.
//
// These tests are fail-closed tripwires in that style: if anyone adds a cover
// image to an event surface, the page-level scan goes red until the tag
// carries a reserved box (an aspect-ratio utility or explicit width+height).
// Helper names carry a `coverAudit` prefix on purpose: Pest loads every
// Feature file into one process, and generic names would collide.

beforeEach(function () {
    $source = Mockery::mock(DiscordEventsSource::class);
    $source->shouldReceive('upcoming')->andReturn([]);
    $source->shouldReceive('lastReadFailed')->andReturn(false);
    app()->instance(DiscordEventsSource::class, $source);
});

/** A published event a guest can open. */
function coverAuditEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

/** Every <img> tag in the HTML. */
function coverAuditImgTags(string $html): array
{
    preg_match_all('/<img[^>]*>/', $html, $matches);

    return $matches[0];
}

/** A tag reserves layout: a ratio box or explicit dimensions. */
function coverAuditTagHasBox(string $tag): bool
{
    return str_contains($tag, 'aspect-video')
        || str_contains($tag, 'aspect-[')
        || str_contains($tag, 'aspect-ratio')
        || (str_contains($tag, 'width="') && str_contains($tag, 'height="'));
}

/** Fail with the offending tags named when a page ships a dimensionless image. */
function coverAuditExpectBoxed(string $html, string $page): void
{
    $unboxed = array_values(array_filter(
        coverAuditImgTags($html),
        fn (string $tag): bool => ! coverAuditTagHasBox($tag),
    ));

    expect($unboxed)->toBe([], "{$page} renders dimensionless content images (TOG-7331)");
}

it('renders the events index with no dimensionless images', function () {
    coverAuditEvent();

    $html = (string) $this->get(route('events.index'))->assertOk()->getContent();

    coverAuditExpectBoxed($html, 'GET /events');
});

it('renders the shareable event page with no dimensionless images', function () {
    $event = coverAuditEvent();

    $html = (string) $this->get(route('events.page', $event))->assertOk()->getContent();

    expect($html)->toContain('data-testid="event-page"');

    coverAuditExpectBoxed($html, 'GET /e/{event_key}');
});

it('renders the gone page with no dimensionless images', function () {
    $event = coverAuditEvent(['status' => EventStatus::Cancelled]);

    $html = (string) $this->get(route('events.page', $event))->assertStatus(410)->getContent();

    expect($html)->toContain('data-testid="event-gone"');

    coverAuditExpectBoxed($html, 'GET /e/{event_key} (410)');
});

it('renders the past archive with no dimensionless images', function () {
    coverAuditEvent([
        'title' => 'Last week’s Valorant night',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
    ]);

    $html = (string) $this->get(route('events.past'))->assertOk()->getContent();

    coverAuditExpectBoxed($html, 'GET /events/past');
});

it('reserves a 16:9 box on the admin featured preview image', function () {
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

    $matched = preg_match(
        '/<img[^>]*src="https:\/\/example\.org\/photo\.jpg"[^>]*>/',
        $html,
        $matches,
    );

    expect($matched)->toBe(1, 'No preview <img> found for the featured image URL');

    // Moderator URLs carry no dimensions, so the ratio box reserves layout —
    // the same 16:9 crop the home and taste cards use (TOG-7331).
    expect($matches[0])->toContain('aspect-ratio:16/9')
        ->toContain('loading="lazy"')
        ->toContain('decoding="async"');
});
