// A gzip front-end for the budgets job.
//
// `php artisan serve` is PHP's built-in web server and it never compresses
// anything, at all, for any content type. Every real host we would ever deploy
// behind — nginx, Caddy, Cloudflare, Coolify's Traefik — compresses text by
// default. So a budget measured directly against `artisan serve` is not
// measuring what production serves, which is the one thing the budgets job says
// in its own comments that it exists to do.
//
// The gap is not academic. Filament ships a 603KB stylesheet that gzips to 63KB,
// and that single resource is render-blocking on the moderator panel. Measured
// uncompressed, /admin reported LCP 6.76s against a 2.0s budget; with
// compression, on the same commit and the same machine, 2.74s. Roughly four
// seconds of that "breach" was an artifact of the harness. Relaxing the CEO's
// budget to accommodate it would have been the wrong fix to a problem that was
// never on the page.
//
// It compresses only what a host would compress, and only when the client asks
// (Lighthouse and Puppeteer both send Accept-Encoding). It deliberately does NOT
// add caching headers, HTTP/2, or anything else a CDN would give us — the point
// is to close one specific measurement lie, not to flatter the numbers. Anything
// else that is slow stays slow and still fails the build.

import http from 'node:http';
import zlib from 'node:zlib';

const UPSTREAM_PORT = Number(process.env.PROXY_UPSTREAM_PORT || 8001);
const LISTEN_PORT = Number(process.env.PROXY_LISTEN_PORT || 8000);
const HOST = '127.0.0.1';

// The same list nginx's default `gzip_types` covers, less the ones we never
// serve. Images and fonts are already compressed formats — gzipping a woff2
// makes it bigger.
const COMPRESSIBLE = /^(?:text\/|application\/(?:javascript|json|xml)|image\/svg\+xml)/;

const server = http.createServer((req, res) => {
  const upstream = http.request(
    {
      host: HOST,
      port: UPSTREAM_PORT,
      path: req.url,
      method: req.method,
      headers: {
        ...req.headers,
        // The app must generate absolute URLs on the port the browser is talking
        // to, or its own stylesheets resolve to an origin the browser never
        // visits and the whole exercise measures nothing.
        host: `${HOST}:${LISTEN_PORT}`,
        // Ask upstream for plain bytes so there is exactly one compression step.
        'accept-encoding': 'identity',
      },
    },
    (upstreamRes) => {
      const type = String(upstreamRes.headers['content-type'] || '');
      const clientAcceptsGzip = /\bgzip\b/.test(String(req.headers['accept-encoding'] || ''));
      const headers = { ...upstreamRes.headers };

      if (COMPRESSIBLE.test(type) && clientAcceptsGzip) {
        // Length changes when we compress, and a stale Content-Length truncates
        // the response — which shows up as a blank page, not as an error.
        delete headers['content-length'];
        headers['content-encoding'] = 'gzip';
        headers.vary = headers.vary ? `${headers.vary}, Accept-Encoding` : 'Accept-Encoding';

        res.writeHead(upstreamRes.statusCode ?? 502, headers);
        upstreamRes.pipe(zlib.createGzip()).pipe(res);

        return;
      }

      res.writeHead(upstreamRes.statusCode ?? 502, headers);
      upstreamRes.pipe(res);
    }
  );

  upstream.on('error', (error) => {
    // Loud rather than a hang: a dead upstream otherwise surfaces as a
    // Lighthouse timeout thirty seconds later with no cause attached.
    console.error(`::error::compressing proxy could not reach the app: ${error.message}`);
    res.writeHead(502, { 'content-type': 'text/plain' });
    res.end(`upstream unreachable: ${error.message}`);
  });

  req.pipe(upstream);
});

server.listen(LISTEN_PORT, HOST, () => {
  console.log(`compressing proxy: ${HOST}:${LISTEN_PORT} -> ${HOST}:${UPSTREAM_PORT}`);
});
