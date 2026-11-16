#!/usr/bin/env node
// KBB Studio — model installer check. Proves, against a local stand-in for huggingface.co:
//   1. a connection dropped mid-file RESUMES with an HTTP Range request (never starts over)
//   2. a damaged file is detected (sha256) and fetched again
//   3. a 503 is retried automatically
//   4. resume survives an ENGINE RESTART (the .part file is picked up with Range)
//   5. nothing is installed under its final name until it verifies; no .part left behind
//   6. too little disk space is refused up front with a plain message
//   7. the gate: install without the Allow grant is refused
// and, with --real, the whole whisper-base_timestamped model from the real huggingface.co through the allowlist
// (redirects to the hf.co CDNs), every file's size + checksum checked again here. --keep leaves it in tools/out/real-studio.
//
// usage: node tools/check-models.cjs [--real [--keep]]
'use strict';
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const BIN = path.join(ROOT, 'engine', 'kbbstudio-linux-amd64');
const OUT = path.join(ROOT, 'tools', 'out');
fs.mkdirSync(OUT, { recursive: true });
let failures = 0;
const ok = (cond, msg) => { console.log((cond ? 'PASS ' : 'FAIL ') + msg); if (!cond) failures++; };

function bytes(n, seed) { const b = Buffer.alloc(n); let x = seed; for (let i = 0; i < n; i++) { x = (x * 1103515245 + 12345) >>> 0; b[i] = x >>> 24; } return b; }
const sha256 = (b) => crypto.createHash('sha256').update(b).digest('hex');
const gitsha1 = (b) => crypto.createHash('sha1').update(`blob ${b.length}\0`).update(b).digest('hex');

const BIG = bytes(24 << 20, 7), BAD = bytes(3 << 20, 11), TXT = Buffer.from('{"hello":"kbb"}\n'), SLOW = bytes(12 << 20, 13);
const log = [];
const counts = {};
let slowRate = 0; // bytes per 50 ms for slow.bin (0 = full speed)

function serve(req, res) {
  const name = req.url.split('/').pop();
  counts[name] = (counts[name] || 0) + 1;
  const n = counts[name];
  const range = req.headers.range || '';
  log.push(`${name} #${n} ${range || '(no range)'}`);
  const body = { 'big.bin': BIG, 'bad.bin': BAD, 'config.json': TXT, 'slow.bin': SLOW }[name];
  if (!body) { res.writeHead(404); return res.end(); }
  if (name === 'config.json' && n === 1) { res.writeHead(503); return res.end('busy'); }
  let start = 0;
  const m = /bytes=(\d+)-/.exec(range);
  if (m) start = +m[1];
  let data = body.subarray(start);
  if (name === 'bad.bin' && n === 1) { data = Buffer.from(data); data[1000] ^= 0xff; }   // damaged on the first try
  res.writeHead(start ? 206 : 200, Object.assign({ 'Content-Length': data.length, 'Accept-Ranges': 'bytes' }, start ? { 'Content-Range': `bytes ${start}-${body.length - 1}/${body.length}` } : {}));
  if (name === 'big.bin' && n <= 2) {                // drop the connection part-way, twice
    const cut = n === 1 ? 9 << 20 : 6 << 20;
    res.write(data.subarray(0, cut), () => setTimeout(() => req.socket.destroy(), 50));
    return;
  }
  if (name === 'slow.bin' && slowRate) {
    let off = 0;
    const tick = () => { if (res.destroyed) return; const k = Math.min(slowRate, data.length - off); res.write(data.subarray(off, off + k)); off += k; if (off < data.length) setTimeout(tick, 50); else res.end(); };
    return tick();
  }
  res.end(data);
}

function makeStudio(manifest) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'kbbstudio-models-'));
  fs.mkdirSync(path.join(dir, 'app'));
  fs.copyFileSync(path.join(ROOT, 'app', 'index.html'), path.join(dir, 'app', 'index.html'));
  fs.mkdirSync(path.join(dir, 'models'));
  fs.writeFileSync(path.join(dir, 'models', 'manifest.json'), JSON.stringify(manifest));
  return dir;
}

function startEngine(dir, base) {
  return new Promise((res, rej) => {
    const args = ['--dir', dir, '--no-browser', '--idle', '600s'];
    if (base) args.push('--test-hf-base', base);
    const p = spawn(BIN, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let buf = '';
    p.stdout.on('data', async (d) => {
      buf += d; const m = /running at (http:\/\/[^\s]+)\/app\//.exec(buf);
      if (m && !p.url) {
        p.url = m[1];
        const html = await (await fetch(p.url + '/app/')).text();
        p.token = /rs-token" content="([^"]+)/.exec(html)[1];
        res(p);
      }
    });
    p.stderr.on('data', (d) => { p.log = (p.log || '') + d; fs.appendFileSync(path.join(OUT, 'check-models-engine.log'), d); });
    p.on('exit', (c) => { if (!p.url) rej(new Error('engine exited ' + c)); });
  });
}
const api = (e, p, body) => fetch(e.url + p, { method: body ? 'POST' : 'GET', headers: { 'X-RS-Token': e.token, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
const allow = (e, repo) => api(e, '/api/permission', { url: `https://huggingface.co/${repo}/`, scope: 'repo', what: 'test' });
async function waitState(e, states, ms = 120000) {
  const t0 = Date.now();
  for (;;) {
    const s = await (await api(e, '/api/models')).json();
    if (states.includes(s.installer.state)) return s;
    if (Date.now() - t0 > ms) return s;
    await new Promise((r) => setTimeout(r, 250));
  }
}
const stop = (e) => new Promise((r) => { e.on('exit', r); e.kill('SIGTERM'); });

(async () => {
  const srv = http.createServer(serve);
  await new Promise((r) => srv.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${srv.address().port}`;
  const manifest = {
    v: 1, revision: 'main', models: [
      { id: 'test-model', repo: 'test/model', required: true, purpose: 'test', files: [
        { path: 'config.json', size: TXT.length, gitBlobSha1: gitsha1(TXT) },
        { path: 'onnx/big.bin', size: BIG.length, sha256: sha256(BIG) },
        { path: 'onnx/bad.bin', size: BAD.length, sha256: sha256(BAD) },
      ] },
      { id: 'slow-model', repo: 'test/slow', required: false, purpose: 'test', files: [{ path: 'slow.bin', size: SLOW.length, sha256: sha256(SLOW) }] },
      { id: 'huge-model', repo: 'test/huge', required: false, purpose: 'test', files: [{ path: 'huge.bin', size: 50e12, sha256: '00' }] },
    ],
  };
  const dir = makeStudio(manifest);
  let e = await startEngine(dir, base);

  // 7. the gate
  let r = await api(e, '/api/models/install', { ids: ['test-model'] });
  ok(r.status === 400 && /Allow/.test((await r.json()).error), 'install without the Allow grant is refused');

  // 1-3, 5
  await allow(e, 'test/model');
  r = await api(e, '/api/models/install', { ids: ['test-model'] });
  ok(r.status === 200, 'install starts after Allow');
  let s = await waitState(e, ['done', 'stalled']);
  ok(s.installer.state === 'done', `all files installed (state ${s.installer.state}: ${s.installer.message})`);
  const bigLog = log.filter((l) => l.startsWith('big.bin'));
  ok(bigLog.length === 3 && bigLog[1].includes(`bytes=${9 << 20}-`) && bigLog[2].includes(`bytes=${15 << 20}-`), 'dropped connection resumed with Range twice: ' + bigLog.join(' | '));
  ok(log.filter((l) => l.startsWith('bad.bin')).length === 2, 'damaged file detected and fetched again');
  ok(log.filter((l) => l.startsWith('config.json')).length === 2, '503 retried automatically');
  const got = fs.readFileSync(path.join(dir, 'models/test/model/onnx/big.bin'));
  ok(sha256(got) === sha256(BIG), 'installed big.bin is byte-identical');
  const parts = [];
  (function walk(d) { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); fs.statSync(p).isDirectory() ? walk(p) : f.endsWith('.part') && parts.push(p); } })(path.join(dir, 'models'));
  ok(parts.length === 0, 'no .part files left behind');
  ok(s.models.find((m) => m.id === 'test-model').installed === true, 'status reports the model installed');

  // 4. resume across an engine restart
  await allow(e, 'test/slow');
  slowRate = 64 << 10;   // ~1.3 MB/s
  await api(e, '/api/models/install', { ids: ['slow-model'] });
  const partPath = path.join(dir, 'models/test/slow/slow.bin.part');
  for (let i = 0; i < 200 && (!fs.existsSync(partPath) || fs.statSync(partPath).size < (3 << 20)); i++) await new Promise((q) => setTimeout(q, 50));
  s = await (await api(e, '/api/models/pause', {})).json();
  ok(s.installer.state === 'paused', 'pause works');
  await stop(e);
  const had = fs.statSync(partPath).size;
  ok(had > 0 && had < SLOW.length, `partial file kept across stop (${(had / 1048576).toFixed(1)} MB)`);
  slowRate = 0;
  e = await startEngine(dir, base);
  await allow(e, 'test/slow');
  await api(e, '/api/models/install', { ids: ['slow-model'] });
  s = await waitState(e, ['done', 'stalled']);
  const slowLog = log.filter((l) => l.startsWith('slow.bin'));
  ok(s.installer.state === 'done' && /bytes=\d+-/.test(slowLog[slowLog.length - 1]) && +/bytes=(\d+)-/.exec(slowLog[slowLog.length - 1])[1] >= had - 1, 'after restart the download resumed with Range: ' + slowLog.join(' | '));
  ok(sha256(fs.readFileSync(path.join(dir, 'models/test/slow/slow.bin'))) === sha256(SLOW), 'resumed file is byte-identical');

  // 6. disk space
  await allow(e, 'test/huge');
  await api(e, '/api/models/install', { ids: ['huge-model'] });
  s = await (await api(e, '/api/models')).json();
  ok(s.installer.state === 'nospace' && /Not enough free space/.test(s.installer.message), 'too little disk is refused up front: ' + s.installer.message);
  ok(!fs.existsSync(path.join(dir, 'models/test/huge')), 'nothing written for the refused model');
  await stop(e);
  srv.close();
  fs.rmSync(dir, { recursive: true, force: true });

  if (process.argv.includes('--real')) {
    // The whole fast speech model, for real: LFS weights are redirected by huggingface.co to its CDNs
    // (cas-bridge.xethub.hf.co, us.aws.cdn.hf.co ...). Sizes AND sha256 are checked here independently of the engine.
    const real = JSON.parse(fs.readFileSync(path.join(ROOT, 'models', 'manifest.json'), 'utf8'));
    const wb = real.models.find((m) => m.id === 'whisper-base');
    const d2 = path.join(OUT, 'real-studio');
    fs.rmSync(d2, { recursive: true, force: true });
    fs.cpSync(makeStudio({ v: 1, revision: 'main', models: [wb] }), d2, { recursive: true });
    const e2 = await startEngine(d2, '');
    await allow(e2, wb.repo);
    const t0 = Date.now();
    await api(e2, '/api/models/install', { ids: [wb.id] });
    const s2 = await waitState(e2, ['done', 'stalled'], 30 * 60000);
    const secs = (Date.now() - t0) / 1000;
    ok(s2.installer.state === 'done', `real download of ${wb.repo} (${(wb.size / 1e6).toFixed(0)} MB, ${wb.files.length} files) in ${secs.toFixed(0)} s: ${s2.installer.message}`);
    let good = 0;
    for (const f of wb.files) {
      const p = path.join(d2, 'models', wb.repo, f.path);
      const b = fs.existsSync(p) ? fs.readFileSync(p) : Buffer.alloc(0);
      const same = b.length === f.size && (f.sha256 ? sha256(b) === f.sha256 : gitsha1(b) === f.gitBlobSha1);
      if (same) good++; else console.log('  mismatch', f.path, b.length, f.size);
    }
    ok(good === wb.files.length, `every file on disk has the manifest size and checksum (${good}/${wb.files.length})`);
    const hosts = [...new Set([...(e2.log || '').matchAll(/redirect to ([\w.-]+)/g)].map((m) => m[1]))];
    ok(hosts.length > 0 && hosts.every((h) => /(^|\.)(hf\.co|huggingface\.co)$/.test(h)), 'redirects followed only to Hugging Face hosts: ' + hosts.join(', '));
    ok(!fs.readdirSync(path.join(d2, 'models', wb.repo, 'onnx')).some((n) => n.endsWith('.part')), 'no .part files left');
    await stop(e2);
    if (!process.argv.includes('--keep')) fs.rmSync(d2, { recursive: true, force: true });
  }
  console.log(failures ? `\n${failures} FAILED` : '\nall model-installer checks passed');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
