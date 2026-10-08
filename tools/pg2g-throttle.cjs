/*
 * Lane PG2 (gallery): a forward HTTP proxy that is ONE slow phone connection.
 *
 *   node tools/pg2g-throttle.cjs PORT [kbps=1600] [rtt=150]
 *
 * Why not CDP's Network.emulateNetworkConditions: it throttles the PAGE target
 * only. With the shop's service worker in control (App -> Site App, on by
 * default) every image is re-fetched BY THE WORKER, which CDP does not slow,
 * so a throttled phone would see images arrive at loopback speed. Pointing
 * Chromium at this proxy (--proxy-server, --proxy-bypass-list=<-loopback>)
 * slows the page and its worker alike, and it shares ONE bandwidth budget
 * between every concurrent response, as a real radio link does.
 *
 * Every response is held for `rtt` ms before its first byte, then its body is
 * paced from a single shared bucket of `kbps`, round-robin across streams.
 * GET http://pg2g.control/stats returns bytes by content type since the last
 * GET http://pg2g.control/reset (read from the server), and under `sent` the
 * bytes actually paced out to the browser -- which stop when it cancels.
 */
const http = require('http');
process.on('uncaughtException', (e) => { process.stderr.write('proxy error ' + (e && e.message) + '\n'); });

const PORT = Number(process.argv[2] || 8899);
const BPS = Number(process.argv[3] || 1600) * 1000 / 8;
const RTT = Number(process.argv[4] || 150);
const TICK = 10;
let stats = {};
const active = new Set();

setInterval(() => {
  if (active.size === 0) return;
  let budget = BPS * TICK / 1000;
  let streams = [...active];
  while (budget > 0 && streams.length) {
    const share = Math.max(1, Math.floor(budget / streams.length));
    const next = [];
    for (const s of streams) {
      if (budget <= 0) break;
      const n = Math.min(share, s.buf.length - s.off, budget);
      if (n > 0) {
        try { s.res.write(s.buf.subarray(s.off, s.off + n)); } catch (e) { s.ended = true; s.off = s.buf.length; }
        s.off += n; budget -= n;
        if (s.type) { stats.sent = stats.sent || {}; stats.sent[s.type] = (stats.sent[s.type] || 0) + n; }
        if (s.path && process.env.PG2G_BYPATH) { stats.byPath = stats.byPath || {}; stats.byPath[s.path] = (stats.byPath[s.path] || 0) + n; }
      }
      if (s.off >= s.buf.length && s.ended) { s.res.end(); active.delete(s); } else if (s.off < s.buf.length) next.push(s);
    }
    streams = next;
  }
  for (const s of active) if (s.off >= s.buf.length && s.ended) { s.res.end(); active.delete(s); }
}, TICK);

http.createServer((req, res) => {
  let u;
  try { u = new URL(req.url); } catch (e) { res.writeHead(400); res.end(); return; }
  if (u.hostname === 'pg2g.control') {
    if (u.pathname === '/reset') stats = {};
    res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
    res.end(JSON.stringify(stats));
    return;
  }
  res.on('error', () => {});
  req.on('error', () => {});
  const t0 = Date.now();
  const up = http.request({ host: u.hostname, port: u.port || 80, path: u.pathname + u.search, method: req.method, headers: req.headers }, (r) => {
    const type = String(r.headers['content-type'] || 'other').split(';')[0];
    const s = { res, buf: Buffer.alloc(0), off: 0, ended: false, type, path: u.pathname };
    const wait = Math.max(0, RTT - (Date.now() - t0));
    // (Lane GX) A request the browser cancels inside the RTT must not be
    // paced out afterwards: it used to join `active` anyway and burn the
    // shared budget on a closed socket -- a whole file's worth of bandwidth
    // stolen from every other response, which no real network does.
    let closed = false;
    setTimeout(() => {
      if (closed) return;
      try { res.writeHead(r.statusCode, r.headers); } catch (e) { return; }
      active.add(s);
    }, wait);
    res.on('close', () => { closed = true; active.delete(s); r.destroy(); });
    r.on('data', (c) => { s.buf = Buffer.concat([s.buf, c]); stats[type] = (stats[type] || 0) + c.length; });
    r.on('end', () => { s.ended = true; });
  });
  up.on('error', () => { try { if (!res.headersSent) res.writeHead(502); res.end(); } catch (e) { /* closed */ } });
  req.pipe(up);
}).on('clientError', (e, sock) => sock.destroy()).listen(PORT, '127.0.0.1', () => console.log('throttle proxy on ' + PORT + ' ' + BPS * 8 / 1000 + 'kbps rtt ' + RTT));
