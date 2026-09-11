<?php

use App\Models\FeaturedContent;
use App\Support\Counts\CountsSource;
use App\Support\Counts\LiveCounts;
use App\Support\Counts\Rank;
use Illuminate\Support\Carbon;

it('serves the Hallmark concept without replacing the homepage', function () {
    $this->get('/design-lab/hallmark')
        ->assertOk()
        ->assertSee('Together We Own')
        ->assertSee('A route through the lobby.')
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false)
        ->assertSee(route('join'), escape: false);

    $this->get('/')
        ->assertOk()
        ->assertSee('The lobby is open.')
        ->assertDontSee('A route through the lobby.');
});

it('uses the production homepage counts and rank contract', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::fromRow(
            memberCount: 54,
            onlineCount: 12,
            countsUpdatedAt: Carbon::now(),
        ));
        $mock->shouldReceive('ranks')->andReturn([
            new Rank(key: 'prospect', label: 'Prospect', memberCount: 31),
            new Rank(key: 'legend', label: 'Legend', memberCount: 0),
        ]);
    });

    $this->get('/design-lab/hallmark')
        ->assertOk()
        ->assertSee('54')
        ->assertSee('12')
        ->assertSee('Prospect')
        ->assertSee('31 members')
        ->assertSee('Legend')
        ->assertSee('unclaimed');
});

it('keeps the concept honest when live counts are unavailable', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::unavailable());
        $mock->shouldReceive('ranks')->andReturn([]);
    });

    $response = $this->get('/design-lab/hallmark')->assertOk();

    $response
        ->assertSee('The lobby is open.')
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertDontSee('members')
        ->assertDontSee('as of');

    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});

it('shows the same moderator-published featured content as the homepage', function () {
    FeaturedContent::factory()->published()->create([
        'title' => 'Community night on Friday',
        'body' => 'Bring a game and a friend.',
    ]);

    $this->get('/design-lab/hallmark')
        ->assertOk()
        ->assertSee('Community night on Friday')
        ->assertSee('Bring a game and a friend.')
        ->assertSee('data-testid="featured-content"', escape: false);
});

it('keeps design-lab concepts out of the public sitemap', function () {
    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertDontSee('/design-lab/hallmark');
});
