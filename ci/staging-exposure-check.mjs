// Prove that the intentional staging hostname is reachable only through the
// Cloudflare Access control we own (TOG-1284).
//
//   CF_ACCESS_CLIENT_ID=... CF_ACCESS_CLIENT_SECRET=... \
//     node ci/staging-exposure-check.mjs
//
// Exit 0 only when all of these are true:
//   - the zone has no wildcard and both apex/staging resolve over IPv4 and IPv6;
//   - an unauthenticated GET is redirected to *.cloudflareaccess.com;
//   - the Access service token reaches staging's /up endpoint with HTTP 200;
//   - that authenticated response has exactly X-Robots-Tag: noindex, nofollow.
//
// Token values are passed to curl on stdin through --config -, never in argv and
// never in output. The shared implementation also powers cutover-check.mjs so
// the two security gates cannot drift.

import { FAIL, checkStagingAccess } from './staging-access.mjs';

const results = checkStagingAccess();
let failed = 0;
for (const { status, name, detail } of results) {
  if (status === FAIL) failed += 1;
  console.log(`${status.padEnd(7)} ${name.padEnd(27)} ${detail}`);
}
console.log(`\n${results.length} checks, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
