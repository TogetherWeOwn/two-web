<?php

use App\Support\Counts\CountsFreshness;
use App\Support\Counts\CountsSource;
use App\Support\Counts\LiveCounts;
use App\Support\Counts\Rank;
use Illuminate\Support\Carbon;

// The counts contract, from the website's side.
//
// The rule under test is one sentence — **a zero is never a stand-in for "we do
// not know"** — and it is worth this many tests because it is the one thing on
// this page that can be wrong in a way that costs us members. two-design
// `docs/CONTENT.md` §4: "a counter that can render 0 without its designed
// sentence" is on the hard never-show list, and two-bot
// `docs/WEBSITE_CONTRACT.md` §3 calls a rendered `0` the single most damaging
// thing the page can display.
//
// These drive `LiveCounts` directly rather than through the database, because
// what is being pinned is the decision — given this row, do we print a number —
// and that decision has to hold whatever the connection did. The reader's own
// failure path is covered in LandingPageDegradedTest.

it('publishes a fresh count plainly', function () {
    $counts = LiveCounts::fromRow(
        memberCount: 54,
        onlineCount: null,
        countsUpdatedAt: Carbon::parse('2026-09-03T12:00:00Z'),
        now: Carbon::parse('2026-09-03T12:04:00Z'),
    );

    expect($counts->hasMemberCount())->toBeTrue()
        ->and($counts->memberCount)->toBe(54)
        ->and($counts->freshness)->toBe(CountsFreshness::Fresh)
        ->and($counts->isStale())->toBeFalse();
});

it('marks a count older than ten minutes as stale so the page can date it', function () {
    // The ladder in WEBSITE_CONTRACT §5: under 10 minutes renders plainly,
    // 10 minutes to the view's null ceiling renders with "as of HH:MM".
    $counts = LiveCounts::fromRow(
        memberCount: 54,
        onlineCount: null,
        countsUpdatedAt: Carbon::parse('2026-09-03T12:00:00Z'),
        now: Carbon::parse('2026-09-03T12:11:00Z'),
    );

    expect($counts->hasMemberCount())->toBeTrue()
        ->and($counts->freshness)->toBe(CountsFreshness::Stale)
        ->and($counts->isStale())->toBeTrue();
});

it('treats the boundary minute as still fresh', function () {
    // Exactly 10 minutes is the edge the ladder is written on. Pinned so a
    // later `<=`/`<` change is a failing test rather than a page that starts
    // dating every number it prints.
    $counts = LiveCounts::fromRow(
        memberCount: 54,
        onlineCount: null,
        countsUpdatedAt: Carbon::parse('2026-09-03T12:00:00Z'),
        now: Carbon::parse('2026-09-03T12:09:59Z'),
    );

    expect($counts->freshness)->toBe(CountsFreshness::Fresh);
});

it('never publishes a member count the bot could not read', function () {
    // Null is the bot saying "we do not know", and it is the expected answer
    // whenever the collector has been dark for 24 hours. The page omits the
    // number; it does not substitute anything for it.
    $counts = LiveCounts::fromRow(
        memberCount: null,
        onlineCount: null,
        countsUpdatedAt: Carbon::parse('2026-09-03T12:00:00Z'),
    );

    expect($counts->hasMemberCount())->toBeFalse()
        ->and($counts->memberCount)->toBeNull()
        ->and($counts->freshness)->toBe(CountsFreshness::Unavailable);
});

it('keeps the timestamp on the degraded path so the page can say when we last knew', function () {
    // The view returns the timestamp even when the value beside it has aged
    // out, precisely so a degraded render is not silent about it.
    $counts = LiveCounts::fromRow(
        memberCount: null,
        onlineCount: null,
        countsUpdatedAt: Carbon::parse('2026-09-01T09:00:00Z'),
    );

    expect($counts->countsUpdatedAt)->not->toBeNull()
        ->and($counts->countsUpdatedAt->format('H:i'))->toBe('09:00');
});

it('refuses to publish a count that arrived without a read time', function () {
    // An undated count cannot be aged, so publishing it means publishing it as
    // fresh forever. The bot's schema makes the row impossible to write
    // (guild_counters_members_dated); if one arrives anyway we decline it
    // rather than trust it.
    $counts = LiveCounts::fromRow(memberCount: 54, onlineCount: 12, countsUpdatedAt: null);

    expect($counts->hasMemberCount())->toBeFalse()
        ->and($counts->freshness)->toBe(CountsFreshness::Unavailable);
});

it('publishes a real zero when the server genuinely emptied out', function () {
    // The distinction the whole contract is built on: 0 means the server has
    // no humans in it, null means our plumbing hiccuped. A 0 that reached us
    // through the view is a fact, and it renders — with the count block's own
    // framing around it, which is what the never-show rule actually requires.
    $counts = LiveCounts::fromRow(
        memberCount: 0,
        onlineCount: null,
        countsUpdatedAt: Carbon::parse('2026-09-03T12:00:00Z'),
        now: Carbon::parse('2026-09-03T12:01:00Z'),
    );

    expect($counts->hasMemberCount())->toBeTrue()
        ->and($counts->memberCount)->toBe(0);
});

it('hides the presence dot when nobody is online, and when presence is unknown', function () {
    // online_count is null in v1 — the bot does not request the presence intent
    // (WEBSITE_CONTRACT §6.1). Zero online is true but reads as "dead" on a page
    // whose job is to read as "small", so neither case shows the dot.
    $base = ['memberCount' => 54, 'countsUpdatedAt' => Carbon::now()];

    expect(LiveCounts::fromRow(...[...$base, 'onlineCount' => null])->hasOnlineCount())->toBeFalse()
        ->and(LiveCounts::fromRow(...[...$base, 'onlineCount' => 0])->hasOnlineCount())->toBeFalse()
        ->and(LiveCounts::fromRow(...[...$base, 'onlineCount' => 12])->hasOnlineCount())->toBeTrue();
});

it('distinguishes a rung nobody holds from a rung we could not read', function () {
    // Both render without a numeral, for different reasons, and the page says
    // something different about each: an unclaimed rung is a fact worth stating,
    // an unreadable one is ours to keep quiet about.
    $unclaimed = new Rank(key: 'legend', label: 'Legend', memberCount: 0);
    $unknown = new Rank(key: 'legend', label: 'Legend', memberCount: null);
    $held = new Rank(key: 'prospect', label: 'Prospect', memberCount: 31);

    expect($unclaimed->isUnclaimed())->toBeTrue()
        ->and($unknown->isUnclaimed())->toBeFalse()
        ->and($unknown->hasCount())->toBeFalse()
        ->and($held->isUnclaimed())->toBeFalse()
        ->and($held->hasCount())->toBeTrue();
});

// ---------------------------------------------------------------------------
// The rendered page
// ---------------------------------------------------------------------------

it('says a rung is unclaimed rather than printing a bare zero on the ladder', function () {
    // CONTENT.md §4: no counter renders `0` without a designed sentence. A
    // column of numbers ending in `0` reads as a dead ladder, which is the exact
    // impression this page exists to avoid.
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::unavailable());
        $mock->shouldReceive('ranks')->andReturn([
            new Rank(key: 'prospect', label: 'Prospect', memberCount: 31),
            new Rank(key: 'legend', label: 'Legend', memberCount: 0),
        ]);
    });

    $response = $this->get('/')->assertOk();

    $response->assertSee('31')
        ->assertSee('Legend')
        ->assertSee('unclaimed');

    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});

it('renders the number and omits nothing else when counts are fresh', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::fromRow(
            memberCount: 54,
            onlineCount: null,
            countsUpdatedAt: Carbon::now(),
        ));
        $mock->shouldReceive('ranks')->andReturn([]);
    });

    $this->get('/')
        ->assertOk()
        ->assertSee('54')
        ->assertSee('members')
        ->assertDontSee('as of');
});

it('dates a stale number instead of hiding it', function () {
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::fromRow(
            memberCount: 54,
            onlineCount: null,
            countsUpdatedAt: Carbon::now()->subMinutes(30),
        ));
        $mock->shouldReceive('ranks')->andReturn([]);
    });

    $this->get('/')
        ->assertOk()
        ->assertSee('54')
        ->assertSee('as of');
});

it('renders the page with no number at all when the counts are unavailable', function () {
    // The designed degraded state: the headline and the join button carry the
    // section, and the counter block is *replaced*, not zeroed.
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::unavailable());
        $mock->shouldReceive('ranks')->andReturn([]);
    });

    $response = $this->get('/')->assertOk();

    // The things that must survive a dark collector.
    $response->assertSee('The lobby is open.')
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertDontSee('members')
        ->assertDontSee('as of');

    // And the thing that must never appear. Checked against the rendered body
    // rather than a helper, because this is the assertion the whole contract
    // exists to make: no bare zero anywhere a count would have been.
    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});
