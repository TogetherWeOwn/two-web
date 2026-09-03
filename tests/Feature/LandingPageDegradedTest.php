<?php

use App\Support\Counts\CountsFreshness;
use App\Support\Counts\CountsReader;
use App\Support\Counts\CountsSource;
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
    $response->assertSee('Since 1998.')
        ->assertSee('You start as a Prospect')
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee(route('discord'), escape: false);
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

it('is bound to the real reader by default', function () {
    // The degraded tests above would all pass against a null implementation, so
    // this pins that what production resolves is the thing that reads web_v1.
    expect(app(CountsSource::class))->toBeInstanceOf(CountsReader::class);
});
