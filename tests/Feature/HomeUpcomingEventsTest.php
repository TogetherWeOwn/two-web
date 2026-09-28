<?php

use App\Enums\EventStatus;
use App\Models\Event;

// The home teaser (TOG-6927): the next published events as signposts, or a
// designed empty state — never a blank list — when there is nothing upcoming.
//
// RefreshDatabase leaves the database empty unless a test creates rows, so the
// empty-state tests below are the "seeded-empty DB" case: nothing is seeded,
// and the page must still answer with a next step.

/** An upcoming published event on a given day out, with a distinct title. */
function homeTeaserEvent(string $title, int $daysOut, array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => $title,
        'starts_at' => now()->addDays($daysOut),
        'ends_at' => now()->addDays($daysOut)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('shows a designed empty state with a join CTA when no events exist', function () {
    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('data-testid="home-events-empty"')
        ->and($html)->toContain('Nothing scheduled yet.')
        ->and($html)->toContain('data-testid="home-events-join"')
        ->and($html)->not->toContain('data-testid="home-events-list"');

    // The CTA routes through /join (one-click OAuth + invite fallback), never
    // the raw /discord invite — the TOG-5931 funnel rule.
    preg_match('/<a href="([^"]+)"[^>]*data-testid="home-events-join"/', $html, $matches);

    expect($matches[1] ?? null)->toBe(route('join'));
});

it('lists upcoming published events soonest first with links to their pages', function () {
    $later = homeTeaserEvent('Sunday Valorant scrims', 5);
    $sooner = homeTeaserEvent('Friday night Helldivers', 2);

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('data-testid="home-events-list"')
        ->and($html)->not->toContain('data-testid="home-events-empty"');

    $response = $this->get('/');
    $response->assertSeeInOrder(['Friday night Helldivers', 'Sunday Valorant scrims']);

    expect($html)->toContain(route('events.page', $sooner))
        ->and($html)->toContain(route('events.page', $later));
});

it('still shows the empty state when only drafts exist', function () {
    // A draft is unannounced by definition: moderators preview it on /events
    // and /admin, and a guest must not learn it exists from the homepage.
    homeTeaserEvent('Secret draft night', 2, ['status' => EventStatus::Draft]);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="home-events-empty"', escape: false)
        ->assertSee('data-testid="home-events-join"', escape: false)
        ->assertDontSee('Secret draft night')
        ->assertDontSee('data-testid="home-events-list"', escape: false);
});

it('still shows the empty state when only past events exist', function () {
    homeTeaserEvent('Last week Valorant night', -7, [
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="home-events-empty"', escape: false)
        ->assertDontSee('data-testid="home-events-list"', escape: false);
});

it('leaves cancelled events out of the teaser', function () {
    homeTeaserEvent('Called-off night', 2, ['status' => EventStatus::Cancelled]);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="home-events-empty"', escape: false)
        ->assertDontSee('Called-off night');
});

it('caps the teaser at three events', function () {
    homeTeaserEvent('Alpha night', 1);
    homeTeaserEvent('Beta night', 2);
    homeTeaserEvent('Gamma night', 3);
    homeTeaserEvent('Delta night', 4);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="home-events-list"', escape: false)
        ->assertSee('Alpha night')
        ->assertSee('Beta night')
        ->assertSee('Gamma night')
        ->assertDontSee('Delta night');
});
