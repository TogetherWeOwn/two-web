'use strict';

const CLIENT_ID_HEADER = 'CF-Access-Client-Id';
const CLIENT_SECRET_HEADER = 'CF-Access-Client-Secret';

function accessHeaders(env = process.env, { required = false } = {}) {
  const clientId = env.CF_ACCESS_CLIENT_ID || '';
  const clientSecret = env.CF_ACCESS_CLIENT_SECRET || '';

  if (!clientId && !clientSecret) {
    if (required) {
      throw new Error(
        'CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET are required for Cloudflare Access QA.',
      );
    }

    return undefined;
  }

  if (!clientId || !clientSecret) {
    const missing = clientId ? 'CF_ACCESS_CLIENT_SECRET' : 'CF_ACCESS_CLIENT_ID';
    throw new Error(`${missing} is required when the other Cloudflare Access credential is set.`);
  }

  return {
    [CLIENT_ID_HEADER]: clientId,
    [CLIENT_SECRET_HEADER]: clientSecret,
  };
}

function isAccessLoginUrl(value) {
  let url;
  try {
    url = new URL(value);
  } catch {
    return false;
  }

  const hostname = url.hostname.toLowerCase();
  const pathname = url.pathname.toLowerCase();

  return (
    (url.protocol === 'https:' && /^[^.]+\.cloudflareaccess\.com$/.test(hostname)) ||
    pathname.startsWith('/cdn-cgi/access/login') ||
    pathname.startsWith('/cdn-cgi/access/authorized')
  );
}

async function configureAccessForPage(page, requestedUrl, env = process.env) {
  const headers = accessHeaders(env);
  if (!headers) return false;

  const origin = new URL(requestedUrl).origin;
  const session = await page.createCDPSession();
  await session.send('Network.enable');
  await session.send('Fetch.enable', {
    patterns: [{ urlPattern: `${origin}/*`, requestStage: 'Request' }],
  });
  session.on('Fetch.requestPaused', ({ requestId, request }) => {
    const requestHeaders = Object.entries({ ...request.headers, ...headers }).map(([name, value]) => ({
      name,
      value: String(value),
    }));
    session.send('Fetch.continueRequest', { requestId, headers: requestHeaders }).catch(() => {});
  });

  return true;
}

function challengeReason({ status, headers = {}, finalUrl, title = '', html = '' }) {
  if (typeof status !== 'number') return 'navigation returned no main-document response';
  if (status >= 400) return `main document returned HTTP ${status}`;
  if (String(headers['cf-mitigated'] || '').toLowerCase() === 'challenge') {
    return 'Cloudflare marked the main document as a challenge';
  }
  if (isAccessLoginUrl(finalUrl)) return `navigation ended on a Cloudflare Access login URL`;

  const lowerTitle = String(title).trim().toLowerCase();
  const lowerHtml = String(html).toLowerCase();
  const challengeTitle = lowerTitle === 'just a moment...' || lowerTitle.includes('attention required');
  const challengeMarkup =
    lowerHtml.includes('/cdn-cgi/challenge-platform/') ||
    lowerHtml.includes('cf-chl-') ||
    lowerHtml.includes('cf-error-details');

  if (challengeTitle && challengeMarkup) return 'navigation rendered a Cloudflare challenge page';
  return null;
}

async function assertValidPageResponse(page, response) {
  const reason = challengeReason({
    status: response?.status(),
    headers: response?.headers() || {},
    finalUrl: page.url(),
    title: await page.title(),
    html: await page.content(),
  });

  if (reason) throw new Error(reason);
}

function navigationOptions(env = process.env) {
  if (env.BROWSER_QA_WAIT_UNTIL === 'load') return { waitUntil: 'load' };
  return { waitUntil: 'networkidle0' };
}

module.exports = {
  CLIENT_ID_HEADER,
  CLIENT_SECRET_HEADER,
  accessHeaders,
  assertValidPageResponse,
  challengeReason,
  configureAccessForPage,
  isAccessLoginUrl,
  navigationOptions,
};
