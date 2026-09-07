import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

import cloudflareAccess from './cloudflare-access.cjs';

const require = createRequire(import.meta.url);
const { accessHeaders, challengeReason, isAccessLoginUrl } = cloudflareAccess;
const ID = 'fixture-client-id';
const SECRET = 'fixture-secret-never-print';

const headers = accessHeaders({
  CF_ACCESS_CLIENT_ID: ID,
  CF_ACCESS_CLIENT_SECRET: SECRET,
});
assert.deepEqual(headers, {
  'CF-Access-Client-Id': ID,
  'CF-Access-Client-Secret': SECRET,
});
console.log('ok  exact Cloudflare Access header names are configured');

for (const env of [
  { CF_ACCESS_CLIENT_ID: ID },
  { CF_ACCESS_CLIENT_SECRET: SECRET },
]) {
  assert.throws(() => accessHeaders(env), /CF_ACCESS_CLIENT_(ID|SECRET)/);
}
console.log('ok  a partial credential pair fails and names only the missing variable');

assert.equal(isAccessLoginUrl('https://team.cloudflareaccess.com/cdn-cgi/access/login/app'), true);
assert.equal(isAccessLoginUrl('https://cloudflareaccess.com.evil.example/login'), false);
assert.equal(
  challengeReason({
    status: 200,
    finalUrl: 'https://staging.togetherweown.com/',
    title: 'Just a moment...',
    html: '<div id="cf-chl-widget">challenge</div>',
  }),
  'navigation rendered a Cloudflare challenge page',
);
console.log('ok  login and challenge matchers reject lookalikes and catch challenge markup');

const old = {
  id: process.env.CF_ACCESS_CLIENT_ID,
  secret: process.env.CF_ACCESS_CLIENT_SECRET,
  cookie: process.env.CI_SESSION_COOKIE,
};
process.env.CF_ACCESS_CLIENT_ID = ID;
process.env.CF_ACCESS_CLIENT_SECRET = SECRET;
process.env.CI_SESSION_COOKIE = 'session=test-cookie';
const pagesPath = require.resolve('../pages.cjs');
delete require.cache[pagesPath];
const pages = require('../pages.cjs');
assert.equal(pages.extraHeaders.Cookie, 'session=test-cookie');
assert.equal(pages.extraHeaders['CF-Access-Client-Id'], ID);
assert.equal(pages.extraHeaders['CF-Access-Client-Secret'], SECRET);
const lighthousePath = require.resolve('../lighthouserc.cjs');
delete require.cache[lighthousePath];
const lighthouse = require('../lighthouserc.cjs');
assert.strictEqual(lighthouse.ci.collect.settings.extraHeaders, pages.extraHeaders);
console.log('ok  Lighthouse receives cookie and Access headers under collect.settings');

for (const [key, value] of Object.entries(old)) {
  const envName = { id: 'CF_ACCESS_CLIENT_ID', secret: 'CF_ACCESS_CLIENT_SECRET', cookie: 'CI_SESSION_COOKIE' }[key];
  if (value === undefined) delete process.env[envName];
  else process.env[envName] = value;
}

console.log('\n4/4 ok');
