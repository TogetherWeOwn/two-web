<?php

// Log rotation + retention (TOG-8728). Staging and production must rotate the
// Laravel log daily and keep 14 days — the old env doc said `single` (or
// `daily` "if wanted"), which is an unbounded file that fills the disk at
// production volumes. The code default in config/logging.php is the first
// half of the guarantee; the documented staging/prod values in docs/env.md
// and the disk-watch threshold in the runbook are the second half. What can
// rot is the docs: somebody edits docs/env.md or docs/runbook.md and the
// pinned values or the threshold silently goes missing while deploys keep
// looking green. So this pins the doc lines the same way
// QueueDrainOnDeployTest pins the restart command.

$envDocs = 'docs/env.md';
$runbook = 'docs/runbook.md';

it('keeps the 14-day daily retention default in the logging config', function () {
    // LOG_DAILY_DAYS is unset in test env, so this asserts the code default
    // itself — a default change ships only with this test updated, on purpose.
    expect(config('logging.channels.daily.days'))->toBe(14);
});

it('pins daily rotation for staging and production in the env doc', function () use ($envDocs) {
    $source = file_get_contents(base_path($envDocs));

    // Staging/prod run the daily channel; only local dev keeps the unbounded
    // single file. If this sentence goes missing, a reader can ship `single`
    // to production and learn about it from a full disk.
    expect($source)->toContain('**`daily`**');
});

it('pins the explicit 14-day retention value in the env doc', function () use ($envDocs) {
    $source = file_get_contents(base_path($envDocs));

    // Explicit even though it matches the code default, so a future default
    // change cannot silently extend or shrink retention.
    expect($source)->toContain('**`14`**');
});

it('documents the disk-watch threshold in the runbook', function () use ($runbook) {
    $source = file_get_contents(base_path($runbook));

    // Rotation is the guarantee; the threshold is the backstop for volumes
    // rotation cannot absorb. Both numbers must stay on the page.
    expect($source)->toContain('80%');
    expect($source)->toContain('90%');
    expect($source)->toContain('df -h');
});
