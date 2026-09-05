/**
 * Is the homepage's primary CTA a button at every width a visitor can have?
 *
 * TOG-1163: on the live apex it was not. The whole hero — the dark band, the
 * gradient wordmark, the badge, the feature row and the Discord button — sat
 * inside `@media (min-width: 1279px)`. Below that, the page fell back to the
 * browser default stylesheet: white background, serif type, and the only action
 * on the page rendered as 16px-tall blue link text. Every phone got that.
 *
 * Why this is a script and not a paragraph in a runbook
 * -----------------------------------------------------
 * The bug is a number (1279) compared against another number (the viewport),
 * and the answer differs on either side of it by one pixel. That is exactly the
 * kind of check a model re-improvises differently every time, so it is pinned
 * here: same input, same output, forever.
 *
 * The two traps this encodes, both paid for once
 * ----------------------------------------------
 * 1. **Cloudflare's interstitial reads as "unstyled".** The apex is behind CF.
 *    Resizing a loaded page, or navigating with `isMobile: true`, flips the
 *    `sec-ch-ua-mobile` client hint, which does not match the `cf_clearance`
 *    the browser holds. CF then answers 403 with "Just a moment…", the hero is
 *    absent, and the CTA measures transparent/0px at EVERY width — including
 *    1280, where it is demonstrably fine. A challenged load and a broken page
 *    are indistinguishable unless you check, so every row here is guarded by a
 *    title/body check and reported as CHALLENGED, never silently as UNSTYLED.
 *    `--file` exists for the same reason: it takes CF out of the loop entirely.
 * 2. **A "styled" check on background alone is too weak.** A CTA can have a
 *    background and still be a 16px-tall tap target. This asserts background
 *    AND radius AND the 44px minimum from WCAG 2.5.5.
 *
 * Usage:
 *   node ci/browser/cta-breakpoints.mjs                          # the live apex
 *   node ci/browser/cta-breakpoints.mjs --url https://host/
 *   node ci/browser/cta-breakpoints.mjs --file page.html         # served bytes, offline
 *   node ci/browser/cta-breakpoints.mjs --shots DIR              # also write PNGs
 *   node ci/browser/cta-breakpoints.mjs --url https://host/ --navigate   # opt out of curl
 *
 * How `--url` gets the document, and why it is not a Chrome navigation
 * --------------------------------------------------------------------
 * It was, and the check was unrunnable because of it: against the live apex
 * every one of the six widths came back HTTP 403 CHALLENGED, so the script
 * exited 2 and could neither confirm nor deny the fix. That is trap 1 above
 * biting the tool that documents it. Cloudflare challenges *headless Chrome*,
 * not the request: on the same host, in the same second, `curl` with a browser
 * UA returns 200 and the full 129,783-byte document, three times out of three,
 * under both a phone and a desktop UA, byte-identical each time.
 *
 * So `--url` now fetches the HTML with curl and measures those bytes through
 * the same path `--file` uses. This is not a workaround that weakens the
 * check — the document IS the subject, the hero rules are inline in it, and
 * the external sheets still load from the real origin, so the cascade is the
 * visitor's. `--navigate` keeps the old behaviour for a host that is not
 * behind CF, where a real navigation is strictly better.
 *
 * `--file` reads the DOCUMENT from disk and still fetches its stylesheets from
 * their real origin, so the cascade is the one a visitor gets. Only the HTML is
 * challenged by CF; static assets are not. The apex's hero rules live in an
 * inline block that is byte-identical under a mobile and a desktop UA
 * (sha256 16bb0402…, 2520 bytes), so a saved copy is a faithful subject. The
 * script prints how many external sheets it loaded — if that number is 0 on a
 * page you expected to have some, the measurement is not faithful and says so.
 *
 * Exit codes:
 *   0  the CTA is a real button at every width measured
 *   1  at least one width is UNSTYLED or below the tap-target floor — a defect
 *   2  no width could be measured (all challenged, or the CTA is absent)
 */
import { launch } from './launch.mjs';
import { mkdirSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { resolve, join } from 'node:path';
import { tmpdir } from 'node:os';

const argv = process.argv.slice(2);
const arg = (name, fallback = null) => {
  const i = argv.indexOf(name);
  return i === -1 ? fallback : argv[i + 1];
};
const flag = (name) => argv.includes(name);

/**
 * Pull the document with curl and hand back a local path plus the HTTP status.
 *
 * Throws rather than returning a partial page: a challenge body measures as
 * "unstyled at every width", which is exactly the false FAIL this whole script
 * exists to avoid. A non-200, or a 200 that does not contain the CTA selector's
 * literal class, is not a subject we can measure.
 */
function fetchWithCurl(target, ua) {
  const dir = mkdtempSync(join(tmpdir(), 'cta-breakpoints-'));
  const out = join(dir, 'document.html');
  // -L: a host may canonicalise (http->https, /page -> /page/). Without it a
  // legitimate 301 reads as "unfetchable" and the check reports exit 2 on a
  // page that is perfectly measurable. %{http_code} is the FINAL hop's status.
  const status = execFileSync('curl', [
    '-sL', '-A', ua, '-o', out, '-w', '%{http_code}', '--max-time', '30', target,
  ], { encoding: 'utf8' }).trim();
  return { path: out, status: Number(status) };
}

// A real browser UA. The default curl UA gets a Cloudflare 403 on this host —
// that 403 is the bot challenge, not an authz answer, and reading it as "no
// access" has cost this company a wrong conclusion before.
const CURL_UA = arg('--ua',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 ' +
  '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');

const fileArg = arg('--file');
const urlArg = arg('--url', 'https://togetherweown.com/');
const navigate = flag('--navigate');

// Resolve the subject. Three modes collapse to two code paths: anything that
// ends up as a local document is measured identically, whether it came off disk
// or off the wire a second ago.
let file = fileArg;
let fetchedStatus = null;
if (!file && !navigate) {
  const got = fetchWithCurl(urlArg, CURL_UA);
  if (got.status !== 200) {
    console.log(`\nRESULT: could not fetch ${urlArg} — HTTP ${got.status}.`);
    console.log('Nothing was measured. Retry, or pass --file with a saved copy.');
    process.exit(2);
  }
  file = got.path;
  fetchedStatus = got.status;
  console.log(`fetched ${urlArg} via curl — HTTP ${got.status}\n`);
}

const url = file ? 'file://' + resolve(file) : urlArg;
const shotDir = arg('--shots');
// The CTA, named the way the page names it, with a generic fallback so this
// keeps working after the WordPress page is replaced by the Laravel one.
const SELECTOR = arg('--selector', '.cs-discord, a[href*="discord"], a[href*="/join"]');

// The widths that matter: a small phone, a common phone, a tablet, and both
// sides of the 1279px boundary. 1278/1279 is the pair that localises the cutoff
// to the exact pixel — without them "mobile is broken" is a guess about where.
const WIDTHS = [
  ['360', 360, 780, 2],
  ['390', 390, 844, 3],
  ['768', 768, 1024, 2],
  ['1278', 1278, 900, 1],
  ['1279', 1279, 900, 1],
  ['1280', 1280, 900, 1],
];

const TAP_MIN = 44; // WCAG 2.5.5 / Apple HIG minimum touch target, in CSS px.

if (shotDir) mkdirSync(shotDir, { recursive: true });

const browser = await launch();
const rows = [];

for (const [label, w, h, dpr] of WIDTHS) {
  const page = await browser.newPage();
  // isMobile is deliberately left FALSE even for phone widths. It is not
  // cosmetic: it flips sec-ch-ua-mobile and re-triggers the CF challenge (see
  // the header). Width at deviceScaleFactor is a genuine CSS-pixel reflow —
  // documentElement.clientWidth below proves it — and the shot still lands on
  // disk at w*dpr, which is how a reviewer tells it from a desktop crop.
  await page.setViewport({ width: w, height: h, deviceScaleFactor: dpr, isMobile: false });
  if (file) {
    // Let the page's own <link rel=stylesheet> hrefs load from their real
    // origin. They are absolute URLs to static assets, which Cloudflare serves
    // without a challenge — only the HTML document is challenged, and that is
    // the part we are reading from disk. Blocking them instead would drop the
    // Bricks button base styles and measure a cascade no visitor ever gets.
    // Images and scripts are still blocked: they cannot affect computed style
    // and they dominate the wall clock.
    await page.setRequestInterception(true);
    page.on('request', (r) => {
      const t = r.resourceType();
      if (r.url().startsWith('file://') || t === 'stylesheet' || t === 'font') return r.continue();
      return r.abort();
    });
  }

  let status = 0;
  try {
    const resp = await page.goto(url, { waitUntil: file ? 'domcontentloaded' : 'networkidle2', timeout: 60000 });
    status = resp ? resp.status() : 0;
  } catch (e) {
    console.log(`${label.padEnd(5)} | navigation failed: ${e.message}`);
    await page.close();
    rows.push({ label, w, verdict: 'ERROR' });
    continue;
  }

  const m = await page.evaluate((sel) => {
    const challenged = /security verification|Just a moment|Verifying/i.test(document.body.innerText || '');
    const el = document.querySelector(sel);
    const out = {
      challenged,
      present: !!el,
      layoutWidth: document.documentElement.clientWidth,
      externalSheets: [...document.querySelectorAll('link[rel~="stylesheet"]')].length,
    };
    if (!el) return out;
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const hero = el.closest('section') || document.querySelector('.cs-hero');
    return Object.assign(out, {
      text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 40),
      backgroundColor: cs.backgroundColor,
      borderRadius: cs.borderRadius,
      padding: cs.padding,
      color: cs.color,
      width: Math.round(r.width),
      height: Math.round(r.height),
      heroBg: hero ? getComputedStyle(hero).backgroundColor : null,
    });
  }, SELECTOR);

  if (shotDir) {
    await page.screenshot({ path: `${shotDir}/page-${label}.png` });
    const el = await page.$(SELECTOR);
    if (el) await el.screenshot({ path: `${shotDir}/cta-${label}.png` });
  }
  await page.close();

  // Three separate questions, reported separately. An interstitial invalidates
  // the measurement; a missing CTA is a different defect from an unstyled one.
  const hasBg = m.backgroundColor && m.backgroundColor !== 'rgba(0, 0, 0, 0)';
  const hasRadius = parseFloat(m.borderRadius) > 0;
  const tapOk = m.height >= TAP_MIN;
  const verdict = m.challenged ? 'CHALLENGED'
    : !m.present ? 'CTA-ABSENT'
    : hasBg && hasRadius && tapOk ? 'OK'
    : 'UNSTYLED';

  rows.push({ label, w, status, verdict, hasBg, hasRadius, tapOk, ...m });
  console.log(
    `${label.padEnd(5)} | HTTP ${String(status).padEnd(3)} | layout ${String(m.layoutWidth).padEnd(4)} | ` +
    `${String(m.backgroundColor ?? '-').padEnd(17)} | r ${String(m.borderRadius ?? '-').padEnd(5)} | ` +
    `${String(m.height ?? '-').padEnd(3)}px | ${verdict}` +
    (verdict === 'UNSTYLED' && !tapOk ? `  [tap target ${m.height}px < ${TAP_MIN}px]` : '')
  );
}

const measured = rows.filter((r) => r.verdict === 'OK' || r.verdict === 'UNSTYLED');
const bad = rows.filter((r) => r.verdict === 'UNSTYLED' || r.verdict === 'CTA-ABSENT');
const challenged = rows.filter((r) => r.verdict === 'CHALLENGED');

console.log(`\nsource:      ${fetchedStatus ? `${urlArg}  (curl HTTP ${fetchedStatus}, measured locally)` : url}`);
if (rows[0]?.externalSheets !== undefined)
  console.log(`external stylesheets: ${rows[0].externalSheets} loaded from origin`);
console.log(`measured:    ${measured.length}/${WIDTHS.length}` + (challenged.length ? `  (${challenged.length} challenged — rerun, or use --file)` : ''));
if (shotDir) console.log(`shots:       ${shotDir}`);
if (shotDir) writeFileSync(`${shotDir}/cta-breakpoints.json`, JSON.stringify({ url, rows }, null, 2));

await browser.close();

if (!measured.length) {
  console.log('\nRESULT: nothing could be measured. Every load was challenged or the CTA was absent.');
  process.exit(2);
}
if (bad.length) {
  console.log(`\nRESULT: FAIL — the CTA is not a button at ${bad.map((r) => r.w + 'px').join(', ')}.`);
  process.exit(1);
}
console.log(`\nRESULT: PASS — the CTA is a button with a >=${TAP_MIN}px tap target at every width measured.`);
