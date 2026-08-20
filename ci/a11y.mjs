// The automated WCAG 2.2 AA pass. Runs axe-core in real Chrome against every page
// in ci/pages.cjs and fails the build on any violation.
//
// Two things this is not:
//
//   1. It is not the Lighthouse accessibility score. That score is a weighted
//      subset with no WCAG 2.2 rules in it at all, and a green score next to a
//      keyboard trap is exactly the kind of false comfort that gets a member
//      stuck on the join button.
//
//   2. It is not proof the site is accessible. Automated tooling catches roughly a
//      third of real barriers. Contrast on a gradient, a focus order that makes no
//      sense, a screen reader announcing "button button button" — all pass here.
//      The manual pass lives in the launch hardening checklist (TWO-35). This job
//      catches the third that a machine can, on every PR, for free.
//
// Adding an exception: see ALLOWED_VIOLATIONS below. There is a process. Use it or
// fix the page.

import { writeFileSync } from 'node:fs';
import { AxePuppeteer } from '@axe-core/puppeteer';
import puppeteer from 'puppeteer';
import pages from './pages.cjs';

const { urls } = pages;

// wcag22aa implies the earlier levels are still checked — axe tags are additive,
// not a replacement — so all six are listed. `best-practice` is deliberately absent:
// it is opinion, and a build that fails on opinion gets ignored on a bad day.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

// Rules knowingly accepted, each with a reason and an issue that will remove it.
// An entry with no issue number is not an exception, it is an untracked bug.
// Empty is the correct state. Adding to it needs QA sign-off in the PR, not a
// commit that slips through with a feature.
//
//   { rule: 'color-contrast', issue: 'TWO-00', why: '...' }
const ALLOWED_VIOLATIONS = [];

const IGNORED_RULES = new Set(ALLOWED_VIOLATIONS.map((entry) => entry.rule));

// Every page is checked at both widths. This is not belt-and-braces — the two find
// different things:
//
//   360px is the tested floor of the design system (TWO-19). It is where WCAG 2.2's
//   target-size and reflow rules actually bite, where a focus ring collides with the
//   element next to it, and where a nav that is fine at 412px starts overlapping.
//   The Designer asked for this width specifically because it is the one they
//   designed against, so a violation here is a real defect and not a hypothetical.
//
//   1280px is where landmark, heading-order and colour-contrast problems on the
//   wide layout live — a mobile-only pass would never render the desktop nav at all.
//
// Two widths doubles the axe runs, not the page loads that matter: axe itself is a
// couple of hundred milliseconds. Adding a third width would buy very little.
const VIEWPORTS = [
  { label: '360px', width: 360, height: 780, deviceScaleFactor: 3, isMobile: true },
  { label: '1280px', width: 1280, height: 900, deviceScaleFactor: 1, isMobile: false },
];

const browser = await puppeteer.launch({
  headless: true,
  // The GitHub runner has no user namespaces available to Chrome's sandbox. This
  // is a throwaway container rendering our own pages; it is not a trust boundary.
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

const report = [];
let violationCount = 0;

try {
  for (const viewport of VIEWPORTS) {
    for (const url of urls) {
      const page = await browser.newPage();
      const where = `${url} @ ${viewport.label}`;

      await page.setViewport({
        width: viewport.width,
        height: viewport.height,
        deviceScaleFactor: viewport.deviceScaleFactor,
        isMobile: viewport.isMobile,
      });

      const response = await page.goto(url, { waitUntil: 'networkidle0', timeout: 30_000 });

      // A 500 that renders an error page can otherwise pass the accessibility check
      // with flying colours.
      if (!response || !response.ok()) {
        console.error(`::error::${where} returned HTTP ${response ? response.status() : 'no response'}`);
        violationCount += 1;
        await page.close();
        continue;
      }

      const results = await new AxePuppeteer(page).withTags(TAGS).analyze();
      const violations = results.violations.filter((violation) => !IGNORED_RULES.has(violation.id));

      report.push({
        url,
        viewport: viewport.label,
        violations,
        passes: results.passes.length,
        incomplete: results.incomplete.length,
      });

      if (violations.length === 0) {
        console.log(`PASS  ${where}  (${results.passes.length} checks passed)`);
      } else {
        console.log(`FAIL  ${where}`);
        for (const violation of violations) {
          violationCount += violation.nodes.length;

          // GitHub renders ::error:: as an annotation on the job, so a failure is
          // readable without opening the artifact. The width is in the message
          // because "it looks fine on my laptop" is the usual first reply.
          console.error(
            `::error::${violation.id} (${violation.impact}) at ${viewport.label} — ${violation.help}\n` +
              `  ${where}\n` +
              `  ${violation.helpUrl}\n` +
              violation.nodes
                .map((node) => `  at: ${node.target.join(' ')}\n      ${node.failureSummary?.replace(/\n/g, '\n      ')}`)
                .join('\n')
          );
        }
      }

      await page.close();
    }
  }
} finally {
  await browser.close();
}

writeFileSync(new URL('./a11y-report.json', import.meta.url), JSON.stringify(report, null, 2));

if (ALLOWED_VIOLATIONS.length > 0) {
  console.log(`\n${ALLOWED_VIOLATIONS.length} rule(s) suppressed by exception:`);
  for (const entry of ALLOWED_VIOLATIONS) {
    console.log(`  ${entry.rule} — ${entry.issue} — ${entry.why}`);
  }
}

const widths = VIEWPORTS.map((viewport) => viewport.label).join(' and ');

if (violationCount > 0) {
  console.error(
    `\n${violationCount} accessibility violation(s) across ${urls.length} page(s) at ${widths}. ` +
      'WCAG 2.2 AA is a merge gate, not a warning.'
  );
  process.exit(1);
}

console.log(`\nWCAG 2.2 AA: clean across ${urls.length} page(s) at ${widths}.`);
