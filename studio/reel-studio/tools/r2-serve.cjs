// Lane R2 harness server: serves studio/reel-studio/ on 127.0.0.1 with the COOP/COEP headers that
// multithreaded WASM needs, plus the few engine endpoints core/engine.js calls for models
// (/api/stat, /api/permission). /net/* always refuses: the harness is offline by construction.
// Usage: node tools/r2-serve.cjs [port]   (prints the URL)
const http = require('http'), fs = require('fs'), path = require('path');
const ROOT = path.resolve(__dirname, '..');
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css',
  '.json': 'application/json', '.wasm': 'application/wasm', '.onnx': 'application/octet-stream', '.woff2': 'font/woff2',
  '.png': 'image/png', '.wav': 'audio/wav', '.svg': 'image/svg+xml' };

function safe(p) {
  const full = path.resolve(ROOT, '.' + decodeURIComponent(p));
  return full.startsWith(ROOT + path.sep) || full === ROOT ? full : null;
}

const server = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  const h = { 'Cross-Origin-Opener-Policy': 'same-origin', 'Cross-Origin-Embedder-Policy': 'require-corp',
    'Cross-Origin-Resource-Policy': 'same-origin', 'Cache-Control': 'no-store' };
  if (u.pathname === '/api/stat') {
    const f = safe('/' + (u.searchParams.get('path') || ''));
    if (f && fs.existsSync(f)) { res.writeHead(200, { ...h, 'Content-Type': 'application/json' }); return res.end('{}'); }
    res.writeHead(404, h); return res.end('{"error":"not found"}');
  }
  if (u.pathname === '/api/permission') { res.writeHead(200, { ...h, 'Content-Type': 'application/json' }); return res.end('{}'); }
  if (u.pathname.startsWith('/net/') || u.pathname.startsWith('/api/')) {
    res.writeHead(403, { ...h, 'Content-Type': 'application/json' }); return res.end('{"error":"offline harness"}');
  }
  if (req.method === 'PUT' && u.pathname.startsWith('/models/_r2-test/')) { // fixture writer (harness only)
    const f = safe(u.pathname); if (!f) { res.writeHead(400); return res.end(); }
    const out = fs.createWriteStream(f); req.pipe(out); out.on('finish', () => { res.writeHead(200, h); res.end(); });
    return;
  }
  const f = safe(u.pathname === '/' ? '/app/captions/dev/index.html' : u.pathname);
  if (!f || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404, h); return res.end('not found'); }
  res.writeHead(200, { ...h, 'Content-Type': TYPES[path.extname(f)] || 'application/octet-stream', 'Content-Length': fs.statSync(f).size });
  fs.createReadStream(f).pipe(res);
});
server.listen(+process.argv[2] || 0, '127.0.0.1', () => console.log(`http://127.0.0.1:${server.address().port}/`));
module.exports = server;
