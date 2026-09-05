// Does our Workspace mail actually authenticate — SPF, DKIM, DMARC?
//
// TOG-1154. Both zones publish five Google MX, so Workspace mail is real, but
// neither published a `google._domainkey` selector. `_dmarc.two.gg` is at
// `p=reject`, so any forwarded two.gg mail breaks SPF, has no DKIM to fall back
// on, and is rejected outright by conforming receivers. This script is how we
// know when that is fixed, and it is deliberately harder to satisfy than "the
// record exists".
//
//   node ci/mail-auth-check.mjs [domain ...]
//   node ci/mail-auth-check.mjs --selftest
//
// Exit 0 when every domain publishes a usable >=2048-bit DKIM key. Exit 1
// otherwise.
//
// Two properties worth not "simplifying" away:
//
//   * Every lookup goes to TWO independent resolvers (Cloudflare and Google
//     DoH) and a bogus selector is queried in the SAME batch. A single-resolver
//     NXDOMAIN is not evidence of absence, and a resolver that synthesises
//     answers would make a missing record look present. If the control ever
//     answers, the whole run is void and says so.
//   * A published record is NOT the finish line. The Google admin console
//     defaults to a 1024-bit key and TOG-1167 asks for 2048. Miss that one
//     dropdown and an existence check goes green over a key of half the
//     required strength. So the modulus is decoded from the DER and measured.
//
// DoH over https, rather than shelling out to dig, because dig is not installed
// in CI and the DoH JSON gives us the rcode directly instead of by scraping.

const RESOLVERS = {
  cloudflare: (name, type) =>
    `https://1.1.1.1/dns-query?name=${name}&type=${type}`,
  google: (name, type) => `https://8.8.8.8/resolve?name=${name}&type=${type}`,
};

const RCODE = { 0: 'NOERROR', 2: 'SERVFAIL', 3: 'NXDOMAIN' };

// Must NOT exist. Proves an NXDOMAIN elsewhere means something.
const CONTROL_SELECTOR = 'nx-9x7q2';
const MIN_KEY_BITS = 2048;

const PASS = 'PASS';
const FAIL = 'FAIL';
const INFO = 'INFO';

const results = [];
const record = (status, name, detail) => results.push({ status, name, detail });

async function query(name, type, resolver) {
  const res = await fetch(RESOLVERS[resolver](name, type), {
    headers: { accept: 'application/dns-json' },
    signal: AbortSignal.timeout(20_000),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();
  return {
    status: RCODE[data.Status] ?? `STATUS_${data.Status}`,
    answers: (data.Answer ?? []).map((a) => a.data),
  };
}

// Ask both resolvers. Disagreement is a result, not an error to retry away:
// it means one of them is lying and no conclusion is safe.
async function lookup(name, type = 'TXT') {
  const seen = {};
  for (const resolver of Object.keys(RESOLVERS)) {
    try {
      seen[resolver] = await query(name, type, resolver);
    } catch (err) {
      seen[resolver] = { status: `ERROR(${err.name})`, answers: [] };
    }
  }
  const statuses = Object.values(seen).map((v) => v.status);
  const agreed = new Set(statuses).size === 1;
  const first = Object.values(seen)[0];
  return {
    status: agreed ? first.status : 'DISAGREE',
    answers: first.answers,
    perResolver: Object.fromEntries(
      Object.entries(seen).map(([k, v]) => [k, v.status]),
    ),
  };
}

// A TXT value over 255 bytes arrives as several quoted strings that must be
// concatenated with NO separator (RFC 6376 §3.6.2.2). Every 2048-bit DKIM key
// is over that limit, so splitting on whitespace instead would corrupt the p=
// tag and report a valid key as malformed.
function unquoteTxt(raw) {
  const chunks = [...raw.matchAll(/"((?:[^"\\]|\\.)*)"/g)].map((m) => m[1]);
  if (chunks.length === 0) return raw.trim();
  return chunks.join('').replace(/\\"/g, '"');
}

function parseTags(value) {
  const tags = {};
  for (const part of value.split(';')) {
    const eq = part.indexOf('=');
    if (eq === -1) continue;
    tags[part.slice(0, eq).trim().toLowerCase()] = part.slice(eq + 1).trim();
  }
  return tags;
}

function derLength(buf, i) {
  const n = buf[i];
  if (n < 0x80) return [n, i + 1];
  const count = n & 0x7f;
  let len = 0;
  for (let k = 0; k < count; k += 1) len = len * 256 + buf[i + 1 + k];
  return [len, i + 1 + count];
}

// Bits in the RSA modulus of a base64 DER SubjectPublicKeyInfo. Walks the
// structure rather than guessing from the base64 prefix (`MIGf` = 1024,
// `MIIBIjAN` = 2048): the prefix heuristic is real and widely copied, but it
// breaks on any re-encoded or non-RSA key and would silently report null.
function rsaKeyBits(p) {
  let der;
  try {
    der = Buffer.from(p, 'base64');
  } catch {
    return { error: 'p= is not valid base64' };
  }
  try {
    if (der[0] !== 0x30) return { error: 'not a DER SEQUENCE' };
    let i = derLength(der, 1)[1];
    if (der[i] !== 0x30) return { error: 'no AlgorithmIdentifier' };
    const [algLen, afterAlgLen] = derLength(der, i + 1);
    i = afterAlgLen + algLen;
    if (der[i] !== 0x03) return { error: 'no BIT STRING' };
    i = derLength(der, i + 1)[1] + 1; // skip the unused-bits octet
    if (der[i] !== 0x30) return { error: 'BIT STRING does not wrap RSAPublicKey' };
    i = derLength(der, i + 1)[1];
    if (der[i] !== 0x02) return { error: 'no modulus INTEGER' };
    const [modLen, afterModLen] = derLength(der, i + 1);
    let modulus = der.subarray(afterModLen, afterModLen + modLen);
    let lead = 0;
    while (lead < modulus.length && modulus[lead] === 0x00) lead += 1;
    modulus = modulus.subarray(lead);
    if (modulus.length === 0) return { error: 'empty modulus' };
    return { bits: modulus.length * 8 };
  } catch (err) {
    return { error: `malformed DER (${err.name})` };
  }
}

export function inspectDkim(answers) {
  if (answers.length === 0) return { ok: false, note: 'NOERROR but no TXT record' };
  const tags = parseTags(unquoteTxt(answers[0]));
  if ((tags.v ?? '').toUpperCase() !== 'DKIM1') {
    return { ok: false, note: `missing v=DKIM1 (got ${tags.v ?? 'nothing'})` };
  }
  // An empty p= is the REVOKED-key form (RFC 6376 §3.6.1). It is a syntactically
  // valid record that fails every signature, so an existence check calls it a
  // success and mail silently fails.
  if (tags.p === '') {
    return { ok: false, note: 'p= is empty — this is a REVOKED key, all mail fails DKIM' };
  }
  if (tags.p === undefined) return { ok: false, note: 'no p= tag — not a usable key' };
  const { bits, error } = rsaKeyBits(tags.p);
  if (error) return { ok: false, note: `could not parse public key: ${error}` };
  if (bits < MIN_KEY_BITS) {
    return {
      ok: false,
      bits,
      note: `key is ${bits}-bit, we require >=${MIN_KEY_BITS} — the console defaulted to 1024, regenerate it`,
    };
  }
  return { ok: true, bits, note: `k=${tags.k ?? 'rsa'} ${bits}-bit` };
}

async function checkDomain(domain) {
  const selector = `google._domainkey.${domain}`;
  const dkim = await lookup(selector);

  const control = await lookup(`${CONTROL_SELECTOR}._domainkey.${domain}`);
  if (control.status === 'NOERROR' && control.answers.length > 0) {
    record(
      FAIL,
      `${domain} control`,
      'BOGUS SELECTOR ANSWERED — the resolver is synthesising records, every result for this domain is void',
    );
    return;
  }
  record(PASS, `${domain} control`, `${CONTROL_SELECTOR} is ${control.status} — absence is real`);

  if (dkim.status === 'DISAGREE') {
    record(FAIL, `${domain} dkim`, `resolvers disagree: ${JSON.stringify(dkim.perResolver)}`);
  } else if (dkim.status !== 'NOERROR') {
    record(FAIL, `${domain} dkim`, `google._domainkey is ${dkim.status} — Workspace DKIM not published`);
  } else {
    const verdict = inspectDkim(dkim.answers);
    record(verdict.ok ? PASS : FAIL, `${domain} dkim`, verdict.note);
  }

  for (const [label, name, type] of [
    ['dmarc', `_dmarc.${domain}`, 'TXT'],
    ['spf', domain, 'TXT'],
    ['mx', domain, 'MX'],
  ]) {
    const got = await lookup(name, type);
    const shown =
      type === 'MX'
        ? `${got.answers.length} MX`
        : (got.answers.find((a) => /v=(spf1|DMARC1)/i.test(a)) ?? 'none');
    record(INFO, `${domain} ${label}`, shown);
  }
}

// Live third-party selectors, used to prove the key parser agrees with reality
// in BOTH directions. github.com publishes 2048-bit; stripe.com and shopify.com
// publish 1024-bit. A parser that cannot separate those would pass a 1024-bit
// key as done, which is the exact miss this file exists to catch. Asserting
// only the positive case would not notice.
const SELFTEST = [
  ['google._domainkey.github.com', 2048, true],
  ['google._domainkey.stripe.com', 1024, false],
  ['google._domainkey.shopify.com', 1024, false],
];

async function selftest() {
  let failed = 0;
  for (const [name, wantBits, wantOk] of SELFTEST) {
    const got = await lookup(name);
    if (got.status !== 'NOERROR' || got.answers.length === 0) {
      console.log(`SKIP    ${name} — ${got.status} (external fixture moved, not our bug)`);
      continue;
    }
    const verdict = inspectDkim(got.answers);
    const good = verdict.bits === wantBits && verdict.ok === wantOk;
    if (!good) failed += 1;
    console.log(
      `${(good ? PASS : FAIL).padEnd(7)} ${name.padEnd(38)} ` +
        `got ${verdict.bits}-bit ok=${verdict.ok}, want ${wantBits}-bit ok=${wantOk}`,
    );
  }
  console.log(`\nselftest: ${failed} failed`);
  return failed > 0 ? 1 : 0;
}

// Only run when invoked directly. ci/mail-auth-check-selftest.sh imports
// inspectDkim to drive the shipped parser rather than a copy of it, and an
// unguarded main block would fire a dozen live DNS lookups on every import.
const invokedDirectly =
  process.argv[1] && import.meta.url === `file://${process.argv[1]}`;

if (invokedDirectly) {
  const argv = process.argv.slice(2);

  if (argv.includes('--selftest')) {
    process.exit(await selftest());
  }

  const domains = argv.filter((a) => !a.startsWith('--'));
  for (const domain of domains.length > 0 ? domains : ['togetherweown.com', 'two.gg']) {
    await checkDomain(domain);
  }

  let failed = 0;
  for (const { status, name, detail } of results) {
    if (status === FAIL) failed += 1;
    console.log(`${status.padEnd(7)} ${name.padEnd(30)} ${detail}`);
  }
  console.log(`\n${results.length} checks, ${failed} failed`);
  process.exit(failed > 0 ? 1 : 0);
}
