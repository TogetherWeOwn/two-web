<?php

// Deployment records grow by one per merge and nothing deleted them
// (TOG-9273). The Shipping KPI reads the GitHub Deployment records that the
// `environment:` keys in deploy.yml create, so the fix is retention —
// newest 30 per environment, weekly — not silence. GitHub Actions is the only
// place the prune runs, because the credential (GITHUB_TOKEN with
// `deployments: write`) is minted per run; there is no in-repo code path
// beyond the script, so what can rot is the runbook plus the schedule: the
// prune stops running, the keep rule drifts, or the bound goes missing while
// deploys keep looking green. So this pins the runbook lines and the
// schedule's shape the same way PreDeploySnapshotDocTest pins the snapshot
// wiring.
//
// The behavior itself — newest kept, oldest deleted first, active records
// retired, stuck ids named, errors never read as empty — is pinned by
// ci/prune-deployments-selftest.sh against a stub gh, offline in `static`.

$docs = 'docs/ci.md';
$workflow = '.github/workflows/deploy-records-prune.yml';
$script = 'ci/prune-deployments.sh';

it('documents the retention rule: newest 30 per environment', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The keep count and the unit it applies to. A global keep-30 would let
    // staging churn evict the whole production history the KPI reads, so the
    // per-environment half is load-bearing, not decoration.
    expect($source)->toContain('newest 30 per environment');
    expect($source)->toContain('TOG-9273');
});

it('names the schedule that runs the prune, and that it applies', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // A script with no schedule is a promise, and a scheduled dry run is
    // TOG-913 theater. Both halves must survive: the workflow file and the
    // --apply that makes it real.
    expect($source)->toContain('deploy-records-prune.yml');
    expect($source)->toContain('--apply');
});

it('documents why the credential lives in Actions and not on the box', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // Without the reason, the next reader sees "no artisan command" as a gap
    // and re-cards a box-side pruner with a stored token — credential
    // distribution for a janitor job, which is owner-reserved. `minted per`
    // rather than `minted per run`: the sentence wraps in markdown.
    expect($source)->toContain('deployments: write');
    expect($source)->toContain('GITHUB_TOKEN');
    expect($source)->toContain('minted per');
});

it('documents the bound: only inactive records delete', function () use ($docs) {
    $source = file_get_contents(base_path($docs));

    // The API rule that shapes the retire path. If this sentence goes
    // missing, a reader can believe the prune is a plain delete loop and
    // "simplify" the retire step into a 422 on every active record.
    expect($source)->toContain('inactive');
    expect($source)->toContain('422');
});

it('keeps the scheduled workflow applying the prune, not rehearsing it', function () use ($workflow) {
    $source = file_get_contents(base_path($workflow));

    // The workflow must run the script with --apply on a schedule. A
    // scheduled dry run is green without doing anything — the TOG-913 defect
    // in a smaller box. Pinned on the exact invocation, not just the script
    // name, so dropping the flag fails here instead of rotting silently.
    expect($source)->toContain('schedule:');
    expect($source)->toContain('./ci/prune-deployments.sh --keep 30 --env staging,production --apply');
});

it('never schedules the prune on pull requests', function () use ($workflow) {
    $source = file_get_contents(base_path($workflow));

    // ci/verify-pipeline.sh compares REQUIRED_CHECKS against the check-run
    // names every pull-request workflow reports. A new PR-triggered job id is
    // not a required check, which only warns there — but a job this workflow
    // does not run on any PR would, if ever required by name, block every PR
    // forever as an absent check. Schedule plus dispatch only. Anchored on a
    // YAML trigger entry, not the substring: the header documents the rule in
    // prose, which itself contains the words.
    expect($source)->not->toMatch('/^\s+pull_request:/m');
});

it('scopes the workflow token to deployments write and nothing else', function () use ($workflow) {
    $source = file_get_contents(base_path($workflow));

    // The job lists, retires, and deletes deployment records. Any scope
    // beyond deployments is privilege the janitor does not need; any scope
    // below it cannot retire. Pinned exactly so widening it is a reviewable
    // edit.
    expect($source)->toContain('deployments: write');
    expect($source)->not->toContain('contents: write');
});

it('keeps the pruner dry-run by default', function () use ($script) {
    $source = file_get_contents(base_path($script));

    // The default must stay safe to run by hand: without --apply nothing is
    // deleted. If the default ever flips to apply, a curious run deletes
    // records the KPI reads.
    expect($source)->toContain('APPLY=0');
    expect($source)->toContain('dry run: nothing was deleted');
});
