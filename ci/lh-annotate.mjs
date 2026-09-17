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
const breached = results.filter((r) => !r.passed);

// The per-run numbers behind an aggregate, and how far apart they were.
//
// lhci already records every sample on the assertion as `values` (see
// @lhci/utils/src/assertions.js — `values: filteredValues`), and this script used
// to print only the median. That is the difference between a red that can be
// acted on and one that cannot: "LCP 2114 exceeds 2000" is equally consistent
// with a byte regression and with a contended runner, and the two want opposite
// responses. The samples tell them apart, and we already have them.
//
// Measured on TOG-3224: the same fixed-weight page medians ±3.7% on an idle host
// and ±20.1% on one under CPU contention. A breach whose own samples are spread
// that wide is a measurement taken on a busy host, not a page that got heavier.
const spreadOf = (values) => {
  if (!Array.isArray(values) || values.length < 2) return null;
  const nums = values.filter((v) => typeof v === 'number' && Number.isFinite(v));
  if (nums.length < 2) return null;
  const mean = nums.reduce((a, b) => a + b, 0) / nums.length;
  if (mean === 0) return null;
  return { nums, pct: ((Math.max(...nums) - Math.min(...nums)) / 2 / mean) * 100 };
};

// Rounded to the precision the budget is written in: ms budgets are integers,
// CLS is a fraction and rounding it to 0 would hide the whole number.
const fmt = (v) => (Math.abs(v) >= 10 ? String(Math.round(v)) : String(Number(v.toFixed(3))));

const samplesNote = (r) => {
  const s = spreadOf(r.values);
  if (!s) return r.auditProperty ?? 'median of 3 runs';
  return `median of ${s.nums.length} runs: ${s.nums.map(fmt).join(', ')} — spread ±${s.pct.toFixed(1)}%`;
};

// `level` decides the verdict, and it has to, because ci/lighthouserc.cjs writes
// two kinds of budget and means the difference. `largest-contentful-paint`,
// `cumulative-layout-shift` and `server-response-time` are `['error', ...]` — the
// CEO's numbers, a red build. `total-blocking-time` and `first-contentful-paint`
// are `['warn', ...]` and are documented there, in as many words, as "not a CEO
// budget, so not a failure ... a cheap early signal before LCP actually
// breaches".
//
// This script used to fail on `!r.passed` alone, which quietly promoted every
// warning to a gate. /admin then went red on FCP 1957ms against the 1800ms
// tripwire while every error-level budget passed with room — LCP 2779ms against
// its 3200ms ceiling (TOG-54). A warning that fails the build is not a warning,
// and the pressure it creates is to delete the tripwire or inflate the number,
// which costs the early signal the tripwire exists to give.
const failures = breached.filter((r) => r.level === 'error');
const warnings = breached.filter((r) => r.level !== 'error');

// An all-green job used to return here, before anything read the reports. That
// cost the one reading that tells a real green from a vacuous one: the host
// speed. A green measured on a badly contended runner is the same coin flip as a
// red measured on one — it just landed the other way — and with no baseline from
// passing runs there is nothing to calibrate a contended run against. So the
// reports are scanned first now, and the verdict is decided at the bottom.
for (const f of failures) {
  // `actual` and `expected` are the numbers the budget is written in — ms for the
  // paint timings, unitless for CLS — so they are reported raw rather than
  // reformatted into something that no longer matches ci/lighthouserc.cjs.
  console.error(
    `::error::${f.auditId} on ${f.url} — ${f.actual} exceeds the budget of ${f.expected} ` +
      `(${f.level}, ${samplesNote(f)}). ` +
      'Budgets are set in ci/lighthouserc.cjs and lowering one is a CEO decision in writing.'
  );
}

// Still annotated, and still as ::error:: so it reaches the check-run API for a
// principal that cannot read job logs — but worded so nobody reads it as the
// thing that stopped the merge, and not counted in the exit code below.
for (const w of warnings) {
  console.error(
    `::error::TRIPWIRE, NOT A FAILURE — ${w.auditId} on ${w.url} — ${w.actual} exceeds ` +
      `its early-warning threshold of ${w.expected} (${w.level}, ${samplesNote(w)}). ` +
      'This does not fail the build. It is the number that moves first when a page starts getting slower.'
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
const lcpElement = new Map();
const longTasks = new Map();

// How fast the host actually was while it measured.
//
// Lighthouse runs its own CPU benchmark on every run and reports it as
// `environment.benchmarkIndex`. Under `throttlingMethod: 'simulate'` the paint
// timings are rebuilt by Lantern from observed main-thread task durations, so a
// contended host inflates them — LCP is not insulated from the runner's load, it
// is a function of it.
//
// Measured on TOG-3224, same page and same settings: benchmarkIndex 1431-1759 on
// an idle host and 891-1500 with six competing CPU hogs, while the LCP spread
// went from ±3.7% to ±20.1%. `budgets` shares a host with `pest`, `dusk` and
// two-bot's service containers, so this number is the difference between "the
// page regressed" and "the box was busy" — and without it nobody can tell.
const benchmarks = [];
for (const file of readdirSync(DIR).filter((f) => /^lhr-.*\.json$/.test(f))) {
  let lhr;
  try {
    lhr = JSON.parse(readFileSync(`${DIR}/${file}`, 'utf8'));
  } catch {
    continue; // a half-written report is not worth failing the annotate step over
  }
  // Collected before the `url` guard: a run whose URL we cannot name still
  // measured the host, and the host reading is per-runner, not per-page.
  const bi = lhr.environment?.benchmarkIndex;
  if (typeof bi === 'number' && Number.isFinite(bi)) benchmarks.push(bi);

  const url = lhr.finalDisplayedUrl ?? lhr.finalUrl ?? lhr.requestedUrl;
  if (!url) continue;
  if (!byUrl.has(url)) byUrl.set(url, new Map());
  for (const id of AUDITS) {
    const v = lhr.audits?.[id]?.numericValue;
    if (typeof v !== 'number') continue;
    if (!byUrl.get(url).has(id)) byUrl.get(url).set(id, []);
    byUrl.get(url).get(id).push(v);
  }

  // The LCP element itself. `largest-contentful-paint-element` reports it as a
  // node with a selector and a snippet; one run's worth is enough to name it.
  if (!lcpElement.has(url)) {
    const node = lhr.audits?.['largest-contentful-paint-element']?.details?.items?.[0]?.items?.[0]?.node;
    const sel = node?.selector ?? node?.snippet;
    if (sel) lcpElement.set(url, String(sel).slice(0, 300));
  }

  // What the network was still doing. Sorting by transfer size names the bytes
  // on the critical path without anyone having to guess at them.
  if (!longTasks.has(url)) {
    const items = lhr.audits?.['network-requests']?.details?.items;
    if (Array.isArray(items)) {
      const top = items
        .filter((i) => typeof i.transferSize === 'number' && i.transferSize > 10_000)
        .sort((a, b) => b.transferSize - a.transferSize)
        .slice(0, 5)
        .map((i) => `${String(i.url).replace(/^https?:\/\/[^/]+/, '')} ${Math.round(i.transferSize / 1024)}KB`);
      if (top.length) longTasks.set(url, top.join(', '));
    }
  }
}

// Emitted as ::error:: rather than ::notice:: on purpose, and it is not a second
// failure — the job's verdict is the exit code below, which counts only breaches.
// GitHub's check-run annotations API returns `failure` annotations; a ::notice::
// is written to the log and does not appear there, which would put this line back
// in the artifact this script exists to avoid needing. The wording says plainly
// that nothing here breached.
//
// Only when something breached. These lines exist to give a breach something to
// be read against; emitting four of them on every green build would spend the
// reader's attention on runs that need none.
for (const [url, audits] of breached.length ? byUrl : []) {
  const parts = [...audits].map(([id, vals]) => `${id} ${Math.round(median(vals))}`);
  console.error(`::error::CONTEXT, NOT A FAILURE — measured on ${url} (median of ${
    audits.values().next().value?.length ?? 0
  } runs): ${parts.join(', ')}`);
  // Which element the LCP actually is, and what the page was still waiting on.
  // The timings alone say a page is slow; they never say what is slow. Without
  // this the only way to find out is the artifact, which is what this file exists
  // to avoid needing.
  const el = lcpElement.get(url);
  if (el) console.error(`::error::CONTEXT, NOT A FAILURE — LCP element on ${url}: ${el}`);
  const chain = longTasks.get(url);
  if (chain) console.error(`::error::CONTEXT, NOT A FAILURE — slowest resources on ${url}: ${chain}`);
}

// One line per job, not per URL: every run on this job shared one host.
if (benchmarks.length) {
  const bi = Math.round(median(benchmarks));
  console.error(
    `::error::CONTEXT, NOT A FAILURE — host speed while measuring: benchmarkIndex ` +
      `${bi} (median of ${benchmarks.length} runs, range ${Math.round(Math.min(...benchmarks))}–${Math.round(
        Math.max(...benchmarks)
      )}). Lower means a busier runner. These timings are wall-clock on a host shared with pest, dusk ` +
      'and two-bot, so read a breach against the spread above before hunting for bytes (TOG-3224).'
  );
}

if (breached.length === 0) {
  console.log('Every performance assertion passed.');
  process.exit(0);
}

if (failures.length === 0) {
  console.error(
    `\n${warnings.length} early-warning threshold(s) exceeded, 0 budgets breached. Not a failure.`
  );
  process.exit(0);
}

console.error(
  `\n${failures.length} performance assertion(s) breached` +
    (warnings.length ? `, plus ${warnings.length} early-warning threshold(s) exceeded.` : '.')
);
process.exit(1);
