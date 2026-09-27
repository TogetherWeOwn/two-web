// Nightly dead-link crawl over staging's main routes (TOG-6950).
//
// The one-shot crawl is TOG-6767; this is the recurring net. It runs
// authenticated through Cloudflare Access (staging is Access-gated — an
// unauthenticated crawl measures the login wall, not the site) and checks
// every same-origin link it can reach from the sitemap plus a seed list of
// main routes. New 404s become one Paperclip card each; see the routine
// description for the filing step.
//
//   CF_ACCESS_CLIENT_ID=... CF_ACCESS_CLIENT_SECRET=... \
//     node ci/staging-dead-link-crawl.mjs
//   node ci/staging-dead-link-crawl.mjs --json        # machine readable
//   node ci/staging-dead-link-crawl.mjs --selftest    # offline, no network
//
// Exit 0 when every reachable page answers and no new dead link is found.
// Exit 1 when at least one dead internal link is found (its URLs are printed).
// Exit 2 when the run itself cannot be trusted — missing credentials, a
// Cloudflare challenge, or the control probe not 404ing. An exit-2 run files
// nothing: a crawler that cannot tell dead from live must fail loudly, never
// file. (TOG-913: a green run must mean something was actually measured.)
//
// Zero-false-positive rules, each learned the hard way elsewhere in this repo:
//   - Control probe first: GET /nx-9x7q2-zzz (the NotFoundTest control path,
//     random enough to never become a route) must answer 404. If it does not,
//     this site soft-404s like the WordPress apex did (TOG-1153) and no 404
//     below is evidence of anything. Abort.
//   - Auth-gated pages (/profile, /members/*, /admin/*) 302 to the Discord
//     handoff when crawled without an app session. That redirect is the gate
//     working, not a broken link. Skipped, counted separately.
//   - OAuth handoffs (/auth/*, /join/discord, /join/callback) and /discord
//     are redirects by design (DiscordFunnelTest pins them). Never followed
//     as pages; never reported.
//   - POST-only routes (e.g. /logout) answer 405 to a GET. Method-gated, not
//     dead. Skipped.
//   - External redirects (discord.com invite, Cloudflare Access login) are
//     followed at most to the redirect target, never fetched. The target host
//     is recorded so a hijacked invite URL would still be visible in the log.

import { request, STAGING_HOST } from './staging-access.mjs';

const UA_NOTE = 'authenticated crawl via CF Access service token';

const MAX_HOPS = 5;
const CONTROL_PATH = '/nx-9x7q2-zzz';

// Routes a human can reach without signing in, plus the login destination
// itself (which must redirect outward, never 404). Sitemap URLs are added at
// runtime; these seeds cover what the sitemap deliberately omits — /discord
// answers with a redirect and no document, so it is not in the index.
const SEEDS = [
  { path: '/', expect: 'ok' },
  { path: '/events', expect: 'ok' },
  { path: '/about', expect: 'ok' },
  { path: '/rules', expect: 'ok' },
  { path: '/join', expect: 'ok' },
  { path: '/events.rss', expect: 'ok' },
  { path: '/sitemap_index.xml', expect: 'ok' },
  { path: '/up', expect: 'ok' },
  { path: '/discord', expect: 'redirect-external' },
  { path: '/auth/discord/redirect', expect: 'redirect-external' },
  { path: '/profile', expect: 'auth-gate' },
  { path: '/admin', expect: 'auth-gate' },
];

// Handoffs and method-gated routes that are never pages. Each pattern carries
// its reason so a future skip reads as a decision, not an oversight.
const SKIP_PATTERNS = [
  { re: /^\/auth\//, reason: 'oauth-handoff' },
  { re: /^\/join\/(discord|callback)/, reason: 'oauth-handoff' },
  { re: /^\/logout\/?$/, reason: 'post-only' },
];

const args = process.argv.slice(2);
const asJson = args.includes('--json');

function accessHeaders(env = process.env) {
  const id = env.CF_ACCESS_CLIENT_ID;
  const secret = env.CF_ACCESS_CLIENT_SECRET;
  if (!id || !secret) return null;
  return { 'CF-Access-Client-Id': id, 'CF-Access-Client-Secret': secret };
}

function isChallenge(res) {
  return (
    res.headers?.['cf-mitigated'] === 'challenge' ||
    (res.status === 403 && (res.body || '').includes('<title>Just a moment...</title>'))
  );
}

function isAccessLogin(res) {
  const loc = res.headers?.location || res.redirect;
  if (!loc) return false;
  try {
    const u = new URL(loc, `https://${STAGING_HOST}`);
    return u.hostname.endsWith('.cloudflareaccess.com');
  } catch {
    return false;
  }
}

// One same-origin GET chain, followed by hand so the hops stay visible (the
// live-seo-probe chase() pattern). External targets are recorded, not fetched.
function chase(startPath, headers) {
  const chain = [];
  let current = new URL(startPath, `https://${STAGING_HOST}`).toString();

  for (let hop = 0; hop <= MAX_HOPS; hop++) {
    const res = request(current, { headers });
    if (res.error) {
      return { url: startPath, chain, status: 0, finalUrl: current, error: res.error, body: '' };
    }
    if (isChallenge(res)) {
      return { url: startPath, chain, status: res.status, finalUrl: current, challenged: true, body: '' };
    }
    const loc = res.headers?.location || res.redirect;
    if (res.status >= 300 && res.status < 400 && loc) {
      let next;
      try {
        next = new URL(loc, current).toString();
      } catch {
        return { url: startPath, chain, status: res.status, finalUrl: current, error: `bad redirect target ${loc}`, body: '' };
      }
      chain.push({ url: current, status: res.status, to: next });
      if (new URL(next).hostname !== STAGING_HOST) {
        return { url: startPath, chain, status: res.status, finalUrl: next, external: true, body: '' };
      }
      current = next;
      continue;
    }
    return { url: startPath, chain, status: res.status, finalUrl: current, headers: res.headers, body: res.body || '' };
  }
  return { url: startPath, chain, status: 0, finalUrl: current, error: `>${MAX_HOPS} redirects`, body: '' };
}

function sameOriginLink(href, base) {
  try {
    const u = new URL(href, base);
    if (u.hostname !== STAGING_HOST) return null;
    if (!['http:', 'https:'].includes(u.protocol)) return null;
    u.hash = '';
    return u.pathname + u.search;
  } catch {
    return null;
  }
}

export function extractLinks(html, base) {
  const out = new Set();
  const re = /<a\s[^>]*?href=(["'])(.*?)\1/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const link = sameOriginLink(m[2].trim(), base);
    if (link) out.add(link);
  }
  return [...out];
}

function skipReason(path) {
  for (const { re, reason } of SKIP_PATTERNS) {
    if (re.test(path)) return reason;
  }
  return null;
}

function parseSitemapLocs(xml) {
  return [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].trim());
}

export function classify(res) {
  // Returns one of: ok | dead | auth-gate | redirect-external | skipped:<reason> | error
  if (res.error) return 'error';
  if (res.challenged) return 'error';
  // An auth gate reached through the OAuth handoff: /profile ->
  // /auth/discord/redirect -> discord.com. The handoff hop in the chain proves
  // the gate worked; the external tail is the design, not breakage. A page that
  // redirects straight out (e.g. /discord) has no such hop and stays
  // redirect-external.
  const viaHandoff = (res.chain || []).some((h) => {
    try {
      return new URL(h.to, `https://${STAGING_HOST}`).pathname === '/auth/discord/redirect';
    } catch {
      return false;
    }
  });
  if (viaHandoff) return 'auth-gate';
  if (res.external) {
    if (isAccessLogin({ headers: {}, redirect: res.finalUrl }) || /discord\.com$/i.test(new URL(res.finalUrl).hostname)) {
      return 'redirect-external';
    }
    return 'redirect-external';
  }
  if (res.status === 404 || res.status === 410) return 'dead';
  if (res.status === 405) return 'skipped:post-only';
  if (res.status >= 500) return 'error';
  if (res.status >= 300 && res.status < 400) {
    const loc = res.headers?.location;
    if (loc) {
      try {
        const u = new URL(loc, `https://${STAGING_HOST}`);
        if (u.hostname !== STAGING_HOST) return 'redirect-external';
        if (u.pathname === '/auth/discord/redirect') return 'auth-gate';
      } catch {
        return 'error';
      }
    }
    return 'ok';
  }
  if (res.status >= 400) return 'dead';
  return 'ok';
}

function selftest() {
  const cases = [
    ['same-origin anchor extracted', JSON.stringify(extractLinks('<a href="/events">E</a>', 'https://staging.togetherweown.com/')), JSON.stringify(['/events'])],
    ['external anchor dropped', JSON.stringify(extractLinks('<a href="https://discord.com/x">D</a>', 'https://staging.togetherweown.com/')), JSON.stringify([])],
    ['fragment stripped', JSON.stringify(extractLinks('<a href="/about#team">A</a>', 'https://staging.togetherweown.com/')), JSON.stringify(['/about'])],
    ['single quotes read', JSON.stringify(extractLinks("<a href='/rules'>R</a>", 'https://staging.togetherweown.com/')), JSON.stringify(['/rules'])],
    ['mailto skipped', JSON.stringify(extractLinks('<a href="mailto:a@b.c">M</a>', 'https://staging.togetherweown.com/')), JSON.stringify([])],
    ['absolute staging url kept', JSON.stringify(extractLinks('<a href="https://staging.togetherweown.com/join">J</a>', 'https://staging.togetherweown.com/')), JSON.stringify(['/join'])],
    ['oauth handoff skipped', skipReason('/auth/discord/callback'), 'oauth-handoff'],
    ['logout skipped as post-only', skipReason('/logout'), 'post-only'],
    ['ordinary page not skipped', skipReason('/events'), null],
    ['200 is ok', classify({ status: 200 }), 'ok'],
    ['404 is dead', classify({ status: 404 }), 'dead'],
    ['410 is dead', classify({ status: 410 }), 'dead'],
    ['405 is skipped not dead', classify({ status: 405 }), 'skipped:post-only'],
    ['500 is an error not a dead link', classify({ status: 500 }), 'error'],
    ['challenge is an error', classify({ status: 403, challenged: true }), 'error'],
    ['external redirect recorded', classify({ status: 302, chain: [], external: true, finalUrl: 'https://discord.com/invite/x' }), 'redirect-external'],
    ['auth gate through the handoff hop', classify({ status: 302, chain: [{ to: 'https://staging.togetherweown.com/auth/discord/redirect' }], external: true, finalUrl: 'https://discord.com/x' }), 'auth-gate'],
    ['sitemap locs parsed', JSON.stringify(parseSitemapLocs('<urlset><url><loc>https://h.com/a</loc></url></urlset>')), JSON.stringify(['https://h.com/a'])],
  ];
  let bad = 0;
  for (const [name, actual, expected] of cases) {
    const ok = actual === expected;
    if (!ok) bad++;
    console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${ok ? '' : ` (got ${actual}, want ${expected})`}`);
  }
  console.log(`\n${cases.length - bad}/${cases.length} self-test cases pass\n`);
  process.exit(bad ? 1 : 0);
}

function main() {
  const headers = accessHeaders();
  if (!headers) {
    console.error(
      'FAIL  credentials-missing CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET are both required. ' +
        'Without the service token this script would crawl the Cloudflare Access login wall and file it as breakage. ' +
        `(${UA_NOTE})`
    );
    process.exit(2);
  }

  const origin = `https://${STAGING_HOST}`;
  const rows = [];
  const seen = new Set();
  const dead = [];

  const check = (path, referrer) => {
    if (seen.has(path)) return;
    seen.add(path);
    const skipped = skipReason(path);
    if (skipped) {
      rows.push({ path, status: 'skip', verdict: `skipped:${skipped}`, referrer });
      return;
    }
    const res = chase(path, headers);
    if (res.challenged || (res.error && /challenge/i.test(res.error))) {
      console.error(`\nCloudflare served a bot challenge for ${path}. Aborting: every verdict below asserts the absence of a defect, so continuing would file the challenge page as breakage.`);
      process.exit(2);
    }
    const verdict = classify(res);
    const row = {
      path,
      status: res.status,
      verdict,
      finalUrl: res.finalUrl,
      hops: res.chain.length,
      error: res.error || null,
      referrer,
    };
    rows.push(row);
    if (verdict === 'dead') dead.push(row);
  };

  // -- 0. The control probe. If a never-existed URL does not 404, no 404
  // below is evidence (TOG-1153). Abort before measuring anything else.
  const control = chase(CONTROL_PATH, headers);
  if (control.challenged) {
    console.error('Cloudflare served a bot challenge for the control probe. Aborting.');
    process.exit(2);
  }
  if (control.error || control.status !== 404) {
    console.error(
      `FAIL  control-probe ${CONTROL_PATH} answered ${control.error || control.status}, not 404. ` +
        'This site is soft-404ing: every unknown URL looks live, so no dead link below can be trusted. Filing nothing.'
    );
    process.exit(2);
  }
  rows.push({ path: CONTROL_PATH, status: 404, verdict: 'control-ok', finalUrl: control.finalUrl, hops: 0, error: null, referrer: '(control)' });

  // -- 1. Seeds: the main routes CI budgets and the funnel already care about.
  for (const { path } of SEEDS) check(path, '(seed)');

  // -- 2. Everything the site advertises to crawlers.
  const sm = chase('/sitemap_index.xml', headers);
  if (sm.status === 200 && !sm.error) {
    for (const loc of parseSitemapLocs(sm.body)) {
      try {
        const u = new URL(loc);
        if (u.hostname === STAGING_HOST) check(u.pathname + u.search, '(sitemap)');
      } catch {
        rows.push({ path: loc, status: 'skip', verdict: 'skipped:unparseable-sitemap-loc', referrer: '(sitemap)' });
      }
    }
  } else {
    rows.push({ path: '/sitemap_index.xml', status: sm.status, verdict: 'error', finalUrl: sm.finalUrl, hops: 0, error: sm.error || 'sitemap unreadable', referrer: '(seed)' });
  }

  // -- 3. One level out: every same-origin anchor on every fetched page.
  // Fetched bodies are kept only for link extraction, then dropped.
  const pageBodies = [];
  for (const row of rows.filter((r) => r.verdict === 'ok')) {
    const res = chase(row.path, headers);
    if (res.status === 200 && res.body) pageBodies.push({ path: row.path, body: res.body, base: res.finalUrl });
  }
  for (const { path, body, base } of pageBodies) {
    for (const link of extractLinks(body, base)) check(link, path);
  }

  if (asJson) {
    console.log(JSON.stringify({ host: STAGING_HOST, control: 'ok', rows, dead }, null, 2));
  } else {
    console.log(`\nStaging dead-link crawl — ${origin} (${UA_NOTE})\n`);
    for (const r of rows) {
      console.log(`${(r.verdict || '').padEnd(18)} ${r.status === 'skip' ? '-' : r.status}  ${r.path}${r.referrer && r.referrer !== '(seed)' ? `  (from ${r.referrer})` : ''}${r.error ? `  ERROR ${r.error}` : ''}`);
    }
    console.log(
      `\n${rows.length} URLs checked, ${dead.length} dead` +
        (dead.length ? ` — FILING NEEDED:\n${dead.map((d) => `  404 ${d.path} (from ${d.referrer})`).join('\n')}\n` : ' — clean\n')
    );
  }
  process.exit(dead.length ? 1 : 0);
}

if (args.includes('--selftest')) selftest();
else main();
