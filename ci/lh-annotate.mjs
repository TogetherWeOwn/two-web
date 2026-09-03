// Turn Lighthouse's assertion results into GitHub annotations.
//
// Why this exists. `lhci assert` prints the breach to stdout and exits 1, and the
// stdout of a failed step is only reachable by someone who can read the job log or
// download the `budget-reports` artifact. Neither is available to every principal
// that has to act on a red build — an installation token scoped to `checks` can
// read annotations but not logs or artifacts — so a breached budget could be seen
// as "budgets failed" and nothing more. That is exactly the unactionable-red the
// Dusk failure-evidence upload two jobs up was added to prevent; this is the same
// fix for the other job.
//
// It changes no threshold and makes no judgement. It reads the results lhci has
// already written and re-emits them as ::error::, then exits with the same failure
// the assert step would have. ci/lighthouserc.cjs remains the only place a budget
// is defined.

import { readFileSync, readdirSync, existsSync } from 'node:fs';

const DIR = './.lighthouseci';

if (!existsSync(DIR)) {
  console.error('::error::no .lighthouseci directory — Lighthouse never produced results to check.');
  process.exit(1);
}

// lhci writes assertion-results.json next to the reports when any assertion runs.
const resultsFile = readdirSync(DIR).find((f) => f === 'assertion-results.json');

if (!resultsFile) {
  console.log('No assertion-results.json — nothing to annotate.');
  process.exit(0);
}

const results = JSON.parse(readFileSync(`${DIR}/${resultsFile}`, 'utf8'));
const failures = results.filter((r) => !r.passed);

if (failures.length === 0) {
  console.log('Every performance assertion passed.');
  process.exit(0);
}

for (const f of failures) {
  // `actual` and `expected` are the numbers the budget is written in — ms for the
  // paint timings, unitless for CLS — so they are reported raw rather than
  // reformatted into something that no longer matches ci/lighthouserc.cjs.
  console.error(
    `::error::${f.auditId} on ${f.url} — ${f.actual} exceeds the budget of ${f.expected} ` +
      `(${f.level}, ${f.auditProperty ?? 'median of 3 runs'}). ` +
      'Budgets are set in ci/lighthouserc.cjs and lowering one is a CEO decision in writing.'
  );
}

// The passing numbers, as one annotation. A breach is only actionable next to the
// pages that did pass: "LCP 2666ms on /events" says nothing on its own, but
// "…and 1400ms on /" is the difference between one slow page and a budget with no
// headroom anywhere. Reading that off the artifact needs the artifact.
const passes = results.filter((r) => r.passed);

// Emitted as ::error:: rather than ::notice:: on purpose, and it is not a second
// failure — the job's verdict is the exit code below, which counts only breaches.
// GitHub's check-run annotations API returns `failure` annotations; a ::notice::
// is written to the log and does not appear there, which would put this line back
// in the artifact this script exists to avoid needing. The wording says plainly
// that nothing here breached.
if (passes.length > 0) {
  console.error(
    '::error::CONTEXT, NOT A FAILURE — the assertions that passed, for comparison: ' +
      passes.map((p) => `${p.auditId} on ${p.url}: ${p.actual} (budget ${p.expected})`).join('; ')
  );
}

console.error(`\n${failures.length} performance assertion(s) breached.`);
process.exit(1);
