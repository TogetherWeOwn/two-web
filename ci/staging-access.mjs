import { execFileSync } from 'node:child_process';

const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';

export const STAGING_HOST = 'staging.togetherweown.com';
export const APEX_HOST = 'togetherweown.com';
export const CONTROL_HOST = 'nonexistent-probe-tog1284.togetherweown.com';
export const STAGING_ROBOTS_TAG = 'noindex, nofollow';

export const PASS = 'PASS';
export const FAIL = 'FAIL';

function uniqueSorted(values) {
  return [...new Set(values)].sort();
}

export function resolveAddressFamilies(host, run = execFileSync) {
  const addresses = { ipv4: [], ipv6: [] };
  for (const [family, database] of [
    ['ipv4', 'ahostsv4'],
    ['ipv6', 'ahostsv6'],
  ]) {
    try {
      addresses[family] = uniqueSorted(
        run('getent', [database, host], { encoding: 'utf8' })
          .split('\n')
          .map((line) => line.trim().split(/\s+/)[0])
          .filter(Boolean),
      );
    } catch {
      // getent exits 2 when this family has no answer. The other family may still resolve.
    }
  }
  return addresses;
}

function curlConfig(headers) {
  return Object.entries(headers)
    .map(([name, value]) => {
      if (!/^[A-Za-z0-9-]+$/.test(name) || /[\r\n]/.test(value)) {
        throw new Error('invalid HTTP header');
      }
      const escaped = `${name}: ${value}`.replaceAll('\\', '\\\\').replaceAll('"', '\\"');
      return `header = "${escaped}"`;
    })
    .join('\n');
}

export function request(url, { headers = {}, run = execFileSync } = {}) {
  const args = [
    '--silent',
    '--show-error',
    '--dump-header', '-',
    '--output', '-',
    '--write-out', '\n__STATUS__%{http_code} %{redirect_url} %{remote_ip}',
    '--max-time', '25',
    '--user-agent', UA,
    '--header', 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  ];
  const config = curlConfig(headers);
  if (config) args.push('--config', '-');
  args.push(url);

  let raw;
  try {
    raw = run('curl', args, {
      encoding: 'utf8',
      input: config || undefined,
      maxBuffer: 32 * 1024 * 1024,
    });
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
  const separator = payload.search(/\r?\n\r?\n/);
  const rawHeaders = separator === -1 ? payload : payload.slice(0, separator);
  const responseHeaders = {};
  for (const line of rawHeaders.split(/\r?\n/)) {
    const index = line.indexOf(':');
    if (index < 1 || line.startsWith('HTTP/')) continue;
    responseHeaders[line.slice(0, index).toLowerCase()] = line.slice(index + 1).trim();
  }

  return {
    status: Number(status),
    redirect: redirect || responseHeaders.location || null,
    remoteIp: remoteIp || null,
    headers: responseHeaders,
    body: separator === -1 ? '' : payload.slice(separator).trimStart(),
  };
}

export function isCloudflareAccessRedirect(location) {
  if (!location) return false;
  try {
    const target = new URL(location);
    const labels = target.hostname.split('.');
    return (
      target.protocol === 'https:' &&
      labels.length === 3 &&
      labels[0].length > 0 &&
      labels[1] === 'cloudflareaccess' &&
      labels[2] === 'com'
    );
  } catch {
    return false;
  }
}

export function checkStagingAccess({
  resolve = resolveAddressFamilies,
  probe,
  env = process.env,
  host = STAGING_HOST,
  apexHost = APEX_HOST,
  controlHost = CONTROL_HOST,
} = {}) {
  const results = [];
  const record = (status, name, detail) => results.push({ status, name, detail });
  const makeRequest = probe ?? ((url, options = {}) => request(url, options));

  const control = resolve(controlHost);
  const controlAddresses = [...control.ipv4, ...control.ipv6];
  record(
    controlAddresses.length === 0 ? PASS : FAIL,
    'no-wildcard',
    controlAddresses.length === 0
      ? 'a nonexistent sibling is NXDOMAIN — no wildcard record'
      : `a nonexistent sibling resolves (${controlAddresses.join(', ')}) — staging cannot be attributed to its own record`,
  );

  const apex = resolve(apexHost);
  for (const [family, label] of [
    ['ipv4', 'IPv4'],
    ['ipv6', 'IPv6'],
  ]) {
    record(
      apex[family].length > 0 ? PASS : FAIL,
      `apex-${family}`,
      apex[family].length > 0
        ? `${apexHost} resolves over ${label} (${apex[family].join(', ')})`
        : `${apexHost} has no ${label} answer — the zone control is unavailable`,
    );
  }

  const staging = resolve(host);
  for (const [family, label] of [
    ['ipv4', 'IPv4'],
    ['ipv6', 'IPv6'],
  ]) {
    record(
      staging[family].length > 0 ? PASS : FAIL,
      `staging-${family}`,
      staging[family].length > 0
        ? `${host} resolves over ${label} (${staging[family].join(', ')})`
        : `${host} has no ${label} answer`,
    );
  }
  record(
    controlAddresses.length === 0 && staging.ipv4.length > 0 && staging.ipv6.length > 0
      ? PASS
      : FAIL,
    'staging-own-record',
    controlAddresses.length === 0 && staging.ipv4.length > 0 && staging.ipv6.length > 0
      ? `${host} resolves in both families while the sibling control is NXDOMAIN — staging is intentionally published`
      : `${host} is not proven to be an intentional dual-stack record`,
  );

  const unauthenticated = makeRequest(`https://${host}/`);
  if (unauthenticated.error) {
    record(FAIL, 'staging-access-redirect', `unauthenticated probe failed: ${unauthenticated.error}`);
  } else {
    const location = unauthenticated.headers?.location ?? unauthenticated.redirect;
    const accessRedirect =
      unauthenticated.status === 302 && isCloudflareAccessRedirect(location);
    record(
      accessRedirect ? PASS : FAIL,
      'staging-access-redirect',
      accessRedirect
        ? `unauthenticated GET returns HTTP 302 to ${new URL(location).hostname}`
        : `unauthenticated GET returned HTTP ${unauthenticated.status}${
            location ? ` with redirect target ${location}` : ' with no redirect target'
          } — expected 302 to a *.cloudflareaccess.com host`,
    );
  }

  const clientId = env.CF_ACCESS_CLIENT_ID;
  const clientSecret = env.CF_ACCESS_CLIENT_SECRET;
  if (!clientId || !clientSecret) {
    const detail =
      'CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET are required to prove authenticated /up';
    record(FAIL, 'staging-authenticated-up', detail);
    record(FAIL, 'staging-noindex-header', `${detail} and its X-Robots-Tag header`);
  } else {
    const authenticated = makeRequest(`https://${host}/up`, {
      headers: {
        'CF-Access-Client-Id': clientId,
        'CF-Access-Client-Secret': clientSecret,
      },
    });
    record(
      !authenticated.error && authenticated.status === 200 ? PASS : FAIL,
      'staging-authenticated-up',
      authenticated.error
        ? `authenticated /up probe failed: ${authenticated.error}`
        : `authenticated GET /up returned HTTP ${authenticated.status}${
            authenticated.status === 200 ? '' : ' — expected 200'
          }`,
    );
    const robotsTag = authenticated.headers?.['x-robots-tag'];
    record(
      !authenticated.error && robotsTag === STAGING_ROBOTS_TAG ? PASS : FAIL,
      'staging-noindex-header',
      authenticated.error
        ? `authenticated /up probe failed before the X-Robots-Tag header could be checked: ${authenticated.error}`
        : robotsTag === STAGING_ROBOTS_TAG
          ? `authenticated GET /up returns X-Robots-Tag: ${STAGING_ROBOTS_TAG}`
          : `authenticated GET /up returned X-Robots-Tag: ${robotsTag ?? '(missing)'} — expected exactly ${STAGING_ROBOTS_TAG}`,
    );
  }

  return results;
}
