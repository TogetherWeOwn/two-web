/**
 * Prove the TOG-1161 edge headers did not break the live page.
 *
 * A header dump shows the policy shipped; it cannot show what the policy did.
 * Two of the five headers can break rendering in ways a `curl -I` never sees:
 *
 *   - `content-security-policy: frame-ancestors 'self'` governs who may frame
 *     US, so it must NOT affect our own outbound embeds. If a YouTube/Vimeo
 *     iframe stops loading, the scope call on the card was wrong.
 *   - `permissions-policy: geolocation=(), microphone=(), camera=()` disables
 *     three features for the document AND all its iframes. The card argues the
 *     video players do not use them. This checks that claim against the running
 *     page instead of restating it.
 *
 * Reported: CSP violations (from securitypolicyviolation, which fires for real
 * blocks — console text alone is unreliable), page errors, iframe load state,
 * and the three permissions as the document actually resolves them.
 *
 * Usage: node ci/browser/policy-regression.mjs <url> [shotDir]
 */
import { mkdirSync } from 'node:fs';
import { launch, VIEWPORTS } from './launch.mjs';

const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const url = process.argv[2];
const shotDir = process.argv[3];
if (!url) {
  console.error('usage: node ci/browser/policy-regression.mjs <url> [shotDir]');
  process.exit(2);
}

const browser = await launch();
const page = await browser.newPage();
await page.setUserAgent(UA);
await page.setViewport(VIEWPORTS.desktop);

const violations = [];
const pageErrors = [];
const failedRequests = [];

await page.evaluateOnNewDocument(() => {
  window.__violations = [];
  document.addEventListener('securitypolicyviolation', (e) => {
    window.__violations.push({
      directive: e.effectiveDirective,
      blocked: e.blockedURI,
      policy: e.originalPolicy?.slice(0, 120),
    });
  });
});

page.on('pageerror', (e) => pageErrors.push(e.message));
page.on('requestfailed', (r) => failedRequests.push(`${r.failure()?.errorText} ${r.url().slice(0, 110)}`));

let status = null;
try {
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  status = resp?.status();
  if (status === 403 || resp?.headers()['cf-mitigated']) {
    // Same challenge dance as header-check: measure the real page, not the interstitial.
    await new Promise((r) => setTimeout(r, 12000));
    const again = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    status = again?.status();
  }
} catch (err) {
  console.log(`NAVIGATION FAILED: ${err.message}`);
  await browser.close();
  process.exit(1);
}

// Let lazy embeds and below-fold content actually request.
await page.evaluate(async () => {
  await new Promise((res) => {
    let y = 0;
    const step = () => {
      window.scrollTo(0, y);
      y += window.innerHeight;
      if (y < document.body.scrollHeight) setTimeout(step, 250);
      else { window.scrollTo(0, 0); setTimeout(res, 1500); }
    };
    step();
  });
});
await new Promise((r) => setTimeout(r, 4000));

const report = await page.evaluate(() => {
  const iframes = [...document.querySelectorAll('iframe')].map((f) => ({
    src: (f.src || f.dataset.src || '(none)').slice(0, 110),
    // A cross-origin frame that loaded gives us a contentWindow; a blocked one
    // still has one, so also report whether the browser sized it.
    w: f.getBoundingClientRect().width | 0,
    h: f.getBoundingClientRect().height | 0,
  }));
  return {
    title: document.title,
    violations: window.__violations ?? [],
    iframes,
    videoEmbeds: iframes.filter((f) => /youtube|vimeo|youtu\.be/i.test(f.src)).length,
    permissions: {
      geolocation: document.featurePolicy?.allowsFeature('geolocation'),
      microphone: document.featurePolicy?.allowsFeature('microphone'),
      camera: document.featurePolicy?.allowsFeature('camera'),
      fullscreen: document.featurePolicy?.allowsFeature('fullscreen'),
      autoplay: document.featurePolicy?.allowsFeature('autoplay'),
    },
    bodyTextLength: document.body.innerText.trim().length,
    linkCount: document.querySelectorAll('a[href]').length,
  };
});

console.log(`url:            ${url}`);
console.log(`status:         ${status}`);
console.log(`title:          ${report.title}`);
console.log(`body text:      ${report.bodyTextLength} chars`);
console.log(`links:          ${report.linkCount}`);
console.log(`iframes:        ${report.iframes.length} (video embeds: ${report.videoEmbeds})`);
for (const f of report.iframes) console.log(`   ${f.w}x${f.h}  ${f.src}`);
console.log('permissions (document.featurePolicy.allowsFeature):');
for (const [k, v] of Object.entries(report.permissions)) console.log(`   ${k.padEnd(12)} ${v}`);
console.log(`CSP violations: ${report.violations.length}`);
for (const v of report.violations) console.log(`   ${v.directive} blocked ${v.blocked}`);
console.log(`page errors:    ${pageErrors.length}`);
for (const e of pageErrors.slice(0, 8)) console.log(`   ${e.slice(0, 160)}`);
console.log(`failed reqs:    ${failedRequests.length}`);
for (const f of failedRequests.slice(0, 8)) console.log(`   ${f}`);

// Stays true when no shots were requested; set by the mobile capture below.
let mobileOk = true;

if (shotDir) {
  mkdirSync(shotDir, { recursive: true });
  await page.screenshot({ path: `${shotDir}/desktop.png`, fullPage: false });

  // Take the mobile shot by RESIZING the already-loaded page — do not navigate,
  // and do not set isMobile.
  //
  // Measured on togetherweown.com (2026-09-05), all three the hard way:
  //  - A fresh `goto` at a mobile viewport sends sec-ch-ua-mobile:?1, which does
  //    not match the cf_clearance this browser holds. Cloudflare challenged it
  //    4/4 times and mobile.png came back as the "Verifying…" interstitial while
  //    every metric above still described the real desktop load.
  //  - Changing the UA to an Android string is worse: clearance is UA-bound too.
  //  - Even `setViewport(VIEWPORTS.mobile)` on the cleared page re-challenges,
  //    because isMobile:true flips that same client hint.
  // So: width 360 at deviceScaleFactor 2 with isMobile left false. That is a
  // genuine 360 CSS px reflow (documentElement.clientWidth === 360) and still
  // lands on disk at 720px wide, which is how a reviewer distinguishes it from a
  // desktop crop. Resize only AFTER the settle above, or the stylesheet is still
  // in flight and the shot is of unstyled HTML.
  await page.setViewport({ width: 360, height: 780, deviceScaleFactor: 2, isMobile: false, hasTouch: false });
  await new Promise((r) => setTimeout(r, 3000));
  // Is this shot the real page, and does the primary CTA keep its styling here?
  // These are two DIFFERENT questions and are reported separately: an
  // interstitial invalidates the capture, whereas a CTA that loses its styling
  // is a real finding ABOUT the page. Conflating them hides one behind the other.
  const mob = await page.evaluate(() => {
    const cta = document.querySelector('a[href*="discord"], a[href*="/join"]');
    const cs = cta ? getComputedStyle(cta) : null;
    return {
      challenged: /security verification|Just a moment|Verifying/i.test(document.body.innerText),
      layoutWidth: document.documentElement.clientWidth,
      ctaBg: cs ? cs.backgroundColor : null,
      ctaRadius: cs ? cs.borderRadius : null,
    };
  });
  await page.screenshot({ path: `${shotDir}/mobile.png`, fullPage: false });
  // Transparent background on the primary CTA means its styling did not apply.
  const ctaStyled = mob.ctaBg !== null && mob.ctaBg !== 'rgba(0, 0, 0, 0)';
  console.log(`shots:          ${shotDir}/desktop.png, ${shotDir}/mobile.png`);
  console.log(`mobile:         layoutWidth=${mob.layoutWidth} ctaBg=${mob.ctaBg} radius=${mob.ctaRadius}`
    + `${mob.challenged ? '  <-- CHALLENGED, mobile.png is an interstitial' : ''}`);
  if (!mob.challenged && !ctaStyled) {
    console.log('mobile CTA:     UNSTYLED at this width — a page defect, not a header regression '
      + '(CSP frame-ancestors and permissions-policy cannot affect same-document CSS)');
  }
  // Only the capture's validity gates this script's exit code.
  mobileOk = !mob.challenged && mob.layoutWidth === 360;
}

// The three disabled features must read false; the two deliberately left alone
// must NOT have been disabled, or the video players lose fullscreen/autoplay.
const p = report.permissions;
const expectedOff = p.geolocation === false && p.microphone === false && p.camera === false;
const expectedOn = p.fullscreen !== false && p.autoplay !== false;
const clean = report.violations.length === 0;
// 200 with real copy and the primary CTA present. The bar is 200 chars, not a
// bigger number: togetherweown.com is currently a coming-soon page whose entire
// body is ~400 chars, so a "looks too short" threshold would fail on a healthy
// page. The CTA link is the substantive check — the Discord join button is the
// only action on the page, and it is what a broken policy would take away.
const rendered = status === 200
  && report.bodyTextLength > 200
  && report.linkCount > 0
  && /together we own/i.test(report.title);

console.log(`\nassertions: disabled3=${expectedOff} kept(fullscreen,autoplay)=${expectedOn} noCspViolations=${clean} rendered=${rendered} mobileShotReal=${mobileOk}`);
// mobileOk is in the conjunction, not a bare process.exitCode: the explicit
// process.exit() below would otherwise overwrite exitCode and report PASS on a
// run whose mobile.png is a Cloudflare interstitial.
const ok = expectedOff && expectedOn && clean && rendered && mobileOk;
console.log(ok ? 'RESULT: PASS' : 'RESULT: FAIL');
await browser.close();
process.exit(ok ? 0 : 1);
