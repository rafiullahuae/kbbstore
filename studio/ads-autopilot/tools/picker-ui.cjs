#!/usr/bin/env node
// Product picker check (21 Nov 2026, the owner: "give me a way to skip on products or choose the products myself")
// against the REAL engine -mock, in Chromium, EN + AR at 1440 and 1280:
//   1  Approvals (Practice): every waiting ad / ad set / product set shows its products as chips with Pin and
//      "Don't promote this product again", and a Change products button
//   2  Change products on the first Arabic ad: the picker opens (search, badges, avoided disabled "Avoided
//      (Strategy)"), the owner picks one product, Save → that proposal is rebuilt for it and checked again
//   3  Don't promote again on another chip → Playbook Avoid, no waiting card shows it, Undo toast offered
//   4  Approve the changed ad → the Meta write (mock) is exactly the chosen product; the avoided one is never written
//   5  Plan → Upcoming jobs: Edit → Choose products opens the same picker; Approvals → Occasions: Change products
//   6  no console errors, no failed requests
//   NODE_PATH=<repo>/node_modules PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tools/picker-ui.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const PORT = 44500 + Math.floor(Math.random() * 400);
const shots = process.argv.includes('--shots');
const results = [];
const check = (n, ok, msg) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-pk-'));
  const bin = path.join(tmp, 'kaa');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine'), env: Object.assign({}, process.env, { CGO_ENABLED: '0' }) });
  const env = Object.assign({}, process.env, { KAA_SECRETS_FILE: '1', KAA_NOTIFY_CMD: '/bin/true' });
  delete env.KAA_BRAIN;
  const eng = spawn(bin, ['-dir', ROOT, '-data', path.join(tmp, 'data'), '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '900s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let log = ''; eng.stdout.on('data', (d) => { log += d; }); eng.stderr.on('data', (d) => { log += d; });
  const base = `http://127.0.0.1:${PORT}`;
  let info = null;
  for (let i = 0; i < 120 && !info; i++) { await sleep(250); info = await fetch(base + '/api/info').then((r) => (r.ok ? r.json() : null)).catch(() => null); }
  if (!info) { console.log(log); process.exit(2); }
  const api = async (p, method = 'GET', body) => { const r = await fetch(base + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 40; i++) { const c = (await api('/api/connections')).j; if (c && c.meta.ok && c.claude.ok && c.shop.ok) break; await sleep(250); }
  await api('/api/brain/research', 'POST', {});
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'] });
  const c1 = await api('/api/cycle', 'POST', { mode: 'shadow' });
  const pend = async () => (await api('/api/proposals')).j.proposals.filter((p) => ['shadow', 'failed', 'refused', 'choose'].includes(p.status));
  const p0 = await pend();
  check('engine', c1.s === 200 && p0.some((p) => (p.products || []).length), `practice cycle ${c1.s}; ${p0.length} waiting, ${p0.filter((p) => (p.products || []).length).length} name products`);
  await api('/api/products/2210/avoid', 'POST', {}); // an avoided product: the picker shows it disabled
  await api('/api/undo', 'POST', {}).catch(() => {}); // …then back (the checks below avoid one through the UI)
  await api('/api/products/2210/avoid', 'POST', {});

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
    const shot = async (name) => { if (shots) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: path.join(SHOTS, `picker-${name}-${lang}-${w}.jpg`), type: 'jpeg', quality: 74 }); } };
    const go = async (hash, sel) => { await page.goto(base + '/app/#/' + hash); await page.waitForFunction((s) => window.__kaa && window.__kaa.state && document.querySelector(s), sel, { timeout: 30000 }); await sleep(300); };

    await go('approvals', '[data-chg]');
    const cards = await page.$$eval('[data-prop]', (rs) => rs.map((r) => ({ id: r.dataset.prop, chips: r.querySelectorAll('.pk-chip').length, chg: !!r.querySelector('[data-chg]'), avoid: r.querySelectorAll('[data-avoid]').length, pin: r.querySelectorAll('[data-pin]').length })));
    const named = cards.filter((c) => c.chips);
    if (w === 1440 && lang === 'en') check('1 chips', named.length >= 3 && named.every((c) => c.chg && c.avoid === c.chips && c.pin === c.chips), `${named.length} cards with product chips, each with Change products, Pin and Don't-promote on every chip`);
    await shot('approvals');
    // 2 the picker
    const ad = p0.find((p) => p.kind === 'create_ad' && /Prospecting AR/.test(p.params.name) && (p.products || []).length);
    const primary = w === 1440 && lang === 'en';
    await page.click(primary ? `[data-prop="${ad.id}"] [data-chg]` : '[data-prop] [data-chg]');
    await page.waitForSelector('dialog.pk[open] .pk-row', { timeout: 15000 });
    await sleep(400);
    const pk = await page.evaluate(() => { const d = document.querySelector('dialog.pk'); const r = d.getBoundingClientRect(); return { rows: d.querySelectorAll('.pk-row').length, off: [...d.querySelectorAll('.pk-row.off')].map((x) => ({ id: x.dataset.pid, text: x.innerText, disabled: x.querySelector('input').disabled })), badges: d.querySelectorAll('.pk-b .chip').length, imgs: [...d.querySelectorAll('.pk-row img')].filter((i) => i.complete && i.naturalWidth > 0).length, w: Math.round(r.width), sw: document.documentElement.scrollWidth, vw: innerWidth }; });
    if (w === 1440 && lang === 'en') {
      const av = pk.off.find((x) => x.id === '2210');
      check('2 picker', pk.rows >= 6 && av && av.disabled && /Avoided \(Strategy\)/.test(av.text) && pk.badges >= 6 && pk.sw <= pk.vw, `${pk.rows} products, ${pk.badges} rank/new/trending badges, ${pk.imgs} photos; avoided 2210 disabled: "${av && av.text.split('\n').pop()}"; dialog ${pk.w}px, scrollWidth ${pk.sw}/${pk.vw}`);
    } else check(`2 picker ${lang}${w}`, pk.rows >= 6 && pk.sw <= pk.vw, `${pk.rows} rows, dialog ${pk.w}px, scrollWidth ${pk.sw}/${pk.vw}`);
    await page.fill('dialog.pk [data-q]', 'anua foam'); await sleep(350);
    const found = await page.$$eval('dialog.pk .pk-row', (r) => r.map((x) => x.dataset.pid));
    await shot('open');
    if (!(w === 1440 && lang === 'en')) { await page.click('dialog.pk button[value=no]'); await sleep(200); await ctx.close(); continue; }
    check('2 search', found.length === 1 && found[0] === '7301', `"anua foam" → ${found.join(', ')}`);
    for (const b of await page.$$('dialog.pk [data-un]')) { await b.click(); await sleep(60); } // clear the planner's pick
    await page.click('dialog.pk .pk-row[data-pid="7301"]');
    await sleep(150);
    const t0 = Date.now();
    await page.click('dialog.pk [data-save]');
    await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((t) => /Rebuilt|أُعيد/.test(t.innerText)), null, { timeout: 15000 });
    N.change_ms = Date.now() - t0;
    const after = (await pend()).find((p) => p.id === ad.id);
    check('2 rebuilt', after && after.params.creative_id === 'st-7301-ar' && after.status === 'shadow' && after.products.map((p) => p.id).join() === '7301', `${ad.id}: ${after && after.params.creative_id}, status ${after && after.status}, chips ${after && after.products.map((p) => p.id)} (${N.change_ms} ms click → toast)`);
    await sleep(300);
    await shot('changed');
    // 3 don't promote again
    const other = (await pend()).flatMap((p) => p.products || []).find((x) => x.id !== '7301' && x.id !== '2210');
    await page.click(`[data-avoid="${other.id}"]`);
    await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((t) => /promoted again|لن يُروَّج/.test(t.innerText) && t.querySelector('button')), null, { timeout: 15000 });
    const pb = (await api('/api/playbook')).j.playbook;
    const still = (await pend()).some((p) => (p.products || []).some((x) => x.id === other.id));
    const domStill = await page.$$eval(`[data-avoid="${other.id}"]`, (b) => b.length);
    check('3 avoid', pb.avoid.some((r) => r.kind === 'product' && r.value === other.id) && !still && !domStill, `avoided ${other.id} (${other.title}): in Playbook Avoid, on no waiting card (API ${still}, page ${domStill}); Undo toast shown`);
    await shot('avoid');
    // 4 approve → Meta
    const w0 = (await api('/api/test/meta-writes')).j.length;
    await page.click(`[data-prop="${ad.id}"] [data-ok]`);
    await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((t) => /Approved|تمت الموافقة/.test(t.innerText)), null, { timeout: 30000 });
    const ws = (await api('/api/test/meta-writes')).j.slice(w0).filter((x) => x.status === 200);
    const crs = ws.filter((x) => x.path.endsWith('/adcreatives')).map((x) => x.form.object_story_spec);
    const ads = ws.filter((x) => x.path.endsWith('/ads')).map((x) => x.form.name);
    const all = JSON.stringify(ws);
    check('4 meta', crs.length === 1 && /anua-foam/.test(crs[0]) && ads.length === 1 && /Anua - Heartleaf Quercetinol/.test(ads[0]) && !all.includes(other.id === '2210' ? 'snail' : `/${other.id}`), `Meta writes: ${ws.length} (${ws.map((x) => x.path.split('/').pop()).join(', ')}); creative link anua-foam: ${/anua-foam/.test(crs[0] || '')}; ad "${ads[0]}"`);
    // 5 Plan → jobs, Approvals → Occasions
    await go('plan', '[data-jobs]');
    const ed = await page.$('[data-jedit]');
    if (ed) {
      await ed.click(); await sleep(200);
      await page.click('[data-epick]');
      await page.waitForSelector('dialog.pk[open] .pk-row', { timeout: 15000 });
      await sleep(300);
      await shot('plan');
      const n = await page.$$eval('dialog.pk .pk-row', (r) => r.length);
      await page.click('dialog.pk .pk-row[data-pid="6120"]'); await page.click('dialog.pk [data-save]'); await sleep(200);
      const chosen = await page.$$eval('[data-editor] .pk-chips .chip', (c) => c.map((x) => x.innerText));
      check('5 plan', n >= 6 && chosen.some((t) => /Torriden/.test(t)), `Plan → Edit → Choose products: the same picker (${n} rows); chosen in the editor: ${chosen.join(' | ')}`);
    } else check('5 plan', false, 'no job to edit');
    await go('approvals', '[data-occpick]');
    await page.click('[data-occpick]');
    await page.waitForSelector('dialog.pk[open] .pk-row', { timeout: 15000 });
    await sleep(300);
    await shot('occasion');
    const pre = await page.$$eval('dialog.pk [data-order] .chip', (c) => c.length);
    const add = await page.$eval('dialog.pk .pk-row:not(.on):not(.off)', (r) => r.dataset.pid);
    const occId = await page.$eval('[data-occpick]', (b) => b.dataset.occpick);
    await page.click(`dialog.pk .pk-row[data-pid="${add}"]`); await page.click('dialog.pk [data-save]');
    await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((t) => /Prepared again/.test(t.innerText)), null, { timeout: 15000 });
    const occ = (await api('/api/occasions')).j;
    const pk1 = (occ.packages || []).find((j) => j.id === occId) || {};
    const ads1 = (occ.ads || {})[pk1.id] || [];
    check('5 occasions', pre >= 1 && (pk1.product_ids || []).includes(add) && ads1.some((a) => (a.product_ids || []).includes(add)), `Approvals → Occasions → Change products: ${pre} preselected, added ${add}; package now ${pk1.product_ids}; ${ads1.length} ads prepared again`);
    await ctx.close();
  }
  check('6 clean', errors.length === 0 && failed.length === 0, `console errors ${errors.length} ${errors.slice(0, 3).join(' | ')}; failed requests ${failed.length} ${failed.slice(0, 3).join(' | ')}`);
  await browser.close();
  eng.kill();
  const ok = results.every(Boolean);
  console.log(`${ok ? 'ALL PASS' : 'FAILED'}  ${results.filter(Boolean).length}/${results.length}`);
  process.exit(ok ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(1); });
