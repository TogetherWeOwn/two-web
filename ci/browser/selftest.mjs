/**
 * Tests for the prover.
 *
 * These scripts exist to produce evidence a human will trust without re-deriving
 * it. That makes a *silently wrong* capture the worst possible outcome — worse
 * than no capture, because it is indistinguishable from a real one and it gets
 * pasted onto a card as proof. Every case here is a false-success that has
 * actually been produced by this tooling, not a hypothetical:
 *
 *   1. A "mobile" screenshot that is really a crop of the desktop layout.
 *   2. A CLS of 0 measured by an observer attached too late.
 *   3. A GIF reported as "43 frames" that is one 27907px-tall image.
 *   4. A Chrome that passes `--version` and cannot render a thing.
 *
 * Each is asserted against a real render of a fixture page served from this
 * file, so the test fails if the tooling regresses, not if the site changes.
 *
 * No network, no PHP, no app. ~20s.
 *
 * Usage: node ci/browser/selftest.mjs
 */
import { createServer } from 'node:http';
import { mkdtempSync, rmSync, readdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { launch, VIEWPORTS, observeCls, preflight, findChrome } from './launch.mjs';

const HERE = import.meta.dirname;
const WORK = mkdtempSync(join(process.env.TMPDIR ?? tmpdir(), 'browser-selftest-'));

let failed = 0;
const pass = (m) => console.log(`\x1b[32mPASS\x1b[0m  ${m}`);
const fail = (m) => { console.error(`\x1b[31mFAIL\x1b[0m  ${m}`); failed++; };
const check = (cond, m) => (cond ? pass(m) : fail(m));

// A fixture that is deliberately responsive AND deliberately shifts its layout
// late. The shift is what case 2 needs: a page that a late observer scores 0.
const FIXTURE = `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>fixture</title>
<style>
  body{margin:0;font-family:sans-serif}
  main{padding:1rem}
  /* Below 700px the banner stacks and turns crimson. A desktop *crop* keeps the
     wide layout, so the computed style is how we tell emulation from cropping. */
  #banner{background:#123456;color:#fff;padding:2rem;font-size:14px}
  @media (max-width:700px){#banner{background:#c81e3a;font-size:28px}}
  #filler{height:1200px}
  #late{height:0}
</style></head><body>
<main><div id="banner">banner</div><div id="late"></div><div id="filler">filler</div></main>
<script>
  // Injected after first paint: pushes everything down => a real layout shift.
  setTimeout(() => { document.getElementById('late').style.height = '300px'; }, 300);
</script></body></html>`;

const server = createServer((_req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(FIXTURE);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const BASE = `http://127.0.0.1:${server.address().port}/`;

// ---------------------------------------------------------------------------
// Case 4: preflight must reject a Chrome that cannot render.
//
// `--version` is not this check — it succeeds on a binary with no graphics
// stack. Rendering a DOM is. We assert the real binary both preflights AND
// produces a DOM, so the two cannot drift apart.
// ---------------------------------------------------------------------------
try {
  const { missingLibraries } = preflight();
  check(missingLibraries === 0, `preflight reports 0 missing shared libraries`);
} catch (e) {
  fail(`preflight threw: ${e.message}`);
}

const chrome = findChrome();
const dom = execFileSync('bash', ['-lc',
  `"${chrome}" --headless --no-sandbox --disable-gpu --dump-dom 'data:text/html,<h1>probe-ok</h1>' 2>/dev/null`,
], { encoding: 'utf8', env: (await import('./launch.mjs')).chromeEnv() });
check(dom.includes('probe-ok'), 'Chrome renders a real DOM, not just --version');

const browser = await launch();

// ---------------------------------------------------------------------------
// Case 1: device emulation, not a window size.
//
// The mobile viewport must produce a shot 720px wide (360 CSS px x DPR 2) AND
// the mobile branch of the media query. A --window-size crop gives 360px wide
// and the *desktop* computed style — that is the exact artifact that got
// mistaken for a broken responsive layout.
// ---------------------------------------------------------------------------
{
  const page = await browser.newPage();
  await page.setViewport(VIEWPORTS.mobile);
  await page.goto(BASE, { waitUntil: 'networkidle0' });

  const shot = join(WORK, 'mobile.png');
  await page.screenshot({ path: shot });

  const { default: sharp } = await import('sharp');
  const meta = await sharp(shot).metadata();
  check(meta.width === 720, `mobile capture is 720px wide (DPR 2 emulation), got ${meta.width}`);

  const styles = await page.evaluate(() => {
    const cs = getComputedStyle(document.getElementById('banner'));
    return { bg: cs.backgroundColor, size: cs.fontSize, isMobile: matchMedia('(max-width:700px)').matches };
  });
  check(styles.isMobile, 'mobile viewport actually matches the mobile media query');
  check(
    styles.bg.replace(/\s/g, '') === 'rgb(200,30,58)',
    `mobile renders the mobile branch (crimson), got ${styles.bg}`
  );
  await page.close();
}

{
  // The control: the same fixture at desktop must NOT match the mobile branch.
  // Without this, a test that always saw "mobile" would still pass above.
  const page = await browser.newPage();
  await page.setViewport(VIEWPORTS.desktop);
  await page.goto(BASE, { waitUntil: 'networkidle0' });
  const styles = await page.evaluate(() => {
    const cs = getComputedStyle(document.getElementById('banner'));
    return { bg: cs.backgroundColor, isMobile: matchMedia('(max-width:700px)').matches };
  });
  check(!styles.isMobile, 'desktop viewport does not match the mobile media query (control)');
  check(
    styles.bg.replace(/\s/g, '') === 'rgb(18,52,86)',
    `desktop renders the desktop branch, got ${styles.bg}`
  );
  await page.close();
}

// ---------------------------------------------------------------------------
// Case 2: CLS must be observed from before navigation.
//
// The fixture shifts at 300ms. observeCls (installed via evaluateOnNewDocument)
// must see it. An observer attached AFTER goto is the bug, and it reports 0 —
// so we run both and assert they disagree. If they ever agree, this test has
// stopped testing anything.
// ---------------------------------------------------------------------------
{
  const early = await browser.newPage();
  await observeCls(early);
  await early.goto(BASE, { waitUntil: 'networkidle0' });
  await new Promise((r) => setTimeout(r, 700));
  const earlyCls = await early.evaluate(() => Number((window.__cls ?? 0).toFixed(4)));
  await early.close();

  const late = await browser.newPage();
  await late.goto(BASE, { waitUntil: 'networkidle0' });
  // The wrong way: attach after the page has already loaded and shifted.
  await late.evaluate(() => {
    window.__lateCls = 0;
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) if (!e.hadRecentInput) window.__lateCls += e.value;
    }).observe({ type: 'layout-shift' });
  });
  await new Promise((r) => setTimeout(r, 700));
  const lateCls = await late.evaluate(() => Number((window.__lateCls ?? 0).toFixed(4)));
  await late.close();

  check(earlyCls > 0, `observeCls sees the fixture's layout shift (cls=${earlyCls})`);
  check(lateCls === 0, `an observer attached after goto misses it (cls=${lateCls}) — the bug this guards`);
}

await browser.close();

// ---------------------------------------------------------------------------
// Case 3: the GIF must actually be animated.
//
// gif.mjs re-reads the file and asserts the encoded page count. Feed it three
// distinct frames and confirm the output really carries three.
// ---------------------------------------------------------------------------
{
  const frames = join(WORK, 'frames');
  const { default: sharp } = await import('sharp');
  execFileSync('mkdir', ['-p', frames]);
  for (const [i, colour] of [[0, 255], [1, 128], [2, 0]]) {
    const buf = await sharp({
      create: { width: 80, height: 60, channels: 4, background: { r: colour, g: 40, b: 90, alpha: 1 } },
    }).png().toBuffer();
    writeFileSync(join(frames, `f00${i}.png`), buf);
  }

  check(readdirSync(frames).length === 3, 'fixture frames were written (setup)');

  const out = join(WORK, 'out.gif');
  execFileSync('node', [join(HERE, 'gif.mjs'), frames, out, '80', '100'], { encoding: 'utf8' });
  const encoded = (await sharp(out, { animated: true }).metadata()).pages;
  check(encoded === 3, `gif.mjs encodes 3 real frames (metadata reports ${encoded})`);

  // gif.mjs must FAIL LOUDLY on a truncated encode rather than print a count and
  // exit 0 — that exit code is what a caller and CI actually branch on. Feed it a
  // directory of one frame while claiming three by re-encoding, and assert the
  // guard is wired to the exit status, not only to stdout.
  const truncated = join(WORK, 'truncated.mjs');
  writeFileSync(
    truncated,
    (await import('node:fs')).readFileSync(join(HERE, 'gif.mjs'), 'utf8')
      .replace('for (const f of files) {', 'for (const f of files.slice(0, 1)) {')
  );
  let exitCode = 0;
  try {
    execFileSync('node', [truncated, frames, join(WORK, 'bad.gif'), '80', '100'], {
      encoding: 'utf8', stdio: 'pipe',
    });
  } catch (e) {
    exitCode = e.status;
  }
  check(exitCode === 1, `a truncated encode exits 1, not 0 (got ${exitCode})`);
}

server.close();
rmSync(WORK, { recursive: true, force: true });

console.log(failed === 0 ? '\nall browser tooling selftests passed' : `\n${failed} selftest(s) failed`);
process.exit(failed === 0 ? 0 : 1);
