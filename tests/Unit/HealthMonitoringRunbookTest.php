<?php

// /up exists and CI polls it, but no standing watch polls it on any deployed
// host (TOG-7327). The runbook owns that truth: who polls /up at deploy and CI
// time, the fact that continuous monitoring is nobody's job yet, and the bound
// that this section must never be read as a standing watch. What can rot is
// the runbook: somebody edits docs/ci.md and a poller, the gap, or the bound
// silently goes missing while deploys keep looking green. So this pins the
// runbook lines the same way QueueDrainOnDeployTest pins the worker drain.

$docs = 'docs/ci.md';

it('documents who polls /up at deploy and CI time', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The deploy-time poller (deploy.yml waits on ${STAGING}/up) and the
    // post-deploy smoke script, plus the CI-time pollers (Dusk, budgets,
    // opcache readiness probes against throwaway localhost servers).
    // Pinned on section-unique strings: 'deploy.yml' and 'smoke-staging'
    // alone already appear elsewhere in the file, so either would pass with
    // this section deleted.
    expect($source)->toContain('${STAGING}/up');
    expect($source)->toContain('the new release answered, and nothing more');
});

it('documents the gap: nobody polls /up continuously', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // No cron, no scheduled workflow, no third-party pinger — and the manual
    // watch that substitutes for one (the release checklist's "someone is
    // available to watch it"). Pinned on the section's own heading: 'nobody'
    // alone already appears elsewhere in the file.
    expect($source)->toContain('Continuously, on any environment');
    expect($source)->toContain('someone is');
});

it('documents the bound: the runbook is not a standing watch', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // Every /up poll in the repo is attached to a deploy or a CI run. If this
    // sentence goes missing, a reader can believe the section promises a
    // monitor that does not exist.
    expect($source)->toContain('There is no standing watch');
});
