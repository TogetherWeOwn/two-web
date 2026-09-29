<?php

use App\Support\Counts\CountsFreshness;
use App\Support\Counts\CountsReader;
use App\Support\Counts\CountsSource;
use App\Support\Counts\LiveCounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

// What the landing page does when the bot's database is not there.
//
// This is the path that actually runs in production today and it is the one a
// mock cannot prove, because the thing being tested *is* the failure: the page
// must render, with no number and no error, when the connection is refused,
// the schema is missing, or the role cannot read the view.
//
// So these break the connection for real rather than stubbing the reader.
// `CountsReader` catches Throwable deliberately (an unconfigured connection, a
// refused socket, a missing schema and a permission denial are four different
// exception types that mean one thing to a visitor), and a test that stubbed
// one exception type would not notice the day a fifth appeared.

/** Point the `bot` connection at something that cannot answer. */
function breakBotConnection(): void
{
    Config::set('database.connections.bot', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        // Nothing listens here. A refused connection comes back immediately,
        // so this stays a fast test rather than one that waits out a timeout.
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

    // The connection is resolved and cached by the manager, so a config change
    // alone would not reach a connection an earlier test already opened.
    DB::purge('bot');
}

it('serves the landing page when the bot database refuses the connection', function () {
    breakBotConnection();

    $response = $this->get('/')->assertOk();

    // Everything that has to survive the bot being gone: the pitch, the ladder
    // prose, and above all the join button. The funnel does not depend on the
    // counts and this is the test that says so.
    $response->assertSee('The lobby is open.')
        ->assertSee('No application. No interview.')
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee(route('join'), escape: false);
});

it('shows no number and no error when the bot database is unreachable', function () {
    breakBotConnection();

    $response = $this->get('/')->assertOk();

    // No count, no timestamp, and no infrastructure error shown to a visitor.
    $response->assertDontSee('members')
        ->assertDontSee('as of')
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('could not connect');

    // And no bare zero standing in for the number we could not read.
    expect($response->getContent())->not->toMatch('/>\s*0\s*</');
});

it('reports the unavailable state rather than throwing', function () {
    breakBotConnection();

    $counts = app(CountsSource::class);

    expect($counts->liveCounts()->hasMemberCount())->toBeFalse()
        ->and($counts->liveCounts()->freshness)->toBe(CountsFreshness::Unavailable)
        ->and($counts->ranks())->toBe([]);
});

it('logs the failure for us without leaking the connection details', function () {
    breakBotConnection();

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message;
    });

    app(CountsSource::class)->liveCounts();

    expect($logged)->not->toBeEmpty();

    // The class name, never the message. A PDO failure carries the DSN and a
    // QueryException substitutes real bindings into the SQL it prints — see
    // the Laravel QueryException note this codebase already learned once.
    $record = $logged[0];
    expect($record->level)->toBe('warning')
        ->and($record->context['exception'] ?? null)->toBeString()
        ->and($record->message)->not->toContain('SQLSTATE')
        ->and(json_encode($record->context))->not->toContain('nope');
});

// ---------------------------------------------------------------------------
// The short-TTL cache pin (TOG-8416)
// ---------------------------------------------------------------------------
//
// `CountsReader` caches a successful read for 60 seconds. That cache is the
// pin this page hangs on when the bot's database drops mid-day: inside the
// TTL the page serves the number it already knows instead of re-reading, and
// only past the TTL does it fall back to the degraded pitch above.
//
// These seed the cache directly (the key mirrors the reader's private
// `MEMBER_COUNT_KEY`) rather than standing up a bot database, because what is
// being pinned is the TTL boundary — serve stale inside it, degrade past
// it — not the read itself.

it('serves the cached count when the bot database drops inside the TTL', function () {
    Cache::put('counts.live', LiveCounts::fromRow(
        memberCount: 84,
        onlineCount: null,
        countsUpdatedAt: Carbon::now(),
    ), 60);

    breakBotConnection();

    $response = $this->get('/')->assertOk();

    // The numeral, in its element (mirroring the no-bare-zero assertion's
    // shape so a stray "84" elsewhere in the copy cannot satisfy this).
    $response->assertSee('members');
    expect($response->getContent())->toMatch('/>\s*84\s*</');
});

it('renders the degraded pitch once the cached count is past its TTL', function () {
    Cache::put('counts.live', LiveCounts::fromRow(
        memberCount: 84,
        onlineCount: null,
        countsUpdatedAt: Carbon::now(),
    ), 60);

    // 61 seconds: one past the reader's 60-second TTL, so the pinned entry is
    // expired and the page must re-read — against a database that is gone.
    $this->travel(61)->seconds();

    breakBotConnection();

    $response = $this->get('/')->assertOk();

    $response->assertSee('The lobby is open.')
        ->assertDontSee('members');

    expect($response->getContent())->not->toMatch('/>\s*84\s*</');
});

it('is bound to the real reader by default', function () {
    // The degraded tests above would all pass against a null implementation, so
    // this pins that what production resolves is the thing that reads web_v1.
    expect(app(CountsSource::class))->toBeInstanceOf(CountsReader::class);
});
