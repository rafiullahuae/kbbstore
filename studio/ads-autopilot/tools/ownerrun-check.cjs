#!/usr/bin/env node
// "Run it" puts the ads live (the owner, 21 Nov: "I pressed Run on two routines, what will happen next? where i can
// see the status of it, in the ad account i can not see any those ads"). On the REAL engine (-mock: fake Meta):
//   1  the first launch runs (the prospecting campaign exists)
//   2  Create window → Run it on a saved set: a job "Run your ads made with Claude" starts AT ONCE (Practice), every
//      ad of the set is created on Meta in the prospecting ad set and switched on, inside the limits
//   3  the job shows on Plan → Upcoming jobs / recent, done, with its steps
//   4  pressing Run it again creates nothing new
//   5  his real case (approved by the OLD Run it): engine/ownerrun_test.go TestOldRunItApprovalsGoLive
//   NODE_PATH=… node tools/ownerrun-check.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const SHOT = process.argv.includes('--shots');
const PORT = 45700 + Math.floor(Math.random() * 200);
const results = [];
const check = (n, ok, msg) => { results.push({ n, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-run-'));
  const dataDir = path.join(tmp, 'data');
  fs.mkdirSync(dataDir);
  const bin = path.join(tmp, 'engine');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  const env = Object.assign({}, process.env, { KAA_NOTIFY_CMD: '/bin/true', KAA_OPEN_CMD: '/bin/true', KAA_CLAUDE_CONFIG: path.join(tmp, 'cfg.json') });
  delete env.KAA_BRAIN;
  let eng = null, engLog = '';
  const start = async () => {
    eng = spawn(bin, ['-dir', ROOT, '-data', dataDir, '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '1800s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
    eng.stdout.on('data', (d) => { engLog += d; }); eng.stderr.on('data', (d) => { engLog += d; });
    for (let i = 0; i < 200; i++) { await sleep(150); if (await fetch(`http://127.0.0.1:${PORT}/api/info`).then((r) => r.ok).catch(() => false)) return; }
    console.log(engLog.slice(-3000)); process.exit(2);
  };
  await start();
  let mcp = null;
  const stop = () => { try { eng.kill(); } catch {} try { mcp && mcp.kill(); } catch {} };
  process.on('exit', stop);
  const base = `http://127.0.0.1:${PORT}`;
  let info = await (await fetch(base + '/api/info')).json();
  const api = async (p, method = 'GET', body) => { const r = await fetch(base + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 60; i++) { const c = (await api('/api/connections')).j; if (c && c.meta && c.meta.ok && c.shop && c.shop.ok) break; await sleep(250); }
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'], max_active_ads: 20 });

  // 1 ─ the first launch
  await api('/api/cycle', 'POST', { mode: 'shadow' });
  const fl = await api('/api/jobs/first-launch/approve', 'POST', {});
  const adsOf = async () => (((await api("/api/state")).j || {}).campaigns || []).flatMap((c) => (c.adsets || []).flatMap((s) => (s.ads || []).map((a) => Object.assign({ set: s.id, campaign: c.name }, a))));
  const ads0 = await adsOf();
  check('first launch: the prospecting campaign exists', fl.s === 200 && ads0.length > 0, `${ads0.length} ads live · ${JSON.stringify((fl.j && fl.j.run && fl.j.run.counts) || fl.j).slice(0, 120)}`);

  // a set made in the Claude app, saved
  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  await ctx.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.seg', 'off'); } catch {} });
  let page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(base + '/app/#/overview');
  await page.waitForFunction(() => window.__kaa && window.__kaa.state && window.__kaa.sseAt, null, { timeout: 30000 });
  const H = (fn, ...a) => page.evaluate(fn, ...a);
  mcp = spawn(bin, ['-mcp', '-data', dataDir], { stdio: ['pipe', 'pipe', 'pipe'] });
  let buf = '';
  const waiting = new Map();
  mcp.stdout.on('data', (d) => { buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { const ln = buf.slice(0, i); buf = buf.slice(i + 1); if (!ln.trim()) continue; const m = JSON.parse(ln); if (waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } } });
  let rid = 0;
  const rpc = (method, params) => new Promise((ok) => { const id = ++rid; waiting.set(id, ok); mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n'); });
  const tool = (name, args) => rpc('tools/call', { name, arguments: args });
  const textOf = (r) => { try { return JSON.parse(r.result.content[0].text); } catch { return r.result ? r.result.content[0].text : r.error; } };
  await rpc('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'run-check', version: '1' } });
  mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
  const prods = (await api('/api/brain/x/products')).j;
  const copy = (h) => ({ ar: { headline: 'روتين ' + h, primary_text: 'عناية كورية أصلية بتوصيل داخل الإمارات.', sub: 'ترطيب', bullets: ['ترطيب', 'نضارة'], badge: 'K-Beauty', cta: 'SHOP_NOW' },
    en: { headline: 'Routine ' + h, primary_text: 'Authentic Korean skincare, delivered across the UAE.', sub: 'Hydration', bullets: ['Hydration', 'Glow'], badge: 'K-Beauty', cta: 'SHOP_NOW' } });
  const mk = async (h, pid) => { const c = textOf(await tool('create_ad_set', { ideas: [Object.assign({ product_ids: [pid], angle: 'routine ' + h, hook: h, template: 'glow', formats: ['1:1', '4:5', '9:16'], show_price: true }, copy(h))], prompt: 'routine ' + h })); await tool('save_to_library', { session_id: c.session_id }); return c.session_id; };
  const sA = await mk('A', prods[0].id);

  // 2 ─ Run it in the Create window
  await page.goto(base + '/app/#/creatives'); await page.waitForSelector('[data-tab="claude"]'); await page.click('[data-tab="claude"]');
  await page.waitForSelector(`[data-sess="${sA}"]`, { timeout: 15000 }); await page.click(`[data-sess="${sA}"]`);
  await page.waitForSelector('dialog.cc[open] #cc-run');
  await page.waitForFunction(() => document.querySelectorAll('dialog.cc .cc-slot img').length >= 1 && document.querySelectorAll('dialog.cc .cc-sq img').length >= 1, null, { timeout: 120000 }).catch(async (e) => { await page.screenshot({ path: path.join(tmp, 'stuck.png') }); console.log('STUCK', tmp, await H(() => ({ slots: document.querySelectorAll('dialog.cc .cc-slot').length, imgs: document.querySelectorAll('dialog.cc img').length, status: (document.querySelector('dialog.cc .cc-status') || {}).innerText, said: (document.querySelector('dialog.cc') || {}).innerText.slice(0, 600) }))); throw e; });
  await H(() => { window.__kaaLaunch = null; });
  await page.click('#cc-run');
  await page.waitForFunction(() => window.__kaaLaunch, null, { timeout: 180000 });
  const L = await H(() => window.__kaaLaunch);
  const toastTxt = await page.waitForFunction(() => [...document.querySelectorAll('.toast')].map((x) => x.innerText).find((t) => /going live now|queued/.test(t)), null, { timeout: 15000 }).then((h) => h.jsonValue()).catch(() => '');
  let job = null;
  for (let i = 0; i < 60; i++) { await sleep(1000); job = ((await api('/api/jobs')).j.jobs || []).find((j) => j.id === L.job); if (job && !['planned', 'approved', 'waiting'].includes(job.status)) break; }
  const lib = (await api('/api/creatives')).j;
  const metas = new Set(lib.filter((c) => L.ids.includes(c.id)).map((c) => c.meta_creative_id).filter(Boolean));
  const ads1 = await adsOf();
  const mine = ads1.filter((a) => metas.has(a.creative_id));
  check('Run it → a job starts at once and the ads go live on Meta', L.starting === true && job && job.status === 'done' && mine.length === L.ids.length && mine.every((a) => a.status === 'ACTIVE' && /Made with Claude/.test(a.name)),
    `job ${L.job} ${job && job.status} (${job && job.result}) · ${mine.length}/${L.ids.length} ads ACTIVE: ${mine.map((a) => a.name).slice(0, 2).join(' | ')} · toast “${String(toastTxt).slice(0, 90)}”`);
  if (SHOT) { await page.screenshot({ path: path.join(SHOTS, 'run-toast-1280.png') }); }

  // 3 ─ Plan shows it
  await H(() => { const d = document.querySelector('dialog.cc[open]'); if (d) d.close(); });
  await page.goto(base + '/app/#/plan');
  await page.waitForFunction(() => /Run your \d+ ad/.test(document.body.textContent), null, { timeout: 30000 }).catch(() => {}); // the job list loads after the page
  await H(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  const planTxt = await H(() => { const e = [...document.querySelectorAll('.list > div, [data-job]')].find((x) => /Run your \d+ ad/.test(x.textContent)); return e ? e.textContent : ''; });
  if (!planTxt) console.log('PLAN', await H(() => [...document.querySelectorAll('details')].map((d) => d.textContent.slice(0, 600)).join(' || ')));
  check('Plan shows the run job with its result', /Run your \d+ ad/.test(planTxt) && /done|تم/.test(planTxt), planTxt.replace(/\n/g, ' ').slice(0, 160));
  if (SHOT) for (const w of [1280, 390]) { await page.setViewportSize({ width: w, height: 900 }); await sleep(300); const e = await page.$('details'); if (e) await e.scrollIntoViewIfNeeded(); await page.screenshot({ path: path.join(SHOTS, `run-plan-${w}.png`) }); }
  await page.setViewportSize({ width: 1280, height: 900 });
  const sw = await H(() => document.documentElement.scrollWidth);

  // 4 ─ again: nothing new
  const again = (await api('/api/launch', 'POST', { creative_ids: L.ids, launch: false })).j;
  await sleep(3000);
  const ads2 = await adsOf();
  check('Run it again creates nothing new', again.job === L.job && ads2.length === ads1.length, `job ${again.job} · ads ${ads1.length} → ${ads2.length}`);

  // 5 ─ his case (approved by the OLD Run it): engine/ownerrun_test.go TestOldRunItApprovalsGoLive (the fake Meta
  //     lives in this process, so a restart here would lose the account)

  check('no horizontal scroll, no console errors', sw <= 1280 && !errors.length, `scrollWidth ${sw} · ${errors.length} ${errors.slice(0, 3).join(' | ')}`);
  await browser.close();
  stop();
  const bad = results.filter((r) => !r.ok);
  console.log(`\n${results.length - bad.length}/${results.length} passed`);
  if (bad.length) console.log(engLog.slice(-2500));
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
