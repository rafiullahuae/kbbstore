#!/usr/bin/env node
// Preview before it runs (the owner, 22 Nov: "for run, i want a popup by seeing the final things which will actually
// run. incase i don't like anything, i can re-generate instantely or remove any product etc."). REAL engine, -mock:
//   1  Overview → Run a cycle (Auto) opens "What will run now": every ad with picture, words, product, ad set,
//      budget, landing page (super-sale in the ad's language) and Page — and NOTHING is on Meta yet
//   2  ✕ Remove takes an ad out; ↻ Other product brings the next product in its place (prepared again)
//   3  Run sends exactly what is left: the removed ad and the swapped-out product are not on Meta; the rest is,
//      switched on, linking to the super-sale page in its language
//   4  Cancel sends nothing; no console errors; pictures 1280 / 390
//   NODE_PATH=… node tools/preview-check.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const SHOT = process.argv.includes('--shots');
const PORT = 46400 + Math.floor(Math.random() * 200);
const results = [];
const check = (n, ok, msg) => { results.push({ n, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-pv-'));
  const bin = path.join(tmp, 'engine');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  const env = Object.assign({}, process.env, { KAA_NOTIFY_CMD: '/bin/true', KAA_OPEN_CMD: '/bin/true' });
  delete env.KAA_BRAIN;
  const eng = spawn(bin, ['-dir', ROOT, '-data', path.join(tmp, 'data'), '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '900s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let log = ''; eng.stdout.on('data', (d) => { log += d; }); eng.stderr.on('data', (d) => { log += d; });
  process.on('exit', () => { try { eng.kill(); } catch {} });
  const base = `http://127.0.0.1:${PORT}`;
  let info = null;
  for (let i = 0; i < 120 && !info; i++) { await sleep(200); info = await fetch(base + '/api/info').then((r) => (r.ok ? r.json() : null)).catch(() => null); }
  const api = async (p, method = 'GET', body) => { const r = await fetch(base + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 60; i++) { const c = (await api('/api/connections')).j; if (c && c.meta && c.meta.ok && c.shop && c.shop.ok) break; await sleep(250); }
  await api('/api/limits', 'PUT', { daily_aed: 1100, monthly_aed: 15000, autopilot: 'auto', countries: ['AE'], max_active_ads: 20 });
  const kaa = async () => (((await api('/api/state')).j || {}).campaigns || []).filter((c) => /^KAA:/.test(c.name || ''));
  const kaaAds = async () => (await kaa()).flatMap((c) => (c.adsets || []).flatMap((s) => s.ads || []));

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); } catch {} });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(base + '/app/#/overview');
  await page.waitForSelector('#cyc', { timeout: 30000 });
  const H = (fn, ...a) => page.evaluate(fn, ...a);

  // 0 ─ something is already running (the owner's case: his Run it jobs): the preview WAITS, live steps arrive
  await H(() => { window.__kaaLiveSeen = []; window.addEventListener('kaa-live', (e) => window.__kaaLiveSeen.push({ kind: e.detail.kind, running: e.detail.running, n: (e.detail.steps || []).length })); });
  const busy = fetch(base + '/api/cycle', { method: 'POST', headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: JSON.stringify({ mode: 'shadow' }) });
  await sleep(150);
  // 1 ─ the preview
  await page.click('#cyc');
  await page.waitForFunction(() => document.querySelector('#pv[open] [data-pv-run]'), null, { timeout: 300000 });
  const pv = await H(() => JSON.parse(JSON.stringify(window.__kaaPreview.data.preview)));
  const onMeta0 = (await kaa()).length;
  const ads = pv.ads || [];
  const linksOk = ads.filter((a) => a.lang).every((a) => a.link === (a.lang === 'ar' ? 'https://kbeautybliss.com/ar/super-sale/' : 'https://kbeautybliss.com/super-sale/'));
  await busy;
  const seen = await H(() => window.__kaaLiveSeen);
  const pvErr = await H(() => (window.__kaaPreview.data || {}).error || '');
  check('While something runs, the preview waits (no "already running") and the live steps arrive', !/already running/.test(pvErr) && seen.some((x) => x.running && x.n > 0) && seen.some((x) => /Preview/.test(x.kind)),
    `${seen.length} live updates (${[...new Set(seen.map((x) => x.kind))].join(' → ')}) · preview error: ${pvErr || 'none'}`);
  check('Run a cycle opens the preview; nothing is on Meta', ads.length >= 3 && onMeta0 === 0 && ads.every((a) => a.headline && a.page && a.adset) && linksOk,
    `${ads.length} ads (${ads.map((a) => `${a.lang}:${(a.titles || [])[0] || a.name}`).join(' | ')}) · KAA campaigns on Meta: ${onMeta0} · links ${linksOk ? 'super-sale by language' : JSON.stringify(ads.map((a) => a.link))} · Page ${ads[0] && ads[0].page}`);
  if (SHOT) for (const w of [1280, 390]) { await page.setViewportSize({ width: w, height: 900 }); await sleep(500); await page.screenshot({ path: path.join(SHOTS, `preview-${w}.png`) }); }
  await page.setViewportSize({ width: 1280, height: 900 });

  // 2 ─ remove one ad; another product for a second
  const rm = ads[0].creative_id;
  await page.click(`[data-pv-remove="${rm}"]`);
  const shown = await H(() => document.querySelectorAll('#pv [data-pv-ad]').length);
  const other = ads.find((a) => a.creative_id !== rm && (a.products || []).length);
  const swapped = other.products[0];
  await page.click(`#pv [data-pv-ad="${other.creative_id}"] [data-pv-other]`);
  await page.waitForFunction(() => !window.__kaaPreview.busy && document.querySelector('#pv [data-pv-run]'), null, { timeout: 300000 });
  const pv2 = await H(() => JSON.parse(JSON.stringify(window.__kaaPreview.data.preview)));
  const still = (pv2.ads || []).some((a) => (a.products || []).includes(swapped));
  check('✕ Remove takes an ad out; ↻ Other product brings another in its place', shown === ads.length - 1 && !still && (pv2.ads || []).length >= ads.length - 1,
    `${ads.length} → ${shown} after remove · product ${swapped} out, ${(pv2.ads || []).length} ads prepared again (${(pv2.ads || []).map((a) => (a.titles || [])[0]).join(' | ')})`);

  // 3 ─ Run: exactly what is left
  await H(() => { window.__barSeen = false; new MutationObserver(() => { const p = document.getElementById('livepill'); if (p && !p.hidden && /Running/.test(p.textContent)) window.__barSeen = true; }).observe(document.body, { subtree: true, childList: true, attributes: true, characterData: true }); });
  await page.click('#pv [data-pv-run]');
  // the owner, 23 Nov: the window closes at once, the run goes on in the background with the bar at the top, and a
  // result bar stays until closed
  const closedAtOnce = await page.waitForFunction(() => !document.querySelector('#pv[open]'), null, { timeout: 3000 }).then(() => true).catch(() => false);
  await page.evaluate(() => window.__kaaPreview.running);
  const barSeen = await H(() => window.__barSeen);
  const result = await H(() => { const b = document.getElementById('kaaresult'); return b && !b.hidden ? b.innerText : ''; });
  if (SHOT) for (const w of [1280, 390]) { await page.setViewportSize({ width: w, height: 900 }); await sleep(300); await page.screenshot({ path: path.join(SHOTS, `run-result-${w}.png`) }); }
  await page.setViewportSize({ width: 1280, height: 900 });
  check('Run now: the window closes at once, the top bar shows it running, a result bar stays with Close', closedAtOnce && barSeen && /Done: \d+ change|Finished: \d+/.test(result) && /Close/.test(result), `closed at once ${closedAtOnce} · top bar ${barSeen} · result “${result.replace(/\n/g, ' | ').slice(0, 160)}”`);
  await page.click('#kaaresult [data-r-launched]');
  await page.waitForSelector('[data-launched]', { timeout: 20000 }).catch(() => {});
  const L = await H(() => ({ days: [...document.querySelectorAll('[data-lday]')].map((x) => x.textContent.trim()), items: [...document.querySelectorAll('[data-litem]')].map((x) => x.textContent.replace(/\s+/g, ' ').trim()) }));
  check('Plan → Launched on Meta lists what was made, by day, with time, Meta id and status', L.days.length >= 1 && /Today/.test(L.days[0]) && L.items.some((t) => /Ad .*Meta id \d+/.test(t)) && L.items.some((t) => /Campaign/.test(t)), `${L.days.join(' / ')} · ${L.items.length} items · ${L.items.slice(0, 2).join(' || ').slice(0, 220)}`);
  if (SHOT) for (const w of [1280, 390]) { await page.setViewportSize({ width: w, height: 900 }); await sleep(300); await page.screenshot({ path: path.join(SHOTS, `plan-launched-${w}.png`) }); }
  await page.setViewportSize({ width: 1280, height: 900 });
  await H(() => { const b = document.getElementById('kaaresult'); if (b) b.hidden = true; });
  const lib = (await api('/api/creatives')).j;
  const meta = await kaaAds();
  const byMeta = new Map(lib.filter((c) => c.meta_creative_id).map((c) => [c.meta_creative_id, c]));
  const live = meta.map((a) => byMeta.get(a.creative_id)).filter(Boolean);
  const rmOn = live.some((c) => c.id === rm), swOn = live.some((c) => (c.product_ids || []).includes(swapped));
  check('Run sends exactly what is left, switched on', meta.length >= 2 && !rmOn && !swOn && meta.some((a) => a.status === 'ACTIVE'),
    `${meta.length} KAA ads on Meta (${meta.filter((a) => a.status === 'ACTIVE').length} active) · removed ad on Meta: ${rmOn} · swapped-out product on Meta: ${swOn}`);

  // 3b ─ the Overview card shows what ran, every step
  await page.goto(base + '/app/#/overview'); await page.waitForSelector('#live-card', { timeout: 20000 });
  const card = await H(() => (document.querySelector('#live-card') || {}).innerText || '');
  check('Overview → Running now / Just finished lists every step', /Just finished|Running now/.test(card) && /Reading Meta/.test(card) && /New ad/.test(card), card.replace(/\n/g, ' | ').slice(0, 220));
  if (SHOT) { const c = await page.$('#live-card'); if (c) { await c.scrollIntoViewIfNeeded(); await c.screenshot({ path: path.join(SHOTS, 'live-1280.png') }); } }
  // 4 ─ Cancel sends nothing
  const before = (await kaaAds()).length;
  await page.click('#cyc');
  await page.waitForFunction(() => document.querySelector('#pv[open] [data-pv-cancel]'), null, { timeout: 300000 });
  await page.click('#pv [data-pv-cancel]');
  await sleep(1500);
  const after = (await kaaAds()).length;
  check('Cancel sends nothing; no console errors', after === before && !errors.length, `KAA ads ${before} → ${after} · ${errors.length} errors ${errors.slice(0, 2).join(' | ')}`);

  await browser.close();
  const bad = results.filter((r) => !r.ok);
  console.log(`\n${results.length - bad.length}/${results.length} passed`);
  if (bad.length) console.log(log.slice(-2500));
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
