#!/usr/bin/env node
// Media-buying lane UI check (20 Nov 2026) against the REAL engine -mock with the real brain, in Chromium, EN + AR:
//   1  Strategy: the targets from the shop, the funnel split, the rules (editable, with defaults), the ranked products
//      with the why, Our playbook; editing "Prefer new & trending" and Avoid on a product saves (version +1) and the
//      avoided product leaves the ranking
//   2  Plan: the job queue (first launch, tests, funnel pieces); Edit a test (products, format, note) saves; Show the
//      brief carries the owner's instructions; Skip skips
//   3  Campaigns: the funnel with every setting and its why; Audiences: the account's audiences with Meta's sizes
//   4  Approvals: the Occasions packages (ads prepared in AR + EN) wait for approval; a fresh account says why
//      nothing waits, with the fix
//   5  What it learned: the learning panel; no console errors, no failed requests
//   NODE_PATH=<repo>/node_modules PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tools/media-ui.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const PORT = 44000 + Math.floor(Math.random() * 400);
const shots = process.argv.includes('--shots');
const results = [];
const check = (n, ok, msg) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-mui-'));
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
  const api = async (p, method = 'GET', body, acct) => { const h = { 'X-KAA': info.token, 'content-type': 'application/json' }; if (acct) h['X-KAA-Account'] = acct; const r = await fetch(base + p, { method, headers: h, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 40; i++) { const c = (await api('/api/connections')).j; if (c && c.meta.ok && c.claude.ok && c.shop.ok) break; await sleep(250); }
  await api('/api/brain/research', 'POST', {});
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'] });
  const c1 = await api('/api/cycle', 'POST', { mode: 'shadow' }); // Practice: what the first launch WOULD be + the plan + occasions
  const jobs1 = (await api('/api/jobs')).j;
  check('engine', c1.s === 200 && jobs1.jobs.length >= 3, `practice cycle ${c1.s}; ${jobs1.jobs.length} jobs: ${jobs1.jobs.map((j) => j.kind).join(', ')}`);
  // Auto for the funnel (Campaigns / Audiences show it live); the occasion packages stay waiting
  await api('/api/limits', 'PUT', { autopilot: 'auto' });
  const c2 = await api('/api/cycle', 'POST', { mode: 'auto' });
  await api('/api/sync', 'POST', {});
  const occ = (await api('/api/occasions')).j;
  const pk = (occ.packages || []).filter((j) => j.status === 'planned');
  const liveOcc = (await api('/api/state')).j.campaigns.some((c) => c.adsets.some((s) => s.ads.some((a) => /Occasion/.test(a.name))));
  check('occasions wait', c2.s === 200 && pk.length >= 1 && !liveOcc, `${pk.length} occasion package(s) waiting (${pk.map((j) => j.occasion && j.occasion.name).join(', ')}); live occasion ads in Auto: ${liveOcc}`);
  await api('/api/limits', 'PUT', { autopilot: 'shadow' }); // the screens below in Practice (Approvals lists what waits)

  const browser = await chromium.launch();
  const errors = [], failed = [];
  const S = {};
  for (const [w, h, lang] of [[1440, 900, 'en'], [1280, 800, 'en'], [1440, 900, 'ar'], [1280, 800, 'ar']]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') errors.push(`${w}${lang}: ${m.text()}`); });
    page.on('pageerror', (e) => errors.push(`${w}${lang}: ${e}`));
    page.on('response', (r) => { if (r.status() >= 400 && !/\/(approve|replan)$/.test(r.url())) failed.push(`${r.status()} ${r.url()}`); });
    await page.addInitScript((l) => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.lang', l); } catch {} }, lang);
    const shot = async (name) => { if (shots) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: path.join(SHOTS, `${name}-${lang}-${w}.jpg`), type: 'jpeg', quality: 72 }); } };
    const go = async (hash, sel) => { await page.goto(base + '/app/#/' + hash); await page.waitForFunction((s) => window.__kaa && window.__kaa.state && document.querySelector(s), sel, { timeout: 30000 }); await sleep(250); };
    const tab = async (id) => { await page.click(`[data-tab="${id}"]`); await sleep(150); };

    // 1 Strategy
    await go('strategy', '[data-strategy] .card');
    if (w === 1440 && lang === 'en') S.how = await page.evaluate(() => ({ rows: document.querySelectorAll('[data-strategy] table tr').length, text: document.querySelector('#view').innerText }));
    await shot('strategy-how');
    await tab('products'); await shot('strategy-products');
    if (w === 1440 && lang === 'en') {
      S.ranked = await page.$$eval('[data-avoid]', (b) => b.map((x) => x.dataset.avoid));
      S.whys = await page.$$eval('[data-strategy] tbody tr td.small', (t) => t.filter((x) => x.innerText.trim()).length);
      const victim = S.ranked[0];
      await page.click(`[data-avoid="${victim}"]`); await sleep(100);
      await tab('playbook');
      await page.$eval('[data-f="freshness_pct"]', (r) => { r.value = '85'; r.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.click('[data-save]'); await sleep(900);
      const pb = (await api('/api/playbook')).j;
      await tab('products');
      const after = await page.$$eval('[data-avoid]', (b) => b.map((x) => x.dataset.avoid));
      S.save = { version: pb.playbook.version, fresh: pb.playbook.freshness_pct, avoided: pb.playbook.avoid.map((a) => a.value), victim, gone: !after.includes(victim) };
      await tab('playbook');
    } else await tab('playbook');
    await shot('strategy-playbook');
    await tab('rules'); await shot('strategy-rules');
    await tab('occasions'); await shot('strategy-occasions');
    await tab('agency'); await shot('strategy-agency');
    if (w === 1440 && lang === 'en') S.agency = await page.$$eval('[data-strategy] .list > div', (d) => d.length);

    // 2 Plan: the job queue
    await go('plan', '[data-jobs] [data-job]');
    if (w === 1440 && lang === 'en') S.jobs = await page.$$eval('[data-job]', (d) => d.length);
    await shot('plan-jobs');
    if (w === 1440 && lang === 'en') {
      const tid = await page.$$eval('[data-job]', (d) => (d.find((x) => /test/i.test(x.innerText) && x.querySelector('[data-jedit]')) || d[0]).dataset.job);
      await page.click(`[data-jedit="${tid}"]`); await page.waitForSelector('[data-editor]');
      await page.$eval('[data-e="product_ids"]', (s) => { for (const o of s.options) o.selected = false; s.options[0].selected = true; if (s.options[1]) s.options[1].selected = true; });
      await page.selectOption('[data-e="format"]', 'carousel');
      await page.fill('[data-e="note"]', 'Lead with the hydrating texture, Arabic hook first.');
      await shot('plan-edit');
      await page.click(`[data-esave="${tid}"]`); await sleep(700);
      const j = (await api('/api/jobs')).j.jobs.find((x) => x.id === tid);
      await page.click(`[data-brief="${tid}"]`); await page.waitForFunction(() => /OWNER'S INSTRUCTIONS/.test(document.querySelector('#view').innerText), null, { timeout: 15000 });
      await shot('plan-brief');
      const other = (await api('/api/jobs')).j.jobs.find((x) => x.id !== tid && x.status === 'planned' && x.kind !== 'build_funnel' && x.kind !== 'occasion');
      let skipped = null;
      if (other) { await page.click(`[data-jact="skip"][data-id="${other.id}"]`); await sleep(600); skipped = (await api('/api/jobs')).j.jobs.find((x) => x.id === other.id).status; }
      S.edit = { tid, edited: j.edited, format: j.format, products: j.product_ids.length, note: j.note, skipped };
    }

    // 3 Campaigns + Audiences
    await go('campaigns', '[data-funnel] table');
    if (w === 1440 && lang === 'en') S.funnel = await page.evaluate(() => ({ pieces: document.querySelectorAll('[data-funnel] .grid > .card').length, settings: document.querySelectorAll('[data-funnel] .grid > .card tr').length, text: document.querySelector('[data-funnel]').innerText }));
    await shot('funnel-campaigns');
    await go('audiences', '[data-auds]');
    if (w === 1440 && lang === 'en') S.auds = await page.evaluate(() => ({ rows: document.querySelectorAll('[data-auds] tbody tr').length, text: document.querySelector('[data-auds]').innerText, chat: /WhatsApp/i.test(document.querySelector('#view').innerText) }));
    await shot('funnel-audiences');

    // 4 Approvals → Occasions
    await go('approvals', '[data-occasions]');
    await page.waitForFunction(() => [...document.querySelectorAll('[data-occasions] img')].every((i) => i.complete && i.src.startsWith('blob:')), null, { timeout: 15000 }).catch(() => {});
    await page.$eval('[data-occasions]', (e) => e.scrollIntoView()); await sleep(200);
    if (w === 1440 && lang === 'en') S.occ = await page.evaluate(() => ({ pkgs: document.querySelectorAll('[data-pkg]').length, ads: document.querySelectorAll('[data-pkg] img').length, approve: !!document.querySelector('[data-occok]') }));
    await shot('approvals-occasions');

    // 5 What it learned
    await go('learned', '#view .page');
    await page.waitForSelector('[data-learning]', { timeout: 15000 }).catch(() => {});
    if (w === 1440 && lang === 'en') S.learn = await page.evaluate(() => !!document.querySelector('[data-learning]'));
    await shot('learned');
    if (w === 1440 && lang === 'en') { // a fresh account: nothing linked, no limits → Approvals says why, with the fix
      await api('/api/accounts/select', 'POST', { account: 'act_1000000003' });
      await page.evaluate(() => localStorage.setItem('kaa.account', 'act_1000000003'));
      await page.goto(base + '/app/#/approvals'); await page.reload(); // a new page load picks the account up
      await page.waitForFunction(() => window.__kaa && window.__kaa.state && document.querySelector('[data-ready]'), null, { timeout: 30000 });
      S.ready = await page.evaluate(() => ({ text: document.querySelector('[data-ready]').innerText, autolink: !!document.querySelector('[data-ready] [data-autolink]') }));
      await shot('approvals-readiness');
      await api('/api/accounts/select', 'POST', { account: 'act_1000000001' });
      await page.evaluate(() => localStorage.setItem('kaa.account', 'act_1000000001'));
    }
    await ctx.close();
  }
  await browser.close();
  check('1 strategy', S.how && /Break-even/.test(S.how.text) && /Target cost per order/.test(S.how.text) && /Prospecting/.test(S.how.text) && S.ranked.length >= 4 && S.whys >= 4 && S.agency >= 10,
    `targets + funnel shown; ${S.ranked.length} ranked products with the why on ${S.whys}; ${S.agency} playbook rules`);
  check('1 strategy save', S.save && S.save.version >= 1 && S.save.fresh === 85 && S.save.avoided.includes(S.save.victim) && S.save.gone,
    `saved v${S.save && S.save.version}, freshness ${S.save && S.save.fresh}%, avoided ${S.save && S.save.victim} → gone from the ranking: ${S.save && S.save.gone}`);
  check('2 plan', S.jobs >= 3 && S.edit && S.edit.edited && S.edit.format === 'carousel' && S.edit.products >= 1 && /hydrating/.test(S.edit.note) && (S.edit.skipped === null || S.edit.skipped === 'skipped'),
    `${S.jobs} jobs; edit ${JSON.stringify(S.edit)}; brief shows the owner's instructions`);
  check('3 funnel', S.funnel && S.funnel.pieces >= 4 && S.funnel.settings >= 20 && /attribution|7-day click/i.test(S.funnel.text) && S.auds.rows >= 2 && /KAA: Buyers/.test(S.auds.text) && !S.auds.chat,
    `${S.funnel && S.funnel.pieces} funnel pieces, ${S.funnel && S.funnel.settings} settings with their why; ${S.auds && S.auds.rows} audiences with sizes; WhatsApp shown: ${S.auds && S.auds.chat}`);
  check('4 approvals', S.occ && S.occ.pkgs >= 1 && S.occ.ads >= 2 && S.occ.approve && S.ready && S.ready.autolink && /Nothing to approve because/.test(S.ready.text) && /limits/.test(S.ready.text),
    `${S.occ && S.occ.pkgs} occasion package(s), ${S.occ && S.occ.ads} prepared ads, Approve all; fresh account: "${S.ready && S.ready.text.split('\n').slice(0, 3).join(' | ')}"`);
  check('5 learned + clean', S.learn && !errors.length && !failed.length, `learning panel ${S.learn}; console errors ${errors.length} ${errors.slice(0, 3).join(' | ')}; failed ${failed.length} ${failed.slice(0, 3).join(' | ')}`);
  await api('/api/stop', 'POST', {}).catch(() => {});
  eng.kill();
  const ok = results.every(Boolean);
  console.log(`\n${ok ? 'PASS' : 'FAIL'}: ${results.filter(Boolean).length}/${results.length}`);
  if (!ok) console.log(log.slice(-3000));
  process.exit(ok ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(1); });
