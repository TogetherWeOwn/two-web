<?php

// CSP violation reports are log lines, not rows (TOG-8403): POST /csp-reports
// logs a sampled `csp.report.violation` row and stores nothing, so the runbook
// owns the only find path — the grep, the staging trigger, and the bounds.
// What can rot is the runbook: somebody edits docs/runbook.md and the query
// string, the trigger, or a bound silently goes missing while the sink keeps
// logging green. So this pins the runbook lines the same way
// PreDeploySnapshotDocTest pins the snapshot schedule.

$docs = 'docs/runbook.md';

it('names the exact log grep that finds violation reports', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The documented provider path: the log key in grep form plus the file it
    // lives in. Pinned on the command, not the bare key — `csp.report.violation`
    // alone also appears in the sink's own feature test, so the key by itself
    // would pass with this section deleted.
    expect($source)->toContain("grep 'csp.report.violation'");
    expect($source)->toContain('storage/logs/laravel.log');
});

it('documents how to trigger a violation on staging on purpose', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The observe switch plus the flip-back rule: without the trigger the
    // section is a grep with nothing to find; without the flip-back it blesses
    // an unenforced policy as a steady state.
    expect($source)->toContain('CSP_REPORT_ONLY=true');
    expect($source)->toContain('Flip the flag back');
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
