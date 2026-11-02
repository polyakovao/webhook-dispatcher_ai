// Tiny webhook receiver for local testing. No dependencies.
//
//   /ok            -> 200
//   /created       -> 201
//   /fail          -> 500
//   /gone          -> 410
//   /ratelimit     -> 429 with Retry-After: 5
//   /slow          -> hangs 30s, then 200
//   /flaky/<key>   -> 500 for the first 2 calls per key, then 200
//   GET /log       -> last 50 requests received (JSON)
const http = require('node:http');

const PORT = Number(process.env.PORT || 9000);
const counts = new Map();
const log = [];

http
  .createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks).toString();
      const url = new URL(req.url, 'http://receiver');

      if (url.pathname === '/log') {
        res.writeHead(200, { 'content-type': 'application/json' });
        return res.end(JSON.stringify(log.slice(-50), null, 2));
      }

      log.push({
        at: new Date().toISOString(),
        method: req.method,
        path: req.url,
        headers: req.headers,
        body,
      });
      if (log.length > 500) log.shift();

      const send = (code, headers = {}) => {
        res.writeHead(code, { 'content-type': 'application/json', ...headers });
        res.end(JSON.stringify({ code }));
      };

      const [, first, key] = url.pathname.split('/');
      switch (first) {
        case 'ok':
          return send(200);
        case 'created':
          return send(201);
        case 'fail':
          return send(500);
        case 'gone':
          return send(410);
        case 'ratelimit':
          return send(429, { 'Retry-After': '5' });
        case 'slow':
          return void setTimeout(() => send(200), 30000);
        case 'flaky': {
          const n = (counts.get(key) || 0) + 1;
          counts.set(key, n);
          return send(n <= 2 ? 500 : 200);
        }
        default:
          return send(404);
      }
    });
  })
  .listen(PORT, () => console.log(`receiver listening on ${PORT}`));
