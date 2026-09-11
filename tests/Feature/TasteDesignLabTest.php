<?php

use App\Models\FeaturedContent;
use App\Support\Counts\CountsSource;
use App\Support\Counts\LiveCounts;
use App\Support\Counts\Rank;
use Illuminate\Support\Carbon;

it('serves the Taste concept without replacing the production homepage', function () {
    $this->get('/design-lab/taste')
        ->assertOk()
        ->assertSee('Arrive for a game.')
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee(route('join'), escape: false);

    $this->get('/')
        ->assertOk()
        ->assertSee('The lobby is open.')
        ->assertDontSee('Arrive for a game.');
});

it('renders real counts ranks and moderator content in the Taste concept', function () {
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

    FeaturedContent::factory()->published()->create([
        'title' => 'Community night on Friday',
        'body' => 'Bring a game and a friend.',
    ]);

    $response = $this->get('/design-lab/taste')->assertOk();

    $response
        ->assertSee('54')
        ->assertSee('12 online')
        ->assertSee('Prospect')
        ->assertSee('31')
        ->assertSee('Legend')
        ->assertSee('unclaimed')
        ->assertSee('Community night on Friday')
        ->assertSee('Bring a game and a friend.');

    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});

it('keeps the Taste invitation usable when live data is unavailable', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::unavailable());
        $mock->shouldReceive('ranks')->andReturn([]);
    });

    $response = $this->get('/design-lab/taste')->assertOk();

    $response
        ->assertSee('The doors stay open when the counter goes quiet.')
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertDontSee('members');

    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});

it('keeps the Taste route out of the public sitemap', function () {
    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertDontSee('/design-lab/taste');
});
