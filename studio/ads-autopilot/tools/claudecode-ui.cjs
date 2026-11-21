#!/usr/bin/env node
// "Auto-create with Claude Code (Max)" in the app, on the REAL engine (-mock) with tools/fake-claude.cjs as the CLI
// (KAA_CLAUDE_BIN; it drives the REAL `engine -mcp` connector, so this tab draws and the quality gate runs):
//   1  Settings → Connections → "Claude Code on this Mac (Max plan)": detected, version, signed in ✓, Automatic: On
//      (the default), Test → "Works"
//   2  a creation request is raised → the Approvals card says "Being made by Claude Code on this Mac…" with the live
//      step (pushed over SSE, no reload, no Start), then the request is done and "Made by Claude Code on this Mac"
//      shows; the run's environment carried no ANTHROPIC_API_KEY and its allowed tools were only mcp__kbb__*
//   3  the notice ✕ closes it; Settings → Accounts "Automate" is an On/Off toggle that flips
//   4  Arabic, RTL, 390: the same card and status; no console errors, no failed requests, no horizontal scroll
// --shots: shots/claudecode-*.jpg.   NODE_PATH=… node tools/claudecode-ui.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOT = process.argv.includes('--shots');
const PORT = 45000 + Math.floor(Math.random() * 900);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let fails = 0;
const check = (ok, name, info = '') => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  ' + info : ''}`); if (!ok) fails++; };

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-cc-'));
  const dataDir = path.join(tmp, 'data'), runLog = path.join(tmp, 'runs.jsonl');
  fs.mkdirSync(dataDir);
  const bin = path.join(tmp, 'engine');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  const fake = path.join(tmp, 'claude');
  fs.writeFileSync(fake, `#!/bin/sh\nexec node ${JSON.stringify(path.join(ROOT, 'tools', 'fake-claude.cjs'))} "$@"\n`, { mode: 0o755 });
  const env = Object.assign({}, process.env, { KAA_NOTIFY_CMD: '/bin/true', KAA_CLAUDE_BIN: fake, FAKE_CC_LOG: runLog, FAKE_CC_HOLD_MS: '3500', ANTHROPIC_API_KEY: 'sk-ant-api03-MUST-NOT-REACH-CLAUDE-CODE' });
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

  const browser = await chromium.launch();
  const errors = [], failed = [];
  const open = async (lang, w, h, hash = '#/overview') => {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    await ctx.addInitScript((l) => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.seg', 'off'); localStorage.setItem('kaa.lang', l); } catch {} }, lang);
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (r) => { if (r.status() >= 400 && !/seg\/(mask|status)/.test(r.url())) failed.push(`${r.status()} ${r.url()}`); });
    await page.goto(base + '/app/' + hash);
    await page.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });
    return page;
  };
  const shot = async (page, name) => { if (SHOT) await page.screenshot({ path: path.join(ROOT, 'shots', `claudecode-${name}.jpg`), quality: 82, type: 'jpeg' }); };
  const geo = (page) => page.evaluate(() => ({ w: document.documentElement.scrollWidth, vw: innerWidth, dir: document.documentElement.dir }));

  // 1: the Settings card
  let page = await open('en', 1280, 900);
  await page.evaluate(() => { location.hash = '#/settings?tab=connections'; });
  await page.waitForSelector('[data-ccode]', { timeout: 15000 });
  await sleep(300);
  let t = await page.$eval('[data-ccode]', (e) => e.textContent.replace(/\s+/g, ' '));
  check(/✓ detected/.test(t) && /2\.1\.330/.test(t) && /✓ signed in \(Max\)/.test(t), 'card: detected, version, signed in ✓', t.match(/✓ detected.*?\(Max\)/)?.[0] || t.slice(0, 120));
  check(/Automatic: On/.test(t) && (await page.$eval('#cc-auto', (b) => b.getAttribute('aria-checked'))) === 'true', 'Automatic is On by default once found and signed in');
  await page.click('#cc-test');
  await page.waitForSelector('#cc-test-out:not([hidden])', { timeout: 20000 });
  const to = await page.$eval('#cc-test-out', (e) => e.textContent);
  check(/^Works: Claude Code answered/.test(to), 'Test runs the tiny prompt', to.slice(0, 70));
  await page.$eval('[data-ccode]', (e) => e.scrollIntoView());
  await shot(page, 'settings-1280');

  // 2: a request → made automatically, live on the Approvals card
  await page.evaluate(() => { location.hash = '#/approvals'; });
  await sleep(800);
  const req = (await api('/api/exchange/test-request', 'POST', { product_ids: [prods[0].id], angle: 'glass skin glow' })).j;
  await page.waitForSelector('[data-cc-working]', { timeout: 15000 }).catch(() => {});
  const working = await page.$eval(`[data-req="${req.id}"]`, (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
  check(/Being made by Claude Code on this Mac…/.test(working), 'Approvals card: "Being made by Claude Code on this Mac…" (pushed, no Start)', working.slice(0, 140));
  await page.$eval(`[data-req="${req.id}"]`, (e) => e.scrollIntoView({ block: 'center' })).catch(() => {});
  await shot(page, 'approvals-working-1280');
  let done = null;
  for (let i = 0; i < 120 && !done; i++) { await sleep(500); const c = (await api('/api/brain/x/creation')).j; const r = (c.requests || []).find((x) => x.id === req.id); if (r && r.status === 'done' && r.local === 'done') done = r; }
  check(!!done && done.local_n > 0, 'the request is done by Claude Code, ads saved through the connector', done ? `${done.local_n} ad(s) · ${done.local_note}` : engLog.slice(-600));
  await page.waitForSelector('[data-local-done]', { timeout: 10000 }).catch(() => {});
  const ld = await page.$eval('[data-local-done]', (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
  check(/Made by Claude Code on this Mac/.test(ld), 'Approvals: "Made by Claude Code on this Mac"', ld.slice(0, 100));
  await shot(page, 'approvals-done-1280');
  const runs = fs.readFileSync(runLog, 'utf8').trim().split('\n').map((l) => JSON.parse(l)).filter((r) => r.argv.includes('--mcp-config'));
  const r0 = runs[0] || { argv: [], env: [] };
  const allowed = r0.argv[r0.argv.indexOf('--allowedTools') + 1] || '';
  check(runs.length === 1 && !r0.env.includes('ANTHROPIC_API_KEY'), 'one run; no ANTHROPIC_API_KEY in its environment', `${runs.length} run(s)`);
  check(allowed.split(',').length === 7 && allowed.split(',').every((x) => x.startsWith('mcp__kbb__')) && r0.argv.includes('dontAsk') && r0.argv.includes('--strict-mcp-config'), 'only the KBB tools, strict MCP, dontAsk', allowed.slice(0, 60) + '…');
  const act = ((await api('/api/log?limit=50')).j || []);
  const lines = (Array.isArray(act) ? act : act.entries || []).map((e) => e.summary || '').filter((s) => /Claude Code on this Mac/.test(s));
  check(lines.length >= 1, 'Activity logs the run', lines[0] || '');
  await page.evaluate(() => { location.hash = '#/settings?tab=connections'; });
  await page.waitForSelector('[data-cc-runs]', { timeout: 10000 }).catch(() => {});
  t = await page.$eval('[data-ccode]', (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
  check(/made · \d+ ad\(s\)/.test(t), 'the card lists the run', t.match(/— made.*? s/)?.[0] || '');
  await page.$eval('[data-ccode]', (e) => e.scrollIntoView());
  await shot(page, 'settings-run-1280');

  // 3: ✕ on the notice; the Automate toggle
  await page.evaluate(() => { location.hash = '#/approvals'; });
  await page.waitForSelector('[data-local-done] [data-ndis]', { timeout: 10000 }).catch(() => {});
  await page.click('[data-local-done] [data-ndis]').catch(() => {});
  await sleep(800);
  const gone = !(await page.$('[data-local-done]'));
  const view = (await api('/api/brain/x/creation')).j;
  check(gone && !(view.notifications || []).some((n) => n.kind === 'local_done'), '✕ closes the notice (and it stays closed)');
  await page.evaluate(() => { location.hash = '#/settings?tab=accounts'; });
  await page.waitForSelector('button[data-auto]', { timeout: 10000 });
  const b0 = await page.$eval('button[data-auto]:not([disabled])', (b) => ({ on: b.getAttribute('aria-checked'), t: b.textContent.trim(), h: b.getBoundingClientRect().height }));
  await page.click('button[data-auto]:not([disabled])');
  await sleep(800);
  const b1 = await page.$eval('button[data-auto]:not([disabled])', (b) => ({ on: b.getAttribute('aria-checked'), t: b.textContent.trim() }));
  check(b0.on !== b1.on && /^(On|Off)$/.test(b0.t) && /^(On|Off)$/.test(b1.t) && b0.h >= 28, 'Settings → Accounts: Automate is an On/Off toggle that flips', `${b0.t} → ${b1.t}, ${Math.round(b0.h)} px tall`);
  await shot(page, 'accounts-toggle-1280');
  await page.click('button[data-auto]:not([disabled])'); await sleep(500);
  const g1 = await geo(page);
  check(g1.w <= g1.vw, 'no horizontal scroll at 1280', `${g1.w}/${g1.vw}`);
  await page.context().close();

  // 4: Arabic at 390 — a second request, its live card, the settings card
  page = await open('ar', 390, 844, '#/approvals');
  await sleep(800);
  const req2 = (await api('/api/exchange/test-request', 'POST', { product_ids: [prods[1] ? prods[1].id : prods[0].id], angle: 'hydration' })).j;
  await page.waitForSelector(`[data-req="${req2.id}"] [data-cc-working]`, { timeout: 15000 }).catch(() => {});
  const wAr = await page.$eval(`[data-req="${req2.id}"]`, (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
  const g2 = await geo(page);
  check(g2.dir === 'rtl' && /يصنعها Claude Code على هذا الجهاز/.test(wAr) && g2.w <= g2.vw, 'Arabic Approvals card, RTL, no horizontal scroll at 390', `${g2.w}/${g2.vw} · ${wAr.slice(0, 60)}`);
  await page.$eval(`[data-req="${req2.id}"]`, (e) => e.scrollIntoView({ block: 'center' })).catch(() => {});
  await shot(page, 'approvals-working-ar-390');
  for (let i = 0; i < 120; i++) { await sleep(500); const c = (await api('/api/brain/x/creation')).j; const r = (c.requests || []).find((x) => x.id === req2.id); if (r && r.status === 'done') break; }
  await page.evaluate(() => { location.hash = '#/settings?tab=connections'; });
  await page.waitForSelector('[data-ccode]', { timeout: 15000 });
  await sleep(300);
  t = await page.$eval('[data-ccode]', (e) => e.textContent.replace(/\s+/g, ' '));
  const g3 = await geo(page);
  check(/Claude Code على هذا الجهاز/.test(t) && /تلقائي: مفعّل/.test(t) && g3.w <= g3.vw, 'Arabic settings card, no horizontal scroll at 390', `${g3.w}/${g3.vw}`);
  await page.$eval('[data-ccode]', (e) => e.scrollIntoView());
  await shot(page, 'settings-ar-390');
  await browser.close();
  const runs2 = fs.readFileSync(runLog, 'utf8').trim().split('\n').map((l) => JSON.parse(l)).filter((r) => r.argv.includes('--mcp-config'));
  check(runs2.length === 2, 'one run per request (two requests, two runs)', `${runs2.length}`);
  check(!errors.length, 'no console errors', errors.slice(0, 3).join(' | '));
  check(!failed.length, 'no failed requests', failed.slice(0, 3).join(' | '));
  console.log(fails ? `${fails} FAILED` : 'ALL PASSED');
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
