// Are the baseline security headers actually on togetherweown.com — or did we just
// write them down?
//
// TOG-1157 asks for "HSTS, and the security headers that cost nothing". TOG-1161 is
// the operator card that sets them at the Cloudflare edge, because the zone is on our
// own Cloudflare and no agent here holds a credential that can write edge settings.
// That split is the whole reason this file exists: the change is made by hand, in a
// web panel, by someone who is not the person who asked for it. The only thing that
// closes that loop is a command anyone can run that answers yes or no.
//
//   node ci/security-headers-check.mjs              # table + PASS/FAIL, exit 1 on FAIL
//   node ci/security-headers-check.mjs --json       # same measurements, machine readable
//   node ci/security-headers-check.mjs --host x.com # point it somewhere else
//   node ci/security-headers-check.mjs --selftest   # no network; checks the parsers
//
// Deliberately NOT wired into ci.yml, for the same reason ci/live-seo-probe.mjs is not:
// it measures a third-party host (WordPress.com behind our Cloudflare) that no pull
// request can fix, so as a required check it would redden every PR for a defect the
// diff did not cause. A gate that is red for reasons outside the diff gets ignored,
// then deleted. It becomes a CI candidate on the day the apex is ours (TWO-61).
//
// The browser User-Agent and the shell-out to `curl` are both load-bearing. Cloudflare
// fingerprints the TLS ClientHello, so Node's fetch() gets a bot challenge where curl
// walks through — and a challenge page carries its OWN security headers, which is the
// specific trap this file has to avoid. Measuring Cloudflare's error page and reporting
// "headers present" would be worse than not checking at all. See the header comment in
// ci/live-seo-probe.mjs, and `assertNotChallenged` below.

import { execFileSync } from 'node:child_process';

const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

const BROWSER_ACCEPT =
  'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

const args = process.argv.slice(2);
const asJson = args.includes('--json');
const hostArg = args.indexOf('--host');
const HOST = hostArg !== -1 ? args[hostArg + 1] : 'togetherweown.com';
const ORIGIN = `https://${HOST}`;

// The twelve-month max-age TOG-1161 sets. Anything shorter is a weaker commitment
// than the one that was agreed, so it is a finding, not a rounding difference.
const MIN_HSTS_MAX_AGE = 31536000;

// -- pure helpers, all self-tested ------------------------------------------

// Header values arrive as an array per name, because duplicates are the failure mode
// this file is most likely to catch. Cloudflare's Transform Rules offer "set" and
// "add"; TOG-1161 specifies "set" precisely because Cloudflare's own challenge and
// error pages already emit some of these, and "add" would append a second copy. Two
// `x-frame-options` headers is not twice as safe — browsers disagree about which one
// wins, and some ignore both.
function headerValues(headers, name) {
  return headers[name.toLowerCase()] ?? [];
}

// Strict-Transport-Security is a directive list, not a string. Directive names are
// case-insensitive per RFC 6797, and order is not significant, so comparing the raw
// value against a literal would report a false failure for a header that is correct.
function parseHsts(value) {
  if (typeof value !== 'string') return null;

  const out = { maxAge: null, includeSubDomains: false, preload: false };

  for (const rawPart of value.split(';')) {
    const part = rawPart.trim();
    if (!part) continue;

    const eq = part.indexOf('=');
    const name = (eq === -1 ? part : part.slice(0, eq)).trim().toLowerCase();
    const val = eq === -1 ? null : part.slice(eq + 1).trim();

    // Match directives as whole tokens. A substring test would read the perfectly
    // ordinary `max-age` as containing nothing interesting but would also let a
    // typo'd `includesubdomain` (no trailing s) pass as the real directive.
    if (name === 'max-age' && val !== null) {
      const n = Number(val.replace(/^"|"$/g, ''));
      out.maxAge = Number.isFinite(n) ? n : null;
    } else if (name === 'includesubdomains') {
      out.includeSubDomains = true;
    } else if (name === 'preload') {
      out.preload = true;
    }
  }

  return out;
}

// Permissions-Policy is a set of `feature=(allowlist)` entries. Spacing after the
// comma and the order of features are both free, so this compares the parsed set
// rather than the string. `geolocation=()` and `geolocation=( )` mean the same thing.
function parsePermissionsPolicy(value) {
  if (typeof value !== 'string') return null;

  const out = {};
  // Split on commas that are not inside parentheses, so `camera=(self "https://a")`
  // survives intact if anyone ever adds an allowlist with multiple origins.
  let depth = 0;
  let current = '';
  const parts = [];
  for (const ch of value) {
    if (ch === '(') depth++;
    if (ch === ')') depth--;
    if (ch === ',' && depth === 0) {
      parts.push(current);
      current = '';
      continue;
    }
    current += ch;
  }
  parts.push(current);

  for (const rawPart of parts) {
    const part = rawPart.trim();
    if (!part) continue;
    const eq = part.indexOf('=');
    if (eq === -1) continue;
    const feature = part.slice(0, eq).trim().toLowerCase();
    const allowlist = part.slice(eq + 1).trim();
    // Normalise `()` and `( )` to the empty-allowlist form so they compare equal.
    out[feature] = allowlist.replace(/\s+/g, '') === '()' ? '()' : allowlist;
  }

  return out;
}

// CSP is a directive list separated by semicolons. We only assert `frame-ancestors`,
// which is a deliberate scope call recorded on TOG-1161: frame-ancestors cannot break
// page rendering, while a real default-src policy on this host would need
// 'unsafe-inline' (46 inline <script> blocks on the homepage) and buy almost nothing.
function cspDirective(value, directive) {
  if (typeof value !== 'string') return null;

  for (const rawPart of value.split(';')) {
    const part = rawPart.trim();
    if (!part) continue;
    const sp = part.search(/\s/);
    const name = (sp === -1 ? part : part.slice(0, sp)).toLowerCase();
    if (name === directive.toLowerCase()) {
      return (sp === -1 ? '' : part.slice(sp + 1).trim()).replace(/\s+/g, ' ');
    }
  }

  return null;
}

// -- network ----------------------------------------------------------------

function curlOnce(url) {
  const out = execFileSync(
    'curl',
    [
      '-sS',
      '-i',
      // Do not pin the HTTP version. Forcing --http1.1 changes the ALPN and TLS
      // fingerprint enough that Cloudflare challenges the request; measured on
      // ci/live-seo-probe.mjs, where it turned a working probe into a 403.
      '--max-time', '30',
      '-A', UA,
      '-H', `accept: ${BROWSER_ACCEPT}`,
      '-H', 'accept-language: en-US,en;q=0.9',
      url,
    ],
    { encoding: 'latin1', maxBuffer: 32 * 1024 * 1024 }
  );

  // h2 and h1.1 disagree about line endings; find whichever blank-line separator
  // comes first rather than assuming CRLF.
  const crlf = out.indexOf('\r\n\r\n');
  const lf = out.indexOf('\n\n');
  const split = crlf === -1 ? lf : lf === -1 ? crlf : Math.min(crlf, lf);
  const sepLen = split === crlf ? 4 : 2;

  const rawHead = split === -1 ? out : out.slice(0, split);
  const body = split === -1 ? '' : out.slice(split + sepLen);

  const lines = rawHead.split(/\r?\n/);
  const status = Number((lines[0].match(/^HTTP\/[\d.]+\s+(\d+)/) || [])[1] || 0);

  // Collect every occurrence, not the last one. See headerValues above.
  const headers = {};
  for (const line of lines.slice(1)) {
    const at = line.indexOf(':');
    if (at <= 0) continue;
    const name = line.slice(0, at).trim().toLowerCase();
    (headers[name] ??= []).push(line.slice(at + 1).trim());
  }

  return { status, headers, body };
}

// A Cloudflare challenge is not a measurement. Every check here is of the form "this
// header is present and correct" — and Cloudflare's challenge page sets several of
// them itself. So a challenged request does not merely measure nothing, it actively
// manufactures a pass for a site nobody looked at. Fatal for the whole run.
function assertNotChallenged(url, res) {
  const challenged =
    res.headers['cf-mitigated']?.[0] === 'challenge' ||
    (res.status === 403 && res.body.includes('<title>Just a moment...</title>'));

  if (!challenged) return;

  console.error(
    `\nCloudflare served a bot challenge for ${url}.\n\n` +
      'Its challenge page carries its own security headers, so continuing would report\n' +
      'these checks as passing against a page that is not our site. Aborting instead.\n\n' +
      'Usually means the UA at the top of this file has aged out, or this is running\n' +
      'somewhere Cloudflare rate-limits. Reproduce by hand:\n\n' +
      `  curl -sSI -A '${UA}' ${url}\n`
  );
  process.exit(2);
}

// -- main -------------------------------------------------------------------

function main() {
  const results = [];
  const check = (id, ok, detail) => results.push({ id, ok, detail });

  const res = curlOnce(ORIGIN + '/');
  assertNotChallenged(ORIGIN + '/', res);

  const measured = {};
  const NAMES = [
    'strict-transport-security',
    'x-content-type-options',
    'referrer-policy',
    'x-frame-options',
    'content-security-policy',
    'permissions-policy',
  ];
  for (const n of NAMES) measured[n] = headerValues(res.headers, n);

  // -- 0 --------------------------------------------------------------------
  // If the apex stopped answering 200 the rest of this file is measuring an error
  // page, and an error page's headers are not the site's headers.
  check(
    'apex-answers-200',
    res.status === 200,
    res.status === 200
      ? `${ORIGIN}/ -> 200`
      : `${ORIGIN}/ -> ${res.status}; every header check below is reading an error page`
  );

  // -- 1 --------------------------------------------------------------------
  // Duplicates. This is the specific defect "Add" instead of "Set" produces in a
  // Cloudflare Transform Rule, and it is invisible unless you look for it.
  const dupes = NAMES.filter((n) => measured[n].length > 1);
  check(
    'no-duplicate-headers',
    dupes.length === 0,
    dupes.length
      ? `sent twice: ${dupes.map((n) => `${n} (${measured[n].length}x)`).join(', ')} ` +
        '— the Transform Rule is using "add" where it must use "set"'
      : 'each security header is sent exactly once'
  );

  // -- 2 --------------------------------------------------------------------
  const hsts = parseHsts(measured['strict-transport-security'][0]);
  check(
    'hsts-max-age',
    hsts !== null && hsts.maxAge !== null && hsts.maxAge >= MIN_HSTS_MAX_AGE,
    hsts === null
      ? 'no strict-transport-security header at all'
      : hsts.maxAge === null
        ? `strict-transport-security has no max-age: "${measured['strict-transport-security'][0]}"`
        : `max-age=${hsts.maxAge}${hsts.maxAge >= MIN_HSTS_MAX_AGE ? '' : ` — below the agreed ${MIN_HSTS_MAX_AGE}`}`
  );

  // Step 1 of TOG-1161. This is the half that is NOT instantly reversible: browsers
  // that saw it honour it for up to max-age regardless of what we serve afterwards.
  check(
    'hsts-includesubdomains',
    hsts?.includeSubDomains === true,
    hsts?.includeSubDomains
      ? 'includeSubDomains is set'
      : 'includeSubDomains missing — TOG-1161 Step 1 (SSL/TLS -> Edge Certificates -> HSTS) has not been applied'
  );

  // preload is an owner decision, not an operator one: it is a submission to a list
  // baked into browsers, slow and painful to reverse, and a public commitment made in
  // the company's name. TOG-1161 says do not tick it. This check is here to catch it
  // being ticked by accident while someone is in that panel for Step 1.
  check(
    'hsts-preload-absent',
    hsts?.preload !== true,
    hsts?.preload
      ? 'preload IS set — this was not authorised (TOG-1161: owner decision, and reversing it is slow). Untick it in SSL/TLS -> Edge Certificates -> HSTS'
      : 'preload correctly absent'
  );

  // -- 3 --------------------------------------------------------------------
  // The four flat-value headers from Step 2.
  const EXPECTED = {
    'x-content-type-options': 'nosniff',
    'referrer-policy': 'strict-origin-when-cross-origin',
    'x-frame-options': 'SAMEORIGIN',
  };

  for (const [name, want] of Object.entries(EXPECTED)) {
    const got = measured[name][0];
    // Values here are case-insensitive tokens in practice; compare that way so a
    // `sameorigin` from the panel is not reported as a defect.
    const ok = typeof got === 'string' && got.trim().toLowerCase() === want.toLowerCase();
    check(
      name,
      ok,
      got === undefined
        ? `absent — expected "${want}"`
        : ok
          ? `${got}`
          : `"${got}" — expected "${want}"`
    );
  }

  // -- 4 --------------------------------------------------------------------
  const frameAncestors = cspDirective(measured['content-security-policy'][0], 'frame-ancestors');
  check(
    'csp-frame-ancestors',
    frameAncestors === "'self'",
    measured['content-security-policy'][0] === undefined
      ? "absent — expected content-security-policy: frame-ancestors 'self'"
      : frameAncestors === null
        ? `content-security-policy present but has no frame-ancestors directive: "${measured['content-security-policy'][0]}"`
        : frameAncestors === "'self'"
          ? "frame-ancestors 'self'"
          : `frame-ancestors ${frameAncestors} — expected 'self'`
  );

  // -- 5 --------------------------------------------------------------------
  // Exactly the three directives TOG-1161 specifies. fullscreen=() and autoplay=()
  // were deliberately excluded: the site embeds YouTube and Vimeo and those two
  // would break the players. If someone adds them "for completeness", the video
  // embeds break quietly and nobody connects it to a header — so flag them.
  const pp = parsePermissionsPolicy(measured['permissions-policy'][0]);
  const WANT_PP = ['geolocation', 'microphone', 'camera'];
  const missingPp = pp === null ? WANT_PP : WANT_PP.filter((f) => pp[f] !== '()');
  check(
    'permissions-policy',
    pp !== null && missingPp.length === 0,
    pp === null
      ? `absent — expected ${WANT_PP.map((f) => `${f}=()`).join(', ')}`
      : missingPp.length
        ? `missing or non-empty: ${missingPp.join(', ')} (got "${measured['permissions-policy'][0]}")`
        : `${WANT_PP.map((f) => `${f}=()`).join(', ')}`
  );

  const risky = pp ? ['fullscreen', 'autoplay'].filter((f) => pp[f] === '()') : [];
  check(
    'permissions-policy-keeps-video-working',
    risky.length === 0,
    risky.length
      ? `${risky.map((f) => `${f}=()`).join(', ')} present — this breaks the YouTube and Vimeo embeds. TOG-1161 excluded these on purpose`
      : 'no directive here disables the video embeds'
  );

  // -- report ---------------------------------------------------------------

  if (asJson) {
    console.log(
      JSON.stringify(
        { host: HOST, status: res.status, headers: measured, checks: results },
        null,
        2
      )
    );
  } else {
    const pad = (s, n) => String(s ?? '').padEnd(n).slice(0, n);
    console.log(`\nSecurity headers — ${ORIGIN}\n`);
    console.log(pad('HEADER', 32) + 'VALUE AS SERVED');
    console.log('-'.repeat(100));
    for (const n of NAMES) {
      const vals = measured[n];
      console.log(pad(n, 32) + (vals.length ? vals.join('  ||  ') : '(absent)'));
    }
    console.log('');
    for (const c of results) {
      console.log(`${c.ok ? 'PASS' : 'FAIL'}  ${pad(c.id, 38)} ${c.detail}`);
    }
    const failed = results.filter((c) => !c.ok).length;
    console.log(
      `\n${results.length - failed}/${results.length} checks pass` +
        (failed ? ` — ${failed} FAILING\n` : '\n')
    );
  }

  process.exit(results.some((c) => !c.ok) ? 1 : 0);
}

// Exercises the parsers against the shapes that actually cause false results, with no
// network, so it stays runnable when the host is down or challenging. Same reasoning
// as the --selftest in ci/live-seo-probe.mjs.
function selftest() {
  const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  const cases = [
    // HSTS: the live value today, and the value TOG-1161 asks for.
    ['bare max-age parses', parseHsts('max-age=31536000').maxAge, 31536000],
    ['bare max-age has no includeSubDomains', parseHsts('max-age=31536000').includeSubDomains, false],
    ['includeSubDomains is found', parseHsts('max-age=31536000; includeSubDomains').includeSubDomains, true],
    // Case and order are both free per RFC 6797. A literal string comparison — the
    // obvious implementation — reports a correct header as a failure here.
    ['includeSubDomains is case-insensitive', parseHsts('max-age=1; INCLUDESUBDOMAINS').includeSubDomains, true],
    ['directive order does not matter', parseHsts('includeSubDomains; max-age=31536000').maxAge, 31536000],
    ['whitespace does not matter', parseHsts('max-age=31536000 ;  includeSubDomains').includeSubDomains, true],
    // The trailing-s typo is the realistic hand-entry mistake, and it must NOT pass.
    ['includesubdomain (no s) is not a match', parseHsts('max-age=1; includesubdomain').includeSubDomains, false],
    ['preload is detected when present', parseHsts('max-age=1; preload').preload, true],
    ['preload is absent when not sent', parseHsts('max-age=1; includeSubDomains').preload, false],
    ['a quoted max-age still parses', parseHsts('max-age="31536000"').maxAge, 31536000],
    ['garbage max-age is null, not NaN', parseHsts('max-age=abc').maxAge, null],
    ['a missing header is null', parseHsts(undefined), null],

    // Permissions-Policy.
    ['permissions-policy parses the three directives', eq(parsePermissionsPolicy('geolocation=(), microphone=(), camera=()'), { geolocation: '()', microphone: '()', camera: '()' }), true],
    ['spacing after comma is not a difference', eq(parsePermissionsPolicy('geolocation=(),microphone=(),camera=()'), { geolocation: '()', microphone: '()', camera: '()' }), true],
    ['( ) normalises to ()', parsePermissionsPolicy('camera=( )').camera, '()'],
    ['a non-empty allowlist is preserved, not read as ()', parsePermissionsPolicy('camera=(self)').camera, '(self)'],
    // Commas inside parentheses must not split the entry, or a multi-origin allowlist
    // silently becomes two malformed features.
    ['commas inside parens do not split', eq(parsePermissionsPolicy('camera=(self "https://a.com"), geolocation=()'), { camera: '(self "https://a.com")', geolocation: '()' }), true],
    ['a missing header is null', parsePermissionsPolicy(undefined), null],

    // CSP.
    ['frame-ancestors is extracted', cspDirective("frame-ancestors 'self'", 'frame-ancestors'), "'self'"],
    ['frame-ancestors is found among other directives', cspDirective("default-src 'none'; frame-ancestors 'self'; img-src *", 'frame-ancestors'), "'self'"],
    ['a directive that is absent is null', cspDirective("default-src 'none'", 'frame-ancestors'), null],
    // `frame-ancestors` must not be matched by the prefix of another directive name.
    ['a prefix is not a match', cspDirective("frame-ancestors-foo 'self'", 'frame-ancestors'), null],
    ['inner whitespace is collapsed', cspDirective("frame-ancestors   'self'   https://a.com", 'frame-ancestors'), "'self' https://a.com"],
    ['a missing header is null', cspDirective(undefined, 'frame-ancestors'), null],

    // Duplicate detection depends on values being collected as arrays.
    ['headerValues returns every occurrence', eq(headerValues({ 'x-frame-options': ['SAMEORIGIN', 'DENY'] }, 'X-Frame-Options'), ['SAMEORIGIN', 'DENY']), true],
    ['headerValues is empty for an absent header', eq(headerValues({}, 'x-frame-options'), []), true],
  ];

  let bad = 0;
  for (const [name, actual, expected] of cases) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (!ok) bad++;
    console.log(
      `${ok ? 'ok  ' : 'FAIL'}  ${name}${ok ? '' : ` (got ${JSON.stringify(actual)}, want ${JSON.stringify(expected)})`}`
    );
  }
  console.log(`\n${cases.length - bad}/${cases.length} self-test cases pass\n`);
  process.exit(bad ? 1 : 0);
}

if (args.includes('--selftest')) selftest();
else main();
