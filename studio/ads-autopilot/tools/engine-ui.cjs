#!/usr/bin/env node
// LANE ENGINE UI check (20 Nov fixes) against the REAL engine -mock with the real brain, in Chromium:
//   1  Practice: Approvals lists the first launch (starter set) from /api/proposals, with its "First launch" card
//   2  Approve on ONE starter ad runs that ad + the campaign / ad set / creative it needs (Meta objects, PAUSED →
//      ACTIVE), the rest keeps waiting; Not now sets one aside
//   3  Creatives shows the library's real pictures (GET /api/creatives/{id}/file as blob:) and the starter's shop photos
//   4  Settings → Limits saves the ad-wording rule on /api/limits
//   5  no console errors, no failed requests
//   NODE_PATH=<repo>/node_modules PLAYWRIGHT_BROWSERS_PATH=… node tools/engine-ui.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const PORT = 43400 + Math.floor(Math.random() * 400);
const shots = process.argv.includes('--shots');
const results = [];
const check = (n, ok, msg) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-eui-'));
  const bin = path.join(tmp, 'kaa');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine'), env: Object.assign({}, process.env, { CGO_ENABLED: '0' }) });
  const env = Object.assign({}, process.env, { KAA_SECRETS_FILE: '1' });
  delete env.KAA_BRAIN;
  const eng = spawn(bin, ['-dir', ROOT, '-data', path.join(tmp, 'data'), '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '600s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let log = ''; eng.stdout.on('data', (d) => { log += d; }); eng.stderr.on('data', (d) => { log += d; });
  const base = `http://127.0.0.1:${PORT}`;
  let info = null;
  for (let i = 0; i < 120 && !info; i++) { await new Promise((r) => setTimeout(r, 250)); info = await fetch(base + '/api/info').then((r) => (r.ok ? r.json() : null)).catch(() => null); }
  if (!info) { console.log(log); process.exit(2); }
  const api = async (p, method = 'GET', body) => { const r = await fetch(base + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 40; i++) { const c = (await api('/api/connections')).j; if (c && c.meta.ok && c.claude.ok && c.shop.ok) break; await new Promise((r) => setTimeout(r, 250)); }
  // a real picture in the library (the mock's product photo stand-in), then Practice with limits → the starter set
  const photo = Buffer.from(await (await fetch(base + '/api/brain/x/img?u=' + encodeURIComponent('https://kbeautybliss.com/x.webp'), { headers: { 'X-KAA': info.token } })).arrayBuffer());
  await api('/api/creatives', 'POST', { id: 'cr-owner-photo', lang: 'ar', format: '1:1', png: photo.toString('base64'), headline: 'صورة المنتج', primary_text: 'منتج كوري أصلي', link: 'https://kbeautybliss.com/', product_ids: ['4410'], angle: 'owner photo', source: 'owner' });
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'] });
  const cyc = (await api('/api/cycle', 'POST', { mode: 'shadow' })).j;

  const browser = await chromium.launch();
  const errors = [], failed = [];
  const out = {};
  for (const [w, h] of [[1440, 900], [1280, 800]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('requestfailed', (r) => { if (!/\/api\/events/.test(r.url())) failed.push(r.url() + ' ' + (r.failure() || {}).errorText); });
    page.on('response', (r) => { if (r.status() >= 400 && !/\/api\/proposals\/.*\/approve/.test(r.url())) failed.push(`${r.status()} ${r.url()}`); });
    await page.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); } catch {} });
    await page.goto(base + '/app/#/approvals');
    await page.waitForFunction(() => window.__kaa && window.__kaa.state && document.querySelector('[data-ok]'), null, { timeout: 30000 });
    if (w === 1440) out.rows = await page.$$eval('[data-ok]', (b) => b.length);
    out.card = await page.evaluate(() => /First launch/.test(document.querySelector('#view').innerText));
    if (shots) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: path.join(SHOTS, `engine-approvals-practice-${w}.jpg`), type: 'jpeg', quality: 70 }); }
    if (w === 1440) {
      // 2: approve ONE starter ad
      const before = (await api('/api/proposals')).j.proposals;
      const ad = before.find((p) => p.kind === 'create_ad' && p.id.startsWith('starter-') && / AR /.test(' ' + p.params.name + ' '));
      const t0 = Date.now();
      await page.click(`[data-ok="${ad.id}"]`);
      await page.waitForFunction((id) => !document.querySelector(`[data-ok="${id}"]`), ad.id, { timeout: 30000 });
      out.approveMs = Date.now() - t0;
      const after = (await api('/api/proposals')).j.proposals;
      out.done = after.filter((p) => p.status === 'done').map((p) => p.kind);
      out.waiting = after.filter((p) => p.status === 'shadow').length;
      const st = (await api('/api/state')).j;
      out.campaigns = st.campaigns.filter((c) => c.managed).map((c) => `${c.name} ${c.status}`);
      out.rowsAfter = await page.$$eval('[data-ok]', (b) => b.length);
      // Not now on another
      const other = after.find((p) => p.status === 'shadow');
      await page.click(`[data-no="${other.id}"]`);
      await page.waitForFunction((id) => !document.querySelector(`[data-no="${id}"]`), other.id, { timeout: 10000 });
      out.rejected = (await api('/api/proposals')).j.proposals.find((p) => p.id === other.id).status;
      if (shots) await page.screenshot({ path: path.join(SHOTS, `engine-approvals-after-${w}.jpg`), type: 'jpeg', quality: 70 });
      // 4: wording on /api/limits
      await page.goto(base + '/app/#/settings');
      await page.waitForSelector('[data-w="strict"]', { timeout: 10000 }).catch(() => {});
      await page.waitForTimeout(1200); // the screen repaints once its lists load
      if (await page.$('[data-w="strict"]')) { await page.click('[data-w="strict"]'); await page.click('#saveL'); await page.waitForTimeout(800); }
      out.wording = (await api('/api/limits')).j.wording;
    }
    // 3: the library's real pictures
    await page.goto(base + '/app/#/creatives');
    await page.waitForFunction(() => [...document.querySelectorAll('#view .cr img')].filter((i) => i.src.startsWith('blob:') && i.complete && i.naturalWidth > 0).length >= 1, null, { timeout: 20000 }).catch(() => {});
    out.imgs = await page.$$eval('#view .cr img', (is) => is.filter((i) => i.src.startsWith('blob:') && i.naturalWidth > 0).map((i) => `${i.naturalWidth}x${i.naturalHeight}`));
    out.libFile = await page.$$eval('#view .cr img', (is) => is.filter((i) => i.alt === 'صورة المنتج' && i.naturalWidth > 0).length);
    if (shots) await page.screenshot({ path: path.join(SHOTS, `engine-creatives-${w}.jpg`), type: 'jpeg', quality: 70 });
    await ctx.close();
  }
  check('Practice lists the first launch', out.rows >= 10 && out.card && cyc.starter, `${out.rows} proposals waiting (starter: ${cyc.starter && cyc.starter.why}); First launch card ${out.card ? 'shown' : 'missing'}`);
  check('Approve runs ONE proposal (+ what it needs)', out.done.length >= 4 && out.done.length < out.rows && out.campaigns.length === 1 && out.rowsAfter === out.rows - out.done.length,
    `done ${JSON.stringify(out.done)} in ${out.approveMs} ms; ${out.waiting} still waiting; app campaigns ${JSON.stringify(out.campaigns)}`);
  check('Not now sets one aside', out.rejected === 'rejected', `status ${out.rejected}`);
  check('Creatives shows real pictures', out.libFile >= 1 && out.imgs.length >= 2, `${out.imgs.length} pictures from the engine (${[...new Set(out.imgs)].join(', ')}), library PNG shown ${out.libFile}x`);
  check('wording saved on /api/limits', out.wording === 'strict', `limits.wording = ${out.wording}`);
  check('no console errors', errors.length === 0, errors.slice(0, 4).join(' | ') || '0');
  check('no failed requests', failed.length === 0, failed.slice(0, 4).join(' | ') || '0');
  await browser.close();
  eng.kill('SIGTERM');
  fs.rmSync(tmp, { recursive: true, force: true });
  const bad = results.filter((x) => !x).length;
  console.log(`\n${results.length - bad}/${results.length} checks passed`);
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(3); });
