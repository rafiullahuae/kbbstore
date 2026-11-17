#!/usr/bin/env node
// KBB Studio — Themes 2 renders: every Countries / Travel & study / Work abroad theme as a 432×768 mp4 (108 frames at
// 30 fps, bold intensity, over the drawn sample presenter) + a jpg poster at frame 60 → tools/out/themes2/, and the
// frame time of every theme at 1080×1920 (the package's busiest frames) → tools/out/themes2/frame-times.json.
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/th2-render.cjs   (ONLY=country-gb,travel  NOVIDEO=1  ALL=1)
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');
const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'themes2'), STUDIO = path.join(ROOT, 'tools', 'out', 'th2-studio');
fs.mkdirSync(OUT, { recursive: true });
(async () => {
  for (const d of ['app', 'vendor', 'brands', 'library']) { fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true }); fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true }); }
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true }); fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const b = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined });
  const p = await b.newPage();
  const errs = []; p.on('pageerror', (x) => errs.push(String(x))); p.on('console', (m) => { if (m.type() === 'error' || /\[(themes|render)\]/.test(m.text())) errs.push(m.text()); });
  await p.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await p.goto(e.url + '/app/'); await p.waitForTimeout(2500);
  const only = (process.env.ONLY || '').split(',').filter(Boolean);
  // transitions between two sample clips, and the bulletin templates, each to its own mp4 (+ mid-switch / mid-hold stills)
  const extra = process.env.EXTRA === '0' ? [] : [
    ...[['flag', 'country-ca'], ['globe', 'country-au'], ['plane', 'travel'], ['page', 'passport'], ['map', 'country-it'], ['slabs', 'country-de'], ['landmark', 'country-fr'], ['ticket', 'country-jp']].map(([k, th]) => ({ name: 'transition-' + k, make: `Hv.transSpec('${th}', '${k}', {})`, frames: 78, still: 36 })),
    ...[['bulletin', 'country-gb'], ['breaking', 'country-de'], ['split', 'country-au'], ['lowerbar', 'work-abroad'], ['visa', 'country-ca']].map(([el, th]) => ({ name: 'bulletin-' + el, make: `Hv.elementSpec('${th}', '${el}', {})`, frames: 108, still: el === 'visa' ? 96 : 60 })),
  ];
  for (const x of extra) {
    const frames = await p.evaluate(async ({ make, n }) => {
      const Hv = await import('/app/themes/hover.js');
      const spec = eval(make); await spec.prepare();
      const cv = document.createElement('canvas'); cv.width = 432; cv.height = 768; const g = cv.getContext('2d');
      const out = []; for (let f = 0; f < n; f++) { spec.draw(g, 432, 768, (f / 30) % spec.dur); out.push(cv.toDataURL('image/jpeg', 0.9)); }
      return out;
    }, { make: x.make, n: x.frames });
    const d = path.join(OUT, 'f'); fs.rmSync(d, { recursive: true, force: true }); fs.mkdirSync(d, { recursive: true });
    frames.forEach((u, i) => fs.writeFileSync(path.join(d, String(i).padStart(3, '0') + '.jpg'), Buffer.from(u.split(',')[1], 'base64')));
    execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-framerate', '30', '-i', path.join(d, '%03d.jpg'), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '24', '-preset', 'slow', '-movflags', '+faststart', path.join(OUT, x.name + '.mp4')]);
    fs.copyFileSync(path.join(d, String(x.still).padStart(3, '0') + '.jpg'), path.join(OUT, x.name + '.jpg'));
    for (const k of [24, 30, 42, 48]) if (k < x.frames) fs.copyFileSync(path.join(d, String(k).padStart(3, '0') + '.jpg'), path.join(OUT, `${x.name}-f${k}.jpg`));
    console.log(x.name);
  }
  if (process.env.EXTRA_ONLY) { await b.close(); await H.stopEngine(e); return; }
  const list = await p.evaluate(async ({ only, all }) => { const T = await import('/app/themes/themes.js'); return T.THEMES.filter((t) => (only.length ? only.includes(t.id) : all || ['Countries', 'Travel & study', 'Work abroad'].includes(t.group))).map((t) => ({ id: t.id, name: t.name, group: t.group, country: t.country || null })); }, { only, all: !!process.env.ALL });
  console.log(list.length, 'themes');
  const times = {};
  for (const th of list) {
    // frame time at 1080×1920: every 3rd frame of the 3.6 s loop, the whole package
    const ft = await p.evaluate(async (id) => {
      const Hv = await import('/app/themes/hover.js');
      const spec = Hv.themeSpec(id, { opts: { intensity: 'bold' } }); await spec.prepare();
      const cv = document.createElement('canvas'); cv.width = 1080; cv.height = 1920; const g = cv.getContext('2d');
      for (let f = 0; f < 108; f += 3) { spec.draw(g, 1080, 1920, f / 30); g.getImageData(0, 0, 1, 1); }   // warm the caches (and settle the canvas on the CPU, where the timing is honest)
      const out = [];
      for (let f = 0; f < 108; f += 3) { const a = performance.now(); spec.draw(g, 1080, 1920, f / 30); g.getImageData(0, 0, 1, 1); out.push([f, +(performance.now() - a).toFixed(2)]); }
      return out;
    }, th.id);
    const ms = ft.map((x) => x[1]).sort((a, b2) => a - b2);
    times[th.id] = { median: ms[Math.floor(ms.length / 2)], p95: ms[Math.floor(ms.length * 0.95)], worst: ms[ms.length - 1], worstFrame: ft.find((x) => x[1] === ms[ms.length - 1])[0] };
    if (process.env.NOVIDEO) { console.log(th.id, JSON.stringify(times[th.id]), process.env.FRAMES ? JSON.stringify(ft) : ''); continue; }
    const frames = await p.evaluate(async (id) => {
      const Hv = await import('/app/themes/hover.js');
      const spec = Hv.themeSpec(id, { opts: { intensity: 'bold' } }); await spec.prepare();
      const cv = document.createElement('canvas'); cv.width = 432; cv.height = 768; const g = cv.getContext('2d');
      const out = []; for (let f = 0; f < 108; f++) { spec.draw(g, 432, 768, f / 30); out.push(cv.toDataURL('image/jpeg', 0.9)); }
      return out;
    }, th.id);
    const d = path.join(OUT, 'f'); fs.rmSync(d, { recursive: true, force: true }); fs.mkdirSync(d, { recursive: true });
    frames.forEach((u, i) => fs.writeFileSync(path.join(d, String(i).padStart(3, '0') + '.jpg'), Buffer.from(u.split(',')[1], 'base64')));
    execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-framerate', '30', '-i', path.join(d, '%03d.jpg'), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '24', '-preset', 'slow', '-movflags', '+faststart', path.join(OUT, th.id + '.mp4')]);
    fs.copyFileSync(path.join(d, '060.jpg'), path.join(OUT, th.id + '.jpg'));
    for (const k of [10, 40, 90]) fs.copyFileSync(path.join(d, String(k).padStart(3, '0') + '.jpg'), path.join(OUT, `${th.id}-f${k}.jpg`));
    console.log(th.id, JSON.stringify(times[th.id]));
  }
  fs.rmSync(path.join(OUT, 'f'), { recursive: true, force: true });
  const worst = Object.entries(times).sort((a, c) => c[1].worst - a[1].worst);
  fs.writeFileSync(path.join(OUT, 'frame-times.json'), JSON.stringify({ worst: worst[0], times }, null, 1));
  if (!only.length) fs.writeFileSync(path.join(OUT, 'list.json'), JSON.stringify(list));
  console.log('worst', JSON.stringify(worst.slice(0, 5)));
  console.log('errors', errs.length, errs.slice(0, 5));
  await b.close(); await H.stopEngine(e);
})().catch((x) => { console.error(x); process.exit(2); });
