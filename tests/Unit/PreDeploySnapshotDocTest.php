<?php

// The pre-deploy DB snapshot lives in Coolify, not in the deploy workflow
// (TOG-9253). Migrations run on the box inside Coolify's post_deployment_command
// (`php artisan migrate --force`); GitHub Actions never SSHes in, the staging
// database is not public, and bin/pg-backup.sh refuses any host that is not
// local docker — so no deploy.yml step could take the snapshot. A workflow step
// claiming to would be TOG-913 theater: green without doing anything. The
// honest half of the card is therefore a Coolify scheduled backup plus a
// Backup-Now-before-migrations rule, documented in docs/ci.md.
//
// GitHub Actions never touches the box, so there is no in-repo code path to
// pin — what can rot is the runbook: somebody edits docs/ci.md and the
// schedule, the Backup Now rule, or the bound silently goes missing while
// deploys keep looking green. So this pins the runbook lines the same way
// QueueDrainOnDeployTest pins the queue:restart wiring.

$docs = 'docs/ci.md';
$workflow = '.github/workflows/deploy.yml';

it('documents the scheduled snapshot on the staging database', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The Coolify database resource and the daily rule that matches the
    // bin/pg-backup.sh retention (TOG-8418). A different database name here is
    // a different snapshot target and must be reviewed as one.
    expect($source)->toContain('two-web-staging-db');
    expect($source)->toContain('Backup Now');
});

it('documents why deploy.yml carries no snapshot step', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The reason must survive, not just the rule: without it, the next reader
    // sees "no snapshot in deploy.yml" as a gap and re-cards this work.
    expect($source)->toContain('TOG-913 theater');
    expect($source)->toContain('never SSHes');
});

it('keeps deploy.yml free of a snapshot step', function () use ($workflow) {
    $source = file_get_contents(base_path($workflow));

    // Any of these in the workflow is a step claiming to do what only the box
    // can do — the TOG-913 defect in a smaller box. The snapshot lives in the
    // Coolify database backup schedule, documented in docs/ci.md.
    expect($source)->not->toContain('pg-backup');
    expect($source)->not->toContain('pg_dump');
    expect($source)->not->toContain('Backup Now');
});

it('documents the bound: the schedule is cron-based, not per-deploy', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // A deploy nobody flagged as migration-carrying has only the last daily
    // snapshot to fall back on. If this sentence goes missing, a reader can
    // believe every deploy is snapshotted and skip the Backup Now.
    expect($source)->toContain('not per-deploy');
});
