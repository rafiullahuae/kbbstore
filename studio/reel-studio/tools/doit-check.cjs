#!/usr/bin/env node
// KBB Studio — the "Do it for me" bar is ONE row that scrolls sideways with ‹ › arrows (the owner, 24 Nov 2026: "I
// don't want two rows for the top options, i need slider left right arrows, i want to give the maximum space to the
// editor frame"). Headless Chromium against the Linux engine, at 1280×800 and 1920×1080:
//   one row     every button on the same line; the bar's height
//   arrows      ‹ disabled at the start, › moves the row, the last button (🏢 Office tour) reachable, then › disabled
//   wheel       a mouse wheel over the bar scrolls it sideways
//   space       the preview (monitor) height, printed so a run before the change can be compared
//   hygiene     no console errors; screenshots → shots/doit/
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/doit-check.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');
const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'doit'), SHOTS = path.join(ROOT, 'shots', 'doit');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) failures++; };

(async () => {
  const clip = path.join(OUT, 'clip.mp4');
  if (!fs.existsSync(clip)) execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=720x1280:rate=30:duration=3', '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', clip]);
  const dir = H.makeStudio(), e = await H.startEngine(dir);
  const browser = await H.launchChrome();
  const errors = [];
  for (const [W, Hh] of [[1280, 800], [1920, 1080]]) {
    const page = await browser.newPage({ viewport: { width: W, height: Hh } });
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (x) => errors.push(String(x)));
    await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
    await page.goto(e.url + '/app/'); await page.waitForSelector('.home');
    await page.setInputFiles('.home-drop input[type=file]', clip);
    await page.waitForSelector('.doit-btn[data-mod="office-tour"]', { timeout: 60000 });
    await page.waitForTimeout(800);
    const m = await page.evaluate(() => {
      const row = document.querySelector('.doit-row'), d = document.querySelector('.doit'), btns = [...d.querySelectorAll('.doit-btn')];
      const tops = new Set(btns.map((b) => Math.round(b.getBoundingClientRect().top)));
      const mon = document.querySelector('.monitor');
      return { rowH: Math.round(row.getBoundingClientRect().height), lines: tops.size, n: btns.length, monH: Math.round(mon.getBoundingClientRect().height),
        L: document.querySelector('.doit-arrow[data-dir="left"]'), scrollable: d.scrollWidth > d.clientWidth, sw: document.documentElement.scrollWidth };
    });
    const st = async () => page.evaluate(() => { const d = document.querySelector('.doit'); return { x: Math.round(d.scrollLeft), max: d.scrollWidth - d.clientWidth, l: document.querySelector('.doit-arrow[data-dir="left"]').disabled, r: document.querySelector('.doit-arrow[data-dir="right"]').disabled, rh: document.querySelector('.doit-arrow[data-dir="right"]').hidden }; });
    const s0 = await st();
    ok(m.lines === 1 && m.rowH <= 48 && m.sw === W, `${W}×${Hh}: ${m.n} buttons on ONE row (${m.lines} line), bar ${m.rowH} px high, page width ${m.sw}; preview ${m.monH} px high`);
    await page.screenshot({ path: path.join(SHOTS, `doit-start-${W}.png`) });
    if (m.scrollable) {
      ok(s0.l && !s0.r && !s0.rh, `${W}: at the start ‹ is disabled and › is on (row ${s0.max} px wider than the bar) ${JSON.stringify(s0)}`);
      for (let i = 0; i < 12 && !(await st()).r; i++) { await page.click('.doit-arrow[data-dir="right"]'); await page.waitForTimeout(450); }
      const s1 = await st();
      const vis = await page.evaluate(() => { const b = document.querySelector('.doit-btn[data-mod="office-tour"]').getBoundingClientRect(), d = document.querySelector('.doit').getBoundingClientRect(); return b.left >= d.left - 1 && b.right <= d.right + 1; });
      ok(s1.r && !s1.l && s1.x >= s1.max - 2 && vis, `${W}: › scrolls to the end (${s1.x}/${Math.round(s1.max)} px), 🏢 Office tour in view, › now disabled`);
      await page.screenshot({ path: path.join(SHOTS, `doit-end-${W}.png`) });
      await page.click('.doit-btn[data-mod="office-tour"]');
      const tab = await page.waitForSelector('[data-x="tour"]', { timeout: 10000 }).then(() => true).catch(() => false);
      ok(tab, `${W}: 🏢 Office tour opens from the scrolled row`);
      await page.hover('.doit'); await page.mouse.wheel(0, -2000); await page.waitForTimeout(600);
      const s2 = await st();
      ok(s2.x <= 4 && s2.l, `${W}: the mouse wheel over the bar scrolls it back to the start`);
    } else ok(s0.rh, `${W}: every button fits, so the arrows are hidden`);
    await page.close();
  }
  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}`);
  await browser.close(); await H.stopEngine(e);
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS'); process.exit(failures ? 1 : 0);
})().catch((x) => { console.error(x); process.exit(1); });
