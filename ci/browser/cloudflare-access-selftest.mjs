import assert from 'node:assert/strict';
import { createServer } from 'node:http';

import cloudflareAccess from './cloudflare-access.cjs';
import { launch } from './launch.mjs';

const {
  accessHeaders,
  assertValidPageResponse,
  challengeReason,
  configureAccessForPage,
  isAccessLoginUrl,
} = cloudflareAccess;
const ID = 'fixture-client-id';
const SECRET = 'fixture-secret-never-print';
const requests = [];

let failed = 0;
const pass = (message) => console.log(`\x1b[32mPASS\x1b[0m  ${message}`);
const fail = (message) => {
  console.error(`\x1b[31mFAIL\x1b[0m  ${message}`);
  failed++;
};
const check = (condition, message) => (condition ? pass(message) : fail(message));
const FIXTURE = '<!doctype html><html><head><title>staging fixture</title></head><body><main><h1>ok</h1></main></body></html>';
const CHALLENGE = '<!doctype html><html><head><title>Just a moment...</title></head><body><div id="cf-chl-widget">challenge</div></body></html>';

const server = createServer((req, res) => {
  requests.push({
    url: req.url,
    id: req.headers['cf-access-client-id'],
    secret: req.headers['cf-access-client-secret'],
  });

  if (req.url === '/redirect-login') {
    res.writeHead(302, { Location: '/cdn-cgi/access/login' });
    res.end();
    return;
  }
  if (req.url === '/cdn-cgi/access/login') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><head><title>Sign in</title></head><body>Access login</body></html>');
    return;
  }
  if (req.url === '/challenge') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'cf-mitigated': 'challenge' });
    res.end(CHALLENGE);
    return;
  }
  if (req.headers['cf-access-client-id'] !== ID || req.headers['cf-access-client-secret'] !== SECRET) {
    res.writeHead(403, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><head><title>Forbidden</title></head><body>403</body></html>');
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(FIXTURE);
});

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const BASE = `http://127.0.0.1:${server.address().port}/`;
const env = { CF_ACCESS_CLIENT_ID: ID, CF_ACCESS_CLIENT_SECRET: SECRET };

const headers = accessHeaders(env);
assert.equal(headers['CF-Access-Client-Id'], ID);
assert.equal(headers['CF-Access-Client-Secret'], SECRET);
pass('the exact Cloudflare Access header names are configured');
for (const partial of [{ CF_ACCESS_CLIENT_ID: ID }, { CF_ACCESS_CLIENT_SECRET: SECRET }]) {
  assert.throws(() => accessHeaders(partial), /CF_ACCESS_CLIENT_(ID|SECRET)/);
}
pass('a partial credential pair fails before navigation and names only the missing variable');

const browser = await launch();

{
  const page = await browser.newPage();
  await configureAccessForPage(page, BASE, env);
  const response = await page.goto(BASE, { waitUntil: 'load', timeout: 10000 });
  await assertValidPageResponse(page, response);
  check(response.status() === 200, 'authenticated navigation returns HTTP 200');
  check(requests[0]?.id === ID && requests[0]?.secret === SECRET, 'the first document request carries both service-token headers');
  await page.close();
}

{
  const page = await browser.newPage();
  const before = requests.length;
  const response = await page.goto(BASE, { waitUntil: 'load', timeout: 10000 });
  await assert.rejects(() => assertValidPageResponse(page, response), /HTTP 403/);
  check(requests[before]?.id === undefined, 'the unauthenticated control sends no token and is rejected');
  await page.close();
}

{
  const page = await browser.newPage();
  await configureAccessForPage(page, `${BASE}challenge`, env);
  const response = await page.goto(`${BASE}challenge`, { waitUntil: 'load', timeout: 10000 });
  await assert.rejects(
    () => assertValidPageResponse(page, response),
    /Cloudflare marked the main document as a challenge/,
  );
  pass('cf-mitigated: challenge is rejected even with HTTP 200');
  await page.close();
}

{
  const page = await browser.newPage();
  await configureAccessForPage(page, `${BASE}redirect-login`, env);
  const response = await page.goto(`${BASE}redirect-login`, { waitUntil: 'load', timeout: 10000 });
  await assert.rejects(() => assertValidPageResponse(page, response), /Cloudflare Access login URL/);
  pass('a redirected Access login is rejected even with a final HTTP 200');
  await page.close();
}

await browser.close();
server.close();

assert.equal(isAccessLoginUrl('https://team.cloudflareaccess.com/cdn-cgi/access/login/app'), true);
assert.equal(isAccessLoginUrl('https://cloudflareaccess.com.evil.example/login'), false);
assert.equal(
  challengeReason({ status: 200, finalUrl: BASE, title: 'Just a moment...', html: CHALLENGE }),
  'navigation rendered a Cloudflare challenge page',
);
pass('login and challenge matchers reject lookalikes and catch challenge markup');

console.log(failed === 0 ? '\nall Cloudflare Access browser selftests passed' : `\n${failed} selftest(s) failed`);
process.exit(failed === 0 ? 0 : 1);
