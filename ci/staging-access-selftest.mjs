import assert from 'node:assert/strict';
import { checkStagingAccess, isCloudflareAccessRedirect, request } from './staging-access.mjs';

const addresses = (ipv4 = [], ipv6 = []) => ({ ipv4, ipv6 });
const successDns = new Map([
  ['nonexistent-probe-tog1284.togetherweown.com', addresses()],
  ['togetherweown.com', addresses(['104.21.1.1'], ['2606:4700::1'])],
  ['staging.togetherweown.com', addresses(['104.21.1.1'], ['2606:4700::1'])],
]);
const successProbe = (url, options = {}) => {
  if (url.endsWith('/up')) {
    assert.equal(options.headers['CF-Access-Client-Id'], 'test-id');
    assert.equal(options.headers['CF-Access-Client-Secret'], 'test-secret');
    return { status: 200, headers: {} };
  }
  return {
    status: 302,
    headers: { location: 'https://two.cloudflareaccess.com/cdn-cgi/access/login/app' },
  };
};
const run = ({ dns = successDns, probe = successProbe, env } = {}) =>
  checkStagingAccess({
    resolve: (host) => dns.get(host) ?? addresses(),
    probe,
    env: env ?? { CF_ACCESS_CLIENT_ID: 'test-id', CF_ACCESS_CLIENT_SECRET: 'test-secret' },
  });
const result = (results, name) => results.find((item) => item.name === name);

const success = run();
assert.equal(success.length, 8);
assert.equal(success.every((item) => item.status === 'PASS'), true);
console.log('ok  success fixture passes dual-stack DNS, Access redirect and authenticated /up');

const ipv6Missing = new Map(successDns);
ipv6Missing.set('staging.togetherweown.com', addresses(['104.21.1.1']));
const ipv6Failure = run({ dns: ipv6Missing });
assert.equal(result(ipv6Failure, 'staging-ipv6').status, 'FAIL');
assert.equal(result(ipv6Failure, 'staging-own-record').status, 'FAIL');
console.log('ok  missing staging IPv6 fails the DNS gate');

const wildcardDns = new Map(successDns);
wildcardDns.set(
  'nonexistent-probe-tog1284.togetherweown.com',
  addresses(['104.21.1.1'], ['2606:4700::1']),
);
assert.equal(result(run({ dns: wildcardDns }), 'no-wildcard').status, 'FAIL');
console.log('ok  wildcard DNS fails attribution of the staging record');

for (const fixture of [
  { status: 200, headers: {}, label: 'public HTTP 200' },
  {
    status: 302,
    headers: { location: 'https://example.com/login' },
    label: 'redirect outside Cloudflare Access',
  },
  {
    status: 301,
    headers: { location: 'https://two.cloudflareaccess.com/login' },
    label: 'wrong redirect status',
  },
]) {
  const failed = run({
    probe: (url, options) =>
      url.endsWith('/up') ? successProbe(url, options) : fixture,
  });
  assert.equal(result(failed, 'staging-access-redirect').status, 'FAIL');
  console.log(`ok  ${fixture.label} fails the unauthenticated Access gate`);
}

const missingToken = run({ env: {} });
assert.equal(result(missingToken, 'staging-authenticated-up').status, 'FAIL');
assert.equal(JSON.stringify(missingToken).includes('test-secret'), false);
console.log('ok  missing service token fails without exposing a value');

const unhealthy = run({
  probe: (url, options) =>
    url.endsWith('/up') ? { status: 503, headers: {} } : successProbe(url, options),
});
assert.equal(result(unhealthy, 'staging-authenticated-up').status, 'FAIL');
console.log('ok  authenticated /up must return HTTP 200');

assert.equal(isCloudflareAccessRedirect('https://team.cloudflareaccess.com/login'), true);
assert.equal(isCloudflareAccessRedirect('https://nested.team.cloudflareaccess.com/login'), false);
assert.equal(isCloudflareAccessRedirect('https://cloudflareaccess.com.evil.example/login'), false);
assert.equal(isCloudflareAccessRedirect('http://team.cloudflareaccess.com/login'), false);
console.log('ok  redirect hostname matcher rejects lookalikes and non-HTTPS targets');

let invocation;
const curlRaw =
  'HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\nhealthy\n__STATUS__200  104.21.1.1';
const secret = 'secret-not-in-argv';
const response = request('https://staging.togetherweown.com/up', {
  headers: {
    'CF-Access-Client-Id': 'test-id',
    'CF-Access-Client-Secret': secret,
  },
  run: (file, args, options) => {
    invocation = { file, args, options };
    return curlRaw;
  },
});
assert.equal(response.status, 200);
assert.equal(invocation.file, 'curl');
assert.equal(invocation.args.includes('--config'), true);
assert.equal(invocation.args.join(' ').includes(secret), false);
assert.equal(invocation.options.input.includes(secret), true);
console.log('ok  service-token values go through curl stdin, never argv');

console.log('\n11/11 ok');
