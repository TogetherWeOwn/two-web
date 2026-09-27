// TOG-5634 one-shot audit: axe-core over home, join, events, profile at both widths.
// Not ci/a11y.mjs because ci/pages.cjs covers only /, /events and /admin.
import { writeFileSync } from 'node:fs';
import { AxePuppeteer } from '@axe-core/puppeteer';
import { launch } from './browser/launch.mjs';

const BASE_URL = process.env.CI_BASE_URL || 'http://127.0.0.1:8000';
const SESSION_COOKIE = process.env.CI_SESSION_COOKIE || '';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];
// /profile needs auth; the cookie is ignored on public pages.
const PAGES = [
  { path: '/', auth: false },
  { path: '/join', auth: false },
  { path: '/events', auth: false },
  { path: '/events', auth: true, state: 'signed-in' },
  { path: '/profile', auth: true },
];
const VIEWPORTS = [
  { label: '360px', width: 360, height: 780 },
  { label: '1280px', width: 1280, height: 900 },
];

const browser = await launch();
const report = [];
let violationCount = 0;
try {
  for (const viewport of VIEWPORTS) {
    for (const { path, auth, state: pageState } of PAGES) {
      const page = await browser.newPage();
      const where = `${path}${pageState ? ` (${pageState})` : ''} @ ${viewport.label}`;
      await page.setViewport({ width: viewport.width, height: viewport.height });
      if (auth && SESSION_COOKIE) {
        const [name, ...rest] = SESSION_COOKIE.split('=');
        await page.setCookie({ name, value: rest.join('='), url: BASE_URL });
      }
      const response = await page.goto(BASE_URL + path, { waitUntil: 'networkidle0', timeout: 30_000 });
      if (!response || !response.ok()) {
        console.error(`HTTP ${response ? response.status() : 'none'} at ${where}`);
        violationCount += 1;
        await page.close();
        continue;
      }
      const landed = new URL(page.url()).pathname;
      if (landed !== path) {
        console.error(`REDIRECT ${where} -> ${landed}`);
        violationCount += 1;
        await page.close();
        continue;
      }
      // Let Livewire settle before measuring.
      await new Promise((r) => setTimeout(r, 1500));

      // axe cannot see focus order, so probe it with real Tab keypresses and
      // record where focus lands. A trap, a skip, or focus dropping to <body>
      // shows up here, not in the axe report.
      const collectTabOrder = async () => {
        const order = [];
        for (let i = 0; i < 20; i++) {
          // eslint-disable-next-line no-await-in-loop
          await page.keyboard.press('Tab');
          // eslint-disable-next-line no-await-in-loop
          const desc = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body) return 'BODY (focus lost)';
            const label =
              el.getAttribute?.('aria-label') ||
              el.textContent?.trim().slice(0, 40) ||
              el.getAttribute?.('placeholder') ||
              el.getAttribute?.('name') ||
              el.id;
            return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} "${label}"`;
          });
          order.push(desc);
          if (desc === 'BODY (focus lost)') break;
        }
        return order;
      };

      // Fresh Tab order per measured state: after clicking "Add profile
      // details" the DOM changes, so a Tab order taken before the click no
      // longer describes the page. Start from the top each time.
      const analyze = async (state) => {
        // eslint-disable-next-line no-await-in-loop
        await page.keyboard.press('Home');
        // eslint-disable-next-line no-await-in-loop
        await page.evaluate(() => document.activeElement?.blur?.());
        // eslint-disable-next-line no-await-in-loop
        const order = await collectTabOrder();
        // eslint-disable-next-line no-await-in-loop
        const results = await new AxePuppeteer(page).withTags(TAGS).analyze();
        report.push({
          path, viewport: viewport.label, state,
          violations: results.violations,
          passes: results.passes.length,
          tabOrder: order,
        });
        return results;
      };

      const reportViolations = (results, state) => {
        if (results.violations.length === 0) {
          console.log(`PASS  ${where}${state} (${results.passes.length} checks)`);
        } else {
          console.log(`FAIL  ${where}${state}`);
          for (const v of results.violations) {
            violationCount += v.nodes.length;
            console.error(
              `${v.id} (${v.impact}) — ${v.help}\n  ${v.helpUrl}\n` +
                v.nodes.map((n) => `  at: ${n.target.join(' ')}\n      ${n.failureSummary?.replace(/\n/g, '\n      ')}`).join('\n')
            );
          }
        }
      };

      const results = await analyze('initial');
      reportViolations(results, ' initial');

      // States that only exist behind interaction.
      if (path === '/events') {
        // Calendar view: a different DOM (table grid, month nav) with its own
        // labels, plus "See past events" when past events exist.
        const calBtn = await page.evaluate(() => {
          const b = [...document.querySelectorAll('button')].find((x) =>
            x.textContent.trim() === 'Calendar');
          if (b) { b.click(); return true; }
          return false;
        });
        if (calBtn) {
          try {
            await page.waitForSelector('[data-testid="events-calendar-grid"]', { timeout: 8000 });
            await new Promise((r) => setTimeout(r, 1000));
            const calResults = await analyze('calendar-view');
            reportViolations(calResults, ' calendar-view');
            // Back to list for the past-events state below. The list UL only
            // exists when there are upcoming events; with an empty calendar
            // the empty-state marker proves the view switched back.
            await page.evaluate(() => {
              [...document.querySelectorAll('button')].find((x) =>
                x.textContent.trim() === 'List')?.click();
            });
            await page.waitForFunction(
              () => document.querySelector('[data-testid="events-list"]') ||
                document.querySelector('[data-testid="events-empty-never"]') ||
                document.querySelector('[data-testid="events-empty-no-upcoming"]'),
              { timeout: 8000 }
            );
            await new Promise((r) => setTimeout(r, 1000));
          } catch (e) {
            console.error(`CALENDAR did not open at ${where}: ${e.message}`);
            violationCount += 1;
          }
        }
        const pastBtn = await page.evaluate(() => {
          const b = document.querySelector('[data-testid="events-show-past"]');
          if (b) { b.click(); return true; }
          return false;
        });
        if (pastBtn) {
          try {
            await page.waitForSelector('[data-testid="events-past-list"]', { timeout: 8000 });
            await new Promise((r) => setTimeout(r, 1000));
            const pastResults = await analyze('past-shown');
            reportViolations(pastResults, ' past-shown');
          } catch (e) {
            console.error(`PAST LIST did not open at ${where}: ${e.message}`);
            violationCount += 1;
          }
        }
      }
      // Labels that only exist behind interaction: open the profile edit form
      // and measure that state too.
      if (path === '/profile') {
        const opened = await page.evaluate(() => {
          const btn = [...document.querySelectorAll('button')].find((b) =>
            /add profile details/i.test(b.textContent || ''));
          if (btn) { btn.click(); return true; }
          return false;
        });
        if (opened) {
          try {
            await page.waitForSelector('[data-testid="profile-edit-form"]', { timeout: 5000 });
            await new Promise((r) => setTimeout(r, 1000));
            const editResults = await analyze('edit-form-open');
            reportViolations(editResults, ' edit-form-open');
          } catch (e) {
            console.error(`EDIT FORM did not open at ${where}: ${e.message}`);
            violationCount += 1;
          }
        }
      }
      await page.close();
    }
  }
} finally {
  await browser.close();
}
writeFileSync(new URL('./a11y-tog5634-report.json', import.meta.url), JSON.stringify(report, null, 2));
console.log(violationCount > 0 ? `\n${violationCount} violation(s).` : '\nClean.');
process.exit(violationCount > 0 ? 1 : 0);
