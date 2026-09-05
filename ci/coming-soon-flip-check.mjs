// Did turning off Bricks "Coming Soon" actually work — and did it cost us the funnel?
//
// TOG-1159 asks an operator to make two changes in the WordPress.com admin (give
// the site a real static homepage, then set Bricks > Maintenance > Mode to
// Disabled) and then paste back the result of a three-line curl snippet. This file
// replaces that snippet, because the snippet cannot answer the question it asks.
//
// The snippet was wrong in both directions, measured 2026-09-05:
//
//   - The bogus path it probes, /nx-9x7q2-zzz/, answers `cf-cache-status: HIT`
//     with `age: 7268`. Cloudflare holds it despite an origin `no-store` header.
//     Re-running the snippet after a correct flip still returns the CACHED 200, so
//     the operator reads "it didn't work" and rolls back a fix that worked.
//
//   - The homepage answers `HIT` with `age: 121580` — a ~34 hour old copy. The
//     third line of the snippet is the regression guard for the Discord CTA, the
//     only conversion path the site has. Against a day-old cached body it prints
//     `CTA OK` no matter what the origin now serves. The one check protecting the
//     funnel was structurally incapable of failing.
//
// So every request here carries a unique cache-busting query parameter and asserts
// on `cf-cache-status` before believing anything. A measurement served from cache
// is not a measurement of the change you just made.
//
//   node ci/coming-soon-flip-check.mjs           # table + PASS/FAIL, exit 1 on FAIL
//   node ci/coming-soon-flip-check.mjs --json    # machine readable
//   node ci/coming-soon-flip-check.mjs --before  # expect the flip to NOT have happened
//
// Run it once BEFORE the flip (with --before, which inverts the 404 expectation and
// so should pass on today's site) and once after. Two runs that both pass are what
// tells you the change landed and landed correctly.
//
// Shells out to `curl` rather than using `fetch` for the same reason
// ci/live-seo-probe.mjs does: Cloudflare fingerprints the TLS ClientHello and
// challenges Node's undici with a byte-identical header set. See the note at the
// top of that file before "simplifying" this.

import { execFileSync } from 'node:child_process';

const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

const BROWSER_ACCEPT =
  'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

const args = process.argv.slice(2);
const asJson = args.includes('--json');
const beforeMode = args.includes('--before');
const hostArg = args.find((a) => a.startsWith('--host='));
const HOST = hostArg ? hostArg.slice('--host='.length) : 'togetherweown.com';
const ORIGIN = `https://${HOST}`;

// The marker text of the Discord call to action on the coming-soon design. This is
// the string the funnel guard looks for. It is deliberately the human-visible copy
// rather than a class name or an element id: Bricks regenerates markup and rewrites
// classes when a template is edited, but if this sentence disappears from the
// homepage then a visitor genuinely has no way into Discord.
const CTA_MARKER = 'Join us on Discord';

// A fixed, never-registered path. Fixed rather than random so a human can re-run it
// by hand and get the same answer — a probe you cannot reproduce is not evidence.
// The cache-buster below is what keeps it from being served stale, not randomising
// the path itself.
const NEVER_EXISTED = '/tog1159-never-existed-path/';

let bustCounter = 0;

function bust(path) {
  // A unique query per request. Cloudflare caches by full URL including query, so
  // this guarantees a MISS/DYNAMIC and therefore an origin answer. process.pid and
  // a counter, not Math.random(), so a single run is internally consistent and the
  // URLs appear in logs in a readable order.
  const sep = path.includes('?') ? '&' : '?';
  return `${ORIGIN}${path}${sep}cb=tog1159-${process.pid}-${++bustCounter}`;
}

function curlOnce(url) {
  const out = execFileSync(
    'curl',
    [
      '-sS',
      '-i',
      // Do not pin the HTTP version — forcing --http1.1 changes the ALPN
      // fingerprint enough for Cloudflare to challenge. Same trap as live-seo-probe.
      '--max-time', '30',
      '-A', UA,
      '-H', `accept: ${BROWSER_ACCEPT}`,
      '-H', 'accept-language: en-US,en;q=0.9',
      // Ask politely for a fresh copy. Not sufficient on its own — Cloudflare
      // ignored these on the measured runs, which is why bust() exists — but it
      // costs nothing and helps on any hop that does honour it.
      '-H', 'cache-control: no-cache',
      '-H', 'pragma: no-cache',
      url,
    ],
    { encoding: 'latin1', maxBuffer: 32 * 1024 * 1024 }
  );

  const crlf = out.indexOf('\r\n\r\n');
  const lf = out.indexOf('\n\n');
  const split = crlf === -1 ? lf : lf === -1 ? crlf : Math.min(crlf, lf);
  const sepLen = split === crlf ? 4 : 2;

  const rawHead = split === -1 ? out : out.slice(0, split);
  const body = split === -1 ? '' : out.slice(split + sepLen);

  const lines = rawHead.split(/\r?\n/);
  const status = Number((lines[0].match(/^HTTP\/[\d.]+\s+(\d+)/) || [])[1] || 0);

  const headers = {};
  for (const line of lines.slice(1)) {
    const at = line.indexOf(':');
    if (at > 0) {
      headers[line.slice(0, at).trim().toLowerCase()] = line.slice(at + 1).trim();
    }
  }

  return { status, headers, body };
}

// A Cloudflare challenge is not a measurement. Abort the whole run rather than let
// a 403 masquerade as a data point — the same reasoning as live-seo-probe.mjs.
function assertNotChallenged(url, res) {
  const challenged =
    res.headers['cf-mitigated'] === 'challenge' ||
    (res.status === 403 && res.body.includes('<title>Just a moment...</title>'));
  if (!challenged) return;
  console.error(
    `\nCloudflare served a bot challenge for ${url}.\n\n` +
      'Aborting rather than reporting a result nobody measured. Usually means the\n' +
      'UA at the top of this file has aged out, or this is running somewhere\n' +
      `Cloudflare rate-limits.\n\n  curl -sSI -A '${UA}' ${url}\n`
  );
  process.exit(2);
}

// Follow redirects by hand, re-busting each hop, so `/join -> /join/ -> 200` stays
// visible instead of collapsing into a bare 200 that hides a soft 404.
function chase(path) {
  const chain = [];
  let url = bust(path);

  for (let hop = 0; hop <= 5; hop++) {
    let res;
    try {
      res = curlOnce(url);
    } catch (err) {
      return { path, chain, status: 0, finalUrl: url, cache: '', body: '', error: String(err) };
    }
    assertNotChallenged(url, res);

    const location = res.headers.location;
    if (res.status >= 300 && res.status < 400 && location) {
      chain.push({ status: res.status, url });
      const next = new URL(location, url);
      // Preserve the cache-buster across the hop; WordPress drops the query on its
      // trailing-slash redirect, which would hand us a cached copy at the end of an
      // otherwise fresh chain.
      if (!next.searchParams.has('cb')) {
        next.searchParams.set('cb', `tog1159-${process.pid}-${++bustCounter}`);
      }
      url = next.toString();
      continue;
    }

    return {
      path,
      chain,
      status: res.status,
      finalUrl: url,
      cache: res.headers['cf-cache-status'] || '(none)',
      age: res.headers.age || '',
      title: (res.body.match(/<title>([^<]*)<\/title>/i) || [])[1] || '',
      body: res.body,
    };
  }

  return { path, chain, status: 0, finalUrl: url, cache: '', body: '', error: 'too many redirects' };
}

function main() {
  const home = chase('/');
  const bogus = chase(NEVER_EXISTED);
  const discord = chase('/discord');

  const results = [];
  const check = (id, ok, detail) => results.push({ id, ok, detail });

  // ---------------------------------------------------------------------------
  // Gate everything on having actually reached the origin. If these fail, no other
  // line below means anything, which is why they are checks and not assumptions.
  const stale = [home, bogus].filter((r) => r.cache === 'HIT');
  check(
    'measured-at-origin',
    stale.length === 0,
    stale.length === 0
      ? `home and bogus both answered fresh (${home.cache}/${bogus.cache})`
      : `served from cache despite busting: ${stale
          .map((r) => `${r.path} ${r.cache} age=${r.age}`)
          .join(', ')} — result is NOT evidence about the flip`
  );

  // ---------------------------------------------------------------------------
  // The actual deliverable: unknown paths must 404.
  const bogus404 = bogus.status === 404 || bogus.status === 410;
  if (beforeMode) {
    check(
      'baseline-soft-404-present',
      bogus.status === 200,
      bogus.status === 200
        ? `${NEVER_EXISTED} answers 200 "${bogus.title}" — the defect TOG-1159 fixes is present, as expected pre-flip`
        : `${NEVER_EXISTED} answers ${bogus.status}, not the 200 this baseline expects — has the flip already happened?`
    );
  } else {
    check(
      'unknown-paths-404',
      bogus404,
      bogus404
        ? `${NEVER_EXISTED} answers ${bogus.status}`
        : `${NEVER_EXISTED} answers ${bogus.status} "${bogus.title}" — the soft 404 is still there`
    );
  }

  // ---------------------------------------------------------------------------
  // The regression guard. This is the one that matters most: it is the reason step 1
  // of the card is mandatory. Disabling Coming Soon without a static homepage drops
  // the front page to the blog index listing "Hello world!", which silently removes
  // the only route into Discord.
  check(
    'homepage-200',
    home.status === 200,
    home.status === 200 ? 'homepage answers 200' : `homepage answers ${home.status}`
  );

  const hasCta = home.body.includes(CTA_MARKER);
  check(
    'homepage-discord-cta',
    hasCta,
    hasCta
      ? `homepage still contains "${CTA_MARKER}"`
      : `homepage no longer contains "${CTA_MARKER}" — THE FUNNEL IS DOWN, ROLL BACK (set Bricks Mode back to Coming Soon)`
  );

  // The specific failure mode step 1 prevents, named explicitly so a failure tells
  // the operator what went wrong rather than just that something did.
  const looksLikeBlogIndex =
    /Hello world/i.test(home.body) && !hasCta;
  check(
    'homepage-is-not-blog-index',
    !looksLikeBlogIndex,
    looksLikeBlogIndex
      ? 'homepage fell back to the blog index listing "Hello world!" — step 1 (static homepage) was skipped or did not take'
      : 'homepage is not the blog-index fallback'
  );

  // ---------------------------------------------------------------------------
  // The funnel itself, end to end. A homepage CTA that links to a dead /discord is
  // not a working funnel.
  const reachedDiscord =
    discord.chain.length > 0 &&
    /discord\.com$/.test(safeHost(discord.finalUrl));
  check(
    'discord-funnel-alive',
    reachedDiscord,
    reachedDiscord
      ? `/discord -> ${discord.chain[0].status} discord.com`
      : `/discord did not reach discord.com (status ${discord.status})`
  );

  // ---------------------------------------------------------------------------
  if (asJson) {
    console.log(
      JSON.stringify(
        {
          host: HOST,
          mode: beforeMode ? 'before' : 'after',
          measured: [home, bogus, discord].map((r) => ({
            path: r.path,
            status: r.status,
            cache: r.cache,
            age: r.age,
            title: r.title,
          })),
          checks: results,
        },
        null,
        2
      )
    );
  } else {
    console.log(
      `\nComing Soon flip check — ${ORIGIN}  [${beforeMode ? 'BEFORE' : 'AFTER'} the flip]\n`
    );
    const pad = (s, n) => String(s ?? '').padEnd(n).slice(0, n);
    console.log(pad('URL', 36) + pad('STATUS', 9) + pad('CACHE', 10) + 'TITLE');
    console.log('-'.repeat(100));
    for (const r of [home, bogus, discord]) {
      const status = r.chain.length ? `${r.chain[0].status}>${r.status}` : `${r.status}`;
      console.log(pad(r.path, 36) + pad(status, 9) + pad(r.cache, 10) + r.title);
    }
    console.log('');
    for (const c of results) {
      console.log(`${c.ok ? 'PASS' : 'FAIL'}  ${pad(c.id, 32)} ${c.detail}`);
    }
    const failed = results.filter((c) => !c.ok).length;
    console.log(
      `\n${results.length - failed}/${results.length} checks pass` +
        (failed ? ` — ${failed} FAILING\n` : '\n')
    );
  }

  process.exit(results.some((c) => !c.ok) ? 1 : 0);
}

function safeHost(u) {
  try {
    return new URL(u).host;
  } catch {
    return '';
  }
}

main();
