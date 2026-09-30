<?php

use App\Support\ErrorAlertRateLimit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * The log-based error alert (TOG-8730).
 *
 * No Sentry, no Flare, no Bugsnag — none installed, none allowed. The
 * contract: an unhandled exception produces one operator-greppable critical
 * log line (`Unhandled exception.` from the `report` listener in
 * bootstrap/app.php), and repeats of the same failure are muted by
 * ErrorAlertRateLimit so a crashing deploy produces one line, not thousands.
 * bin/error-log-watch.sh tails those lines on the box; that half is a
 * runbook step in docs/runbook.md, because no test can see a box's log file.
 *
 * What is asserted here, against the test database only:
 *
 *   - a reported exception logs one `Unhandled exception.` critical line
 *     with the class, route and message;
 *   - a second report of the same fingerprint is muted (the noise guard);
 *   - a different fingerprint still alerts (muting is per-failure, not
 *     global);
 *   - `error-alert:probe` reports the alert-then-muted chain end to end.
 */

function swapTestLog(): TestHandler
{
    $handler = new TestHandler;
    Log::swap(new Illuminate\Log\Logger(new Logger('test', [$handler])));

    return $handler;
}

/** @return array<int, LogRecord> */
function unhandledAlerts(TestHandler $handler): array
{
    return collect($handler->getRecords())
        ->filter(fn ($record) => $record->message === 'Unhandled exception.'
            && $record->level === Level::Critical)
        ->values()
        ->all();
}

it('logs one critical alert for a reported exception, naming the cause', function () {
    $handler = swapTestLog();

    report(new RuntimeException('alert-marker-boom'));

    $alerts = unhandledAlerts($handler);

    expect($alerts)->toHaveCount(1);

    expect($alerts[0]->context['exception'])->toBe(RuntimeException::class)
        ->and($alerts[0]->context['message'])->toContain('alert-marker-boom')
        ->and($alerts[0]->context)->toHaveKey('route');
});

it('mutes a repeat of the same fingerprint but alerts a new one', function () {
    $handler = swapTestLog();

    report(new RuntimeException('same-failure-twice'));
    report(new RuntimeException('same-failure-twice'));
    report(new LogicException('different-failure'));

    $alerts = unhandledAlerts($handler);

    // Two alerts, not three: the repeat muted, the distinct failure through.
    // Console has no current route, so the fingerprint falls back to the
    // request path — identical here, which is exactly the repeated-crash
    // shape the guard exists for.
    expect($alerts)->toHaveCount(2);
});

it('does not alert for exceptions the framework declines to report', function () {
    $handler = swapTestLog();

    report(new NotFoundHttpException);

    expect(unhandledAlerts($handler))->toHaveCount(0);
});

it('names the route in the fingerprint on a web request', function () {
    $handler = swapTestLog();

    config(['app.debug' => false]);
    Route::get('/_test-boom-8730', function (): void {
        throw new RuntimeException('web-boom-8730');
    })->name('test.boom.8730');

    $this->get('/_test-boom-8730')->assertStatus(500);

    $alerts = unhandledAlerts($handler);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->context['route'])->toBe('test.boom.8730');
});

it('alerts while the limiter store is down instead of going silent', function () {
    // A store whose driver throws on every call: the guard must degrade to
    // unmuted, never to silent. See ErrorAlertRateLimit's class docblock.
    // `failover` tries its stores in order and rethrows the last exception,
    // so a single broken store is a store that always throws.
    config(['cache.stores.error-alert-broken' => [
        'driver' => 'failover',
        'stores' => ['error-alert-also-broken'],
    ]]);
    config(['cache.stores.error-alert-also-broken' => ['driver' => 'non-existent-driver']]);
    config(['cache.default' => 'error-alert-broken']);

    // Directly: shouldAlert must not throw, and must say "alert".
    expect(ErrorAlertRateLimit::shouldAlert('broken-store-fingerprint-'.str()->uuid()))->toBeTrue();
});

it('reports the alert-then-muted chain through the probe command', function () {
    $code = Artisan::call('error-alert:probe', ['--json' => true]);

    expect($code)->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload['status'])->toBe('ok')
        ->and($payload['marker'])->not->toBeEmpty();
});
