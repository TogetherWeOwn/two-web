// Cutover verification for retiring the WordPress install (TOG-85).
//
// The runbook on TOG-85 is a list of things to check at 11pm by someone who is
// tired. That is exactly the situation in which a checklist gets read as
// "yes, looks fine" — so every item on it that a machine can decide lives here
// instead, and the runbook points at this file.
//
// Run it twice, with the phase that says which side of the DNS flip you are on,
// because most of these checks invert at the flip:
//
//   node ci/cutover-check.mjs --phase before
//   node ci/cutover-check.mjs --phase after
//
// Optional, and worth passing before the flip — the new app's own origin, so
// the funnel can be checked where it lives before it is the thing answering
// the apex:
//
//   node ci/cutover-check.mjs --phase before --app https://two-web.fly.dev
//
// Exit status is 1 if anything FAILs, 0 otherwise. UNKNOWN never fails the run:
// see "What this cannot check" at the bottom, which is the honest part.
//
// ---------------------------------------------------------------------------
// Why every request shells out to curl instead of using fetch()
//
// The apex is behind Cloudflare, and Cloudflare fingerprints the TLS handshake.
// Node's fetch() is identifiable at any header set you give it, and the apex
// answers it differently from a browser — TOG-138 covers the same trap for the
// SEO probe. curl's handshake gets through, so curl is what we use. Do not
// "modernise" this to fetch(); it will pass locally and lie in the one place
// it matters.
//
// The browser User-Agent is not optional either: a plain curl UA gets a
// Cloudflare challenge on the apex, which reads as a broken site when it is
// not.

import { execFileSync } from 'node:child_process';
import { checkStagingAccess } from './staging-access.mjs';

const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';

const APEX = 'https://togetherweown.com';

// The Discord application the *WordPress* install holds credentials for. It is
// the thing being retired. `Owen` — the new bot, on the new app — is a
// different application, and the single most expensive mistake available on
// cutover night is rotating the wrong one. If /discord still points here after
// the flip, the flip did not take.
const OLD_APP_ID = '456483983870394368';
const OLD_CALLBACK = 'https://togetherweown.com/wp-json/two/v1/callback';

// The guild every join has to land in, and the invite the new app redirects to.
// Kept in sync with config/services.php `discord.invite_url` by
// ci/cutover-check-selftest.sh, which fails if the two drift apart.
const GUILD_ID = '326474832151838730';
const INVITE_CODE = '4GwEDNRTtx';

// The Atomic install's own hostname, and a hostname that is definitely not a
// site. WordPress.com answers an unbound *.wpcomstaging.com host with a 302 and
// a bound one with something else, so the control is what gives the check its
// meaning — without it, "not a 302" proves nothing about which site it is.
const ATOMIC_HOST = 'https://togetherweown.wpcomstaging.com';
const ATOMIC_CONTROL_HOST = 'https://zzq7x4nonexistent.wpcomstaging.com';

// A second WordPress.com property carrying the TWO name, found while verifying
// the first (site 228533449, `unlaunched`, noindex). It is not the install this
// issue retires and it holds no Discord credential — but it is the same brand
// on someone else's hosting, and "we switched WordPress off" is false while it
// is up. Surfaced so the decision is made rather than missed.
const OTHER_WPCOM_SITE = 'https://togetherweown.wordpress.com';

const PASS = 'PASS';
const FAIL = 'FAIL';
const UNKNOWN = 'UNKNOWN';

const results = [];

function record(status, name, detail) {
  results.push({ status, name, detail });
}

// A single request. Returns the status code, the Location header and the
// response headers/body, or `{ error }` when curl itself could not complete —
// a DNS failure and a 500 are different findings and must not collapse into
// one.
function request(url, { method = 'GET', maxTime = 25 } = {}) {
  const args = [
    '--silent',
    '--show-error',
    '--dump-header', '-',
    '--output', '-',
    '--write-out', '\n__STATUS__%{http_code} %{redirect_url} %{remote_ip}',
    '--max-time', String(maxTime),
    '--user-agent', UA,
    '--header', 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    '--request', method,
    url,
  ];

  let raw;
  try {
    raw = execFileSync('curl', args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
  } catch (error) {
    return { error: error.stderr?.trim() || error.message };
  }

  const marker = raw.lastIndexOf('\n__STATUS__');
  if (marker === -1) return { error: 'curl produced no status line' };

  const [status, redirect, remoteIp] = raw
    .slice(marker + '\n__STATUS__'.length)
    .trim()
    .split(' ');

  const payload = raw.slice(0, marker);
  // curl writes every header block it saw, including the ones from redirects it
  // did not follow. We never pass --location, so there is at most one block,
  // but split defensively rather than assume.
  const separator = payload.search(/\r?\n\r?\n/);
  const headers = separator === -1 ? payload : payload.slice(0, separator);
  const body = separator === -1 ? '' : payload.slice(separator).trimStart();

  return {
    status: Number(status),
    redirect: redirect && redirect !== '' ? redirect : null,
    remoteIp: remoteIp || null,
    headers: headers.toLowerCase(),
    body,
  };
}

function json(url) {
  const response = request(url);
  if (response.error) return response;
  try {
    return { ...response, data: JSON.parse(response.body) };
  } catch {
    return { ...response, data: null };
  }
}

// ---------------------------------------------------------------------------
// Which stack is answering the apex
//
// WordPress.com's Atomic hosting is unambiguous in the headers: `host-header:
// WordPress.com`, an `x-ac:` cache marker and a `link:` header advertising
// wp-json. We check the headers rather than the HTML because the apex serves a
// different homepage depending on the Accept header (TOG-138) and the headers
// do not vary that way.

function checkApexStack(phase) {
  const response = request(`${APEX}/`);
  if (response.error) {
    record(FAIL, 'apex-stack', `apex unreachable: ${response.error}`);
    return null;
  }

  const isWordPress =
    response.headers.includes('host-header: wordpress.com') ||
    response.headers.includes('rel="https://api.w.org/"');

  const stack = isWordPress ? 'WordPress.com' : 'not WordPress (new app, presumably)';
  const detail = `HTTP ${response.status}, serving: ${stack}`;

  if (phase === 'before') {
    // Before the flip WordPress is *supposed* to be there. Finding something
    // else means either the flip already happened or we are looking at the
    // wrong hostname; both are worth stopping for.
    record(isWordPress ? PASS : FAIL, 'apex-stack', detail);
  } else {
    record(isWordPress ? FAIL : PASS, 'apex-stack', detail);
  }
  return isWordPress;
}

// ---------------------------------------------------------------------------
// /discord — the only web-to-Discord conversion path TWO has
//
// This is the check the whole runbook exists to protect. It must answer, and
// after the flip it must answer from the new app rather than from the OAuth
// flow whose callback is about to stop existing.

function classifyDiscordTarget(target) {
  if (!target) return 'no redirect';
  if (target.includes(OLD_APP_ID) || target.includes('/wp-json/two/v1/')) {
    return 'old WordPress OAuth flow';
  }
  if (/discord\.(gg|com)\//.test(target)) return 'Discord invite';
  return 'somewhere else';
}

function checkFunnel(origin, label, phase) {
  const discord = request(`${origin}/discord`);
  if (discord.error) {
    record(FAIL, `${label}-discord`, `unreachable: ${discord.error}`);
  } else {
    const target = discord.redirect;
    const kind = classifyDiscordTarget(target);
    const detail = `HTTP ${discord.status} -> ${kind}${target ? ` (${target.slice(0, 120)})` : ''}`;

    if (discord.status < 300 || discord.status >= 400) {
      record(FAIL, `${label}-discord`, `not a redirect: ${detail}`);
    } else if (phase === 'after' && kind === 'old WordPress OAuth flow') {
      // The callback is on the apex. The moment the apex is the new app, this
      // flow dies at the callback — so this is not "stale", it is an outage.
      record(FAIL, `${label}-discord`, `still the retired flow, whose callback no longer exists: ${detail}`);
    } else {
      record(PASS, `${label}-discord`, detail);
    }
  }

  const join = request(`${origin}/join`);
  if (join.error) {
    record(FAIL, `${label}-join`, `unreachable: ${join.error}`);
  } else if (join.status >= 300 && join.status < 400) {
    record(PASS, `${label}-join`, `HTTP ${join.status} -> ${join.redirect}`);
  } else if (
    phase === 'after' &&
    join.status === 200 &&
    join.body.includes('data-testid="one-click-join"') &&
    join.body.includes('data-testid="invite-link"')
  ) {
    // TOG-80 replaced the temporary redirect with a real one-click join page.
    // Require both the primary OAuth action and its raw-invite fallback: a 200
    // alone could still be the old WordPress soft 404 this check was written to
    // catch.
    record(PASS, `${label}-join`, 'HTTP 200, one-click join and invite fallback rendered');
  } else {
    // /join is in the wild in stream titles and DMs. On the WordPress install
    // it lands on a soft 404, which is the status quo we are replacing, so
    // before the flip this is a note and not a failure.
    record(phase === 'before' ? UNKNOWN : FAIL, `${label}-join`, `HTTP ${join.status}, no working join journey detected`);
  }
}

// ---------------------------------------------------------------------------
// The invite the new app hands out has to be live
//
// Discord's invite endpoint needs no credentials, so this is checkable from
// anywhere at any time. An invite that has expired or been revoked turns
// /discord into a redirect to an error page, which looks fine to every check
// that only asserts "302".

function checkInvite() {
  const response = json(
    `https://discord.com/api/v10/invites/${INVITE_CODE}?with_counts=true`,
  );

  if (response.error) {
    record(FAIL, 'invite-live', `Discord unreachable: ${response.error}`);
    return;
  }
  if (response.status !== 200 || !response.data) {
    record(FAIL, 'invite-live', `invite ${INVITE_CODE} does not resolve (HTTP ${response.status})`);
    return;
  }

  const guild = response.data.guild?.id;
  if (guild !== GUILD_ID) {
    record(FAIL, 'invite-live', `invite resolves to guild ${guild}, expected ${GUILD_ID}`);
    return;
  }
  if (response.data.expires_at) {
    record(FAIL, 'invite-live', `invite expires at ${response.data.expires_at} — the funnel has a deadline on it`);
    return;
  }

  record(PASS, 'invite-live', `${INVITE_CODE} -> guild ${guild} (${response.data.guild?.name}), no expiry`);
}

// ---------------------------------------------------------------------------
// Step 4: switching it off at Cloudflare is not switching it off
//
// The Atomic install answers on its own WordPress.com hostname, on an
// Automattic IP, with no Cloudflare in front. Removing the apex DNS record
// hides it from togetherweown.com and leaves an unpatched WordPress running
// that nobody is logging into. This check is what stops "DNS moved" being
// mistaken for "install retired".

function checkAtomicStillUp() {
  const control = request(ATOMIC_CONTROL_HOST);
  const site = request(ATOMIC_HOST);

  if (control.error || site.error) {
    record(UNKNOWN, 'atomic-host-off', `could not probe: ${control.error || site.error}`);
    return;
  }

  // An unbound host gets WordPress.com's 302. Anything else means the hostname
  // is bound to a real site — including the 403 it currently returns.
  const controlIsRedirect = control.status >= 300 && control.status < 400;
  if (!controlIsRedirect) {
    // The control moved. We can no longer tell bound from unbound, so say so
    // rather than report a result the control no longer supports.
    record(
      UNKNOWN,
      'atomic-host-off',
      `control host returned HTTP ${control.status}, expected a 3xx — probe can no longer distinguish bound from unbound`,
    );
    return;
  }

  const stillBound = !(site.status >= 300 && site.status < 400);
  record(
    stillBound ? FAIL : PASS,
    'atomic-host-off',
    stillBound
      ? `${ATOMIC_HOST} still answers (HTTP ${site.status} from ${site.remoteIp}, control ${control.status}) — the install is reachable off-CDN`
      : `${ATOMIC_HOST} behaves like an unbound host (HTTP ${site.status}, same as control)`,
  );
}

// The apex must stop serving the retired plugin's REST namespace. This is the
// positive confirmation that the apex is no longer the WordPress install,
// independent of the header fingerprint above.
function checkWpJsonGone() {
  const response = json(`${APEX}/wp-json/`);
  if (response.error) {
    record(UNKNOWN, 'apex-wp-json-gone', `could not probe: ${response.error}`);
    return;
  }
  const namespaces = response.data?.namespaces ?? [];
  const hasPlugin = namespaces.includes('two/v1');
  const served = response.status === 200 && namespaces.length > 0;

  record(
    served ? FAIL : PASS,
    'apex-wp-json-gone',
    served
      ? `apex still serves wp-json (HTTP ${response.status}, ${namespaces.length} namespaces, two/v1 ${hasPlugin ? 'present' : 'absent'})`
      : `apex no longer serves a WordPress REST root (HTTP ${response.status})`,
  );
}

// Staging is an intentional, separate Coolify application now. The cutover gate
// must prove that the record resolves, remains behind Cloudflare Access and
// returns the staging-only noindex header; a stale NXDOMAIN assertion would reject
// the secured state and encourage deleting the application hostname. Keep this
// delegated to the same implementation as
// ci/staging-exposure-check.mjs so the cutover and standalone gates cannot drift.
function checkStagingRecord() {
  for (const result of checkStagingAccess()) {
    record(result.status, result.name, result.detail);
  }
}

function checkOtherWpcomSite() {
  const response = request(`${OTHER_WPCOM_SITE}/`);
  if (response.error) {
    record(UNKNOWN, 'other-wpcom-site', `could not probe: ${response.error}`);
    return;
  }
  const live = response.status === 200;
  record(
    live ? UNKNOWN : PASS,
    'other-wpcom-site',
    live
      ? `${OTHER_WPCOM_SITE} still serves HTTP 200 — a second WordPress.com property under the TWO name, not covered by retiring the Atomic install`
      : `HTTP ${response.status}`,
  );
}

// ---------------------------------------------------------------------------
// The apex must not carry TOG-71's soft-404/canonical/robots defects forward
//
// Board decision, 2026-08-27: "carry the live SEO, canonical, soft-404,
// robots.txt and Discord-unfurl checks into the cutover acceptance checklist."
// This is that decision, in code. Until it was written the checklist could go
// green on cutover night without ever looking at the thing TOG-71 is about.
//
// It shells out to ci/live-seo-probe.mjs rather than reimplementing any of it.
// That script already measures every one of those checks, already carries the
// Cloudflare/Accept-header handling (TOG-138), and has a 12-case selftest. Two
// implementations of "is this page indexable" would drift, and the copy that
// drifts is the one nobody runs.
//
// Deliberately `after`-only, and pointed at the origin that is about to answer
// the apex. The WordPress install FAILS this probe today — that is the open
// defect, not a regression to gate on — so running it in `before` would wire a
// known-red check into the pre-flip run and train whoever is reading at 11pm to
// ignore a red line. The question at cutover is "does the new site reproduce
// TOG-71", and that is answerable only against the new site.
function checkSeo(origin) {
  const target = new URL(origin).host;
  let raw;
  try {
    raw = execFileSync(
      'node',
      [new URL('live-seo-probe.mjs', import.meta.url).pathname, '--host', target, '--json'],
      { encoding: 'utf8', timeout: 180_000, maxBuffer: 32 * 1024 * 1024 },
    );
  } catch (error) {
    // The probe exits 1 when a check fails, which is the normal failing path
    // and still prints its JSON on stdout. Only treat it as unusable when
    // nothing parseable came back.
    raw = error.stdout;
    if (!raw) {
      record(UNKNOWN, 'seo-no-regression', `could not run live-seo-probe: ${error.message}`);
      return;
    }
  }

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch {
    record(UNKNOWN, 'seo-no-regression', 'live-seo-probe did not return parseable JSON');
    return;
  }
  const checks = parsed.checks ?? [];
  if (checks.length === 0) {
    record(UNKNOWN, 'seo-no-regression', 'live-seo-probe returned no checks');
    return;
  }

  // Reachability gate, before any per-check line is printed.
  //
  // Several of the probe's checks are assertions over the set of pages it
  // managed to fetch — "every advertised page is its own canonical" and the
  // like. When the host answers nothing at all that set is empty, and an
  // assertion over an empty set is vacuously true: measured against a dead
  // origin the probe emits four green lines. The run still fails overall, but
  // "PASS self-canonical" against a site that is not up is a lie, and this
  // output is read at 11pm by someone tired. Refuse to report it.
  const measured = parsed.measured ?? [];
  const reachable = measured.filter((m) => Number(m.status) > 0).length;
  if (measured.length > 0 && reachable === 0) {
    record(
      UNKNOWN,
      'seo-no-regression',
      `${target}: nothing answered — all ${measured.length} URLs failed to fetch, so the SEO checks have nothing to measure. Not a pass and not a regression; the origin is down or wrong.`,
    );
    return;
  }

  // Each probe check is reported as its own line. A single rolled-up PASS/FAIL
  // would tell the person reading the runbook that "SEO" is broken without
  // saying which of five different things to go and fix.
  const failed = checks.filter((c) => !c.ok);
  for (const c of checks) {
    record(c.ok ? PASS : FAIL, `seo-${c.id}`, c.detail);
  }
  record(
    failed.length === 0 ? PASS : FAIL,
    'seo-no-regression',
    failed.length === 0
      ? `${target}: all ${checks.length} live SEO checks pass`
      : `${target}: ${failed.length}/${checks.length} live SEO checks FAIL (${failed
          .map((c) => c.id)
          .join(', ')}) — this is TOG-71 carried onto the new site`,
  );
}

// ---------------------------------------------------------------------------

function main() {
  const argv = process.argv.slice(2);
  const phaseIndex = argv.indexOf('--phase');
  const phase = phaseIndex === -1 ? 'before' : argv[phaseIndex + 1];
  const appIndex = argv.indexOf('--app');
  const app = appIndex === -1 ? null : argv[appIndex + 1];

  if (phase !== 'before' && phase !== 'after') {
    console.error('usage: node ci/cutover-check.mjs --phase <before|after> [--app <origin>]');
    process.exit(2);
  }

  console.log(`cutover-check: phase "${phase}"${app ? `, new app at ${app}` : ''}\n`);

  checkApexStack(phase);
  checkFunnel(APEX, 'apex', phase);
  if (app) checkFunnel(app, 'app', 'after');
  checkInvite();

  if (phase === 'after') {
    checkWpJsonGone();
    checkAtomicStillUp();
    checkStagingRecord();
    checkOtherWpcomSite();
    // Against the app's own origin when one was named, otherwise the apex,
    // which after the flip is the new site anyway.
    checkSeo(app ?? APEX);
  }

  const width = Math.max(...results.map((r) => r.name.length));
  for (const { status, name, detail } of results) {
    console.log(`  ${status.padEnd(7)} ${name.padEnd(width)}  ${detail}`);
  }

  // -------------------------------------------------------------------------
  // What this cannot check, stated every run so it is never mistaken for green
  //
  // Step 3 of the runbook — the credential rotation — is the security step, and
  // none of it is verifiable from outside the Discord developer console:
  //
  //   3a  bot token reset      no unauthenticated endpoint reports token age
  //   3b  client secret reset  same
  //   3c  redirect URI removed  measured 2026-08-25: discord.com/api/oauth2/
  //       authorize returns an identical 302 to the login page for a valid
  //       redirect_uri, a bogus one, AND a nonexistent client_id. Validation
  //       happens after login, in the SPA. There is no credential-free probe,
  //       and a check that always passes is worse than no check at all.
  //
  // The WordPress.com plan state and billing are console-only too. All of these
  // need a human to confirm in writing; the runbook names who.
  //
  // The seo-* checks are `after`-only by design (see checkSeo). They say the new
  // site is clean; they say nothing about the WordPress install's own live
  // defects, which are TOG-71 and are fixed in wp-admin, not here. To see those,
  // run `node ci/live-seo-probe.mjs` directly against the apex.
  const failures = results.filter((r) => r.status === FAIL);
  const unknowns = results.filter((r) => r.status === UNKNOWN);

  console.log(
    `\n  ${results.length} checks: ${results.length - failures.length - unknowns.length} pass, ` +
      `${failures.length} fail, ${unknowns.length} unknown`,
  );
  console.log(
    '  Not checkable from here: bot token rotation (3a), client secret rotation (3b),\n' +
      '  redirect URI removal (3c), WordPress.com plan state. These need the console.',
  );

  process.exit(failures.length > 0 ? 1 : 0);
}

main();
