#!/usr/bin/env node
// KBB Ads cloud worker — run by the scheduled Claude Code task on the owner's Max plan (cloud/WORKER.md).
// Node only (no new dependencies): git for the exchange repository, Playwright's Chromium (already in the cloud
// container / the kbbstore checkout) for the app's OWN renderer and quality gate (app/js/creative/*).
//
//   node cloud/worker.mjs claim  [--max 2]           pull, claim up to N waiting jobs (claim.json, pushed), print them
//   node cloud/worker.mjs brief  --job ACCT/JOB      print one job's brief (what Claude reads to write ads.json)
//   node cloud/worker.mjs render --job ACCT/JOB      draw every idea x language x format (+ motion), run the gate
//   node cloud/worker.mjs finish --job ACCT/JOB      done.json, commit, push (the Mac picks it up)
//   node cloud/worker.mjs fail   --job ACCT/JOB --note "why"
//   node cloud/worker.mjs status                     every job and its state; recent feedback from the Mac
// Options: --exchange DIR (default: $KBB_EXCHANGE_DIR, ../kbb-ad-exchange next to the kbbstore checkout, or a fresh
// clone of github.com/$KBB_EXCHANGE_REPO, default rafiullahuae/kbb-ad-exchange), --worker ID, --offline-photo PNG
// (self-test: every product photo is this file; no network).
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const AUTOPILOT = path.resolve(HERE, '..');
const APP = path.join(AUTOPILOT, 'app');
const KBBSTORE = path.resolve(AUTOPILOT, '..', '..');
const CLAIM_TTL = 3 * 3600e3;
const FORMATS = { '1:1': [1080, 1080], '4:5': [1080, 1350], '9:16': [1080, 1920] };
const FAMILIES = ['hero', 'glow', 'ingredient', 'offer', 'routine', 'editorial', 'petals', 'texture', 'qa', 'collection'];

const args = process.argv.slice(2);
const cmd = args[0] || 'status';
const opt = (k, d) => { const i = args.indexOf('--' + k); return i > 0 && args[i + 1] ? args[i + 1] : d; };
const WORKER = opt('worker', process.env.KBB_WORKER_ID || `cloud-${os.hostname().slice(0, 12)}-${process.pid}`);
const REPO = process.env.KBB_EXCHANGE_REPO || 'rafiullahuae/kbb-ad-exchange';
const segOK = (s) => /^[A-Za-z0-9_-]{1,64}$/.test(s);
const die = (m) => { console.error('worker: ' + m); process.exit(1); };

// ---------- the exchange (git) ----------
function exchangeDir() {
  const cands = [opt('exchange'), process.env.KBB_EXCHANGE_DIR, path.join(KBBSTORE, '..', REPO.split('/')[1]), path.join(os.homedir(), REPO.split('/')[1])].filter(Boolean);
  for (const d of cands) if (fs.existsSync(path.join(d, '.git'))) return path.resolve(d);
  const d = path.join(KBBSTORE, '..', REPO.split('/')[1]);
  console.log(`cloning ${REPO} into ${d}`);
  execFileSync('git', ['clone', '--quiet', `https://github.com/${REPO}.git`, d], { stdio: 'inherit' });
  return d;
}
const EX = cmd === 'help' ? '' : exchangeDir();
const git = (...a) => execFileSync('git', ['-C', EX, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { GIT_AUTHOR_NAME: 'KBB cloud worker', GIT_AUTHOR_EMAIL: 'noreply@anthropic.com', GIT_COMMITTER_NAME: 'KBB cloud worker', GIT_COMMITTER_EMAIL: 'noreply@anthropic.com' }) }).trim();
const tryGit = (...a) => { try { return [git(...a), null]; } catch (e) { return [null, e]; } };
const branch = () => git('rev-parse', '--abbrev-ref', 'HEAD');
function pull() {
  const [, e] = tryGit('pull', '--quiet', '--rebase', 'origin', branch());
  if (e) { tryGit('rebase', '--abort'); git('fetch', '--quiet', 'origin'); git('reset', '--quiet', '--hard', 'origin/' + branch()); }
}
const readJSON = (p, d = null) => { try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch { return d; } };
const writeJSON = (p, v) => { fs.mkdirSync(path.dirname(p), { recursive: true }); fs.writeFileSync(p, JSON.stringify(v, null, 1) + '\n'); };
const jobDir = (a, j) => path.join(EX, 'jobs', a, j);
const resDir = (a, j) => path.join(EX, 'results', a, j);

function jobs() {
  const out = [];
  const root = path.join(EX, 'jobs');
  if (!fs.existsSync(root)) return out;
  for (const a of fs.readdirSync(root)) {
    if (!segOK(a) || !fs.statSync(path.join(root, a)).isDirectory()) continue;
    for (const j of fs.readdirSync(path.join(root, a))) {
      if (!segOK(j) || !fs.existsSync(path.join(root, a, j, 'brief.json'))) continue;
      const st = readJSON(path.join(root, a, j, 'status.json'), {}) || {}, cl = readJSON(path.join(root, a, j, 'claim.json'));
      const done = fs.existsSync(path.join(resDir(a, j), 'done.json'));
      let state = 'waiting';
      if (st.state === 'withdrawn') state = 'withdrawn';
      else if (done) state = 'finished';
      else if (st.state === 'failed') state = 'failed';
      else if (cl && Date.now() - Date.parse(cl.at) < CLAIM_TTL) state = 'working';
      out.push({ account: a, id: j, key: a + '/' + j, state, claim: cl, at: (readJSON(path.join(root, a, j, 'brief.json'), {}) || {}).at || '' });
    }
  }
  return out.sort((x, y) => String(x.at).localeCompare(String(y.at)));
}

function push(msg, paths) {
  for (let i = 0; i < 4; i++) {
    git('add', '--', ...paths);
    if (!git('status', '--porcelain', '--', ...paths)) return true;
    git('commit', '--quiet', '-m', msg);
    const [, e] = tryGit('push', '--quiet', 'origin', 'HEAD:' + branch());
    if (!e) return true;
    // someone else pushed: replay ours on top (our paths are ours alone except claim.json, checked by the caller)
    const [, re] = tryGit('pull', '--quiet', '--rebase', 'origin', branch());
    if (re) { tryGit('rebase', '--abort'); return false; }
  }
  return false;
}

function claim(max) {
  pull();
  const got = [];
  for (const j of jobs()) {
    if (got.length >= max) break;
    if (j.state !== 'waiting') continue;
    const p = path.join(jobDir(j.account, j.id), 'claim.json');
    writeJSON(p, { worker: WORKER, at: new Date().toISOString() });
    git('add', '--', p);
    git('commit', '--quiet', '-m', `claim ${j.key} by ${WORKER}`);
    let ok = !tryGit('push', '--quiet', 'origin', 'HEAD:' + branch())[1];
    if (!ok) { // lost a race, or the ref moved: re-read; the job is ours only if nobody else claimed it meanwhile
      const [, re] = tryGit('pull', '--quiet', '--rebase', 'origin', branch());
      if (re) { tryGit('rebase', '--abort'); git('reset', '--quiet', '--hard', 'origin/' + branch()); continue; }
      const c = readJSON(p, {});
      ok = c && c.worker === WORKER && !tryGit('push', '--quiet', 'origin', 'HEAD:' + branch())[1];
      if (!ok) { git('reset', '--quiet', '--hard', 'origin/' + branch()); continue; }
    }
    got.push(j.key);
  }
  return got;
}

function feedback(n = 5) {
  const root = path.join(EX, 'feedback');
  const out = [];
  if (!fs.existsSync(root)) return out;
  for (const a of fs.readdirSync(root)) for (const j of fs.readdirSync(path.join(root, a))) {
    const f = readJSON(path.join(root, a, j, 'import.json'));
    if (f) out.push(Object.assign({ job: a + '/' + j }, f));
  }
  return out.sort((x, y) => String(y.at).localeCompare(String(x.at))).slice(0, n);
}

// ---------- designs: the same spec the app builds (engine/brain/copy.go designFor) ----------
function specsFor(brief, ads) {
  const prods = new Map([...(brief.products || []), ...(brief.more_products || [])].map((p) => [p.id, p]));
  const out = [];
  (ads.ideas || []).forEach((x, i) => {
    const ps = (x.product_ids || []).map((id) => prods.get(id)).filter(Boolean);
    if (!ps.length) return;
    for (const s of x.sets || []) {
      const lang = s.lang === 'ar' ? 'ar' : 'en';
      out.push({ id: `cl-${i + 1}-${lang}`, pair_id: `cl-${i + 1}`, idea: i + 1, lang, template: FAMILIES.includes(x.template) ? x.template : 'hero',
        formats: (x.formats || brief.formats || ['1:1', '4:5', '9:16']).filter((f) => FORMATS[f]), product_ids: x.product_ids, images: ps.map((p) => p.photo).slice(0, 3),
        headline: s.headline || '', sub: s.sub || '', bullets: s.bullets || [], badge: s.badge || ps[0].brand || '', cta: s.cta || 'SHOP_NOW',
        price: x.show_price ? (lang === 'ar' ? ps[0].price_ar : ps[0].price_en) : '', angle: x.angle || '', category: ps[0].category || '',
        numerals: lang === 'ar' ? 'arab' : undefined, palette: (x.palette || []).filter((c) => /^#[0-9a-fA-F]{6}$/.test(c)).slice(0, 4), primary_text: s.primary_text || '', motion: !!x.motion });
    }
  });
  return out;
}

// ---------- the renderer: the app folder served same-origin, product photos proxied ----------
const MIME = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.html': 'text/html', '.json': 'application/json', '.png': 'image/png', '.wasm': 'application/wasm' };
const HARNESS = `<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/app/css/theme.css"><body><script type="module">
import { renderSpec, toPNG, FORMATS } from '/app/js/creative/render.js';
import { score } from '/app/js/creative/quality.js';
import { renderVideo } from '/app/js/creative/motion.js';
window.kaaStill = async (spec, format) => { const q = await score(spec, format); const cv = await renderSpec(spec, format); const { b64 } = await toPNG(cv); return { q, b64 }; };
window.kaaMotion = async (spec) => { const r = await renderVideo(spec, '9:16', 10); const b = new Uint8Array(await r.blob.arrayBuffer()); let s = ''; for (let i = 0; i < b.length; i += 32768) s += String.fromCharCode.apply(null, b.subarray(i, i + 32768)); return { b64: btoa(s), mime: r.mime, codec: r.codec, encoder: r.encoder }; };
window.kaaReady = true;
</script>`;

function serve(photos, offline) {
  const allowed = new Set(photos);
  const srv = http.createServer(async (req, res) => {
    const u = new URL(req.url, 'http://x');
    try {
      if (u.pathname === '/harness.html') { res.writeHead(200, { 'content-type': 'text/html' }); return res.end(HARNESS); }
      if (u.pathname === '/api/info') { res.writeHead(200, { 'content-type': 'application/json' }); return res.end('{"token":""}'); }
      if (u.pathname === '/api/brain/x/img') {
        const src = u.searchParams.get('u') || '';
        if (!allowed.has(src)) { res.writeHead(404); return res.end(); } // only the brief's own photos
        let buf;
        if (offline) buf = fs.readFileSync(offline);
        else { const r = await fetch(src, { redirect: 'follow' }); if (!r.ok) throw new Error('photo ' + r.status); buf = Buffer.from(await r.arrayBuffer()); }
        res.writeHead(200, { 'content-type': 'image/' + (/\.png($|\?)/i.test(src) || offline ? 'png' : 'jpeg') });
        return res.end(buf);
      }
      if (u.pathname.startsWith('/app/')) {
        const f = path.normalize(path.join(APP, decodeURIComponent(u.pathname.slice(5))));
        if (f.startsWith(APP + path.sep) && fs.existsSync(f) && fs.statSync(f).isFile()) { res.writeHead(200, { 'content-type': MIME[path.extname(f)] || 'application/octet-stream' }); return res.end(fs.readFileSync(f)); }
      }
      res.writeHead(404, { 'content-type': 'application/json' }); res.end('{"error":"not here"}');
    } catch (e) { res.writeHead(502); res.end(String(e.message || e)); }
  });
  return new Promise((ok) => srv.listen(0, '127.0.0.1', () => ok(srv)));
}

function playwright() {
  for (const base of [process.cwd(), KBBSTORE, ...(process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean).map((p) => path.dirname(p))]) {
    try { return createRequire(path.join(base, 'noop.js'))('playwright'); } catch {}
  }
  die('Playwright is not installed: run `npm ci` (or `npm i --no-save playwright`) in the kbbstore checkout, then `npx playwright install chromium`.');
}

async function render(key) {
  const [a, j] = key.split('/');
  if (!segOK(a) || !segOK(j)) die('--job ACCOUNT/JOB');
  const brief = readJSON(path.join(jobDir(a, j), 'brief.json')) || die('no brief for ' + key);
  const adsPath = path.join(resDir(a, j), 'ads.json');
  const ads = readJSON(adsPath) || die(`write ${path.relative(EX, adsPath)} first (the ideas; see the brief's "output")`);
  ads.job_id = j; ads.worker = ads.worker || WORKER;
  const specs = specsFor(brief, ads);
  if (!specs.length) die('no idea in ads.json names a product from the brief');
  const photos = specs.flatMap((s) => s.images);
  const srv = await serve(photos, opt('offline-photo'));
  const { chromium } = playwright();
  const browser = await chromium.launch({ args: ['--font-render-hinting=none'] });
  const page = await browser.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(String(e)));
  await page.goto(`http://127.0.0.1:${srv.address().port}/harness.html`);
  await page.waitForFunction(() => window.kaaReady === true, null, { timeout: 30000 });
  ads.renders = []; ads.refused = [];
  for (const old of fs.existsSync(resDir(a, j)) ? fs.readdirSync(resDir(a, j)) : []) if (/\.(png|mp4)$/.test(old)) fs.unlinkSync(path.join(resDir(a, j), old));
  for (const s of specs) {
    for (const f of s.formats) {
      const file = `i${s.idea}-${s.lang}-${f.replace(':', 'x')}.png`;
      const r = await page.evaluate(([spec, fmt]) => window.kaaStill(spec, fmt), [s, f]);
      const q = { ok: r.q.ok, score: r.q.score, fails: r.q.fails, checks: r.q.checks };
      const row = { idea: s.idea, lang: s.lang, format: f, kind: 'png', file, family: r.q.family, quality: q };
      if (!q.ok) { ads.refused.push(row); console.log(`  refused ${file}: ${q.fails.join('; ')}`); continue; }
      fs.writeFileSync(path.join(resDir(a, j), file), Buffer.from(r.b64, 'base64'));
      ads.renders.push(row);
      console.log(`  ${file}  score ${q.score}  (${r.q.family}, cut-out ${q.checks.cutout})`);
    }
    if (s.motion && (brief.motion || s.motion)) {
      const still = ads.renders.find((x) => x.idea === s.idea && x.lang === s.lang && x.format === '9:16');
      if (!still) { console.log(`  no motion for idea ${s.idea} ${s.lang}: its 9:16 still did not pass`); continue; }
      const file = `i${s.idea}-${s.lang}-motion.mp4`;
      try {
        const v = await page.evaluate((spec) => window.kaaMotion(spec), s);
        if (v.mime !== 'video/mp4') throw new Error('the browser made ' + v.mime + ', not MP4');
        fs.writeFileSync(path.join(resDir(a, j), file), Buffer.from(v.b64, 'base64'));
        ads.renders.push({ idea: s.idea, lang: s.lang, format: '9:16', kind: 'mp4', file, family: still.family, quality: still.quality });
        console.log(`  ${file}  ${v.codec} (${v.encoder})`);
      } catch (e) { console.log(`  motion ${file} skipped: ${e.message}`); }
    }
  }
  await browser.close(); srv.close();
  writeJSON(adsPath, ads);
  if (errs.length) console.log('page errors:\n  ' + errs.join('\n  '));
  console.log(`rendered ${ads.renders.length}, refused by the quality gate ${ads.refused.length} → ${path.relative(EX, adsPath)}`);
  return ads;
}

function finish(key, error) {
  const [a, j] = key.split('/');
  if (!segOK(a) || !segOK(j)) die('--job ACCOUNT/JOB');
  const ads = readJSON(path.join(resDir(a, j), 'ads.json'), { renders: [], refused: [] });
  if (!error && !(ads.renders || []).length) die('nothing rendered: run render first (or fail --note "why")');
  writeJSON(path.join(resDir(a, j), 'done.json'), { job_id: j, worker: WORKER, plan: 'Claude Max (scheduled Claude Code task)', at: new Date().toISOString(), rendered: (ads.renders || []).length, refused: (ads.refused || []).length, error: error || undefined });
  const paths = [path.join('results', a, j)];
  if (error) { writeJSON(path.join(jobDir(a, j), 'status.json'), { state: 'failed', at: new Date().toISOString(), note: error }); paths.push(path.join('jobs', a, j, 'status.json')); }
  if (!push(`${error ? 'failed' : 'ads for'} ${key} (${WORKER})`, paths)) die('push failed: run finish again');
  console.log(`${key}: ${error ? 'marked failed' : 'pushed ' + ads.renders.length + ' creatives'}`);
}

function printBrief(key) {
  const [a, j] = key.split('/');
  const b = readJSON(path.join(jobDir(a, j), 'brief.json')) || die('no brief ' + key);
  console.log(JSON.stringify(b, null, 1));
  const fb = feedback(3);
  if (fb.length) console.log('\nRECENT FEEDBACK FROM THE MAC (learn from the refusals):\n' + JSON.stringify(fb, null, 1));
  console.log(`\nWrite: ${path.join(resDir(a, j), 'ads.json')}`);
}

switch (cmd) {
  case 'claim': {
    const got = claim(Math.max(1, Math.min(5, +opt('max', 2) || 2)));
    console.log(got.length ? `claimed by ${WORKER}:\n` + got.map((k) => '  ' + k).join('\n') + '\nNext: node cloud/worker.mjs brief --job <one of them>' : 'no waiting jobs');
    break;
  }
  case 'brief': printBrief(opt('job') || die('--job ACCOUNT/JOB')); break;
  case 'render': await render(opt('job') || die('--job ACCOUNT/JOB')); break;
  case 'finish': pull(); finish(opt('job') || die('--job ACCOUNT/JOB')); break;
  case 'fail': pull(); finish(opt('job') || die('--job ACCOUNT/JOB'), opt('note', 'the worker could not make it')); break;
  case 'status': {
    pull();
    for (const j of jobs()) console.log(`${j.state.padEnd(10)} ${j.key}${j.claim ? '  (' + j.claim.worker + ' ' + j.claim.at + ')' : ''}`);
    const fb = feedback(5);
    if (fb.length) console.log('\nfeedback:\n' + fb.map((f) => `  ${f.job}: kept ${(f.imported || []).length}, refused ${(f.rejected || []).length}${(f.rejected || []).slice(0, 3).map((r) => '\n    - ' + r.file + ': ' + r.why).join('')}`).join('\n'));
    break;
  }
  default: console.log(fs.readFileSync(fileURLToPath(import.meta.url), 'utf8').split('\n').slice(1, 14).join('\n'));
}
