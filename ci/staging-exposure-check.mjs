// Is `staging.togetherweown.com` walled off by a control we own — or just broken?
//
// TOG-1156. TOG-59 requires staging to be "noindex and behind basic auth". Today
// the hostname answers 403 from WordPress.com because its domain connection there
// has lapsed. That satisfies "never appears in a search result" by accident. The
// moment anyone repairs that connection, staging is public with nothing in front
// of it. This script is the difference between those two states, so nobody has to
// re-litigate it by eyeballing curl output at 11pm.
//
//   node ci/staging-exposure-check.mjs
//
// Exit 0 when staging is walled off by a control we own (401 + X-Robots-Tag), or
// when the name does not resolve at all. Exit 1 when it is exposed, and exit 1
// when it is only "safe" by accident — because accidental safety is the finding.
//
// Requests shell out to curl with a browser User-Agent for the same reason
// ci/cutover-check.mjs does: Cloudflare fingerprints the TLS handshake and
// answers Node's fetch() with a challenge, which reads as a broken site when it
// is not. Do not "modernise" this. See that file's header.

import { execFileSync } from 'node:child_process';

const HOST = 'staging.togetherweown.com';
const APEX = 'togetherweown.com';

const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';

const PASS = 'PASS';
const FAIL = 'FAIL';
const UNKNOWN = 'UNKNOWN';

const results = [];
const record = (status, name, detail) => {
  results.push({ status, name, detail });
};

// getent prints one line per (address, socktype) pair, so every address comes
// back three times. Dedupe, or three addresses read like nine.
function resolve(host) {
  try {
    const addresses = execFileSync('getent', ['ahostsv4', host], { encoding: 'utf8' })
      .split('\n')
      .map((line) => line.trim().split(/\s+/)[0])
      .filter(Boolean);
    return [...new Set(addresses)].sort();
  } catch {
    return []; // getent exits 2 on NXDOMAIN
  }
}

function head(url, { user } = {}) {
  const args = ['-sS', '-o', '/dev/null', '-D', '-', '--max-time', '25', '-A', UA];
  if (user) args.push('--user', user);
  args.push(url);
  try {
    const raw = execFileSync('curl', args, { encoding: 'utf8' });
    const lines = raw.split('\n').map((l) => l.trim()).filter(Boolean);
    const statusLine = lines.find((l) => l.startsWith('HTTP/')) ?? '';
    const status = Number(statusLine.split(/\s+/)[1]);
    const headers = {};
    for (const line of lines) {
      const i = line.indexOf(':');
      if (i < 1 || line.startsWith('HTTP/')) continue;
      headers[line.slice(0, i).toLowerCase()] = line.slice(i + 1).trim();
    }
    return { status, statusLine, headers };
  } catch (error) {
    return { error: error.message };
  }
}

// A name that certainly has no record. If this resolves there is a wildcard, and
// deleting the `staging` record would achieve nothing — everything downstream of
// that choice changes, so it is checked first rather than assumed.
function checkNoWildcard() {
  const probe = resolve('nonexistent-probe-tog1156.togetherweown.com');
  record(
    probe.length === 0 ? PASS : FAIL,
    'no-wildcard',
    probe.length === 0
      ? 'a nonexistent subdomain is NXDOMAIN — no wildcard record'
      : `a nonexistent subdomain resolves (${probe.join(', ')}) — there is a wildcard, so deleting the staging record would not remove the name`,
  );
  return probe.length === 0;
}

// Proxied records in one Cloudflare zone all resolve to the same anycast
// addresses, so "staging shares the apex's IPs" proves nothing about whether it
// has a record. Absence of a wildcard does: if staging resolves and a random
// sibling does not, something explicit is publishing `staging`.
function checkStagingHasOwnRecord(noWildcard) {
  const staging = resolve(HOST);
  const apex = resolve(APEX);
  if (staging.length === 0) {
    record(
      PASS,
      'staging-record-exists',
      `${HOST} is NXDOMAIN — the name is gone, which is the strongest control available`,
    );
    return false;
  }
  if (!noWildcard) {
    record(
      UNKNOWN,
      'staging-record-exists',
      `${HOST} resolves (${staging.join(', ')}) but a wildcard is present, so the record cannot be attributed`,
    );
    return true;
  }
  const sharesApex = apex.length > 0 && staging.every((ip) => apex.includes(ip));
  record(
    FAIL,
    'staging-record-exists',
    `${HOST} resolves to ${staging.join(', ')} via an explicit record${
      sharesApex
        ? ' (same anycast IPs as the apex, which is expected for any proxied record in this zone and is NOT evidence it lacks a record)'
        : ''
    }`,
  );
  return true;
}

// The requirement from TOG-59, stated as two measurements: a challenge without
// credentials, and noindex on that challenge. Both must come from us.
function checkWalledOff() {
  const response = head(`https://${HOST}/`);
  if (response.error) {
    record(UNKNOWN, 'staging-auth', `could not probe: ${response.error}`);
    return;
  }
  const { status, headers } = response;
  const challenge = headers['www-authenticate'];
  const robots = headers['x-robots-tag'];

  record(
    status === 401 && challenge ? PASS : FAIL,
    'staging-basic-auth',
    status === 401 && challenge
      ? `HTTP 401 with www-authenticate: ${challenge}`
      : `HTTP ${status} with no www-authenticate challenge — not behind basic auth`,
  );

  record(
    robots && /noindex/i.test(robots) ? PASS : FAIL,
    'staging-noindex-header',
    robots ? `x-robots-tag: ${robots}` : 'no x-robots-tag on the response',
  );

  // The distinction this whole card exists to draw. A 403 from WordPress.com's
  // "Active domain connection for this domain not found" is not a control we
  // own; it is the absence of a site. It stops being protection the moment
  // anyone repairs the connection, and nobody would tell us.
  const upstream = headers['x-ac'] ?? headers['server-timing'] ?? '';
  const accidental = status === 403 && /a8c|_dca|_atomic/.test(upstream);
  record(
    accidental ? FAIL : PASS,
    'protection-is-ours',
    accidental
      ? `HTTP 403 is coming from WordPress.com (${upstream}), not from a control we own — staging is unreachable by accident, and becomes public the moment that connection is repaired`
      : 'response is not an upstream WordPress.com domain-connection error',
  );
}

const noWildcard = checkNoWildcard();
const present = checkStagingHasOwnRecord(noWildcard);
if (present) checkWalledOff();

let failed = 0;
for (const { status, name, detail } of results) {
  if (status === FAIL) failed += 1;
  console.log(`${status.padEnd(7)} ${name.padEnd(24)} ${detail}`);
}
console.log(`\n${results.length} checks, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
