<?php

use App\Http\Controllers\CspReportController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * CSP report-only mode and the violation sink (TOG-8403).
 *
 * CSP is enforced by AddContentSecurityPolicy, but violations were invisible:
 * no report-only mode, no report endpoint. `CSP_REPORT_ONLY=true` swaps the
 * enforcing header for `Content-Security-Policy-Report-Only` with the same
 * policy plus `report-uri /csp-reports`, and POST /csp-reports logs a sampled
 * subset of reports — always answering 204, session-free, from the funnel's
 * empty middleware stack.
 *
 * What each test owns: report-only asserts the header swap on a guest HTML
 * page with the same policy plus the report directive; enforcement-unchanged
 * asserts the flag-off header is byte-identical to the strict policy pinned in
 * ContentSecurityPolicyTest; the two sink tests assert the logged row for both
 * browser report shapes; malformed asserts the always-204 no-retry contract;
 * oversize asserts the body cap logs under its own key without the violation
 * row; the middleware test asserts the sink stays session-free next to
 * /discord; and sampling asserts 0.0 writes nothing.
 */

// The classic `report-uri` shape — what a blocked inline script produces.
function cspViolationBody(): array
{
    return [
        'csp-report' => [
            'document-uri' => 'http://localhost/',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'inline',
            'source-file' => 'http://localhost/',
            'line-number' => 1,
        ],
    ];
}

// The strict policy, pinned in full like tests/Feature/ContentSecurityPolicyTest.php
// pins it — a widening has to edit both assertions, not slip past one. Keep the
// two in sync: both quote App\Http\Middleware\AddContentSecurityPolicy. A shared
// helper is not used on purpose: Pest only loads the files it runs, so calling
// ContentSecurityPolicyTest's `strictCsp()` would pass in the full suite and
// fatal running this file alone.
function cspReportOnlyPolicy(): string
{
    return "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: https:; "
        ."font-src 'self' data:; "
        .'connect-src \'self\'; '
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."object-src 'none'";
}

it('swaps the enforcing header for report-only with the same policy plus the report directive', function () {
    config()->set('csp.report_only', true);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertHeaderMissing('Content-Security-Policy');

    $header = $response->headers->get('Content-Security-Policy-Report-Only');
    expect($header)->not->toBeNull('report-only mode carries no Content-Security-Policy-Report-Only');

    expect($header)->toBe(cspReportOnlyPolicy().'; report-uri /csp-reports');
});

it('leaves enforcement mode byte-identical with the flag off', function () {
    config()->set('csp.report_only', false);

    $this->get('/')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', cspReportOnlyPolicy())
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

it('logs a blocked inline script report and answers 204', function () {
    Log::spy();

    $this->postJson('/csp-reports', cspViolationBody())
        ->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->with(
        'csp.report.violation',
        [
            'blocked_uri' => 'inline',
            'violated_directive' => 'script-src',
            'document_uri' => 'http://localhost/',
            'source_file' => 'http://localhost/',
            'line_number' => 1,
        ],
    );
});

it('logs the newer Reporting API shape too', function () {
    Log::spy();

    // `report-to` batches `[{body: {...}}]` — the sink takes the first body.
    $this->postJson('/csp-reports', [[
        'body' => [
            'blockedURL' => 'inline',
            'effectiveDirective' => 'script-src-elem',
            'url' => 'http://localhost/',
        ],
    ]])->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->with(
        'csp.report.violation',
        [
            'blocked_uri' => 'inline',
            'violated_directive' => 'script-src-elem',
            'document_uri' => 'http://localhost/',
            'source_file' => null,
            'line_number' => null,
        ],
    );
});

it('answers 204 without logging for malformed bodies', function () {
    Log::spy();

    // Not JSON, valid JSON that is not a report, and an empty body: the
    // browser must see 204 in every case (a 4xx makes it retry, and a report
    // endpoint that retries is a flood amplifier), and nothing is worth a log
    // row when there is no recognisable report.
    $this->call('POST', '/csp-reports', content: 'not-json{{{')->assertNoContent();
    $this->postJson('/csp-reports', ['hello' => 'world'])->assertNoContent();
    $this->call('POST', '/csp-reports', content: '')->assertNoContent();

    Log::shouldNotHaveReceived('warning');
});

it('drops oversize bodies before parsing under their own log key', function () {
    Log::spy();

    $this->call(
        'POST',
        '/csp-reports',
        content: str_repeat('x', CspReportController::MAX_BODY_BYTES + 1),
    )->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->with(
        'csp.report.dropped_oversize',
        ['bytes' => CspReportController::MAX_BODY_BYTES + 1],
    );
});

it('never logs the raw report body, which is attacker-shaped', function () {
    Log::spy();

    // A report carrying an exfil probe in an unlogged field: the fixed key
    // set keeps it out of the log, so the sink cannot be used to plant
    // strings (tokens, phishing URLs) into our logs. Asserted on the exact
    // logged context — the second `with()` argument — so a change that starts
    // passing the raw body through fails here.
    $body = cspViolationBody();
    $body['csp-report']['evil-probe'] = 'exfil-probe-marker-abc123';

    $this->postJson('/csp-reports', $body)->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->with(
        'csp.report.violation',
        // `use Mockery;` is a warning-as-error under Pest's eval'd test
        // namespace, so the FQCN is referenced inline (JoinCallbackFailureTest
        // is a namespaced class test, which is why the import works there).
        Mockery::on(fn ($context) => is_array($context)
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'exfil-probe-marker-abc123')
            && ($context['blocked_uri'] ?? null) === 'inline'),
    );
});

it('carries no middleware on the sink, like /discord', function () {
    // The structural half of the zero-query promise: the browser fires this
    // session-free from any page, and it must keep answering during an app-DB
    // outage — StartSession or throttle would open the database first.
    $route = Route::getRoutes()->getByName('csp-reports');

    expect($route)->not->toBeNull('Route [csp-reports] is missing. It is the report-only sink — it must exist.');
    expect($route->gatherMiddleware())->toBe([], 'Route [csp-reports] has picked up middleware.');
});

it('touches no database at all, even with a database-backed session', function () {
    // Same production-parity shape as the funnel leaves: SESSION_DRIVER and
    // CACHE_STORE are database everywhere shipped, and phpunit.xml hides that
    // with array drivers — so configure the real drivers, then count.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    $this->expectsDatabaseQueryCount(0);

    $this->postJson('/csp-reports', cspViolationBody())->assertNoContent();
});

it('writes nothing when the sample rate is zero', function () {
    Log::spy();
    config()->set('csp.report_sample_rate', 0.0);

    $this->postJson('/csp-reports', cspViolationBody())->assertNoContent();

    Log::shouldNotHaveReceived('warning');
});
