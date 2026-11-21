#!/usr/bin/env node
// Cloud creation in the app, on the REAL engine (-mock, KAA_EXCHANGE_DIR: the exchange is a plain folder):
//   1  a creation request is pushed as a job → Overview says "N ads are being made in the cloud" (no reload: SSE)
//   2  the cloud's results land → sync → Overview and Approvals show "Made in the cloud on your Max plan"
//   3  Settings → Connections → Cloud creation (GitHub): connected, counts, the "Create GitHub key" link pre-filled
//      with GitHub's documented parameters (name, description, target_name, expires_in, contents=write) and the
//      checklist for what GitHub cannot pre-fill (Only select repositories → kbb-ad-exchange)
//   4  Arabic: the same, RTL; no console errors, no failed requests, no horizontal scroll at 390 and 1280
// --shots: shots/cloud-*.jpg.   NODE_PATH=… node tools/cloud-ui.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');
const zlib = require('zlib');

const ROOT = path.resolve(__dirname, '..');
const SHOT = process.argv.includes('--shots');
const PORT = 44000 + Math.floor(Math.random() * 900);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let fails = 0;
const check = (ok, name, info = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  ' + info : ''}`); if (!ok) fails++; };

// a real PNG (a soft gradient) without any dependency
function png(w, h) {
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) { raw[y * (w * 3 + 1)] = 0; for (let x = 0; x < w; x++) { const o = y * (w * 3 + 1) + 1 + x * 3; raw[o] = 200 + (x * 50 / w | 0); raw[o + 1] = 150 + (y * 80 / h | 0); raw[o + 2] = 180; } }
  const crc = (b) => { let c = ~0; for (const v of b) { c ^= v; for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xEDB88320 & -(c & 1)); } return ~c >>> 0; };
  const chunk = (t, d) => { const l = Buffer.alloc(4); l.writeUInt32BE(d.length); const td = Buffer.concat([Buffer.from(t), d]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([l, td, c]); };
  const ih = Buffer.alloc(13); ih.writeUInt32BE(w, 0); ih.writeUInt32BE(h, 4); ih[8] = 8; ih[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ih), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-cloud-'));
  const dataDir = path.join(tmp, 'data'), ex = path.join(tmp, 'exchange');
  fs.mkdirSync(dataDir); fs.mkdirSync(ex);
  const bin = path.join(tmp, 'engine');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  const env = Object.assign({}, process.env, { KAA_EXCHANGE_DIR: ex, KAA_NOTIFY_CMD: '/bin/true' });
  const eng = spawn(bin, ['-dir', ROOT, '-data', dataDir, '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '1800s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let engLog = '';
  eng.stdout.on('data', (d) => { engLog += d; }); eng.stderr.on('data', (d) => { engLog += d; });
  const stop = () => { try { eng.kill(); } catch {} try { fs.rmSync(tmp, { recursive: true, force: true }); } catch {} };
  process.on('exit', stop);
  const base = `http://127.0.0.1:${PORT}`;
  let up = false;
  for (let i = 0; i < 200 && !up; i++) { await sleep(150); up = await fetch(base + '/api/info').then((r) => r.ok).catch(() => false); }
  if (!up) { console.log(engLog.slice(-3000)); process.exit(2); }
  const info = await (await fetch(base + '/api/info')).json();
  const api = async (p, method = 'GET', body) => { const r = await fetch(base + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 100; i++) { const a = await api('/api/accounts'); if (a.j && a.j.accounts && a.j.accounts.length) break; await sleep(200); }
  await sleep(1500);
  const prods = (await api('/api/brain/x/products')).j || [];
  const req = (await api('/api/exchange/test-request', 'POST', { product_ids: [prods[0].id], angle: 'glass skin glow' })).j;

  const browser = await chromium.launch();
  const errors = [], failed = [];
  const open = async (lang, w, h) => {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    await ctx.addInitScript((l) => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.seg', 'off'); localStorage.setItem('kaa.lang', l); } catch {} }, lang);
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (r) => { if (r.status() >= 400 && !/seg\/(mask|status)/.test(r.url())) failed.push(`${r.status()} ${r.url()}`); });
    await page.goto(base + '/app/#/overview');
    await page.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });
    return page;
  };
  const shot = async (page, name) => { if (SHOT) await page.screenshot({ path: path.join(ROOT, 'shots', `cloud-${name}.jpg`), quality: 82, type: 'jpeg' }); };

  // 1
  let page = await open('en', 1280, 800);
  const sync = await api('/api/exchange/sync', 'POST');
  check(sync.s === 200 && fs.existsSync(path.join(ex, 'jobs', 'act_1000000001', req.id, 'brief.json')), 'the request is pushed as a job', `${req.id}`);
  await page.waitForSelector('[data-cloud-busy]', { timeout: 8000 }).catch(() => {});
  const busy = await page.$eval('[data-cloud-busy]', (e) => e.textContent).catch(() => '');
  check(/ads are being made in the cloud/.test(busy), 'Overview: "N ads are being made in the cloud" (pushed, no reload)', busy.trim().slice(0, 60));
  await shot(page, 'overview-busy-1280');

  // 2: the cloud's results
  const rd = path.join(ex, 'results', 'act_1000000001', req.id);
  fs.mkdirSync(rd, { recursive: true });
  const q = { ok: true, score: 90, fails: [], checks: { product_share: 0.5, min_contrast: 6, safe_violations: 0, text_density: 0.1, arabic: { ok: true }, cutout: 'classic' } };
  fs.writeFileSync(path.join(rd, 'ads.json'), JSON.stringify({ job_id: req.id, worker: 'cloud-ui', ideas: [{ product_ids: [prods[0].id], angle: 'glass skin glow', template: 'glow', formats: ['1:1', '4:5'], show_price: true, sets: [{ lang: 'ar', headline: 'توهّج زجاجي كل صباح', primary_text: 'قوام خفيف يمنح بشرتكِ إشراقة.', sub: 'ترطيب يدوم', cta: 'SHOP_NOW' }, { lang: 'en', headline: 'Glass-skin glow, every morning', primary_text: 'A light texture for a dewy finish.', sub: 'Lasting hydration', cta: 'SHOP_NOW' }] }],
    renders: [{ idea: 1, lang: 'ar', format: '1:1', kind: 'png', file: 'i1-ar-1x1.png', family: 'glow', quality: q }, { idea: 1, lang: 'en', format: '4:5', kind: 'png', file: 'i1-en-4x5.png', family: 'glow', quality: q }] }));
  fs.writeFileSync(path.join(rd, 'i1-ar-1x1.png'), png(1080, 1080));
  fs.writeFileSync(path.join(rd, 'i1-en-4x5.png'), png(1080, 1350));
  fs.writeFileSync(path.join(rd, 'done.json'), JSON.stringify({ job_id: req.id, worker: 'cloud-ui', plan: 'Claude Max', at: new Date().toISOString(), rendered: 2 }));
  const s2 = await api('/api/exchange/sync', 'POST');
  const lib = ((await api('/api/creatives')).j || []);
  const cl = (Array.isArray(lib) ? lib : lib.creatives || []).filter((c) => c.origin === 'cloud_max');
  check(s2.s === 200 && cl.length === 2, 'the results are imported as library creatives', `${cl.length} cloud_max`);
  await page.waitForSelector('[data-cloud-done]', { timeout: 8000 }).catch(() => {});
  const badge = await page.$eval('[data-cloud-done]', (e) => e.textContent).catch(() => '');
  check(/Made in the cloud on your Max plan/.test(badge), 'Overview: "Made in the cloud on your Max plan"', badge.trim().slice(0, 80));
  await shot(page, 'overview-done-1280');
  await page.evaluate(() => { location.hash = '#/approvals'; });
  await page.waitForSelector('[data-cloud-done]', { timeout: 8000 }).catch(() => {});
  check(!!(await page.$('[data-cloud-done]')), 'Approvals shows the badge too');
  await shot(page, 'approvals-done-1280');

  // 3: Settings → Connections
  const card = async (p) => { await p.evaluate(() => { location.hash = '#/settings?tab=connections'; }); await p.waitForSelector('[data-cloud]', { timeout: 10000 }); await sleep(300); return p.$eval('[data-cloud]', (e) => ({ text: e.textContent, href: (e.querySelector('#cx-new') || {}).href || '', w: document.documentElement.scrollWidth, vw: innerWidth, dir: document.documentElement.dir })); };
  let c = await card(page);
  const u = new URL(c.href || 'https://x/');
  check(/Connected/.test(c.text) && /Finished: 1/.test(c.text.replace(/\s+/g, ' ')), 'the card: connected, counts', c.text.replace(/\s+/g, ' ').match(/Waiting.*?Failed: \d/)?.[0] || '');
  check(u.pathname === '/settings/personal-access-tokens/new' && u.searchParams.get('contents') === 'write' && u.searchParams.get('target_name') === 'rafiullahuae' && u.searchParams.get('expires_in') === '366' && (u.searchParams.get('name') || '').length <= 40, '"Create GitHub key" is pre-filled with GitHub\'s documented parameters', u.search.slice(0, 90) + '…');
  check(/Only select repositories/.test(c.text) && /kbb-ad-exchange/.test(c.text), 'the checklist names what GitHub cannot pre-fill');
  check(c.w <= c.vw, 'no horizontal scroll at 1280', `${c.w}/${c.vw}`);
  await page.$eval('[data-cloud]', (e) => e.scrollIntoView());
  await shot(page, 'settings-1280');
  await page.context().close();
  // 4: Arabic, phone width
  page = await open('ar', 390, 844);
  c = await card(page);
  check(c.dir === 'rtl' && /الإنشاء في السحابة/.test(c.text) && c.w <= c.vw, 'Arabic card, RTL, no horizontal scroll at 390', `${c.w}/${c.vw}`);
  await page.$eval('[data-cloud]', (e) => e.scrollIntoView());
  await shot(page, 'settings-ar-390');
  await page.evaluate(() => { location.hash = '#/overview'; });
  await page.waitForSelector('[data-cloud-done]', { timeout: 8000 }).catch(() => {});
  check(/صُنعت في السحابة/.test(await page.$eval('[data-cloud-done]', (e) => e.textContent).catch(() => '')), 'Arabic Overview badge');
  await shot(page, 'overview-done-ar-390');
  await browser.close();
  check(!errors.length, 'no console errors', errors.slice(0, 3).join(' | '));
  check(!failed.length, 'no failed requests', failed.slice(0, 3).join(' | '));
  console.log(fails ? `${fails} FAILED` : 'ALL PASSED');
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
