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
// Because UNKNOWN does not fail the run, it is only ever correct for "the
// measurement did not happen" — never for "the measurement happened and I did
// not recognise the answer". A check that returns UNKNOWN on an unanticipated
// response has silently become optional. See classifyAtomicHost, where that
// distinction is enforced by a self-test rather than left to reviewers.
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
import { readFileSync } from 'node:fs';
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

// The browser UA above is mandatory for the apex, and actively harmful here.
// WordPress.com fingerprints the UA string on *.wpcomstaging.com and answers a
// challenge page to some browsers, which turns the control's 302 into a 403 and
// collapses the discriminator — both hosts then look identical and the check
// can only report UNKNOWN.
//
// Measured 2026-09-17 against the control host, 3 trials each, deterministic:
//
//   Chrome/127 on macOS (the UA above)  -> 302   Chrome/140 on Linux -> 403
//   curl/8.5.0                          -> 302   no UA at all        -> 302
//   two-web-cutover-check/1.0           -> 302
//
// So the only thing keeping this check decisive is that the pinned browser UA
// happens to be one WordPress.com does not challenge — and "bump the Chrome
// version" is exactly the kind of tidy-up someone does without running this.
// Probe with a neutral tool UA first, and keep the browser UA as a fallback in
// case the rule ever inverts. ci/cutover-check-selftest.sh fails if neither
// agent yields a usable control.
const ATOMIC_PROBE_AGENTS = ['two-web-cutover-check/1.0 (+https://togetherweown.com)', UA];

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
function request(url, { method = 'GET', maxTime = 25, userAgent = UA } = {}) {
  const args = [
    '--silent',
    '--show-error',
    '--dump-header', '-',
    '--output', '-',
    '--write-out', '\n__STATUS__%{http_code} %{redirect_url} %{remote_ip}',
    '--max-time', String(maxTime),
    '--user-agent', userAgent,
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
//
// It used to decide that on the status code alone: the predicate was `not a
// 3xx`, and the line it printed read "the install is reachable off-CDN".
// Measured 2026-09-17, the host answers 403 with 3.1 kB of WordPress.com's
// *domain-connection error* page and zero install content — so the sentence
// beside the verdict described a served WordPress that had never been shown,
// and that sentence argues for deleting the site, which is irreversible. A 500,
// a WAF challenge and a parked hostname all trip the same predicate and all got
// the same wrong sentence. The verdict was right and stays right; only the
// explanation was wrong (TOG-3178).
//
// So: three states, keyed on status *and* body, plus an unknown that is reserved
// for a broken *measurement* and never used for a response we simply did not
// anticipate.
//
//   3xx to the same place the control goes   PASS     hostname unbound
//   403 + the domain-connection error page   FAIL     bound, nothing serving
//   2xx carrying install markup              FAIL     install reachable off-CDN
//   anything else measurable                 FAIL     not retired, and unclassified
//   probe or control broken                  UNKNOWN  cannot measure at all
//
// The last two lines are the correction that came out of review of the first cut
// of this change, and they matter more than they look. `main()` exits
// `failures.length > 0 ? 1 : 0` and UNKNOWN is excluded from that count, so an
// UNKNOWN on a retirement gate is a *pass* as far as the build is concerned. The
// first cut classified a 500, a challenge page, a bare 403 and a 3xx pointing
// somewhere else as UNKNOWN — which means an unanticipated response would have
// let the gate go quiet, the exact defect shape this file exists to fix.
//
// The dividing line is "could I measure it", not "did I recognise it". If both
// probes answered and the control still redirects, then the measurement worked
// and the honest verdict is FAIL: the host is not demonstrably retired. What
// changes between the FAIL arms is only the sentence — none of them claims a
// reachable install except the one that measured install markup.
//
// PASS is reachable only by matching the live control, so no amount of the site
// being merely broken turns this green. `--selftest` exercises every arm,
// including that one, against a committed capture of the live 403.

// WordPress.com's "this hostname reaches us but no site is connected to it"
// page. `x_graceful=missingdomain` is Automattic's own name for the condition,
// in the tracking pixel the page embeds; the title is what a human reads.
// Either is enough — they have no reason to move together.
const DOMAIN_CONNECTION_ERROR_MARKERS = [
  'active domain connection for this domain not found',
  'x_graceful=missingdomain',
];

// Markup only a rendering WordPress emits. Body only, and deliberately none of
// them a bare `wp-`: the error page above is served by WordPress.com's edge and
// itself links wp-login.php inside a `wp-die-message` div, so a looser marker
// would read "no site here" as "the install is up".
const INSTALL_CONTENT_MARKERS = ['/wp-content/', '/wp-includes/', 'api.w.org', 'wp-json'];

const isRedirect = (status) => status >= 300 && status < 400;

const markersIn = (body, markers) => {
  const haystack = (body ?? '').toLowerCase();
  return markers.filter((marker) => haystack.includes(marker));
};

// Where a redirect points, ignoring the query string. WordPress.com sends an
// unbound host to `https://wordpress.com/typo/?subdomain=<that host>`, so the
// query differs between the control and the host under test by construction and
// the comparison has to be on origin+path. Comparing against the *live* control
// rather than a hardcoded URL is what keeps this true if Automattic moves that
// page: both hosts move together.
function redirectShape(target) {
  if (!target) return null;
  try {
    const url = new URL(target);
    return `${url.origin}${url.pathname}`;
  } catch {
    return null;
  }
}

// Pure, so it can be tested without the network — which is the point. The state
// that must PASS has never once been observed live, so `--selftest` is the only
// thing that ever exercises that arm. Without it the check is a rubber stamp
// for FAIL and nobody would notice it had stopped being able to say anything
// else.
//
// Returns UNKNOWN only from the two guards below — a failed probe and a control
// that has stopped discriminating. Past them, every return is PASS or FAIL, and
// `--selftest` asserts that directly rather than leaving it to be read off the
// control flow.
export function classifyAtomicHost(site, control) {
  if (control.error || site.error) {
    return { status: UNKNOWN, detail: `could not probe: ${control.error || site.error}` };
  }

  // An unbound host gets WordPress.com's 302. Without a control that still
  // demonstrates that, no status code off the real host means anything — so the
  // control moving takes the probe out of service rather than changing its
  // answer.
  if (!isRedirect(control.status)) {
    return {
      status: UNKNOWN,
      detail: `control host returned HTTP ${control.status}, expected a 3xx — probe can no longer distinguish bound from unbound`,
    };
  }

  const body = site.body ?? '';
  const seen =
    `HTTP ${site.status} from ${site.remoteIp ?? 'an unknown IP'}, ` +
    `${body.length} bytes of body, control ${control.status}`;

  if (isRedirect(site.status)) {
    const shape = redirectShape(site.redirect);
    const controlShape = redirectShape(control.redirect);
    if (shape !== null && shape === controlShape) {
      return {
        status: PASS,
        detail: `${ATOMIC_HOST} behaves like an unbound host (${seen}) — redirects to ${shape}, the same place the control goes`,
      };
    }
    // A bound site redirecting to its own new home is also a 3xx. "It moved" is
    // not "it is gone", so this is FAIL — not retired — and the sentence says
    // only what was measured.
    return {
      status: FAIL,
      detail:
        `${ATOMIC_HOST} redirects to ${site.redirect ?? 'somewhere it did not name'} (${seen}), ` +
        `not to the control's ${controlShape ?? 'unreadable target'} — a 3xx elsewhere is not ` +
        `proof the hostname is unbound, so it is not retired`,
    };
  }

  if (site.status === 403 && markersIn(body, DOMAIN_CONNECTION_ERROR_MARKERS).length > 0) {
    return {
      status: FAIL,
      detail:
        `${ATOMIC_HOST} is bound, no active site serving it (${seen}, WordPress.com ` +
        `domain-connection error page carrying no install content) — retirement not provable ` +
        `from outside; console evidence required`,
    };
  }

  const installMarkers = markersIn(body, INSTALL_CONTENT_MARKERS);
  if (site.status >= 200 && site.status < 300 && installMarkers.length > 0) {
    return {
      status: FAIL,
      detail:
        `${ATOMIC_HOST} still answers (${seen}, serving ${installMarkers.join(' ')}) — ` +
        `the install is reachable off-CDN`,
    };
  }

  // Measured, and not any of the states above. The measurement worked, so this
  // is an answer and not a gap: the host is not demonstrably retired, which on a
  // retirement gate is FAIL. It deliberately does not guess what the response
  // was — a 500, a challenge page and a parked hostname all land here and the
  // only honest thing to say is the status, the size and "go look".
  return {
    status: FAIL,
    detail:
      `${ATOMIC_HOST} answered ${seen} — not an unbound redirect, not WordPress.com's ` +
      `domain-connection error, and carrying no install markup. Unclassified, and therefore ` +
      `not retired: read it before acting on it`,
  };
}

function checkAtomicStillUp() {
  // An unbound host gets WordPress.com's 302. Anything else means the hostname
  // is bound to a real site — including the 403 it currently returns. Walk the
  // agents until one produces a usable control; a challenged agent answers 403
  // to every hostname and would make the two indistinguishable.
  let control;
  let site;
  const attempts = [];

  for (const userAgent of ATOMIC_PROBE_AGENTS) {
    control = request(ATOMIC_CONTROL_HOST, { userAgent });
    site = request(ATOMIC_HOST, { userAgent });

    if (control.error || site.error) {
      // Clear the control before continuing. Leaving the error object in place
      // makes the `!control` test below pass on the last iteration, and the
      // check then reads `site.status` off an error — reporting FAIL "still
      // answers (HTTP undefined from undefined)" for a probe that never
      // completed. An outage must stay UNKNOWN, as it was before the agent walk.
      attempts.push(`${userAgent}: ${control.error || site.error}`);
      control = undefined;
      continue;
    }
    if (control.status >= 300 && control.status < 400) break;

    attempts.push(`${userAgent}: control HTTP ${control.status}`);
    control = undefined;
  }

  if (!control) {
    // Every agent was challenged or errored. We can no longer tell bound from
    // unbound, so say so rather than report a result the control cannot support.
    record(
      UNKNOWN,
      'atomic-host-off',
      `no probe agent produced a 3xx control, so bound and unbound are indistinguishable (${attempts.join('; ')})`,
    );
    return;
  }

  // Past the agent walk the control is known-3xx and both probes completed, so
  // the classifier's own two UNKNOWN guards cannot fire here. They are not dead
  // code: `--selftest` drives them directly, and the classifier is exported and
  // callable without a network. Everything below is status + body, never status
  // alone — the defect this replaced (TOG-3178).
  const { status, detail } = classifyAtomicHost(site, control);
  record(status, 'atomic-host-off', detail);
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
// The check on the atomic-host check
//
//   node ci/cutover-check.mjs --selftest     (no network, milliseconds)
//
// Two things are being defended here, and only one of them is the classifier.
//
// 1. The PASS arm. The live host has never produced the unbound response, and
//    by the time it does this check has one job left in its life: saying so.
//    An arm that has never executed is an arm that may not work, so it is
//    executed here against a synthetic capture built on the live control.
//
// 2. The relaxation proposed on TOG-1269 and rejected on TOG-3178 — "accept a
//    403 serving no TWO content as retired". Run against the untouched,
//    still-billing site it returns *retired*: it was green before anyone
//    opened the console, which makes it not a gate. That is easy to re-propose
//    from prose on a card and impossible to re-propose past a failing test, so
//    the vacuity is pinned below rather than described. This is the harness
//    TOG-3178 asked to be committed instead of trusted.
const CAPTURE_FIXTURE = 'fixtures/atomic-host-capture-2026-09-17.json';

// The rejected predicate, kept executable. If anyone adopts it, case
// "relaxation-is-vacuous" is what tells them what it costs.
function proposedRelaxationSaysRetired(site) {
  return site.status === 403 && markersIn(site.body, INSTALL_CONTENT_MARKERS).length === 0;
}

function selftest() {
  const captured = JSON.parse(
    readFileSync(new URL(CAPTURE_FIXTURE, import.meta.url), 'utf8'),
  );
  const { site: preActionSite, control: liveControl } = captured;

  // The live 403, untouched and still billing on the day it was captured.
  const preAction = classifyAtomicHost(preActionSite, liveControl);

  // The only shape allowed to pass: WordPress.com sends an unbound host to
  // /typo/ with that host's own subdomain in the query, so this differs from
  // the control in exactly the way a real unbound `togetherweown` would.
  const unbound = classifyAtomicHost(
    {
      status: 302,
      redirect: 'https://wordpress.com/typo/?subdomain=togetherweown',
      remoteIp: '192.0.78.20',
      headers: '',
      body: '',
    },
    liveControl,
  );

  const classify = (site) => classifyAtomicHost({ headers: '', body: '', redirect: null, remoteIp: '192.0.78.20', ...site }, liveControl);

  // The sweep. Every status the host could plausibly answer with, crossed with
  // bodies that do and do not carry the markers, all with the probe and the
  // control working. Two things are read off it, and the second is the one
  // review added: not only must nothing but a control match PASS, nothing in it
  // may come back UNKNOWN either — because UNKNOWN does not fail the run, so an
  // UNKNOWN here is a retirement gate that went quiet on a response it merely
  // did not anticipate. Swept rather than argued: a later arm added without a
  // verdict trips this without anyone having to think of the case.
  const SWEEP_STATUSES = [200, 204, 301, 302, 307, 308, 401, 403, 404, 410, 429, 451, 500, 502, 503];
  const SWEEP_BODIES = [
    '',
    'Forbidden',
    'Checking your browser before accessing',
    '<html><body>parked</body></html>',
    '<link href="/wp-content/themes/x/style.css">',
    'Error: Active domain connection for this domain not found',
  ];
  const sweep = SWEEP_STATUSES.flatMap((status) =>
    SWEEP_BODIES.map((body) => classify({ status, body })),
  );
  const sweepPasses = sweep.filter((verdict) => verdict.status === PASS).length;
  const sweepUnknowns = sweep.filter((verdict) => verdict.status === UNKNOWN).length;

  const cases = [
    // --- the fixture is the real thing, not a stub -------------------------
    ['the fixture is a 403 capture', preActionSite.status, 403],
    ['the fixture carries the real body, not a placeholder', preActionSite.body.length > 2000, true],
    ['the fixture control is the 3xx the classifier needs', isRedirect(liveControl.status), true],

    // --- defect 1: the verdict and the sentence beside it must agree -------
    ['the untouched site is not retired', preAction.status, FAIL],
    ['the FAIL says the hostname is bound with nothing serving', preAction.detail.includes('bound, no active site serving it'), true],
    ['the FAIL names the only evidence that can close it', preAction.detail.includes('console evidence required'), true],
    ['the FAIL no longer claims a reachable install', preAction.detail.includes('the install is reachable off-CDN'), false],

    // --- defect 2: the rejected relaxation, pinned as vacuous -------------
    ['relaxation-is-vacuous: TOG-1269\'s "403 serving no TWO content" calls the untouched site retired', proposedRelaxationSaysRetired(preActionSite), true],
    ['...and the shipped classifier does not', preAction.status === PASS, false],

    // --- the PASS arm, which the live host has never produced -------------
    ['an unbound host matching the control passes', unbound.status, PASS],
    ['the PASS says why it passed', unbound.detail.includes('the same place the control goes'), true],
    ['a 3xx anywhere else is not proof of unbinding', classify({ status: 302, redirect: 'https://togetherweown.com/' }).status, FAIL],
    ['a 3xx naming no target is not proof of unbinding', classify({ status: 301, redirect: null }).status, FAIL],
    ['nothing but a control match can pass', sweepPasses, 0],

    // --- the 2xx arm, the one case the old wording was true for -----------
    ['a served install off-CDN is the reachable case', classify({ status: 200, body: '<link href="https://togetherweown.wpcomstaging.com/wp-content/themes/x/style.css">' }).status, FAIL],
    ['and it is the only verdict that says so', classify({ status: 200, body: '<script src="/wp-includes/js/jquery.js"></script>' }).detail.includes('the install is reachable off-CDN'), true],
    ['a 2xx carrying no install markup is not retired either', classify({ status: 200, body: '<html><body>parked</body></html>' }).status, FAIL],

    // --- an unanticipated answer FAILs; UNKNOWN would let the gate go quiet --
    //
    // UNKNOWN is excluded from main()'s exit code, so each of these returning
    // UNKNOWN — which is what the first cut of this change did — makes the
    // retirement gate non-failing on exactly the responses nobody predicted.
    ['a 403 with install markup is not laundered as "nothing serving"', classify({ status: 403, body: '<img src="/wp-content/uploads/logo.png">' }).status, FAIL],
    ['a 500 is not retired', classify({ status: 500, body: 'upstream error' }).status, FAIL],
    ['a WAF challenge is not retired', classify({ status: 503, body: 'Checking your browser' }).status, FAIL],
    ['a bare 403 with no domain-connection marker is not retired', classify({ status: 403, body: 'Forbidden' }).status, FAIL],

    // Once the unclassified arm FAILs, verdict alone stops discriminating the
    // 403 arms — a 403 taking the domain-connection branch by mistake returns
    // FAIL either way, so only the sentence catches it. Dropping the marker
    // requirement from that branch survived mutation until these two existed,
    // and it is the same over-claim as the original defect pointing the other
    // way: a 403 that *is* serving install markup would be reported as nothing
    // serving, which is the reading that argues for deleting the site.
    ['a 403 carrying install markup is not called "no active site serving"', classify({ status: 403, body: '<img src="/wp-content/uploads/logo.png">' }).detail.includes('no active site serving it'), false],
    ['a bare 403 is not attributed to a domain-connection error nobody saw', classify({ status: 403, body: 'Forbidden' }).detail.includes('domain-connection error page'), false],
    ['an unclassified answer says so instead of guessing', classify({ status: 500, body: 'upstream error' }).detail.includes('Unclassified, and therefore not retired'), true],
    ['and it does not claim a reachable install', classify({ status: 500, body: 'upstream error' }).detail.includes('the install is reachable off-CDN'), false],
    ['and it quotes the status it saw', classify({ status: 502, body: 'bad gateway' }).detail.includes('HTTP 502'), true],
    ['no measurable answer is ever UNKNOWN, because UNKNOWN does not fail the run', sweepUnknowns, 0],

    // --- the control guard (kept from the original, load-bearing) ---------
    ['a control that stopped redirecting takes the probe out of service', classifyAtomicHost(preActionSite, { status: 200, body: 'a real site now', redirect: null, remoteIp: '192.0.78.20', headers: '' }).status, UNKNOWN],
    ['and says so rather than reporting a verdict', classifyAtomicHost(preActionSite, { status: 200, body: '', redirect: null, remoteIp: null, headers: '' }).detail.includes('can no longer distinguish bound from unbound'), true],
    ['a probe error is not a verdict', classifyAtomicHost({ error: 'curl: (6) could not resolve host' }, liveControl).status, UNKNOWN],
    ['a control that could not be probed is not a verdict either', classifyAtomicHost(preActionSite, { error: 'curl: (28) timed out' }).status, UNKNOWN],
  ];

  let bad = 0;
  for (const [name, actual, expected] of cases) {
    const ok = actual === expected;
    if (!ok) bad++;
    console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${ok ? '' : ` (got ${JSON.stringify(actual)}, want ${JSON.stringify(expected)})`}`);
  }
  console.log(`\n${cases.length - bad}/${cases.length} atomic-host self-test cases pass\n`);
  console.log(`  live 403, classified: ${preAction.status} — ${preAction.detail}\n`);
  process.exit(bad ? 1 : 0);
}

// How the fixture above was made, so refreshing it is a command and not an
// afternoon with curl and a text editor. Hand-editing a capture is how a
// fixture stops being evidence.
//
//   node ci/cutover-check.mjs --capture > ci/fixtures/atomic-host-capture-<date>.json
function capture() {
  const control = request(ATOMIC_CONTROL_HOST);
  const site = request(ATOMIC_HOST);
  console.log(
    JSON.stringify(
      {
        note:
          'Live capture of the Atomic hostname and its unbound control, used by ' +
          '`node ci/cutover-check.mjs --selftest`. Regenerate with `--capture`; do not hand-edit.',
        host: ATOMIC_HOST,
        controlHost: ATOMIC_CONTROL_HOST,
        site,
        control,
      },
      null,
      2,
    ),
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
    console.error(
      'usage: node ci/cutover-check.mjs --phase <before|after> [--app <origin>]\n' +
        '       node ci/cutover-check.mjs --selftest   (offline, the atomic-host classifier)\n' +
        '       node ci/cutover-check.mjs --capture    (refresh the selftest fixture)',
    );
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

const args = process.argv.slice(2);
if (args.includes('--selftest')) selftest();
else if (args.includes('--capture')) capture();
else main();
