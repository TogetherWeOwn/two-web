// What togetherweown.wpcomstaging.com is currently serving, classified.
//
// `atomic-host-off` used to decide with one predicate — `!(status >= 300 &&
// status < 400)` — and then report that FAIL as "the install is reachable
// off-CDN". Those are not the same claim. A 500, a WAF challenge, or the
// domain-connection error page WordPress.com actually serves today all trip
// "not a 3xx" without anything being reachable, and the wrong detail argues for
// the wrong remediation: deleting the site (irreversible) on the grounds that
// an unpatched WordPress is up, which has not been shown. Measured 2026-09-17,
// pre-action: HTTP 403, 3,108 bytes, zero install content, WordPress.com's
// "Active domain connection for this domain not found" page.
//
// The verdict does not change — only PASS proves retirement, so every state
// below except the unbound 3xx is still a FAIL. What changes is that the FAIL
// says which state it measured.
//
// Why this lives in its own module: ci/cutover-check.mjs calls main() at import
// time, so anything exported from there cannot be tested without running the
// whole live check. Keeping the classification pure and side-effect free is
// what lets ci/atomic-host-state-selftest.mjs exercise the PASS arm against a
// synthetic unbound capture — otherwise that arm is unreachable until the day
// someone deletes the site, and an untested arm on a retirement gate is how the
// gate goes vacuous.

// WordPress.com's own wording on the 403. Present in both the <title> and the
// body copy of the captured page (ci/atomic-host-state-fixture-403.html), so a
// copy tweak to one of them does not silently drop us into `unrecognised`.
const DOMAIN_CONNECTION_ERROR = /active domain connection/i;

// Markers that the response is a served WordPress install rather than a
// WordPress.com error page. Checked on headers and body together — the apex
// check already relies on the same `api.w.org` link relation.
const INSTALL_MARKERS = /rel=["']https:\/\/api\.w\.org\/["']|\/wp-content\/|\/wp-includes\//i;

export const RETIRED = 'retired';
export const BOUND_NO_SITE = 'bound-no-site';
export const INSTALL_SERVING = 'install-serving';
export const UNRECOGNISED = 'unrecognised';

// `site` and `control` are ci/cutover-check.mjs `request()` results for the
// install's hostname and for a hostname that is definitely not a site on the
// same wildcard. The caller has already established that `control` is a 3xx —
// without that this function has no discriminator and must not be called.
//
// Returns `{ state, retired, detail }`. `retired` is the verdict; `state` is
// for tests and for anyone reading the classification back.
export function classifyAtomicHost({ site, control, host }) {
  const bytes = site.body?.length ?? 0;
  const where = `HTTP ${site.status}${site.remoteIp ? ` from ${site.remoteIp}` : ''}`;
  const versus = `control ${control.status}`;

  if (site.status >= 300 && site.status < 400) {
    return {
      state: RETIRED,
      retired: true,
      detail:
        `${host} behaves like an unbound host (${where}` +
        `${site.redirect ? ` -> ${site.redirect}` : ''}, same as ${versus}) — the site record is gone`,
    };
  }

  if (site.status === 403 && DOMAIN_CONNECTION_ERROR.test(site.body ?? '')) {
    return {
      state: BOUND_NO_SITE,
      retired: false,
      detail:
        `${host} is still a WordPress.com site record: ${where}, ${bytes} bytes of ` +
        `"active domain connection not found" error page, no install content (${versus} = unbound). ` +
        'Nothing is serving, but the site is not retired, and this state cannot prove retirement ' +
        'from outside — closing on it needs console evidence and a written waiver',
    };
  }

  if (site.status >= 200 && site.status < 300) {
    return {
      state: INSTALL_SERVING,
      retired: false,
      detail:
        `${host} still answers (${where}, ${bytes} bytes, ${versus}) — the install is ` +
        `reachable off-CDN${INSTALL_MARKERS.test(`${site.headers ?? ''}${site.body ?? ''}`) ? ', serving WordPress content' : ''}`,
    };
  }

  return {
    state: UNRECOGNISED,
    retired: false,
    // Deliberately a FAIL rather than an UNKNOWN. UNKNOWN belongs to "the probe
    // could not measure" — a curl error, or a control that stopped
    // discriminating — and ci/cutover-check.mjs has already ruled both out by
    // the time it calls this. Everything that reaches here is a measured
    // response from a hostname that is not answering like an unbound one, which
    // is "not retired". Failing closed is the safe direction for a gate whose
    // PASS authorises calling the install gone.
    detail:
      `${host} returned an unrecognised state (${where}, ${bytes} bytes, ${versus}) — ` +
      'not the 3xx an unbound host returns, so retirement is not proven',
  };
}
