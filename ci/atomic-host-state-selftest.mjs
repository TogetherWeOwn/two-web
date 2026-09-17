// Offline cover for the Atomic retirement gate's classification.
//
// `atomic-host-off` is the only automated evidence that the WordPress install
// is retired, and its PASS arm is unreachable in the live check until the day
// someone actually deletes the site — which is exactly the day nobody wants to
// discover the arm was wrong. Every arm is exercised here against fixtures: the
// real pre-action capture on one side, a synthetic unbound response on the
// other.
//
// Run: node ci/atomic-host-state-selftest.mjs

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  BOUND_NO_SITE,
  INSTALL_SERVING,
  RETIRED,
  UNRECOGNISED,
  classifyAtomicHost,
} from './atomic-host-state.mjs';

const HOST = 'https://togetherweown.wpcomstaging.com';

// Captured 2026-09-17 from the live, still-billing site 228642218 with the
// neutral probe UA: HTTP 403, 3,108 bytes, 192.0.78.20.
const captured403 = readFileSync(
  fileURLToPath(new URL('./atomic-host-state-fixture-403.html', import.meta.url)),
  'utf8',
);

// What WordPress.com answers for a hostname that is not a site on the same
// wildcard — measured the same day against zzq7x4nonexistent.wpcomstaging.com,
// and the only response that proves the site record is gone.
const unbound = {
  status: 302,
  redirect: 'https://wordpress.com/typo/?subdomain=togetherweown',
  remoteIp: '192.0.78.20',
  headers: '',
  body: '',
};
const control = { ...unbound, redirect: 'https://wordpress.com/typo/?subdomain=zzq7x4nonexistent' };

const classify = (site) => classifyAtomicHost({ site, control, host: HOST });

// 1-3. Today's state, read from the bytes the host actually returned.
const today = classify({
  status: 403,
  redirect: null,
  remoteIp: '192.0.78.20',
  headers: 'http/2 403\r\ncontent-type: text/html; charset=utf-8',
  body: captured403,
});
assert.equal(today.state, BOUND_NO_SITE);
assert.equal(today.retired, false);
console.log('ok  the live pre-action 403 reads as a bound site record with nothing serving');

// The defect this file exists for: the old detail called this state "the
// install is reachable off-CDN", which argued for deleting the site.
assert.equal(/reachable off-CDN/.test(today.detail), false);
console.log('ok  the FAIL detail no longer claims a reachable install');

// And the claim was not merely unproven, it was false — there is no install
// content in those 3,108 bytes at all.
assert.equal(/wp-content|wp-includes|api\.w\.org/.test(captured403), false);
console.log('ok  the captured 403 body carries no install content to be reachable');

// 4. The arm the whole gate exists to reach. Unreachable live until the site is
// deleted, so if it is ever wrong this is the only place that says so.
const retired = classify(unbound);
assert.equal(retired.state, RETIRED);
assert.equal(retired.retired, true);
console.log('ok  an unbound 3xx matching the control is the one state that PASSes');

// 5. The state the old wording described, which we have never measured.
const serving = classify({
  status: 200,
  redirect: null,
  remoteIp: '192.0.78.20',
  headers: 'http/2 200\r\nlink: <https://togetherweown.wpcomstaging.com/wp-json/>; rel="https://api.w.org/"',
  body: '<html><head><link rel="stylesheet" href="/wp-content/themes/two/style.css"></head></html>',
});
assert.equal(serving.state, INSTALL_SERVING);
assert.equal(serving.retired, false);
assert.equal(/reachable off-CDN/.test(serving.detail), true);
console.log('ok  a 2xx serving install content is the only state called reachable off-CDN');

// 6. The classification is keyed on status *and* body. Mutate the body away and
// the same 403 must stop being the domain-connection state — otherwise "403"
// alone would be doing all the work and the fixture would prove nothing.
const challenged403 = classify({
  status: 403,
  redirect: null,
  remoteIp: '192.0.78.20',
  headers: 'http/2 403',
  body: '<html><body>Attention Required! | Cloudflare</body></html>',
});
assert.equal(challenged403.state, UNRECOGNISED);
console.log('ok  a 403 without the domain-connection page is not mistaken for it');

// 7. Fails closed. Anything measured that is not the unbound 3xx is "not
// retired" — a gate whose PASS authorises calling the install gone must never
// reach PASS by accident.
const upstreamError = classify({
  status: 500,
  redirect: null,
  remoteIp: '192.0.78.20',
  headers: 'http/2 500',
  body: 'upstream error',
});
assert.equal(upstreamError.state, UNRECOGNISED);
assert.equal(upstreamError.retired, false);
console.log('ok  an unrecognised response fails closed instead of passing or going UNKNOWN');

// 8. Across every fixture above, exactly one classifies as retired.
const all = [today, retired, serving, challenged403, upstreamError];
assert.equal(all.filter((item) => item.retired).length, 1);
console.log('ok  exactly one of the five fixtures is a PASS');

console.log('\n8/8 ok');
