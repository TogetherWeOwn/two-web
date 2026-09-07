#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

function usage() {
    console.log(`Usage:
  node ci/collect-suite-health.mjs --since <ISO date> --until <ISO date> [--repo OWNER/REPO] [--failures <json>] [--output <json>]

Collects merged pull requests in the period, then fetches every check-run attempt for
each unchanged head SHA. The optional failures file maps failed Dusk check-run ids to
the test names extracted from that run's log or dusk-failures artifact:

  { "101234567": ["a member can sign out again"] }

A failed-then-passed Dusk commit without that evidence is rejected rather than reported
as zero flakes.`);
}

function parseArgs(argv) {
    const options = { repo: 'TogetherWeOwn/two-web' };
    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];
        if (argument === '--help') {
            options.help = true;
        } else if (['--since', '--until', '--repo', '--failures', '--output'].includes(argument)) {
            const value = argv[index + 1];
            if (!value || value.startsWith('--')) {
                throw new Error(`${argument} needs a value`);
            }
            options[argument.slice(2)] = value;
            index += 1;
        } else {
            throw new Error(`unknown argument: ${argument}`);
        }
    }
    if (!options.help && (!options.since || !options.until)) {
        throw new Error('--since and --until are required');
    }
    return options;
}

function gh(path, fields = {}) {
    const args = ['api', '-X', 'GET', path];
    for (const [key, value] of Object.entries(fields)) {
        args.push('-f', `${key}=${value}`);
    }
    return JSON.parse(execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 50 * 1024 * 1024 }));
}

function inPeriod(timestamp, since, until) {
    if (!timestamp) {
        return false;
    }
    const value = Date.parse(timestamp);
    return value >= since && value <= until;
}

async function main() {
    const options = parseArgs(process.argv.slice(2));
    if (options.help) {
        usage();
        return;
    }

    const since = Date.parse(options.since);
    const until = Date.parse(options.until);
    if (!Number.isFinite(since) || !Number.isFinite(until) || since > until) {
        throw new Error('--since and --until must be ordered ISO dates');
    }

    const failureEvidence = options.failures
        ? JSON.parse(await readFile(resolve(options.failures), 'utf8'))
        : {};
    const pulls = [];

    for (let page = 1; ; page += 1) {
        const batch = gh(`repos/${options.repo}/pulls`, {
            state: 'closed',
            sort: 'updated',
            direction: 'desc',
            per_page: 100,
            page,
        });
        if (batch.length === 0) {
            break;
        }

        let reachedBeforePeriod = false;
        for (const pull of batch) {
            if (pull.merged_at && Date.parse(pull.merged_at) < since) {
                reachedBeforePeriod = true;
            }
            if (!inPeriod(pull.merged_at, since, until)) {
                continue;
            }

            const response = gh(`repos/${options.repo}/commits/${pull.head.sha}/check-runs`, {
                per_page: 100,
                filter: 'all',
            });
            const checkRuns = response.check_runs.map((run) => ({
                id: run.id,
                name: run.name,
                status: run.status,
                conclusion: run.conclusion,
                startedAt: run.started_at,
                completedAt: run.completed_at,
                url: run.details_url,
                failedTests: failureEvidence[String(run.id)] ?? [],
            }));

            const duskFailedThenPassed = checkRuns.some((run) => run.name === 'dusk' && run.conclusion === 'failure')
                && checkRuns.some((run) => run.name === 'dusk' && run.conclusion === 'success');
            const missingFailureEvidence = checkRuns.filter((run) => run.name === 'dusk' && run.conclusion === 'failure' && run.failedTests.length === 0);
            if (duskFailedThenPassed && missingFailureEvidence.length > 0) {
                throw new Error(`PR #${pull.number} (${pull.head.sha}) failed then passed Dusk, but failed check-run ${missingFailureEvidence.map((run) => run.id).join(', ')} has no test-name evidence. Add it to --failures; zero is not a defensible flake count.`);
            }

            pulls.push({
                number: pull.number,
                url: pull.html_url,
                headSha: pull.head.sha,
                mergedAt: pull.merged_at,
                checkRuns,
            });
        }

        if (reachedBeforePeriod || batch.length < 100) {
            break;
        }
    }

    pulls.sort((left, right) => Date.parse(left.mergedAt) - Date.parse(right.mergedAt));
    const output = `${JSON.stringify({
        schemaVersion: 1,
        period: {
            start: new Date(since).toISOString(),
            end: new Date(until).toISOString(),
        },
        pulls,
    }, null, 2)}\n`;

    if (options.output) {
        await writeFile(resolve(options.output), output);
    } else {
        process.stdout.write(output);
    }
}

main().catch((error) => {
    console.error(`FAIL: ${error.message}`);
    process.exitCode = 1;
});
