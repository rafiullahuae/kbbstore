#!/usr/bin/env node
// QA owner journey against the REAL engine -mock (scheduler ON, several ad accounts), in Chromium, EN and AR:
//   keys: a wrong key is refused, the right one accepted · pick accounts · limits · Practice · Run a cycle ·
//   every screen renders real content or an explanatory empty state (never blank) · switch account · Create with
//   Claude opens · Settings → Updates idle · the warm-up bar (Updating… / offline / Up to date) · stop the engine,
//   replace the app folder with a fresh copy, start → keys, accounts, limits, plan, creatives, history still there.
// Records every console error, failed request, blank panel and screen switch over 100 ms.
//   NODE_PATH=<repo>/node_modules node tools/qa-journey.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const PORT = 44100 + Math.floor(Math.random() * 400);
const shots = process.argv.includes('--shots');
const results = [];
const check = (n, ok, msg) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ROUTES = ['overview', 'portfolio', 'campaigns', 'creatives', 'videos', 'audiences', 'research', 'learned', 'plan', 'activity', 'approvals', 'settings'];

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-qaj-'));
const APP = path.join(tmp, 'Downloads', 'KBB Ads Autopilot'); // the app folder the owner replaces
const DATA = path.join(tmp, 'KBB Ads Autopilot Data');
const bin = path.join(tmp, 'kaa-build');

function freshAppFolder() { // a freshly built copy: app/, docs, VERSION, engine binary
  fs.rmSync(APP, { recursive: true, force: true });
  fs.mkdirSync(path.join(APP, 'engine'), { recursive: true });
  fs.cpSync(path.join(ROOT, 'app'), path.join(APP, 'app'), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'VERSION'), path.join(APP, 'VERSION'));
  fs.copyFileSync(bin, path.join(APP, 'engine', 'kaa-mac-arm64'));
  fs.chmodSync(path.join(APP, 'engine', 'kaa-mac-arm64'), 0o755);
}

let eng = null, log = '';
async function startEngine() {
  const env = Object.assign({}, process.env, { KAA_SECRETS_FILE: '1' });
  delete env.KAA_BRAIN;
  eng = spawn(path.join(APP, 'engine', 'kaa-mac-arm64'), ['-dir', APP, '-data', DATA, '-port', String(PORT), '-no-browser', '-mock', '-idle', '600s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  eng.stdout.on('data', (d) => { log += d; }); eng.stderr.on('data', (d) => { log += d; });
  let info = null;
  for (let i = 0; i < 120 && !info; i++) { await sleep(250); info = await fetch(base + '/api/info').then((r) => (r.ok ? r.json() : null)).catch(() => null); }
  if (!info) { console.log(log); process.exit(2); }
  token = info.token;
  for (let i = 0; i < 80; i++) { const c = (await api('/api/connections')).j; if (c && c.meta && c.meta.ok && c.claude.ok) break; await sleep(250); }
  return info;
}
async function stopEngine() {
  const done = new Promise((r) => eng.once('exit', r));
  await api('/api/stop', 'POST').catch(() => {});
  await Promise.race([done, sleep(8000)]);
  if (eng.exitCode === null) eng.kill();
}
const base = `http://127.0.0.1:${PORT}`;
let token = '';
const api = async (p, method = 'GET', body, acct) => {
  const h = { 'X-KAA': token, 'content-type': 'application/json' };
  if (acct) h['X-KAA-Account'] = acct;
  const r = await fetch(base + p, { method, headers: h, body: body ? JSON.stringify(body) : undefined });
  let j = null; try { j = await r.json(); } catch {}
  return { s: r.status, j };
};
const warmDone = async () => { for (let i = 0; i < 200; i++) { const w = (await api('/api/warmup')).j; if (w && !w.running && w.runs > 0) return w; await sleep(100); } return null; };

(async () => {
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine'), env: Object.assign({}, process.env, { CGO_ENABLED: '0' }) });
  freshAppFolder();
  await startEngine();

  // ── keys: wrong refused, right accepted ──
  let r = await api('/api/connections/claude', 'PUT', { api_key: 'EAAwrongMetaToken123' });
  check('K1', r.s === 400 && /Meta token/.test(r.j && r.j.error), `a Meta token in the Claude field → ${r.s} ${r.j && r.j.error}`);
  r = await api('/api/connections/claude', 'PUT', { api_key: 'sk-ant-mock-0000000000' });
  check('K2', r.s === 200, `the right Claude key → ${r.s}`);
  r = await api('/api/connections/meta', 'PUT', { app_secret: 'sk-ant-api03-notasecret' });
  check('K3', r.s === 400, `a Claude key as the Meta app secret → ${r.s}`);
  r = await api('/api/connections/meta', 'PUT', { app_secret: 'mockappsecret0000' });
  check('K4', r.s === 200, `the Meta app secret → ${r.s}`);
  // ── accounts, limits, Practice, a cycle ──
  const accts = ((await api('/api/accounts')).j || {}).accounts || [];
  check('A1', accts.length >= 2, `${accts.length} ad accounts`);
  const [a1, a2] = accts.map((a) => a.id);
  for (const id of [a1, a2]) {
    await api('/api/accounts/' + id, 'PUT', { automated: true });
    await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'] }, id);
  }
  r = await api('/api/cycle', 'POST', { mode: 'shadow' }, a1);
  check('A2', r.s === 200, `Run a cycle (Practice) → ${r.s} ${r.s !== 200 ? JSON.stringify(r.j).slice(0, 200) : ''}`);
  const w0 = await warmDone();
  check('W1', !!w0 && w0.runs >= 1 && w0.last_full && !w0.last_full.startsWith('0001'), `the start warm-up ran: ${JSON.stringify(w0 && w0.steps)}`);

  const before = {
    conns: (await api('/api/connections')).j,
    accts: ((await api('/api/accounts')).j || {}).accounts,
    limits: (await api('/api/limits', 'GET', null, a1)).j,
    plan: ((await api('/api/state', 'GET', null, a1)).j || {}).plan,
    creatives: ((await api('/api/creatives', 'GET', null, a1)).j || {}),
    log: ((await api('/api/log?n=500', 'GET', null, a1)).j || {}),
  };

  // ── the browser: every screen, EN and AR ──
  const browser = await chromium.launch();
  const issues = { console: [], failed: [], blank: [], slow: [] };
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  page.on('console', (m) => { if (m.type() === 'error') issues.console.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => issues.console.push('pageerror ' + String(e).slice(0, 200)));
  page.on('response', (res) => { if (res.status() >= 400 && !res.url().includes('/api/events')) issues.failed.push(`${res.status()} ${res.url().replace(base, '')}`); });
  page.on('requestfailed', (q) => { if (!q.url().includes('/api/events')) issues.failed.push(`failed ${q.url().replace(base, '')} ${q.failure() && q.failure().errorText}`); });
  await page.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); } catch {} });
  const setLang = async (lang) => { await page.click(`#lang button[data-l="${lang}"]`); await page.waitForTimeout(300); };
  for (const lang of ['en', 'ar']) {
    await page.goto(base + '/app/#/overview');
    await page.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });
    await setLang(lang);
    for (const rt of ROUTES) {
      await page.evaluate((x) => { location.hash = '#/' + x; }, rt);
      await page.waitForTimeout(700);
      const v = await page.evaluate(() => {
        const el = document.getElementById('view');
        const sw = (window.__kaa.switches || []).slice(-1)[0] || {};
        return { len: (el.innerText || '').trim().length, skel: !!el.querySelector('.skel, .skeleton'), ms: sw.ms, route: sw.route, sw: document.documentElement.scrollWidth, w: innerWidth };
      });
      if (v.len < 40 || v.skel) issues.blank.push(`${lang}/${rt} (${v.len} chars${v.skel ? ', still a skeleton' : ''})`);
      if (v.ms > 100) issues.slow.push(`${lang}/${rt} ${v.ms} ms`);
      if (v.sw > v.w + 1) issues.blank.push(`${lang}/${rt} scrolls sideways (${v.sw} > ${v.w})`);
      if (shots && lang === 'en') await page.screenshot({ path: path.join(SHOTS, `qa-${rt}.png`) });
    }
  }
  // a failed accounts read at start is not "no accounts" (it sent him to the first-run wizard) -- MUTATION: put back
  // .catch(() => ({ accounts: [] })) in main.js start → S1 red
  const pf = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  await pf.route('**/api/accounts', (rt) => rt.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"disk busy"}' }));
  await pf.goto(base + '/app/#/overview');
  await pf.waitForSelector('#start-failed', { timeout: 8000 }).catch(() => {});
  const s1 = await pf.evaluate(() => ({ err: !!document.getElementById('start-failed'), hash: location.hash, text: (document.getElementById('view').innerText || '').slice(0, 120) }));
  check('S1', s1.err && !s1.hash.includes('welcome'), `accounts read fails at start → ${s1.err ? 'a clear message + Try again' : 'no message'}, at ${s1.hash}: ${s1.text.replace(/\s+/g, ' ')}`);
  await pf.unroute('**/api/accounts');
  if (s1.err) { await pf.click('#start-retry'); await pf.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 15000 }).catch(() => {}); }
  check('S2', await pf.evaluate(() => !!(window.__kaa && window.__kaa.state) && !document.getElementById('start-failed')), 'Try again → the overview');
  await pf.close();

  // switch account, Create with Claude, Settings → Updates
  await page.goto(base + '/app/#/overview');
  await page.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });
  const sw = await page.evaluate(async (id) => { const m = await import('/app/js/main.js'); const t0 = performance.now(); m.switchAccount(id); const ms = Math.round(performance.now() - t0); await new Promise((r) => setTimeout(r, 1500)); return { ms, now: window.__kaa.state && (window.__kaa.state.raw || {}).account_id }; }, a2);
  check('U1', sw.now === a2, `switch account → ${sw.now} in ${sw.ms} ms`);
  await page.click('#cc-top');
  await page.waitForTimeout(800);
  const cc = await page.evaluate(() => { const d = document.querySelector('dialog[open], .cc-panel, .cc, [data-cc]'); return d ? (d.innerText || '').trim().length : 0; });
  check('U2', cc > 20, `Create with Claude opens (${cc} chars)`);
  await page.keyboard.press('Escape');
  await page.goto(base + '/app/#/settings?tab=updates');
  await page.waitForTimeout(1200);
  const upd = await page.evaluate(() => (document.getElementById('view').innerText || ''));
  check('U3', /update|تحديث/i.test(upd) && !/went wrong|did not load/i.test(upd), `Settings → Updates idle: ${upd.slice(0, 120).replace(/\s+/g, ' ')}`);

  // ── the warm-up bar ──
  for (const lang of ['en', 'ar']) {
    await page.goto(base + '/app/#/overview');
    await page.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });
    await setLang(lang);
    await api('/api/test/offline', 'POST', { on: false, pause_ms: 1500 });
    await api('/api/warmup', 'POST', { reason: 'online' });
    await page.waitForSelector('#warm.run', { timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1700);
    const run = await page.evaluate(() => { const e = document.getElementById('warm'); return e && !e.hidden ? e.innerText : ''; });
    check('W2' + lang, /Updating|جارٍ التحديث/.test(run) && /✓/.test(run), `${lang} Updating bar: ${run.replace(/\s+/g, ' ')}`);
    if (shots) await page.screenshot({ path: path.join(SHOTS, `qa-warm-updating-${lang}.png`), clip: { x: 0, y: 0, width: 1440, height: 140 } });
    await warmDone();
    await api('/api/test/offline', 'POST', { on: true, pause_ms: 0 });
    await api('/api/warmup', 'POST', { reason: 'online' });
    await page.waitForSelector('#warm.off', { timeout: 8000 }).catch(() => {});
    const off = await page.evaluate(() => { const e = document.getElementById('warm'); return e && !e.hidden ? e.innerText : ''; });
    check('W3' + lang, /No internet|لا يوجد اتصال/.test(off) && !!(await page.$('#warm-try')), `${lang} offline bar: ${off.replace(/\s+/g, ' ')}`);
    if (shots) await page.screenshot({ path: path.join(SHOTS, `qa-warm-offline-${lang}.png`), clip: { x: 0, y: 0, width: 1440, height: 140 } });
    await api('/api/test/offline', 'POST', { on: false, pause_ms: 0 });
    await page.click('#warm-try');
    await page.waitForFunction(() => { const e = document.getElementById('warm'); return e && e.hidden; }, null, { timeout: 10000 }).catch(() => {});
    const ok = await page.evaluate(() => ({ bar: document.getElementById('warm').hidden, text: document.getElementById('warm').innerText, at: (document.getElementById('warm-at') || {}).innerText || '' }));
    check('W4' + lang, ok.bar && /Up to date|محدَّث/.test(ok.at), `${lang} back online, Try again → bar gone, nav foot "${ok.at}" ${ok.bar ? '' : 'bar: ' + ok.text + ' ' + JSON.stringify((await api('/api/warmup')).j)}`);
    if (shots) await page.screenshot({ path: path.join(SHOTS, `qa-warm-uptodate-${lang}.png`) });
  }
  // a second tab and a reload never re-run the warm-up
  const runsBefore = (await api('/api/warmup')).j.runs;
  const p2 = await browser.newPage();
  await p2.goto(base + '/app/#/overview'); await p2.waitForTimeout(1200); await p2.reload(); await p2.waitForTimeout(1200);
  await api('/api/warmup', 'POST', { reason: 'page' });
  await sleep(300);
  check('W5', (await api('/api/warmup')).j.runs === runsBefore, `a new tab, a reload and a page return within 2 min: warm-ups ${runsBefore} → ${(await api('/api/warmup')).j.runs}`);
  await p2.close();

  check('C1', issues.console.length === 0, `console errors: ${JSON.stringify(issues.console.slice(0, 6))}`);
  check('C2', issues.failed.length === 0, `failed requests: ${JSON.stringify(issues.failed.slice(0, 6))}`);
  check('C3', issues.blank.length === 0, `blank / sideways screens: ${JSON.stringify(issues.blank)}`);
  check('C4', issues.slow.length === 0, `screen switches > 100 ms: ${JSON.stringify(issues.slow)}`);

  // ── stop, replace the app folder with a freshly built copy, start ──
  await stopEngine();
  freshAppFolder();
  await startEngine();
  await warmDone();
  const after = {
    conns: (await api('/api/connections')).j,
    accts: ((await api('/api/accounts')).j || {}).accounts,
    limits: (await api('/api/limits', 'GET', null, a1)).j,
    plan: ((await api('/api/state', 'GET', null, a1)).j || {}).plan,
    creatives: ((await api('/api/creatives', 'GET', null, a1)).j || {}),
    log: ((await api('/api/log?n=500', 'GET', null, a1)).j || {}),
  };
  const sec = (c) => c && c.meta && c.meta.app_secret;
  check('R1', sec(after.conns) && sec(after.conns).set && sec(after.conns).last4 === sec(before.conns).last4, `the Meta app secret (not re-seeded by -mock) after replacing the folder: ${JSON.stringify(sec(after.conns))}`);
  check('R2', after.conns.claude.key.set && after.conns.meta.token.set, 'Meta token and Claude key set');
  check('R3', JSON.stringify((after.accts || []).map((a) => [a.id, a.automated])) === JSON.stringify((before.accts || []).map((a) => [a.id, a.automated])), `accounts: ${(after.accts || []).length}, automated kept`);
  check('R4', after.limits && after.limits.daily_aed === 300 && after.limits.monthly_aed === 9000, `limits: ${after.limits && after.limits.daily_aed}/${after.limits && after.limits.monthly_aed}`);
  check('R5', !!after.plan === !!before.plan, `plan kept: ${!!after.plan}`);
  const n = (c) => (Array.isArray(c) ? c.length : (c.creatives || c.items || []).length);
  check('R6', n(after.creatives) >= n(before.creatives), `creatives ${n(before.creatives)} → ${n(after.creatives)}`);
  const ln = (l) => (Array.isArray(l) ? l.length : (l.entries || l.items || []).length);
  check('R7', ln(after.log) >= ln(before.log), `history ${ln(before.log)} → ${ln(after.log)} entries`);

  await browser.close();
  await stopEngine();
  const pass = results.filter(Boolean).length;
  console.log(`\n${pass}/${results.length} passed`);
  if (pass !== results.length) { fs.writeFileSync(path.join(tmp, 'engine.log'), log); console.log('engine log: ' + path.join(tmp, 'engine.log')); }
  process.exit(pass === results.length ? 0 : 1);
})().catch((e) => { console.error(e); try { eng && eng.kill(); } catch {} process.exit(1); });
