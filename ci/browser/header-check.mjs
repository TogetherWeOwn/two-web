/**
 * Read the security headers a REAL browser session receives for a URL.
 *
 * curl is not sufficient evidence here, and neither is a naive puppeteer `goto`.
 * Cloudflare answers some clients with a bot challenge (`403`,
 * `cf-mitigated: challenge`) whose interstitial emits its OWN referrer-policy,
 * CSP and permissions-policy — values that read like a pass and are not ours.
 * TOG-1161 recorded exactly that false read from curl; default headless Chrome
 * hits the same wall from the other side. So this script:
 *
 *   1. launches with the automation fingerprint blunted and a real UA, then
 *   2. if it still lands on a challenge, waits for it to clear and re-navigates
 *      with the resulting cf_clearance cookie, and
 *   3. reports the challenge status alongside every header, so a pass on an
 *      interstitial can never be mistaken for a pass on the real page.
 *
 * Duplicate detection uses CDP `Network.responseReceivedExtraInfo.headers`,
 * which is the raw wire map: a header sent twice arrives as one entry whose
 * value is the two values joined by a newline. `response.headers()` folds them
 * away, so it cannot see the exact fault "Set, never Add" exists to prevent.
 *
 * Usage: node ci/browser/header-check.mjs <url> [url...]
 * Exit 0 only if every URL served a non-challenge response carrying each
 * expected header exactly once with the expected value.
 */
import { launch } from './launch.mjs';

const EXPECTED = {
  'strict-transport-security': 'max-age=31536000; includeSubDomains',
  'x-content-type-options': 'nosniff',
  'referrer-policy': 'strict-origin-when-cross-origin',
  'x-frame-options': 'SAMEORIGIN',
  'content-security-policy': "frame-ancestors 'self'",
  'permissions-policy': 'geolocation=(), microphone=(), camera=()',
};

const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

const urls = process.argv.slice(2);
if (!urls.length) {
  console.error('usage: node ci/browser/header-check.mjs <url> [url...]');
  process.exit(2);
}

/** Navigate once and capture the raw top-level response headers. */
async function fetchHeaders(page, url) {
  const client = await page.target().createCDPSession();
  await client.send('Network.enable');
  let raw = null;
  const onExtra = (e) => { if (raw === null) raw = e.headers; };
  client.on('Network.responseReceivedExtraInfo', onExtra);
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  client.off('Network.responseReceivedExtraInfo', onExtra);
  await client.detach().catch(() => {});
  return { raw: raw ?? {}, folded: resp?.headers() ?? {}, status: resp?.status() ?? null };
}

const browser = await launch();
let failed = false;

for (const url of urls) {
  const page = await browser.newPage();
  await page.setUserAgent(UA);

  console.log(`\n=== ${url}`);
  let r;
  try {
    r = await fetchHeaders(page, url);
  } catch (err) {
    console.log(`  NAVIGATION FAILED: ${err.message}`);
    failed = true;
    await page.close();
    continue;
  }

  // A managed challenge self-solves in a real Chrome. Give it time, then take
  // the headers from a fresh navigation carrying the clearance cookie.
  if (r.folded['cf-mitigated'] || r.status === 403) {
    console.log(`  first response: ${r.status} cf-mitigated=${r.folded['cf-mitigated'] ?? '-'} — waiting for the challenge to clear`);
    await new Promise((res) => setTimeout(res, 12000));
    try {
      r = await fetchHeaders(page, url);
    } catch (err) {
      console.log(`  RE-NAVIGATION FAILED: ${err.message}`);
      failed = true;
      await page.close();
      continue;
    }
  }

  const challenged = Boolean(r.folded['cf-mitigated']) || r.status === 403;
  console.log(`  status: ${r.status}${challenged ? '   <-- STILL CHALLENGED: values below are the interstitial\'s, not ours' : ''}`);
  if (challenged) failed = true;

  const lower = {};
  for (const [k, v] of Object.entries(r.raw)) lower[k.toLowerCase()] = v;

  for (const [name, want] of Object.entries(EXPECTED)) {
    const rawVal = lower[name];
    const values = rawVal === undefined ? [] : String(rawVal).split('\n').map((s) => s.trim());
    const n = values.length;
    const ok = !challenged && n === 1 && values[0] === want;
    if (!ok) failed = true;
    const shown = values.map((v) => (v.length > 70 ? `${v.slice(0, 70)}…` : v));
    console.log(`  ${ok ? 'PASS' : 'FAIL'} ${name.padEnd(28)} n=${n} ${JSON.stringify(shown)}`);
  }
  await page.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: PASS');
process.exit(failed ? 1 : 0);
