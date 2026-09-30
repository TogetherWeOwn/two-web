#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { basename, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = resolve(fileURLToPath(new URL('..', import.meta.url)));
const DEFAULT_MANIFEST = resolve(REPO_ROOT, 'ci/critical-journeys.json');
const REQUIRED_CHECKS = ['static', 'pest', 'dusk', 'budgets', 'deps-audit', 'tests', 'gitleaks'];
const SUCCESSFUL_CONCLUSIONS = new Set(['success', 'neutral', 'skipped']);

function usage() {
    console.log(`Usage:
  node ci/suite-health.mjs --input <json> [--manifest <json>] [--format markdown|json] [--output <file>]
  node ci/suite-health.mjs --validate-manifest [--manifest <json>]

The input is a JSON object with a pulls array. Each pull contains number, url, headSha,
and checkRuns. A check run has name, status, conclusion, startedAt, completedAt,
and optional failedTests. Preserve every attempt for the week; do not pass only GitHub's
filter=latest response, because that erases the failed half of a flake.`);
}

function fail(message) {
    console.error(`FAIL: ${message}`);
    process.exitCode = 1;
}

function parseArgs(argv) {
    const options = {
        format: 'markdown',
        manifest: DEFAULT_MANIFEST,
    };

    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];
        if (argument === '--help') {
            options.help = true;
        } else if (argument === '--validate-manifest') {
            options.validateManifest = true;
        } else if (['--input', '--manifest', '--format', '--output'].includes(argument)) {
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

    if (!['markdown', 'json'].includes(options.format)) {
        throw new Error('--format must be markdown or json');
    }
    if (!options.help && !options.validateManifest && !options.input) {
        throw new Error('--input is required unless --validate-manifest is used');
    }

    return options;
}

async function loadJson(path) {
    try {
        return JSON.parse(await readFile(path, 'utf8'));
    } catch (error) {
        throw new Error(`${path}: ${error.message}`);
    }
}

function validateManifestShape(manifest) {
    const errors = [];
    if (manifest?.version !== 1) {
        errors.push('version must be 1');
    }
    if (!Array.isArray(manifest?.journeys) || manifest.journeys.length !== 6) {
        errors.push(`journeys must contain exactly six entries (found ${manifest?.journeys?.length ?? 0})`);
        return errors;
    }

    const ids = new Set();
    for (const [journeyIndex, journey] of manifest.journeys.entries()) {
        const prefix = `journeys[${journeyIndex}]`;
        if (!journey.id || typeof journey.id !== 'string') {
            errors.push(`${prefix}.id must be a non-empty string`);
        } else if (ids.has(journey.id)) {
            errors.push(`${prefix}.id duplicates ${journey.id}`);
        } else {
            ids.add(journey.id);
        }
        if (!journey.name || typeof journey.name !== 'string') {
            errors.push(`${prefix}.name must be a non-empty string`);
        }
        if (!Array.isArray(journey.tests) || journey.tests.length === 0) {
            errors.push(`${prefix}.tests must map at least one Dusk test`);
            continue;
        }
        for (const [testIndex, test] of journey.tests.entries()) {
            const testPrefix = `${prefix}.tests[${testIndex}]`;
            if (!test.file?.startsWith('tests/Browser/') || !test.file.endsWith('.php')) {
                errors.push(`${testPrefix}.file must point to a PHP file under tests/Browser`);
            }
            if (!test.name || typeof test.name !== 'string') {
                errors.push(`${testPrefix}.name must be a non-empty string`);
            }
            if (typeof test.complete !== 'boolean') {
                errors.push(`${testPrefix}.complete must be true or false`);
            }
            if (test.complete === false && !test.gap) {
                errors.push(`${testPrefix}.gap is required while complete is false`);
            }
        }
    }

    return errors;
}

async function validateManifest(manifest) {
    const errors = validateManifestShape(manifest);
    if (errors.length > 0) {
        return errors;
    }

    for (const journey of manifest.journeys) {
        for (const test of journey.tests) {
            const path = resolve(REPO_ROOT, test.file);
            let source;
            try {
                source = await readFile(path, 'utf8');
            } catch (error) {
                errors.push(`${journey.id}: cannot read ${test.file}: ${error.message}`);
                continue;
            }
            const singleQuoted = test.name.replaceAll('\\', '\\\\').replaceAll("'", "\\'");
            const doubleQuoted = test.name.replaceAll('\\', '\\\\').replaceAll('"', '\\"');
            const declarations = [
                `test('${singleQuoted}'`,
                `test("${doubleQuoted}"`,
                `it('${singleQuoted}'`,
                `it("${doubleQuoted}"`,
            ];
            if (!declarations.some((declaration) => source.includes(declaration))) {
                errors.push(`${journey.id}: ${test.file} does not declare test ${JSON.stringify(test.name)}`);
            }
        }
    }

    return errors;
}

function normalizeInput(input) {
    if (!Array.isArray(input?.pulls)) {
        throw new Error('input.pulls must be an array');
    }

    return input.pulls.map((pull, pullIndex) => {
        if (!pull.headSha || !Array.isArray(pull.checkRuns)) {
            throw new Error(`pulls[${pullIndex}] needs headSha and checkRuns`);
        }
        return {
            number: pull.number ?? null,
            url: pull.url ?? null,
            headSha: pull.headSha,
            checkRuns: pull.checkRuns.map((run, runIndex) => ({
                ...run,
                name: run.name,
                status: run.status ?? null,
                conclusion: run.conclusion ?? null,
                startedAt: run.startedAt ?? run.started_at ?? null,
                completedAt: run.completedAt ?? run.completed_at ?? null,
                failedTests: [...new Set(run.failedTests ?? [])].sort(),
                inputIndex: runIndex,
            })),
        };
    });
}

function latestRunsByName(checkRuns) {
    const latest = new Map();
    for (const run of checkRuns) {
        const current = latest.get(run.name);
        const runTime = Date.parse(run.completedAt ?? run.startedAt ?? 0);
        const currentTime = Date.parse(current?.completedAt ?? current?.startedAt ?? 0);
        if (!current || runTime > currentTime || (runTime === currentTime && run.inputIndex > current.inputIndex)) {
            latest.set(run.name, run);
        }
    }
    return latest;
}

function analysePull(pull) {
    const latest = latestRunsByName(pull.checkRuns);
    const required = REQUIRED_CHECKS.map((name) => latest.get(name)).filter(Boolean);
    const missingChecks = REQUIRED_CHECKS.filter((name) => !latest.has(name));
    const timed = required.filter((run) => run.startedAt && run.completedAt);
    const incompleteTiming = required.filter((run) => !run.startedAt || !run.completedAt).map((run) => run.name);
    const earliest = timed.length > 0
        ? new Date(Math.min(...timed.map((run) => Date.parse(run.startedAt)))).toISOString()
        : null;
    const latestFinish = timed.length > 0
        ? new Date(Math.max(...timed.map((run) => Date.parse(run.completedAt)))).toISOString()
        : null;
    const runtimeSeconds = earliest && latestFinish
        ? (Date.parse(latestFinish) - Date.parse(earliest)) / 1000
        : null;
    const finalGreen = missingChecks.length === 0
        && required.every((run) => run.status === 'completed' && SUCCESSFUL_CONCLUSIONS.has(run.conclusion));
    const dusk = latest.get('dusk');
    const duskPresent = Boolean(dusk);
    const duskGreen = dusk?.status === 'completed' && dusk.conclusion === 'success';

    const flakyTests = new Set();
    const duskRuns = pull.checkRuns.filter((candidate) => candidate.name === 'dusk');
    for (const run of duskRuns) {
        if (run.conclusion === 'failure') {
            const laterSuccessfulDuskRun = duskRuns.some((candidate) => candidate.conclusion === 'success'
                && Date.parse(candidate.completedAt ?? candidate.startedAt) > Date.parse(run.completedAt ?? run.startedAt));
            if (laterSuccessfulDuskRun) {
                for (const test of run.failedTests) {
                    flakyTests.add(test);
                }
            }
        }
    }

    return {
        number: pull.number,
        url: pull.url,
        headSha: pull.headSha,
        earliestRequiredStart: earliest,
        latestRequiredFinish: latestFinish,
        runtimeSeconds,
        finalGreen,
        duskPresent,
        duskGreen,
        missingChecks,
        incompleteTiming,
        flakyTests: [...flakyTests].sort(),
    };
}

function journeyMatrix(manifest, pullReports) {
    const latestPull = pullReports.at(-1);
    const latestDuskGreen = latestPull?.duskGreen === true;
    const everyPullHasGreenDusk = pullReports.length > 0
        && pullReports.every((pull) => pull.duskPresent && pull.duskGreen);

    return manifest.journeys.map((journey) => {
        const written = journey.tests.length > 0;
        const complete = written && journey.tests.every((test) => test.complete);
        return {
            id: journey.id,
            name: journey.name,
            tests: journey.tests.map((test) => ({ file: test.file, name: test.name })),
            written,
            complete,
            green: complete && latestDuskGreen,
            everyPr: complete && everyPullHasGreenDusk,
            gaps: journey.tests.filter((test) => !test.complete).map((test) => test.gap),
        };
    });
}

function formatDuration(seconds) {
    if (seconds === null) {
        return 'unknown';
    }
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;
    return `${minutes}m ${String(remainder).padStart(2, '0')}s`;
}

function yesNo(value) {
    return value ? 'yes' : 'no';
}

function renderMarkdown(report) {
    const lines = [
        `# Dusk suite health — ${report.period.start} to ${report.period.end}`,
        '',
        `- Pull requests measured: **${report.summary.pullCount}**`,
        `- Latest merge-gate runtime: **${formatDuration(report.summary.latestRuntimeSeconds)}**`,
        `- Flake rate: **${report.summary.distinctFlakyTestCount} distinct test(s)** failed and then passed on an unchanged commit`,
        `- Complete critical journeys: **${report.summary.completeJourneyCount}/6**`,
        '',
        '## Merge-gate runtime',
        '',
        '| PR | Head | Earliest required start | Latest required finish | Runtime | Final gate |',
        '|---|---|---|---|---:|---|',
    ];

    for (const pull of report.pulls) {
        const label = pull.url ? `[#${pull.number}](${pull.url})` : `#${pull.number ?? '?'}`;
        const gate = pull.finalGreen ? 'green' : `incomplete (${[...pull.missingChecks, ...pull.incompleteTiming].join(', ') || 'required check not green'})`;
        lines.push(`| ${label} | \`${pull.headSha.slice(0, 12)}\` | ${pull.earliestRequiredStart ?? 'unknown'} | ${pull.latestRequiredFinish ?? 'unknown'} | ${formatDuration(pull.runtimeSeconds)} | ${gate} |`);
    }

    lines.push('', '## Flakes', '');
    if (report.flakes.length === 0) {
        lines.push('No Dusk test in the supplied check-run data failed and then passed on the same commit.');
    } else {
        for (const flake of report.flakes) {
            lines.push(`- \`${flake.test}\` on \`${flake.headSha}\`${flake.pullNumber ? ` (PR #${flake.pullNumber})` : ''}`);
        }
    }

    lines.push(
        '',
        '> A failed Dusk check with no `failedTests` evidence is not counted as zero flakes. The input collector must attach test names from the failed check output or artifact; otherwise this report cannot classify the failure.',
        '',
        '## Critical journey coverage',
        '',
        '| Journey | Written | Complete | Green | Every PR | Evidence / gap |',
        '|---|---:|---:|---:|---:|---|',
    );

    for (const journey of report.journeys) {
        const evidence = journey.complete
            ? journey.tests.map((test) => `\`${test.file}\` — ${test.name}`).join('<br>')
            : journey.gaps.map((gap) => `**INCOMPLETE:** ${gap}`).join('<br>');
        lines.push(`| ${journey.name} | ${yesNo(journey.written)} | ${yesNo(journey.complete)} | ${yesNo(journey.green)} | ${yesNo(journey.everyPr)} | ${evidence} |`);
    }

    return `${lines.join('\n')}\n`;
}

async function main() {
    let options;
    try {
        options = parseArgs(process.argv.slice(2));
    } catch (error) {
        fail(error.message);
        usage();
        return;
    }

    if (options.help) {
        usage();
        return;
    }

    const manifestPath = resolve(options.manifest);
    const manifest = await loadJson(manifestPath);
    const manifestErrors = await validateManifest(manifest);
    if (manifestErrors.length > 0) {
        for (const error of manifestErrors) {
            fail(error);
        }
        return;
    }

    if (options.validateManifest) {
        console.log(`PASS: ${basename(manifestPath)} maps exactly six critical journeys to existing Dusk tests.`);
        return;
    }

    const input = await loadJson(resolve(options.input));
    const pulls = normalizeInput(input).map(analysePull);
    const flakes = pulls.flatMap((pull) => pull.flakyTests.map((test) => ({
        test,
        headSha: pull.headSha,
        pullNumber: pull.number,
    })));
    const journeys = journeyMatrix(manifest, pulls);
    const latestRuntime = [...pulls].reverse().find((pull) => pull.runtimeSeconds !== null)?.runtimeSeconds ?? null;
    const report = {
        schemaVersion: 1,
        period: {
            start: input.period?.start ?? 'unknown',
            end: input.period?.end ?? 'unknown',
        },
        requiredChecks: REQUIRED_CHECKS,
        summary: {
            pullCount: pulls.length,
            latestRuntimeSeconds: latestRuntime,
            distinctFlakyTestCount: new Set(flakes.map((flake) => flake.test)).size,
            completeJourneyCount: journeys.filter((journey) => journey.complete).length,
        },
        pulls,
        flakes,
        journeys,
    };

    const rendered = options.format === 'json'
        ? `${JSON.stringify(report, null, 2)}\n`
        : renderMarkdown(report);

    if (options.output) {
        const { writeFile } = await import('node:fs/promises');
        await writeFile(resolve(options.output), rendered);
    } else {
        process.stdout.write(rendered);
    }
}

main().catch((error) => fail(error.stack ?? error.message));
