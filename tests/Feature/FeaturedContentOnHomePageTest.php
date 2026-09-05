<?php

use App\Models\FeaturedContent;

// The point of the admin panel. A moderator publishing a featured row must change
// what a visitor sees, with no deploy in between — and the review of PR #218 found
// exactly that link missing: the model, resource, policy and scope all existed and
// nothing on the site ever called `currentlyVisible()`. Rows could be saved and no
// visitor would ever see one.
//
// These tests are the consumer contract. They fail against a landing page that is
// a static `Route::view`, which is what makes them worth having.

it('shows a published featured row to a signed-out visitor', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Community night on Friday',
        'body' => 'Bring a game and a friend.',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Community night on Friday')
        ->assertSee('Bring a game and a friend.');
});

it('hides a draft row until a moderator publishes it', function () {
    $draft = FeaturedContent::factory()->create(['title' => 'Not announced yet']);

    $this->get('/')->assertOk()->assertDontSee('Not announced yet');

    // The publish is the whole feature: the same row, one boolean, no deploy.
    $draft->update(['is_published' => true]);

    $this->get('/')->assertOk()->assertSee('Not announced yet');
});

it('hides a published row whose window has not opened yet', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Embargoed announcement',
        'starts_at' => now()->addDay(),
    ]);

    $this->get('/')->assertOk()->assertDontSee('Embargoed announcement');
});

it('hides a published row whose window has closed', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Last months meetup',
        'ends_at' => now()->subMinute(),
    ]);

    $this->get('/')->assertOk()->assertDontSee('Last months meetup');
});

it('shows a row inside an open window', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Running right now',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    $this->get('/')->assertOk()->assertSee('Running right now');
});

it('orders rows the way moderators arranged them', function () {
    FeaturedContent::factory()->published()->create(['title' => 'Third thing', 'position' => 3]);
    FeaturedContent::factory()->published()->create(['title' => 'First thing', 'position' => 1]);
    FeaturedContent::factory()->published()->create(['title' => 'Second thing', 'position' => 2]);

    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder(['First thing', 'Second thing', 'Third thing']);
});

it('links a featured row when a moderator gave it a url', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Read the charter',
        'url' => 'https://example.org/charter',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('https://example.org/charter', escape: false);
});

it('still serves the join funnel when there is nothing featured', function () {
    // The landing page's one job (TOG-77) outranks this feature. An empty
    // featured list must not cost the site its only link.
    $this->get('/')
        ->assertOk()
        ->assertSee('Together We Own')
        ->assertSee('data-testid="discord-join"', escape: false);
});
