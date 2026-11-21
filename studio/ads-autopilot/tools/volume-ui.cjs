#!/usr/bin/env node
// Volume lane UI check (21 Nov 2026: "create more campaigns / ads ... plan super well on daily basis and monitor
// closely") against the REAL engine -mock with the real brain, in Chromium, EN + AR at 1440 and 1280:
//   1  Overview: Today's plan (yesterday from the shop, today's jobs with times, ads to create) + Watching now (each
//      live ad: age, early signal, next check; pacing)
//   2  Plan: the Daily Plan on top of the job queue
//   3  Strategy → Rules: Activity level (Steady / Active / Aggressive, saved at once) + How much it runs (live ads vs
//      target, campaigns and why, next new ads); switching the level saves and the plan follows
//   no console errors, no failed requests. Screenshots: shots/volume-*.jpg
//   NODE_PATH=<repo>/node_modules PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tools/volume-ui.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const PORT = 44400 + Math.floor(Math.random() * 400);
const shots = process.argv.includes('--shots');
const results = [];
const check = (n, ok, msg) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-vol-'));
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
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'auto', countries: ['AE', 'SA'], max_active_ads: 14 });
  const c1 = await api('/api/cycle', 'POST', { mode: 'auto' }); // the first launch
  // to the next Monday 07:30 in Dubai (a batch day): the morning plan with today's batch and its creation requests
  const now0 = new Date((await api('/api/today')).j.now);
  const dub = new Date(now0.getTime() + 4 * 3600e3);
  let ahead = ((8 - dub.getUTCDay()) % 7 || 7) * 24 + (7.5 - dub.getUTCHours() - dub.getUTCMinutes() / 60);
  if (ahead < 24) ahead += 7 * 24;
  await api('/api/test/clock', 'POST', { advance_hours: Math.round(ahead * 60) / 60 });
  const c2 = await api('/api/cycle', 'POST', { mode: 'auto' }); // the cadence fills toward the target
  const chk = await api('/api/today/check', 'POST', {});
  const pl = await api('/api/today/plan', 'POST', {});
  const d = (pl.j || {}).daily_plan || {};
  const v = (pl.j || {}).volume || {};
  check('engine', c1.s === 200 && c2.s === 200 && chk.s === 200 && pl.s === 200 && d.date && (d.today || []).length >= 2,
    `daily plan ${d.date}: ${(d.today || []).length} lines today, ${(d.create || {}).needed} ads to create (${(d.create || {}).mode}); live ${v.live} / target ${v.live_min}–${v.live_max}; watching ${(chk.j.watch.items || []).length} ads, pacing ${chk.j.watch.pacing.state}`);

  const browser = await chromium.launch();
  const errors = [], failed = [];
  const S = {};
  for (const [w, h, lang] of [[1440, 900, 'en'], [1280, 800, 'en'], [1440, 900, 'ar'], [1280, 800, 'ar']]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, timezoneId: 'Asia/Dubai' });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') errors.push(`${w}${lang}: ${m.text()}`); });
    page.on('pageerror', (e) => errors.push(`${w}${lang}: ${e}`));
    page.on('response', (r) => { if (r.status() >= 400) failed.push(`${r.status()} ${r.url()}`); });
    await page.addInitScript((l) => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.lang', l); } catch {} }, lang);
    const shot = async (name, sel) => {
      if (!shots) return;
      fs.mkdirSync(SHOTS, { recursive: true });
      await page.evaluate(() => document.querySelectorAll('.toast').forEach((t) => t.remove()));
      const p = path.join(SHOTS, `volume-${name}-${lang}-${w}.jpg`);
      if (sel) { // a tall viewport for the moment, so the whole card is drawn (the app scrolls inside its own pane)
        const box = () => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.left, y: r.top, width: r.width, height: r.height }; }, sel);
        let b = await box();
        if (b) {
          await page.setViewportSize({ width: w, height: Math.max(h, Math.ceil(b.y + b.height + 24)) }); await sleep(250);
          b = await box();
          await page.screenshot({ path: p, type: 'jpeg', quality: 76, clip: { x: Math.max(0, b.x - 8), y: Math.max(0, b.y - 8), width: b.width + 16, height: b.height + 16 } });
          await page.setViewportSize({ width: w, height: h }); await sleep(150);
          return;
        }
      }
      await page.screenshot({ path: p, type: 'jpeg', quality: 72 });
    };
    const go = async (hash, sel) => { await page.goto(base + '/app/#/' + hash); await page.waitForFunction((s) => window.__kaa && window.__kaa.state && document.querySelector(s), sel, { timeout: 30000 }); await sleep(300); };

    // 1 Overview
    await go('overview', '[data-today] h2');
    await page.waitForSelector('[data-watch] header', { timeout: 15000 });
    const ov = await page.evaluate(() => ({
      plan: (document.querySelector('[data-today]') || {}).innerText || '', jobs: document.querySelectorAll('[data-today-jobs] > div').length,
      watch: (document.querySelector('[data-watch]') || {}).innerText || '', rows: document.querySelectorAll('[data-watch-rows] tbody tr').length,
      sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
    }));
    S[`ov-${lang}-${w}`] = ov;
    await shot('overview', '.grid.g2:has([data-today])');
    // 2 Plan
    await go('plan', '[data-today] h2');
    S[`plan-${lang}-${w}`] = await page.evaluate(() => document.querySelector('.page').firstElementChild.matches('[data-today]'));
    await shot('plan', '[data-today]');
    // 3 Strategy → Rules
    await go('strategy', '[data-strategy] .card');
    await page.click('[data-tab="rules"]'); await sleep(200);
    await page.waitForSelector('[data-volume]', { timeout: 10000 });
    if (w === 1440 && lang === 'en') {
      const jobs0 = ((await api('/api/volume')).j.upcoming || []).length;
      await page.click('[data-level="aggressive"]'); await sleep(700);
      const pb = (await api('/api/playbook')).j.playbook;
      const vol = (await api('/api/volume')).j.volume;
      const on = await page.$eval('[data-levels] .on', (b) => b.dataset.level);
      S.level = { saved: pb.knobs.activity_level, on, target: `${vol.live_min}–${vol.live_max}`, min: vol.level_min, capped: vol.limit_capped, jobs0, jobs: ((await api('/api/volume')).j.upcoming || []).length, days: vol.batch_days.join('+') };
      await shot('strategy-aggressive', '[data-volume]');
      await page.click('[data-level="active"]'); await sleep(700);
      S.back = (await api('/api/playbook')).j.playbook.knobs.activity_level;
    }
    S[`st-${lang}-${w}`] = await page.evaluate(() => ({ text: document.querySelector('[data-volume]').innerText, camps: document.querySelectorAll('[data-campaigns] > div').length }));
    await shot('strategy', '[data-volume]');
    await ctx.close();
  }
  await browser.close();
  const o = S['ov-en-1440'], oa = S['ov-ar-1440'];
  check('1 overview', o.jobs >= 2 && /Yesterday spend/.test(o.plan) && /Shop orders/.test(o.plan) && /Watching now/.test(o.watch) && o.rows >= 1 && /Spend pacing/.test(o.watch) && /خطة اليوم/.test(oa.plan) && /المراقبة الآن/.test(oa.watch),
    `daily plan with ${o.jobs} lines; Watching now ${o.rows} ads; AR headings present; scrollWidth ${o.sw} = ${o.cw}`);
  check('1b no overflow', Object.keys(S).filter((k) => k.startsWith('ov-')).every((k) => S[k].sw <= S[k].cw), Object.keys(S).filter((k) => k.startsWith('ov-')).map((k) => `${k} ${S[k].sw}/${S[k].cw}`).join(', '));
  check('2 plan', S['plan-en-1440'] && S['plan-ar-1280'], 'the Daily Plan leads the Plan screen (EN + AR)');
  const st = S['st-en-1440'], sa = S['st-ar-1440'];
  check('3 strategy', S.level && S.level.saved === 'aggressive' && S.level.jobs > S.level.jobs0 && S.level.on === 'aggressive' && S.level.min === 12 && S.level.capped && S.back === 'active' && /How much it runs/.test(st.text) && /needs budget|planned|live/.test(st.text) && st.camps >= 2 && /كم يشغّل/.test(sa.text),
    `level saved ${S.level && S.level.saved} (target ${S.level && S.level.target}, ${S.level && S.level.days}) then back to ${S.back}; upcoming new-ad jobs ${S.level && S.level.jobs0} → ${S.level && S.level.jobs} at once; ${st.camps} campaign rows with why`);
  check('4 clean', !errors.length && !failed.length, `console errors ${errors.length} ${errors.slice(0, 3).join(' | ')}; failed requests ${failed.length} ${failed.slice(0, 3).join(' | ')}`);
  eng.kill();
  fs.rmSync(tmp, { recursive: true, force: true });
  const bad = results.filter((x) => !x).length;
  console.log(`\n${bad ? 'FAIL' : 'PASS'}: ${results.length - bad}/${results.length}`);
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
