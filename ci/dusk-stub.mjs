import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'

const port = Number(process.env.DUSK_STUB_PORT ?? 8765)
const receiptPath = process.env.DUSK_BOT_RECEIPT ?? 'storage/logs/dusk-bot-receipt.json'
const callsPath = process.env.DUSK_STUB_CALLS ?? 'storage/logs/dusk-stub-calls.jsonl'

function json(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json' })
  res.end(JSON.stringify(body))
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []
    req.on('data', chunk => chunks.push(chunk))
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
    req.on('error', reject)
  })
}

function recordCall(method, pathname) {
  fs.mkdirSync(path.dirname(callsPath), { recursive: true })
  fs.appendFileSync(callsPath, `${JSON.stringify({ method, pathname })}\n`)
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`)
  recordCall(req.method, url.pathname)

  if (req.method === 'GET' && url.pathname === '/up') {
    return json(res, 200, { ok: true })
  }

  if (req.method === 'GET' && url.pathname === '/oauth2/authorize') {
    const callback = new URL(url.searchParams.get('redirect_uri'))
    callback.searchParams.set('code', 'dusk-code')
    callback.searchParams.set('state', url.searchParams.get('state') ?? '')
    res.writeHead(302, { Location: callback.toString() })
    return res.end()
  }

  if (req.method === 'POST' && url.pathname === '/oauth2/token') {
    return json(res, 200, {
      access_token: 'dusk-access-token',
      token_type: 'Bearer',
      expires_in: 3600,
      scope: 'identify guilds.members.read',
    })
  }

  if (req.method === 'GET' && url.pathname === '/users/@me') {
    return json(res, 200, {
      id: '111222333444555666',
      username: 'wren',
      global_name: 'Wren',
      discriminator: '0',
      avatar: 'abc123',
    })
  }

  if (req.method === 'GET' && url.pathname.match(/^\/api\/v10\/users\/@me\/guilds\/[^/]+\/member$/)) {
    return json(res, 200, {
      roles: ['900000000000000007'],
      joined_at: '2024-03-01T12:00:00.000000+00:00',
      nick: null,
    })
  }

  if (req.method === 'POST' && url.pathname === '/internal/actions') {
    const raw = await readBody(req)
    const body = JSON.parse(raw)

    fs.mkdirSync(path.dirname(receiptPath), { recursive: true })
    fs.writeFileSync(receiptPath, JSON.stringify({
      body,
      headers: {
        idempotencyKey: req.headers['idempotency-key'] ?? null,
        keyId: req.headers['x-two-key-id'] ?? null,
      },
    }, null, 2))

    return json(res, 200, {
      ok: true,
      result: { outcome: 'created', event_id: '1234567890' },
      request_id: 'dusk-bot-request',
    })
  }

  return json(res, 404, { ok: false, path: url.pathname })
})

server.listen(port, '127.0.0.1', () => {
  console.log(`Dusk stub listening on http://127.0.0.1:${port}`)
})
