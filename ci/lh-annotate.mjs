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

// The comparison numbers, from the LHR rather than from the assertion results.
//
// This was got wrong twice before it was got right, and the wrong version is worth
// naming so nobody rebuilds it: `assertion-results.json` contains ONLY entries that
// failed. Filtering it for `passed` yields an empty array on every run, so the
// "here are the passing pages for comparison" line was never emitted and looked
// like an annotations-API quirk. It was not — there was nothing to print.
//
// The passing numbers live in the per-run reports, which lhci writes as
// lhr-<timestamp>.json. Reading the audit straight out of those gives the number
// for every URL, including the ones that passed and therefore never appear above.
// A breach is only actionable next to them: "LCP 2661ms on /events" says nothing
// alone, but "…and 1400ms on /" is the difference between one heavy page and a
// budget with no headroom anywhere.
const AUDITS = ['largest-contentful-paint', 'first-contentful-paint', 'cumulative-layout-shift'];

// median of the runs per URL, matching how the assertions aggregate
const median = (xs) => {
  const s = [...xs].sort((a, b) => a - b);
  return s.length % 2 ? s[(s.length - 1) / 2] : (s[s.length / 2 - 1] + s[s.length / 2]) / 2;
};

const byUrl = new Map();
for (const file of readdirSync(DIR).filter((f) => /^lhr-.*\.json$/.test(f))) {
  let lhr;
  try {
    lhr = JSON.parse(readFileSync(`${DIR}/${file}`, 'utf8'));
  } catch {
    continue; // a half-written report is not worth failing the annotate step over
  }
  const url = lhr.finalDisplayedUrl ?? lhr.finalUrl ?? lhr.requestedUrl;
  if (!url) continue;
  if (!byUrl.has(url)) byUrl.set(url, new Map());
  for (const id of AUDITS) {
    const v = lhr.audits?.[id]?.numericValue;
    if (typeof v !== 'number') continue;
    if (!byUrl.get(url).has(id)) byUrl.get(url).set(id, []);
    byUrl.get(url).get(id).push(v);
  }
}

// Emitted as ::error:: rather than ::notice:: on purpose, and it is not a second
// failure — the job's verdict is the exit code below, which counts only breaches.
// GitHub's check-run annotations API returns `failure` annotations; a ::notice::
// is written to the log and does not appear there, which would put this line back
// in the artifact this script exists to avoid needing. The wording says plainly
// that nothing here breached.
for (const [url, audits] of byUrl) {
  const parts = [...audits].map(([id, vals]) => `${id} ${Math.round(median(vals))}`);
  console.error(`::error::CONTEXT, NOT A FAILURE — measured on ${url} (median of ${
    audits.values().next().value?.length ?? 0
  } runs): ${parts.join(', ')}`);
}

console.error(`\n${failures.length} performance assertion(s) breached.`);
process.exit(1);
