<?php

// Every staging deploy must restart the queue worker without killing running
// jobs (TOG-7288). The lever is a Coolify post-deployment command on the web
// app — `php artisan queue:restart` — which broadcasts a timestamp through the
// default cache; the worker daemon compares it after each job and exits 0,
// and Coolify respawns it on the new release. GitHub Actions never touches the
// box, so there is no in-repo code path to pin — what can rot is the runbook:
// somebody edits docs/ci.md and the command, its placement, or the bound it
// documents silently goes missing while deploys keep looking green. So this
// pins the runbook lines the same way TrustedProxiesPinnedTest pins the
// topology assumption.

$docs = 'docs/ci.md';

it('documents the post-deployment worker restart command', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The exact command the Operator sets as the Coolify post-deployment
    // command on two-web-staging. A different command here is a different
    // mechanism and must be reviewed as one.
    expect($source)->toContain('php artisan queue:restart');
});

it('places the restart after the new release, on the staging worker', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // Post-deployment, not pre-: the signal must fire after the new release
    // answers, so respawned workers boot new code, not the release being
    // replaced. And it names both Coolify apps, so a future edit cannot
    // silently move the lever to the wrong one.
    expect($source)->toContain('post-deployment');
    expect($source)->toContain('two-web-staging-worker');
});

it('documents the bound: the signal restarts processes, it does not ship code', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // TOG-7288 acceptance is "restarts workers without killing running jobs
    // (or documents the bound)". The bound is that queue:restart recycles the
    // process; code freshness depends on the worker app itself redeploying.
    // If this sentence goes missing, a reader can believe the signal alone
    // keeps the worker on the latest release.
    expect($source)->toContain('does not ship code');
});
