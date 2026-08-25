// What the live apex is telling search engines, measured rather than remembered.
//
// TOG-71 reported that togetherweown.com answers HTTP 200 with `index, follow` to
// every URL, including ones that never existed. Half of that was true when it was
// written on 2026-08-19 and is no longer true today, and something worse appeared
// in the meantime that nobody was looking for. That is the whole reason this file
// exists: the apex is a WordPress.com install nobody on this team can log into, it
// changes under us without a changelog, and a description in an issue is a
// photograph of a moving thing.
//
// Run it before you argue about what the apex does, and run it again after anyone
// touches the WordPress side to see whether they fixed it.
//
//   node ci/live-seo-probe.mjs                    # table + PASS/FAIL, exit 1 on FAIL
//   node ci/live-seo-probe.mjs --json             # same measurements, machine readable
//   node ci/live-seo-probe.mjs --host example.com # point it at staging or the new site
//
// This is deliberately NOT wired into ci.yml. It measures a third-party host we do
// not control and cannot fix, so as a required check it would redden every pull
// request for a defect no pull request can cause or cure — and a gate that is red
// for reasons outside the diff gets ignored, then removed. It becomes a candidate
// for CI on the day the apex is ours (TWO-61), and at that point every check below
// is one we can actually hold ourselves to.
//
// The browser user agent is load-bearing, not cargo cult. The apex is proxied by
// Cloudflare and answers a plain request with `403 cf-mitigated: challenge`, which
// is indistinguishable from a broken site if you are not expecting it. Documenting
// a 403 here would be documenting Cloudflare, not WordPress. Same trap as the one
// called out at the top of docs/dns.md.
//
// And it goes deeper than the header, which is why this shells out to `curl`
// instead of using `fetch`. Cloudflare fingerprints the TLS ClientHello, so Node's
// undici gets challenged with a byte-identical header set that curl walks through.
// Measured 2026-08-25: ua-only, ua+accept, ua+accept+language and a full
// sec-fetch-* browser set all came back `403 challenge` from `fetch`; the same UA
// under curl came back 200. If you "simplify" this back to fetch, every check below
// silently starts measuring Cloudflare's challenge page.

import { execFileSync } from 'node:child_process';

const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

const MAX_HOPS = 5;

// URLs that must not answer 200. Two kinds, and the distinction matters when you
// read a failure:
//
//   - dead: real URLs from the site that existed before the 2026-08-01 rebuild.
//     These should be 410 (gone) or 404, so the index sheds them.
//   - never: a path that has never existed on any version of this site. If this
//     one answers 200 then the answer is unbounded — every typo, every scanner
//     probe, every stale link anyone ever published is a live URL.
//
// Keep `never` a fixed string, not a random one. A probe you cannot re-run by hand
// and get the same answer from is not evidence.
const MUST_NOT_EXIST = [
  { path: '/about-us/', kind: 'dead' },
  { path: '/news/', kind: 'dead' },
  { path: '/members', kind: 'dead' },
  { path: '/gamipress/points/', kind: 'dead' },
  { path: '/events/month/2024-01/', kind: 'dead' },
  { path: '/join', kind: 'dead' },
  { path: '/this-url-never-existed-abc123xyz/', kind: 'never' },
];

// `/discord` is the only conversion path on the site (docs/dns.md). It is probed
// so that a run of this file also tells you the funnel is alive, but it is exempt
// from the canonical and status checks — it is a redirect into Discord's OAuth
// flow and is supposed to be one.
const FUNNEL = '/discord';

const args = process.argv.slice(2);
const asJson = args.includes('--json');
const hostArg = args.indexOf('--host');
const HOST = hostArg !== -1 ? args[hostArg + 1] : 'togetherweown.com';
const ORIGIN = `https://${HOST}`;

// ---------------------------------------------------------------------------
// Fetching
// ---------------------------------------------------------------------------

// One request, no redirect following — `-i` puts the response headers on stdout in
// front of the body so both come back in a single call without temp files.
// What a browser and a crawler send. The apex answers `vary: accept` and genuinely
// serves different HTML per variant, so this is the one that matters — see the
// `homepage-same-for-crawlers-and-scripts` check below for why it is a constant
// rather than something a caller picks casually.
const BROWSER_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

// What curl, wget and most scripts send by default. Only used to prove the two
// disagree.
const SCRIPT_ACCEPT = '*/*';

function curlOnce(url, accept = BROWSER_ACCEPT) {
  const out = execFileSync(
    'curl',
    [
      '-sS',
      '-i',
      // Do NOT pin the HTTP version here. Forcing `--http1.1` changes the TLS and
      // ALPN fingerprint enough that Cloudflare challenges the request — measured
      // 2026-08-25, it turned a working probe into a 403 on every URL. Let curl
      // negotiate h2 the way the browser it is impersonating would.
      '--max-time', '30',
      '-A', UA,
      '-H', `accept: ${accept}`,
      '-H', 'accept-language: en-US,en;q=0.9',
      url,
    ],
    { encoding: 'latin1', maxBuffer: 32 * 1024 * 1024 }
  );

  // h2 and h1.1 disagree about line endings, so find whichever blank-line
  // separator comes first rather than assuming CRLF.
  const crlf = out.indexOf('\r\n\r\n');
  const lf = out.indexOf('\n\n');
  const split =
    crlf === -1 ? lf : lf === -1 ? crlf : Math.min(crlf, lf);
  const sepLen = split === crlf ? 4 : 2;

  const rawHead = split === -1 ? out : out.slice(0, split);
  const body = split === -1 ? '' : out.slice(split + sepLen);

  const lines = rawHead.split(/\r?\n/);
  const status = Number((lines[0].match(/^HTTP\/[\d.]+\s+(\d+)/) || [])[1] || 0);

  const headers = {};
  for (const line of lines.slice(1)) {
    const at = line.indexOf(':');
    if (at > 0) headers[line.slice(0, at).trim().toLowerCase()] = line.slice(at + 1).trim();
  }

  return { status, headers, body };
}

// A Cloudflare challenge is not a measurement, and it must never be allowed to
// look like one. Every check in this file is of the form "nothing in this set is
// wrong", so a host that answers 403 to everything makes all of them pass while
// measuring nothing — which is exactly what the first run of this script did.
// Treat it as a fatal condition for the whole run instead of a data point.
function assertNotChallenged(url, res) {
  const challenged =
    res.headers['cf-mitigated'] === 'challenge' ||
    (res.status === 403 && res.body.includes('<title>Just a moment...</title>'));

  if (!challenged) return;

  console.error(
    `\nCloudflare served a bot challenge for ${url}.\n\n` +
      'Every check in this file asserts the absence of a defect, so continuing would\n' +
      'report a clean bill of health for a site nobody measured. Aborting instead.\n\n' +
      'Usually means the UA at the top of this file has aged out, or this is running\n' +
      'somewhere Cloudflare rate-limits. Reproduce by hand:\n\n' +
      `  curl -sSI -A '${UA}' ${url}\n`
  );
  process.exit(2);
}

// Follow redirects by hand so the chain is visible. Letting curl follow with `-L`
// collapses `/members` -> `/members/` -> 200 into a bare "200" and hides the fact
// that the 200 is a 404 page wearing the wrong status code.
function chase(url, accept = BROWSER_ACCEPT) {
  const chain = [];
  let current = url;

  for (let hop = 0; hop <= MAX_HOPS; hop++) {
    let res;
    try {
      res = curlOnce(current, accept);
    } catch (err) {
      return { url, chain, status: 0, finalUrl: current, error: String(err), body: '' };
    }

    assertNotChallenged(current, res);

    const location = res.headers.location;

    if (res.status >= 300 && res.status < 400 && location) {
      chain.push({ url: current, status: res.status });
      current = new URL(location, current).toString();
      continue;
    }

    const contentType = res.headers['content-type'] || '';

    return {
      url,
      chain,
      status: res.status,
      finalUrl: current,
      contentType,
      xRobotsTag: res.headers['x-robots-tag'] ?? null,
      body: contentType.includes('text/') || contentType.includes('xml') ? res.body : '',
    };
  }

  return { url, chain, status: 0, finalUrl: current, error: `>${MAX_HOPS} redirects`, body: '' };
}

// Regex rather than a DOM parser, on purpose: one file, no dependencies, and the
// tags we care about are machine-generated by Rank Math in a fixed shape. If this
// ever has to understand real markup, reach for a parser instead of nesting more
// regex.
const grab = (html, re) => {
  const m = html.match(re);
  return m ? m[1].trim() : null;
};

function readSeo(html) {
  return {
    title: grab(html, /<title>([^<]*)<\/title>/i),
    robots: grab(html, /<meta\s+name="robots"\s+content="([^"]*)"/i),
    canonical: grab(html, /<link\s+rel="canonical"\s+href="([^"]*)"/i),
    ogUrl: grab(html, /<meta\s+property="og:url"\s+content="([^"]*)"/i),
  };
}

// "index" is the default, so absence of a robots meta is indexable, and so is
// anything that does not say noindex. Rank Math writes both orders — `index,
// follow` and `follow, noindex` — so match the token, not the string.
const isIndexable = (robots) => !robots || !/\bnoindex\b/i.test(robots);

// Compare by origin+pathname. A canonical that differs only by a trailing slash or
// a query string is untidy but is not the defect this is hunting; a canonical that
// points at a different page is.
function samePage(a, b) {
  try {
    const x = new URL(a);
    const y = new URL(b);
    const norm = (p) => (p.endsWith('/') ? p : `${p}/`);
    return x.origin === y.origin && norm(x.pathname) === norm(y.pathname);
  } catch {
    return false;
  }
}

// ---------------------------------------------------------------------------
// Discovering what the site says it has
// ---------------------------------------------------------------------------

// Every URL the site advertises to crawlers. Reading the sitemap rather than
// hardcoding a list means this keeps measuring the right pages after someone adds
// one, and it also means a site that publishes no sitemap gets an empty set rather
// than a false green.
function sitemapUrls() {
  const index = chase(`${ORIGIN}/sitemap_index.xml`);
  const locs = (s) => [...s.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].trim());

  if (index.status !== 200) return { urls: [], error: `sitemap_index.xml -> ${index.status}` };

  const children = locs(index.body);
  const urls = new Set();

  for (const child of children) {
    const page = chase(child);
    if (page.status === 200) locs(page.body).forEach((u) => urls.add(u));
  }

  return { urls: [...urls], children };
}

// ---------------------------------------------------------------------------
// The checks
// ---------------------------------------------------------------------------

const results = [];
const check = (id, ok, detail) => results.push({ id, ok, detail });

// ---------------------------------------------------------------------------
// The check on the check
// ---------------------------------------------------------------------------

// `samePage` and `isIndexable` are the two pure functions every verdict above
// routes through, and both have a failure mode that is invisible from the output:
// if they returned a constant, this whole file would print a confident table and
// assert nothing. Same reasoning as ci/verify-lint-selftest.sh — needs no network,
// runs in milliseconds, and is here so nobody has to trust the table.
//
//   node ci/live-seo-probe.mjs --selftest
function selftest() {
  const cases = [
    // [name, actual, expected]
    ['identical URLs are the same page', samePage('https://a.com/x/', 'https://a.com/x/'), true],
    ['trailing slash is not a difference', samePage('https://a.com/x', 'https://a.com/x/'), true],
    ['a different path is a different page', samePage('https://a.com/x/', 'https://a.com/y/'), false],
    ['the coming-soon canonical is caught', samePage('https://a.com/template/coming-soon/', 'https://a.com/shop/'), false],
    ['a different host is a different page', samePage('https://a.com/x/', 'https://b.com/x/'), false],
    ['garbage is never a match', samePage('not-a-url', 'https://a.com/x/'), false],
    ['a missing robots meta is indexable', isIndexable(null), true],
    ['"index, follow" is indexable', isIndexable('index, follow'), true],
    ['"noindex, follow" is not', isIndexable('noindex, follow'), false],
    ['"follow, noindex" is not — order must not matter', isIndexable('follow, noindex'), false],
    ['NOINDEX is not — case must not matter', isIndexable('FOLLOW, NOINDEX'), false],
    // "noindex" must be matched as a word. A substring match would read the
    // perfectly ordinary "max-image-preview" family as a noindex and silently
    // stop reporting indexable pages.
    ['"index, follow, max-snippet:-1" is indexable', isIndexable('index, follow, max-snippet:-1, max-image-preview:large'), true],
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
  const measured = [];
  const probe = (path, tag) => {
    const r = chase(path.startsWith('http') ? path : `${ORIGIN}${path}`);
    const seo = r.body ? readSeo(r.body) : {};
    // Keep the query string in the label. The sitemap advertises `/?mailpoet_page=
    // subscriptions` and `/?mailpoet_page=captcha` alongside `/`, and stripping the
    // query renders three different pages as three identical rows reading `/`.
    const label = path.startsWith('http')
      ? new URL(path).pathname + new URL(path).search
      : path;
    const row = { tag, path: label, ...r, ...seo };
    delete row.body;
    measured.push(row);
    return row;
  };

  const { urls: advertised, error: sitemapError } = sitemapUrls();

  for (const u of advertised) probe(u, 'sitemap');
  for (const { path, kind } of MUST_NOT_EXIST) probe(path, kind);
  const robotsTxt = chase(`${ORIGIN}/robots.txt`);
  const funnel = probe(FUNNEL, 'funnel');

  // Nothing below can pass on an empty set. Both canonical checks are "no row in
  // this set is wrong", so zero rows is a green light that measured nothing — the
  // same shape of lie as the Cloudflare challenge, arriving through a different
  // door. If the site advertises no pages, that is itself the finding.
  const sitemapRows = measured.filter((r) => r.tag === 'sitemap').length;
  check(
    'sitemap-readable',
    sitemapRows > 0,
    sitemapRows > 0
      ? `${sitemapRows} page(s) advertised to crawlers`
      : sitemapError || 'the sitemap advertises no URLs — the canonical checks below are vacuous'
  );

  // -- 1 -------------------------------------------------------------------
  // Dead and never-existed URLs must say so with a status code. A 200 on a page
  // titled "Page Not Found" is a soft 404: crawlers keep the URL, the index never
  // sheds it, and Search Console counts it against the site.
  const soft404s = measured.filter(
    (r) => (r.tag === 'dead' || r.tag === 'never') && r.status === 200
  );
  check(
    'no-soft-404s',
    soft404s.length === 0,
    soft404s.length
      ? `${soft404s.length} URL(s) answer 200 that should be 404/410: ` +
        soft404s.map((r) => r.path).join(', ')
      : `all ${MUST_NOT_EXIST.length} absent URLs return a real error status`
  );

  // -- 2 -------------------------------------------------------------------
  // The original TOG-71 headline. Kept after it stopped failing, because "it got
  // fixed" and "it stays fixed" are different facts and only one of them is worth
  // anything six months from now.
  const indexableGhosts = measured.filter(
    (r) => (r.tag === 'dead' || r.tag === 'never') && r.status === 200 && isIndexable(r.robots)
  );
  check(
    'absent-urls-not-indexable',
    indexableGhosts.length === 0,
    indexableGhosts.length
      ? `${indexableGhosts.length} absent URL(s) are indexable: ` +
        indexableGhosts.map((r) => `${r.path} (${r.robots})`).join(', ')
      : 'no absent URL invites indexing'
  );

  // -- 3 -------------------------------------------------------------------
  // A canonical pointing somewhere else is a page asking to be dropped in favour
  // of that other page. When every real page names the same other page, the whole
  // site collapses onto one URL in the index.
  const foreignCanonicals = measured.filter(
    (r) =>
      r.tag === 'sitemap' &&
      r.status === 200 &&
      r.canonical &&
      !samePage(r.canonical, r.finalUrl)
  );
  check(
    'self-canonical',
    foreignCanonicals.length === 0,
    foreignCanonicals.length
      ? `${foreignCanonicals.length} page(s) point their canonical at another URL: ` +
        foreignCanonicals.map((r) => `${r.path} -> ${r.canonical}`).join(', ')
      : 'every advertised page is its own canonical'
  );

  // -- 4 -------------------------------------------------------------------
  // Same defect through the other tag. og:url drives what gets shown when the link
  // is pasted into Discord, which for this company is the link that matters most.
  const foreignOgUrls = measured.filter(
    (r) => r.tag === 'sitemap' && r.status === 200 && r.ogUrl && !samePage(r.ogUrl, r.finalUrl)
  );
  check(
    'self-og-url',
    foreignOgUrls.length === 0,
    foreignOgUrls.length
      ? `${foreignOgUrls.length} page(s) share a foreign og:url — a Discord paste of any of ` +
        `them will unfurl as ${foreignOgUrls[0].ogUrl}`
      : 'every advertised page unfurls as itself'
  );

  // -- 5 -------------------------------------------------------------------
  // robots.txt has to be a text file at exactly that path. A redirect to an HTML
  // page means there is no robots.txt, which means the sitemap is never announced.
  const robotsOk =
    robotsTxt.status === 200 &&
    robotsTxt.chain.length === 0 &&
    (robotsTxt.contentType || '').includes('text/plain');
  check(
    'robots-txt-served',
    robotsOk,
    robotsOk
      ? 'robots.txt is served as text/plain at /robots.txt'
      : `/robots.txt: ${
          robotsTxt.chain.length
            ? `${robotsTxt.chain[0].status} -> ${robotsTxt.finalUrl}, `
            : ''
        }${robotsTxt.status} ${robotsTxt.contentType || 'no content-type'}`
  );

  // -- 6 -------------------------------------------------------------------
  // The apex sends `vary: accept` and means it: `Accept: */*` gets the WordPress
  // front page, with Rank Math's `index, follow`, a self-canonical and full
  // schema. `Accept: text/html,...` — every browser, and Googlebot — gets the
  // Bricks coming-soon template with no robots meta, no canonical and no schema
  // at all. Two different documents at one URL, and which one you see depends on
  // a header nobody thinks about.
  //
  // This is why it is a check and not a footnote: every quick `curl -I` anyone
  // runs against this host reports the variant that no crawler will ever be
  // served. docs/dns.md recorded the apex as `index, follow` for exactly that
  // reason. If you are about to contradict this file with a one-line curl, send
  // the browser Accept header first.
  const asScript = readSeo(chase(`${ORIGIN}/`, SCRIPT_ACCEPT).body || '');
  const asBrowser = readSeo(chase(`${ORIGIN}/`, BROWSER_ACCEPT).body || '');
  const drift = ['title', 'robots', 'canonical'].filter((k) => asScript[k] !== asBrowser[k]);
  check(
    'homepage-same-for-crawlers-and-scripts',
    drift.length === 0,
    drift.length
      ? `/ serves different HTML per Accept header; ${drift.join(', ')} differ. ` +
        `*/* sees robots=${asScript.robots ?? 'none'} canonical=${asScript.canonical ?? 'none'}; ` +
        `a browser sees robots=${asBrowser.robots ?? 'none'} canonical=${asBrowser.canonical ?? 'none'}`
      : '/ serves the same document to scripts and browsers'
  );

  // -- 7 -------------------------------------------------------------------
  // Not an SEO check. The funnel either works or nothing else on this page is
  // worth reading. See "/discord is launch-blocking" in docs/dns.md.
  const funnelAlive = funnel.chain.length > 0 || funnel.status === 200;
  check(
    'discord-funnel-alive',
    funnelAlive,
    funnelAlive
      ? `${FUNNEL} -> ${funnel.chain[0]?.status ?? funnel.status} ${
          funnel.chain.length ? new URL(funnel.finalUrl).host : ''
        }`
      : `${FUNNEL} returned ${funnel.status} — the only conversion path on the site is down`
  );

  // -----------------------------------------------------------------------

  if (asJson) {
    console.log(JSON.stringify({ host: HOST, measured, checks: results }, null, 2));
  } else {
    console.log(`\nLive SEO probe — ${ORIGIN}\n`);
    const pad = (s, n) => String(s ?? '').padEnd(n).slice(0, n);
    console.log(
      pad('URL', 34) + pad('STATUS', 8) + pad('ROBOTS', 24) + 'CANONICAL'
    );
    console.log('-'.repeat(110));
    for (const r of measured) {
      const status = r.chain.length ? `${r.chain[0].status}>${r.status}` : `${r.status}`;
      console.log(
        pad(r.path, 34) +
          pad(status, 8) +
          pad(r.robots ?? '(none = indexable)', 24) +
          (r.canonical ?? '(none)')
      );
    }
    console.log('');
    for (const c of results) {
      console.log(`${c.ok ? 'PASS' : 'FAIL'}  ${pad(c.id, 40)} ${c.detail}`);
    }
    const failed = results.filter((c) => !c.ok).length;
    console.log(
      `\n${results.length - failed}/${results.length} checks pass` +
        (failed ? ` — ${failed} FAILING\n` : '\n')
    );
  }

  process.exit(results.some((c) => !c.ok) ? 1 : 0);
}

if (args.includes('--selftest')) selftest();
else main();
