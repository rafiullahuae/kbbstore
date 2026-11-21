#!/usr/bin/env node
// Occasion product slots (21 Nov 2026, the owner, on a Winter package with ONE eye cream: "always give me 5 products
// from the most selling / latest ones, and if i remove any product, it should come in next suggested products. once
// i press Find Products button it should perform the new list and fill the missing products") against the REAL
// engine -mock with its fake shop API (KAA_MOCK_SHOP_API=1: units sold per product), in Chromium, EN + AR, 1440/1280:
//   1  Approvals → Occasions → Winter: 5 product slots, each with photo, units sold 30/90 d, the fit reason; the
//      winter best sellers in, the best-selling sun gel and the oil-control powder out
//   2  ✕ on a slot → that product leaves, the next suggestion takes the slot (still 5), with its AR + EN ads; it is
//      "not for this occasion" (never back), not a global Avoid
//   3  the package cut to 2 products → Find products fills it back to 5 and says what it added
//   4  every width / language: 5 slots, no horizontal scroll; no console errors, no failed requests
//   NODE_PATH=<repo>/node_modules PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tools/occasion-slots-ui.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const PORT = 44900 + Math.floor(Math.random() * 400);
const shots = process.argv.includes('--shots');
const results = [];
const check = (n, ok, msg) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-slots-'));
  const bin = path.join(tmp, 'kaa');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine'), env: Object.assign({}, process.env, { CGO_ENABLED: '0' }) });
  const env = Object.assign({}, process.env, { KAA_SECRETS_FILE: '1', KAA_NOTIFY_CMD: '/bin/true', KAA_MOCK_SHOP_API: '1' });
  delete env.KAA_BRAIN;
  const eng = spawn(bin, ['-dir', ROOT, '-data', path.join(tmp, 'data'), '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '900s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let log = ''; eng.stdout.on('data', (d) => { log += d; }); eng.stderr.on('data', (d) => { log += d; });
  const done = (code) => { eng.kill(); fs.rmSync(tmp, { recursive: true, force: true }); process.exit(code); };
  const base = `http://127.0.0.1:${PORT}`;
  let info = null;
  for (let i = 0; i < 120 && !info; i++) { await sleep(250); info = await fetch(base + '/api/info').then((r) => (r.ok ? r.json() : null)).catch(() => null); }
  if (!info) { console.log(log); done(2); }
  const api = async (p, method = 'GET', body) => { const r = await fetch(base + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 40; i++) { const c = (await api('/api/connections')).j; if (c && c.meta.ok && c.claude.ok && c.shop.ok) break; await sleep(250); }
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'] });
  const t0 = Date.now();
  const c1 = await api('/api/cycle', 'POST', { mode: 'shadow' });
  const cycleMs = Date.now() - t0;
  const occ = async () => (await api('/api/occasions')).j;
  const winter = (o) => (o.packages || []).find((j) => j.occasion && j.occasion.occasion === 'winter') || {};
  let o = await occ();
  const W = winter(o);
  const picks = (o.picks || {})[W.id] || [];
  check('0 engine', c1.s === 200 && W.id && (await api('/api/connections')).j.shop.orders_source === 'shop-api', `practice cycle ${c1.s} in ${cycleMs} ms; winter package ${W.id}; shop orders from the (mock) shop API`);
  check('1 five winter products', picks.length === 5 && (W.product_ids || []).length === 5 && ['8130', '8150'].every((id) => W.product_ids.includes(id)) && !['8170', '8178'].some((id) => W.product_ids.includes(id)) && picks.every((p) => p.reason && p.reason_ar) && ((o.ads || {})[W.id] || []).length === 10,
    `5 slots: ${picks.map((p) => `${p.id} "${p.reason}" sold ${p.units_30d}/${p.units_90d}`).join(' | ')}; ${((o.ads || {})[W.id] || []).length} ads (AR + EN); sun gel 8170 (30 sold) and powder 8178 left out`);

  const browser = await chromium.launch();
  const errors = [], failed = [];
  const N = {};
  for (const [w, h, lang] of [[1440, 900, 'en'], [1280, 800, 'en'], [1440, 900, 'ar'], [1280, 800, 'ar']]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') errors.push(`${w}${lang}: ${m.text()}`); });
    page.on('pageerror', (e) => errors.push(`${w}${lang}: ${e}`));
    page.on('response', (r) => { if (r.status() >= 400) failed.push(`${r.status()} ${r.url()}`); });
    await page.addInitScript((l) => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.lang', l); } catch {} }, lang);
    const pkg = `[data-pkg="${W.id}"]`;
    const shot = async (name) => {
      if (!shots) return;
      fs.mkdirSync(SHOTS, { recursive: true });
      await page.$eval(pkg, (e) => e.scrollIntoView({ block: 'start' }));
      await sleep(250);
      await page.screenshot({ path: path.join(SHOTS, `occasion-slots-${name}-${lang}-${w}.jpg`), type: 'jpeg', quality: 76 });
    };
    const slots = () => page.$$eval(`${pkg} .slot:not(.empty)`, (s) => s.map((x) => ({ id: x.dataset.slot, why: (x.querySelector('.why') || {}).innerText || '', img: !!x.querySelector('img') })));
    await page.goto(base + '/app/#/approvals');
    await page.waitForFunction((s) => window.__kaa && window.__kaa.state && document.querySelector(s + ' .slot'), pkg, { timeout: 30000 });
    await sleep(500);
    const s0 = await slots();
    const lay = await page.evaluate((s) => ({ sw: document.documentElement.scrollWidth, vw: innerWidth, imgs: [...document.querySelectorAll(s + ' .slot img')].filter((i) => i.complete && i.naturalWidth > 0).length }), pkg);
    check(`4 layout ${lang}${w}`, s0.length === 5 && s0.every((x) => x.why && x.img) && lay.sw <= lay.vw, `${s0.length} slots with reasons (${s0[0] && s0[0].why}), ${lay.imgs} photos loaded, scrollWidth ${lay.sw}/${lay.vw}`);
    await shot('package');
    if (!(w === 1440 && lang === 'en')) { await ctx.close(); continue; }
    // 2 ✕ → the next suggestion
    const gone = s0[0].id;
    let t = Date.now();
    await page.click(`${pkg} [data-slotrm="${gone}"]`);
    await page.waitForFunction(([s, g]) => document.querySelectorAll(s + ' .slot:not(.empty)').length === 5 && !document.querySelector(`${s} [data-slot="${g}"]`), [pkg, gone], { timeout: 15000 });
    N.remove_ms = Date.now() - t;
    const s1 = await slots();
    const toast1 = await page.$$eval('.toast', (x) => x.map((y) => y.innerText).join(' | '));
    o = await occ();
    const W1 = winter(o);
    const nu = s1.map((x) => x.id).filter((id) => !s0.some((y) => y.id === id));
    const pb = (await api('/api/playbook')).j.playbook;
    check('2 remove → next', s1.length === 5 && nu.length === 1 && (W1.not_for || []).includes(gone) && ((o.ads || {})[W1.id] || []).length === 10 && !pb.avoid.some((r) => r.value === gone),
      `✕ ${gone} → ${nu[0]} took its slot in ${N.remove_ms} ms ("${toast1}"); not_for ${W1.not_for}; 10 ads; not a global Avoid`);
    await shot('removed');
    // 3 cut to 2 → Find products fills to 5
    await api(`/api/jobs/${W.id}`, 'PUT', { product_ids: W1.product_ids.slice(0, 2) });
    await page.reload();
    await page.waitForFunction((s) => document.querySelectorAll(s + ' .slot.empty').length === 3, pkg, { timeout: 15000 });
    await shot('empty');
    t = Date.now();
    await page.click(`${pkg} [data-occfind]`);
    await page.waitForFunction((s) => document.querySelectorAll(s + ' .slot:not(.empty)').length === 5 && [...document.querySelectorAll('.toast')].some((x) => /Added/.test(x.innerText)), pkg, { timeout: 15000 });
    N.find_ms = Date.now() - t;
    const s2 = await slots();
    const toast2 = await page.$$eval('.toast', (x) => x.map((y) => y.innerText).filter((y) => /Added/.test(y)).join(' | '));
    check('3 find products', s2.length === 5 && !s2.some((x) => x.id === gone) && /Added 3/.test(toast2), `2 kept → ${s2.map((x) => x.id).join(', ')} in ${N.find_ms} ms; "${toast2}"; ${gone} did not come back`);
    await shot('found');
    const act = (await api('/api/log?n=200')).j;
    const lines = JSON.stringify(act || {});
    check('5 activity', /took its slot/.test(lines) && /Find products: added 3/.test(lines), 'remove and Find products logged in Activity');
    // 7 Strategy: what each product is for (his word wins) and the products per package
    await page.goto(base + '/app/#/strategy');
    await page.waitForSelector('[data-tab="products"]', { timeout: 15000 });
    await page.click('[data-tab="products"]');
    await page.waitForSelector('[data-season]', { timeout: 15000 });
    const rows = await page.$$eval('[data-seasons]', (r) => r.length);
    const pid = await page.$eval('[data-seasons]', (r) => r.dataset.seasons);
    const wasOn = await page.$eval(`[data-season="${pid}"][data-sk="winter"]`, (b) => b.getAttribute('aria-pressed') === 'true');
    await page.click(`[data-season="${pid}"][data-sk="winter"]`);
    await page.click('[data-save]');
    await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((x) => /Saved/.test(x.innerText)), null, { timeout: 15000 });
    const pb2 = (await api('/api/playbook')).j.playbook;
    const mine = (pb2.product_seasons || {})[pid] || [];
    if (shots) { await page.click('[data-tab="products"]').catch(() => {}); await sleep(300); await page.screenshot({ path: path.join(SHOTS, `occasion-slots-strategy-${lang}-${w}.jpg`), type: 'jpeg', quality: 76 }); }
    await page.click('[data-tab="occasions"]');
    const slotsIn = await page.$eval('[data-f="occasion_slots"]', (i) => i.value).catch(() => '');
    check('7 strategy', rows >= 10 && mine.includes('winter') === !wasOn && pb2.occasion_slots === 5 && slotsIn === '5', `Strategy → Products: season chips on ${rows} products; winter on ${pid} ${wasOn ? 'off' : 'on'} → saved ${JSON.stringify(mine)}; Strategy → Occasions: products per package ${slotsIn}`);
    await ctx.close();
  }
  check('6 clean', errors.length === 0 && failed.length === 0, `console errors ${errors.length} ${errors.slice(0, 3).join(' | ')}; failed requests ${failed.length} ${failed.slice(0, 3).join(' | ')}`);
  await browser.close();
  const ok = results.every(Boolean);
  console.log(`${ok ? 'ALL PASS' : 'FAILED'}  ${results.filter(Boolean).length}/${results.length}`);
  done(ok ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(1); });
