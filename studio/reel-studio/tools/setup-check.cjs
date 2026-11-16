#!/usr/bin/env node
// KBB Studio — Setup screen check: the model list grouped by the manifest's `group` (Speech, Translation, Image AI …),
// each group with its total size and licence, one "Download group" button on optional groups (and none on needed
// ones); the per-model ticks still work. Headless Chromium against the Linux engine; no model is downloaded (the
// install request is caught in the browser and answered with a "running" status). Pictures → shots/setup/.
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/setup-check.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const H = require('./studio-harness.cjs');

const SHOTS = path.join(H.ROOT, 'shots', 'setup');
fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) failures++; };
const fmt = (b) => (b >= 1e9 ? (b / 1e9).toFixed(2) + ' GB' : (b / 1e6).toFixed(1) + ' MB');   // = fmtBytes in app/core/engine.js

(async () => {
  const man = JSON.parse(fs.readFileSync(path.join(H.ROOT, 'models', 'manifest.json'), 'utf8')).models;
  ok(man.every((m) => m.group && m.licence), `manifest: every model has a group and a licence (${[...new Set(man.map((m) => m.group))].join(', ')})`);
  const dir = H.makeStudio();
  const e = await H.startEngine(dir);
  const browser = await H.launchChrome();
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  try {
    await page.goto(e.url + '/app/');
    await page.waitForSelector('.setup-screen .setup-form', { timeout: 15000 });
    const groups = await page.evaluate(() => [...document.querySelectorAll('.model-group')].map((g) => ({
      name: g.dataset.group, meta: g.querySelector('.mgroup-meta').textContent, btn: !!g.querySelector('[data-group-go]'),
      models: [...g.querySelectorAll('.model-row')].map((r) => r.dataset.model),
    })));
    const want = new Map(); for (const m of man) { if (!want.has(m.group)) want.set(m.group, []); want.get(m.group).push(m); }
    ok(groups.length === want.size && groups.every((g) => (want.get(g.name) || []).map((m) => m.id).join() === g.models.join()), `${groups.length} groups, each listing its manifest models: ${groups.map((g) => `${g.name} (${g.models.length})`).join(', ')}`);
    const firstOptional = groups.findIndex((g) => !want.get(g.name).some((m) => m.required));
    ok(firstOptional > 0 && groups.slice(firstOptional).every((g) => !want.get(g.name).some((m) => m.required)), `needed groups first (${groups.slice(0, firstOptional).map((g) => g.name).join(', ')}), then the optional ones`);
    for (const g of groups) {
      const ms = want.get(g.name), total = ms.reduce((s, m) => s + m.size, 0), lic = [...new Set(ms.map((m) => m.licence))], optional = !ms.some((m) => m.required);
      ok(g.meta.includes(fmt(total)) && lic.every((l) => g.meta.includes(l)) && g.btn === optional, `${g.name}: “${g.meta}”${g.btn ? ' + Download group' : ''}`);
    }
    for (const [w, h] of [[1280, 800], [1920, 1080]]) {
      await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(400);
      await page.evaluate(() => { document.querySelector('.setup-screen').scrollTop = 0; });
      const sw = await page.evaluate(() => document.documentElement.scrollWidth);
      ok(sw <= w, `${w}×${h}: no sideways scroll (scrollWidth ${sw})`);
      await page.screenshot({ path: path.join(SHOTS, `setup-${w}x${h}.png`) });
      // the screen scrolls on its own and the main button can be reached and clicked (the page body never scrolls)
      const reach = await page.evaluate(() => {
        const sc = document.querySelector('.setup-screen'), b = document.querySelector('#su-go');
        sc.scrollTop = sc.scrollHeight; const r = b.getBoundingClientRect(), hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
        return { tall: sc.scrollHeight, inView: r.bottom <= innerHeight && r.top >= 0, hit: hit === b || b.contains(hit) };
      });
      ok(reach.inView && reach.hit, `${w}×${h}: list ${reach.tall} px tall; scrolled down, “Allow and download” is on screen and takes the click`);
      await page.screenshot({ path: path.join(SHOTS, `setup-${w}x${h}-bottom.png`) });
    }
    // per-model ticks unchanged: ticking an optional model adds its size to the main button
    const before = await page.textContent('#su-go');
    await page.click('.model-row[data-model="upscaler"] input');
    const after = await page.textContent('#su-go');
    await page.click('.model-row[data-model="upscaler"] input');
    ok(before !== after && (await page.textContent('#su-go')) === before, `per-model tick still adds to the download: “${before.trim()}” → “${after.trim()}” → back`);
    ok(await page.evaluate(() => [...document.querySelectorAll('.model-row')].filter((r) => /Needed/.test(r.textContent)).every((r) => r.querySelector('input').disabled && r.querySelector('input').checked)), 'needed models stay ticked and locked');
    // "Download group" on Translation: ONE install of exactly that group's models (caught, nothing is downloaded)
    let installed = null, grants = 0;
    await page.route('**/api/permission', (r) => { grants++; r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); });
    await page.route('**/api/models/install', async (r) => {
      installed = JSON.parse(r.request().postData() || '{}').ids;
      const st = await (await fetch(e.url + '/api/models', { headers: { 'X-RS-Token': e.token } })).json().catch(() => null) || { models: [] };
      st.installer = { state: 'running', message: '', ids: installed, files: [], total: 1e9, done: 2e8, speed: 5e6, eta: 160 };
      r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(st) });
    });
    await page.click('.model-group[data-group="Translation"] [data-group-go]');
    await page.waitForFunction(() => /Pause/.test(document.querySelector('#su-go')?.textContent || ''), null, { timeout: 8000 }).catch(() => {});
    const tr = man.filter((m) => m.group === 'Translation').map((m) => m.id);
    ok(installed && installed.slice().sort().join() === tr.slice().sort().join() && grants === tr.length, `Download group (Translation): one install of exactly ${installed ? installed.join(', ') : 'nothing'} after ${grants} repo grants`);
    const runUi = await page.evaluate(() => ({ go: document.querySelector('#su-go').textContent, btns: [...document.querySelectorAll('[data-group-go]')].map((b) => b.disabled), ticked: [...document.querySelectorAll('.model-group[data-group="Translation"] .model-row input')].every((i) => i.checked) }));
    ok(/Pause/.test(runUi.go) && runUi.btns.every(Boolean) && runUi.ticked, `while it downloads: the main button reads “${runUi.go}”, group buttons are disabled, the group's models are ticked`);
    await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(300);
    await page.evaluate(() => { const g = document.querySelector('.model-group[data-group="Translation"]'); g.scrollIntoView({ block: 'center' }); });
    await page.screenshot({ path: path.join(SHOTS, 'setup-group-download-1280x800.png') });
    ok(!errors.length, `no console errors (${errors.length}${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''})`);
  } finally {
    await browser.close(); await H.stopEngine(e); fs.rmSync(dir, { recursive: true, force: true });
  }
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
