<?php

// The poison drill runs on an isolated per-drill queue (TOG-10616), so its
// lifecycle bounds live in the runbook, not in code CI can execute against a
// box: retry would restore the poison to a queue no worker listens on, and a
// drill dispatched during maintenance strands a job nothing consumes. The
// command refuses maintenance mode before dispatch and the runbook says
// forget-not-retry — what can rot is docs/ci.md: somebody edits the section
// and the forget guidance or the maintenance bound silently goes missing
// while CI keeps looking green. So this pins the runbook lines the same way
// QueueDrainOnDeployTest pins the queue:restart wiring.

$docs = 'docs/ci.md';

it('cleans the probe row with forget, never retry', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The uuid comes from the probe output; retry would restore the poison to
    // its single-use isolated queue where no worker listens.
    expect($source)->toContain('php artisan queue:forget <uuid>');
    expect($source)->toContain('re-run the probe for a fresh drill');
});

it('warns against retrying the isolated poison', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The old guidance ("or retry it with php artisan queue:retry") must not
    // come back: retry abandons the poison on an unattended queue.
    expect($source)->toContain('Do not `queue:retry`');
    expect($source)->not->toContain('or retry it with');
});

it('documents the maintenance bound: the probe refuses while the app is down', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // Laravel's --once worker returns without consuming while the app is down
    // for maintenance, so the probe refuses before dispatch rather than
    // stranding a job. If this sentence goes missing, a reader can run the
    // drill during a deploy window and leave a poison nothing consumes.
    expect($source)->toContain('down for maintenance');
});
