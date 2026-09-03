/**
 * Drive a real headless Chrome over a URL and record proof of work: full-page
 * screenshots at a phone and a desktop viewport, plus the measurements a
 * screenshot cannot show — CLS, paint timings, horizontal overflow, heading
 * order, landmarks, and the single-primary-CTA rule.
 *
 * The screenshots are the artifact a human opens. `report.json` is the artifact
 * that can be asserted on, so a claim about the page can be checked instead of
 * believed. Both matter: a screenshot proves it rendered, the report proves what
 * it rendered.
 *
 * Usage:
 *   node ci/browser/capture.mjs <outdir> <baseurl> [path ...]
 *
 * Example:
 *   node ci/browser/capture.mjs /tmp/shots http://127.0.0.1:8077 / /join
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { launch, VIEWPORTS, observeCls } from './launch.mjs';

const OUT = process.argv[2];
const BASE = process.argv[3];
const PATHS = process.argv.slice(4).length ? process.argv.slice(4) : ['/'];

if (!OUT || !BASE) {
  console.error('usage: node ci/browser/capture.mjs <outdir> <baseurl> [path ...]');
  process.exit(2);
}

mkdirSync(OUT, { recursive: true });

const browser = await launch();
const report = [];
let failures = 0;

for (const path of PATHS) {
  for (const vp of Object.values(VIEWPORTS)) {
    const page = await browser.newPage();
    await page.setViewport(vp);
    await observeCls(page);

    const url = new URL(path, BASE).toString();
    const t0 = Date.now();
    let resp;
    try {
      resp = await page.goto(url, { waitUntil: 'networkidle0', timeout: 60000 });
    } catch (err) {
      console.error(`  FAILED ${path} @ ${vp.name}: ${err.message}`);
      failures++;
      await page.close();
      continue;
    }
    const loadMs = Date.now() - t0;

    const slug = `${path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home'}-${vp.name}`;
    await page.screenshot({ path: join(OUT, `${slug}.png`), fullPage: true });

    const metrics = await page.evaluate(() => {
      const paints = Object.fromEntries(
        performance.getEntriesByType('paint').map((p) => [p.name, Math.round(p.startTime)])
      );
      const text = document.body.innerText.replace(/\s+/g, ' ').trim();
      // The design system marks the one primary action with the brand fill.
      // More than one per page breaks the "exactly one primary CTA" rule.
      const cta = [...document.querySelectorAll('a,button')].filter(
        (el) => el.className && String(el.className).includes('bg-brand')
      );
      return {
        cls: Number((window.__cls ?? 0).toFixed(4)),
        paints,
        // A horizontal scrollbar at 360px is the classic mobile break.
        overflowsHorizontally: document.documentElement.scrollWidth > window.innerWidth + 1,
        docWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
        primaryCtaCount: cta.length,
        primaryCtaHrefs: cta.map((el) => el.getAttribute('href')),
        h1Count: document.querySelectorAll('h1').length,
        headings: [...document.querySelectorAll('h1,h2,h3')].map(
          (h) => `${h.tagName}:${h.innerText.trim().slice(0, 40)}`
        ),
        landmarks: [...document.querySelectorAll('header,nav,main,footer')].map((e) =>
          e.tagName.toLowerCase()
        ),
        title: document.title,
        textSample: text.slice(0, 240),
      };
    });

    report.push({ path, viewport: vp.name, status: resp.status(), loadMs, ...metrics });
    console.log(
      `  ${slug}: status=${resp.status()} cls=${metrics.cls} ` +
        `fcp=${metrics.paints['first-contentful-paint']}ms ` +
        `overflow=${metrics.overflowsHorizontally} ctas=${metrics.primaryCtaCount}`
    );
    await page.close();
  }
}

await browser.close();
writeFileSync(join(OUT, 'report.json'), JSON.stringify(report, null, 2));
console.log(`\nwrote ${OUT}/report.json (${report.length} captures, ${failures} failed)`);
process.exit(failures > 0 ? 1 : 0);
