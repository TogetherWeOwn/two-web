#!/usr/bin/env node

import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = resolve(fileURLToPath(new URL('..', import.meta.url)));
const SCRIPT = resolve(REPO_ROOT, 'ci/suite-health.mjs');
const MANIFEST = resolve(REPO_ROOT, 'ci/critical-journeys.json');
const EXAMPLE = resolve(REPO_ROOT, 'ci/suite-health-input.example.json');
const work = await mkdtemp(join(tmpdir(), 'suite-health-selftest.'));
let failures = 0;
let cases = 0;

function run(args) {
    return spawnSync(process.execPath, [SCRIPT, ...args], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
    });
}

function pass(name) {
    console.log(`PASS  ${name}`);
}

function fail(name, details) {
    failures += 1;
    console.error(`FAIL  ${name}: ${details}`);
}

function expect(name, condition, details) {
    cases += 1;
    if (condition) {
        pass(name);
    } else {
        fail(name, details);
    }
}

const validation = run(['--validate-manifest']);
expect('manifest maps six real Dusk tests', validation.status === 0, validation.stderr || validation.stdout);

const baseline = run(['--input', EXAMPLE]);
expect('PR 259 runtime is measured as 4m 50s', baseline.status === 0 && baseline.stdout.includes('**4m 50s**'), baseline.stderr || baseline.stdout);
expect('all six critical journeys are reported complete', baseline.stdout.includes('Complete critical journeys: **6/6**') && !baseline.stdout.includes('**INCOMPLETE:**'), baseline.stdout);
const baselineJsonResult = run(['--input', EXAMPLE, '--format', 'json']);
const baselineJson = JSON.parse(baselineJsonResult.stdout);
expect('all six required checks are used', baselineJsonResult.status === 0 && JSON.stringify(baselineJson.requiredChecks) === JSON.stringify(['static', 'pest', 'dusk', 'budgets', 'tests', 'gitleaks']), baselineJsonResult.stderr || baselineJsonResult.stdout);

const flaky = JSON.parse(await readFile(EXAMPLE, 'utf8'));
flaky.pulls[0].checkRuns.splice(2, 0, {
    name: 'dusk',
    status: 'completed',
    conclusion: 'failure',
    startedAt: '2026-09-07T05:20:00Z',
    completedAt: '2026-09-07T05:21:00Z',
    failedTests: ['a member can sign out again'],
});
const flakyInput = join(work, 'flaky.json');
await writeFile(flakyInput, JSON.stringify(flaky));
const flakyReport = run(['--input', flakyInput, '--format', 'json']);
const flakyJson = JSON.parse(flakyReport.stdout);
expect('same-commit failure then success counts one distinct flake', flakyReport.status === 0 && flakyJson.summary.distinctFlakyTestCount === 1, flakyReport.stderr || flakyReport.stdout);

const failedOnly = structuredClone(flaky);
failedOnly.pulls[0].checkRuns = failedOnly.pulls[0].checkRuns.filter((run) => !(run.name === 'dusk' && run.conclusion === 'success'));
const failedOnlyInput = join(work, 'failed-only.json');
await writeFile(failedOnlyInput, JSON.stringify(failedOnly));
const failedOnlyReport = run(['--input', failedOnlyInput, '--format', 'json']);
const failedOnlyJson = JSON.parse(failedOnlyReport.stdout);
expect('a failure without a later pass is not called a flake', failedOnlyReport.status === 0 && failedOnlyJson.summary.distinctFlakyTestCount === 0, failedOnlyReport.stderr || failedOnlyReport.stdout);

const missingJourney = JSON.parse(await readFile(MANIFEST, 'utf8'));
missingJourney.journeys[0].tests = [];
const missingJourneyManifest = join(work, 'missing-journey.json');
await writeFile(missingJourneyManifest, JSON.stringify(missingJourney));
const missingJourneyResult = run(['--validate-manifest', '--manifest', missingJourneyManifest]);
expect('an unmapped required journey fails validation', missingJourneyResult.status === 1 && missingJourneyResult.stderr.includes('must map at least one Dusk test'), missingJourneyResult.stderr || missingJourneyResult.stdout);

const falseComplete = JSON.parse(await readFile(MANIFEST, 'utf8'));
const incompleteProfile = falseComplete.journeys.find((journey) => journey.id === 'profile-view-edit').tests[0];
incompleteProfile.complete = false;
delete incompleteProfile.gap;
const falseCompleteManifest = join(work, 'false-complete.json');
await writeFile(falseCompleteManifest, JSON.stringify(falseComplete));
const falseCompleteResult = run(['--validate-manifest', '--manifest', falseCompleteManifest]);
expect('partial coverage needs an explicit gap', falseCompleteResult.status === 1 && falseCompleteResult.stderr.includes('gap is required'), falseCompleteResult.stderr || falseCompleteResult.stdout);

const staleMapping = JSON.parse(await readFile(MANIFEST, 'utf8'));
staleMapping.journeys[0].tests[0].name = 'a test name that is not in the mapped file';
const staleMappingManifest = join(work, 'stale-mapping.json');
await writeFile(staleMappingManifest, JSON.stringify(staleMapping));
const staleMappingResult = run(['--validate-manifest', '--manifest', staleMappingManifest]);
expect('a stale Dusk test mapping fails validation', staleMappingResult.status === 1 && staleMappingResult.stderr.includes('does not declare test'), staleMappingResult.stderr || staleMappingResult.stdout);

const missingCheck = JSON.parse(await readFile(EXAMPLE, 'utf8'));
missingCheck.pulls[0].checkRuns = missingCheck.pulls[0].checkRuns.filter((run) => run.name !== 'gitleaks');
const missingCheckInput = join(work, 'missing-check.json');
await writeFile(missingCheckInput, JSON.stringify(missingCheck));
const missingCheckResult = run(['--input', missingCheckInput, '--format', 'json']);
const missingCheckJson = JSON.parse(missingCheckResult.stdout);
expect('an absent required check makes the final gate incomplete', missingCheckResult.status === 0 && missingCheckJson.pulls[0].finalGreen === false && missingCheckJson.pulls[0].missingChecks.includes('gitleaks'), missingCheckResult.stderr || missingCheckResult.stdout);

if (failures === 0) {
    console.log(`\n${cases}/${cases} suite-health cases passed.`);
} else {
    console.error(`\n${failures}/${cases} suite-health cases failed.`);
}

process.exitCode = failures === 0 ? 0 : 1;
