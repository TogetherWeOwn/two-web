<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Support\Counts\CountsSource;
use App\Support\Counts\LiveCounts;
use App\Support\Events\UpcomingEventCount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

// Social proof on /join (TOG-7320): member + upcoming-event counts with a
// degraded fallback. Same rule as the landing page — a zero is never a
// stand-in for "we do not know" — so unknown omits the line, never the page.

/** Point the `bot` connection at something that cannot answer (join copy). */
function breakBotConnectionForJoin(): void
{
    Config::set('database.connections.bot', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'options' => [PDO::ATTR_TIMEOUT => 2],
    ]);

    DB::purge('bot');
}

function freshMemberCounts(int $members): LiveCounts
{
    return LiveCounts::fromRow(
        memberCount: $members,
        onlineCount: null,
        countsUpdatedAt: Carbon::now(),
    );
}

it('renders member and upcoming counts live', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(freshMemberCounts(123));
        $mock->shouldReceive('ranks')->andReturn([]);
    });
    Event::factory()->count(2)->create(['status' => EventStatus::Published]);

    $response = $this->get(route('join'))->assertOk();

    $response->assertSeeHtml('data-testid="join-proof"')
        ->assertSee('123')
        ->assertSee('members')
        ->assertSee('2')
        ->assertSee('upcoming events')
        ->assertSee(route('events.index'), escape: false);
});

it('uses singular copy for one member and one event', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(freshMemberCounts(1));
        $mock->shouldReceive('ranks')->andReturn([]);
    });
    Event::factory()->create(['status' => EventStatus::Published]);

    $copyMember = __('join.member_singular');
    $copyEvent = __('join.event_singular');
    expect($copyMember)->not->toBe('join.member_singular')
        ->and($copyEvent)->not->toBe('join.event_singular');

    $this->get(route('join'))->assertOk()
        ->assertSeeHtml('data-testid="join-proof"')
        ->assertSee('1')
        ->assertSee($copyMember, escape: false)
        ->assertSee($copyEvent, escape: false);
});

it('says no upcoming events instead of printing a bare zero', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(freshMemberCounts(54));
        $mock->shouldReceive('ranks')->andReturn([]);
    });

    $copy = __('join.event_none');
    expect($copy)->not->toBe('join.event_none');

    $response = $this->get(route('join'))->assertOk();

    $response->assertSeeHtml('data-testid="join-proof"')
        ->assertSee('54')
        ->assertSee($copy, escape: false);

    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});

it('omits the proof block when both counts are unknown', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::unavailable());
        $mock->shouldReceive('ranks')->andReturn([]);
    });
    // No events table, no count: UpcomingEventCount degrades to null through
    // the missing table (own-DB-down), the same null the view omits. CASCADE
    // because rsvps hold a foreign key into events; RefreshDatabase rolls the
    // drop back after this test.
    DB::statement('DROP TABLE events CASCADE');

    $response = $this->get(route('join'))->assertOk();

    // Pitch and both buttons survive; no number, no error, no bare zero.
    $response->assertSee(__('join.intro'), escape: false)
        ->assertSeeHtml('data-testid="one-click-join"')
        ->assertSeeHtml('data-testid="invite-link"')
        ->assertDontSee('data-testid="join-proof"', escape: false)
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('could not connect');

    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});

it('serves the join page when the bot database refuses the connection', function () {
    breakBotConnectionForJoin();

    $response = $this->get(route('join'))->assertOk();

    $response->assertSee(__('join.intro'), escape: false)
        ->assertSeeHtml('data-testid="one-click-join"')
        ->assertSeeHtml('data-testid="invite-link"')
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('could not connect');
});

it('counts only published upcoming events', function () {
    Event::factory()->create(['status' => EventStatus::Published]);
    Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHours(2),
    ]);
    // Drafts are not announced yet; ended, cancelled and past rows are over.
    Event::factory()->draft()->create();
    Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHour(),
    ]);
    Event::factory()->create([
        'status' => EventStatus::Cancelled,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHours(2),
    ]);
    Event::factory()->create([
        'status' => EventStatus::Past,
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
    ]);

    expect((new UpcomingEventCount)->count())->toBe(2);
});

it('returns null instead of throwing when the events table is gone', function () {
    DB::statement('DROP TABLE events CASCADE');

    expect((new UpcomingEventCount)->count())->toBeNull();
});
