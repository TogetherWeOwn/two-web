<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use Illuminate\Http\Request;

// CSP violation reports are log lines, not rows (TOG-8403): POST /csp-reports
// logs a sampled `csp.report.violation` row and stores nothing, so the runbook
// owns the only find path — the grep, the staging trigger, and the bounds.
// What can rot is the runbook: somebody edits docs/runbook.md and the query
// string, the trigger, or a bound silently goes missing while the sink keeps
// logging green. So this pins the runbook lines the same way
// PreDeploySnapshotDocTest pins the snapshot schedule.

$docs = 'docs/runbook.md';

it('names the exact log queries and their channel prerequisites', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    expect($source)->toContain("grep 'csp.report.violation' /var/www/two-web/storage/logs/laravel.log | tail -30");
    expect($source)->toContain('`LOG_CHANNEL=single`, or `LOG_CHANNEL=stack` with `LOG_STACK=single`');
    expect($source)->toContain('`LOG_CHANNEL=daily`, or `LOG_CHANNEL=stack` with `LOG_STACK=daily`');
    expect($source)->toContain("grep 'csp.report.violation' /var/www/two-web/storage/logs/laravel-????-??-??.log | tail -30");
});

it('pins a trigger forbidden by the shipped report-only policy', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // Pin executable text, not just the toggle: removing the old inline
    // example passed this test, and unsafe-inline allowed it anyway.
    expect($source)->toContain("fetch('https://csp-probe.invalid/csp-probe', {mode: 'no-cors', credentials: 'omit', referrerPolicy: 'no-referrer'}).catch(() => {});");
    expect($source)->toContain('"blocked_uri":"https://csp-probe.invalid","violated_directive":"connect-src"');
    expect($source)->toContain('An inline script is NOT');

    config()->set('csp.report_only', true);
    $response = (new AddContentSecurityPolicy)->handle(
        Request::create('https://togetherweown.test'),
        fn () => response('<html></html>', 200, ['Content-Type' => 'text/html']),
    );
    $policy = $response->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)->toContain("connect-src 'self';");
    expect($policy)->not->toContain('csp-probe.invalid');
    expect($policy)->toContain('report-uri /csp-reports');
});

it('requires cached configuration rebuilds and header checks in both directions', function () use ($docs) {
    $source = file_get_contents(base_path($docs));
    $enable = strpos($source, '1. Set `CSP_REPORT_ONLY=true`');
    $restore = strpos($source, '4. Flip the flag back to `CSP_REPORT_ONLY=false`');

    expect($enable)->not->toBeFalse();
    expect($restore)->not->toBeFalse();
    $enableSteps = substr($source, $enable, $restore - $enable);
    $restoreSteps = substr($source, $restore, strpos($source, '**The bounds, stated plainly.**', $restore) - $restore);

    // Scope each pin to its direction so a lone cache command cannot cover
    // both enabling reports and restoring enforcement.
    expect($enableSteps)->toContain('redeploying the staging target');
    expect($enableSteps)->toContain('`php artisan config:cache`');
    expect($enableSteps)->toContain('actual response headers');
    expect($enableSteps)->toContain('require `Content-Security-Policy-Report-Only`');
    expect($enableSteps)->toContain('no enforcing');
    expect($enableSteps)->toContain('`Content-Security-Policy` header');
    expect($restoreSteps)->toContain('Redeploy the staging');
    expect($restoreSteps)->toContain('`php artisan config:cache` again');
    expect($restoreSteps)->toContain('actual response headers: `Content-Security-Policy` present');
    expect($restoreSteps)->toContain('`Content-Security-Policy-Report-Only` absent');
    expect($restoreSteps)->toContain('restore any temporary sampling/logging settings');
});

it('requires warning-level logging and full sampling for the drill', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    expect($source)->toContain('reports and `csp.report.dropped_oversize` use `Log::warning`');
    expect($source)->toContain('`LOG_LEVEL=warning`, `notice`, `info` or `debug`');
    expect($source)->toContain('`LOG_LEVEL=error` (also `critical`, `alert`, `emergency`) hides both rows');
    expect($source)->toContain('`CSP_REPORT_SAMPLE_RATE=1.0`');
});

it('documents the bounds: sampling blindness, drops, and retention', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // Three ways the grep finds nothing that is not "no violations": a zero
    // sample rate (parsed but never logged), oversize bodies (logged under
    // their own key), and log rotation (which owns how far back the grep
    // reaches — TOG-8728, backlog, explicitly not this card).
    expect($source)->toContain('csp.report.dropped_oversize');
    expect($source)->toContain('CSP_REPORT_SAMPLE_RATE=0.0');
    expect($source)->toContain('TOG-8728');
});

it('states plainly that the log line is the store', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The honest bound: no dashboard, no table. If this sentence goes missing,
    // a reader can believe a query UI exists that nobody built.
    expect($source)->toContain('no dashboard');
});
