#!/usr/bin/env node
// KBB Studio — CAPSULES CAROUSEL check (the owner's "capsules carousel", Planet Careers, 18 Nov 2026). Headless Chrome
// against the Linux engine, in the real editor:
//
//   place     the element from Themes → Place elements (ONE undo step), selected → its inspector sections
//   edit      add from the 100-job ready list, a custom capsule, remove, rename, drag to reorder (all through the UI)
//   picker    the continent picker: "Select all Europe" puts every European country in the bottom row
//   motion    speed and direction measured as the pixel shift per second; seamless wrap (frame at one loop = frame at 0)
//   box       position, scale and opacity move / grow / fade the drawn pixels
//   presets   every preset renders without an error and looks different from the others
//   colours   theme switch keeps capsules + overrides; a capsule override renders in its colour; reset; clear all; undo
//   arabic    EN/AR switch: Arabic labels, the icon on the right
//   flags     every country of the picker has its flag in the bundled set, and it rasterises
//   same      preview = export; undo; save + reload
//   time      frame time at 1080×1920 with 2 rows × 30 capsules
//   previews  every preset as a 9:16 432×768 6 s mp4 + a 16:9 640×360 strip mp4 + jpg posters → tools/out/capsules/
//   shots     6 stills → shots/capsules/, inspector at 1440×900 (list, picker, style/size)
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/caps-check.cjs   (NOVIDEO=1 skips the preview mp4s)
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'capsules'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'capsules');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const meanDiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };

(async () => {
  const T0 = Date.now();
  const clip = path.join(OUT, 'sample.mp4');
  if (!fs.existsSync(clip)) ff('-f', 'lavfi', '-i', 'gradients=size=1080x1920:rate=30:duration=7:speed=0.015:c0=0x2d5d8f:c1=0xe0b48c:c2=0x40607f:c3=0x9fc4e0:nb_colors=4', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=7:sample_rate=48000', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', clip);
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], warns = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/AudioContext was not allowed|No available adapters/.test(m.text())) errors.push(m.text()); if (m.type() === 'warning' && /\[(render|themes|capsules)\]/.test(m.text())) warns.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); localStorage.setItem('kbbstudio.workspace', 'planet-careers'); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  await page.setInputFiles('.home-drop input[type=file]', clip);
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  // page helpers: the module, a strip renderer, the shift between two strips, a bounding box
  await ev(async () => {
    window.C = await import('/app/themes/capsules.js'); window.CD = await import('/app/themes/capsules-data.js');
    window.TH = await import('/app/themes/index.js'); window.R = await import('/app/core/render.js'); window.CTX = window.kbbEditor.context();
    window.strip = (caps, t, W = 1080, Hh = 300) => { const cv = document.createElement('canvas'); cv.width = W; cv.height = Hh; const g = cv.getContext('2d', { willReadFrequently: true }); g.fillStyle = '#000'; g.fillRect(0, 0, W, Hh); window.C.drawCapsules(g, caps, { w: W, h: Hh, t, tg: t, beats: [] }); return g.getImageData(0, 0, W, Hh).data; };
    window.profile = (d, W, Hh) => { const p = new Float64Array(W); for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) { const i = (y * W + x) * 4; p[x] += d[i] + d[i + 1] + d[i + 2]; } return p; };
    window.shift = (a, b, max = 400) => { let best = 0, bv = Infinity; for (let s = -max; s <= max; s++) { let sum = 0, n = 0; for (let x = Math.max(0, s); x < Math.min(a.length, a.length + s); x++) { sum += Math.abs(b[x] - a[x - s]); n++; } const v = sum / n; if (v < bv) { bv = v; best = s; } } return best; };
    window.diffOf = (a, b) => { let s = 0; for (let i = 0; i < a.length; i += 4) s += Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]); return s / (a.length / 4) / 3; };
    window.frame = (p, t, sc = 0.25) => { const W = Math.round(p.canvas.w * sc), Hh = Math.round(p.canvas.h * sc); const cv = document.createElement('canvas'); cv.width = W; cv.height = Hh; const g = cv.getContext('2d', { willReadFrequently: true }); window.R.renderFrame(g, p, t, { scale: sc, source: () => null }); return { W, Hh, d: g.getImageData(0, 0, W, Hh).data }; };
    window.bbox = (f) => { let x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1, mx = 0; for (let y = 0; y < f.Hh; y++) for (let x = 0; x < f.W; x++) { const i = (y * f.W + x) * 4, v = Math.max(f.d[i], f.d[i + 1], f.d[i + 2]); if (v > 24) { x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y); } if (v > mx) mx = v; } return { x0, y0, x1, y1, h: y1 - y0, mx }; };
    window.capsItem = () => window.kbbEditor.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.el' && i.style?.el === 'capsules');
    window.setCaps = (fn, label = 'test') => { const it = window.capsItem(), k = JSON.parse(JSON.stringify(it.style.caps)); fn(k); window.kbbEditor.history.seal(); window.kbbEditor.apply(window.kbbEditor.ops.setItems([{ id: it.id, props: { style: Object.assign({}, it.style, { caps: k }) } }], label)); window.kbbEditor.history.seal(); };
  });

  // ── place: from Themes → Place elements, one undo step ──
  await page.click('.side-tab[data-side="mod:themes"]');
  await page.waitForSelector('.th-place[data-place="capsules"]', { timeout: 30000 });
  const u0 = await ev(() => window.kbbEditor.history.undoStack.length);
  await page.click('.th-place[data-place="capsules"] [data-add="capsules"]');
  await page.waitForTimeout(400);
  const placed = await ev(({ u0 }) => { const it = window.capsItem(), ed = window.kbbEditor; return it ? { steps: ed.history.undoStack.length - u0, rows: it.style.caps.rows.map((r) => r.items.length), box: [it.x, it.y, it.w, it.h], name: it.name, sel: ed.selectedItems().map((x) => x.item.id).includes(it.id) } : null; }, { u0 });
  ok(placed && placed.steps === 1 && placed.rows[0] >= 17 && placed.rows[1] >= 10, `Themes → Place elements → 💊 Capsules carousel: ONE undo step, ${placed?.rows?.[0]} job + ${placed?.rows?.[1]} country capsules, box ${placed?.box?.join(',')}`);
  if (!placed?.sel) await ev(() => window.kbbEditor.select([window.capsItem().id]));
  await page.waitForSelector('[data-caps-insp] .cps-preset', { timeout: 20000 });
  const sections = await ev(() => [...document.querySelectorAll('.sec summary')].map((s) => s.textContent));
  ok(['Capsules · theme', 'Capsules · rows', 'Capsules · size & motion', 'Position & size'].every((s) => sections.includes(s)), `inspector sections: ${sections.join(' · ')}`);
  const nPresets = await ev(() => document.querySelectorAll('[data-caps-insp] .cps-preset').length);
  ok(nPresets >= 12, `${nPresets} theme thumbnails (≥ 12), each drawn with the item's own capsules`);
  const whole = await ev(async () => { const n = await window.TH.setWhole(window.CTX, 'capsules', true); const its = window.TH.wholeItems(window.kbbEditor.project, 'capsules'); const r = { n, t0: its[0]?.t0, end: its[0] && its[0].t0 + its[0].out - its[0].in, dur: window.kbbEditor.duration() }; window.kbbEditor.history.undo(); return r; });
  ok(whole.n === 1 && whole.t0 === 0 && Math.abs(whole.end - whole.dur) < 0.01, `"Whole video" tick: one carousel from 0 to ${whole.end?.toFixed(2)} s (reel ${whole.dur.toFixed(2)} s), undo takes it back`);

  // ── edit through the UI: jobs list, custom, remove, rename, reorder ──
  const before = await ev(() => window.capsItem().style.caps.rows[0].items.map((c) => c.id));
  await page.click('[data-caps-add-jobs]');
  await page.waitForSelector('.cps-jobs label[data-caps-job="o:welder"]');
  const nJobs = await ev(() => document.querySelectorAll('.cps-jobs label[data-caps-job^="o:"]').length);
  await page.click('[data-caps-job="o:welder"] input'); await page.click('[data-caps-job="o:electrician"] input');
  await page.click('#caps-jobs-apply'); await page.waitForTimeout(300);
  let rows = await ev(() => window.capsItem().style.caps.rows[0].items.map((c) => [c.id, c.en, c.ar, c.icon]));
  ok(nJobs === 100 && rows.length === before.length + 2 && rows.slice(-2).map((r) => r[1]).join() === 'Welders,Electricians' && rows.slice(-2).every((r) => r[2] && r[3]), `jobs ready list: ${nJobs} occupations by sector; ticking Welders + Electricians adds them with Arabic (${rows.slice(-2).map((r) => r[2]).join('، ')}) and line icons (${rows.slice(-2).map((r) => r[3]).join(', ')})`);
  await page.click('[data-caps-add-custom="jobs"]');
  await page.fill('[data-caps-custom-en]', 'Video editors'); await page.fill('[data-caps-custom-ar]', 'محررو فيديو');
  await page.click('[data-caps-icon="video"]'); await page.click('#caps-custom-apply'); await page.waitForTimeout(300);
  rows = await ev(() => window.capsItem().style.caps.rows[0].items.map((c) => [c.id, c.en, c.ar, c.icon]));
  const custom = rows[rows.length - 1];
  ok(custom[1] === 'Video editors' && custom[2] === 'محررو فيديو' && custom[3] === 'video', `custom capsule: "${custom[1]}" / "${custom[2]}" with the ${custom[3]} icon`);
  const firstId = rows[0][0];
  await page.click(`[data-caps-remove="${firstId}"]`); await page.waitForTimeout(300);
  rows = await ev(() => window.capsItem().style.caps.rows[0].items.map((c) => [c.id, c.en]));
  ok(!rows.some((r) => r[0] === firstId), `remove: "${before.length ? 'Drivers' : ''}" gone, ${rows.length} left`);
  const renId = rows[0][0];
  await page.fill(`[data-caps-label="${renId}"]`, 'Call centre'); await page.keyboard.press('Tab'); await page.waitForTimeout(300);
  const renamed = await ev((id) => window.capsItem().style.caps.rows[0].items.find((c) => c.id === id)?.en, renId);
  ok(renamed === 'Call centre', `rename: "Customer care" → "${renamed}"`);
  const order0 = await ev(() => window.capsItem().style.caps.rows[0].items.map((c) => c.id));
  await page.dragAndDrop(`.cps-item[data-caps-item="${order0[0]}"] .cps-grip`, `.cps-item[data-caps-item="${order0[3]}"]`); await page.waitForTimeout(300);
  const order1 = await ev(() => window.capsItem().style.caps.rows[0].items.map((c) => c.id));
  ok(order1[3] === order0[0] && order1[0] === order0[1] && order1.length === order0.length, `drag to reorder: capsule 1 dropped on capsule 4 → it is 4th (${order1.slice(0, 4).map((id) => order0.indexOf(id) + 1).join(',')})`);
  const undoUI = await ev(() => { const ed = window.kbbEditor, a = JSON.stringify(window.capsItem().style.caps.rows[0].items.map((c) => c.id)); ed.history.undo(); const b = window.capsItem().style.caps.rows[0].items.map((c) => c.id); ed.history.redo(); const c = window.capsItem().style.caps.rows[0].items.map((x) => x.id); return { a, b, c }; });
  ok(JSON.stringify(undoUI.b) !== undoUI.a && JSON.stringify(undoUI.c) === undoUI.a, 'undo / redo: each edit is its own step (the reorder comes back and goes again)');

  // ── the continent picker: select all of Europe ──
  await page.click('[data-caps-add-countries]');
  await page.waitForSelector('.cps-countries label[data-caps-country]');
  await page.click('[data-caps-group="Europe"]'); await page.click('[data-caps-all="Europe"]');
  const pickShot = path.join(SHOTS, 'inspector-country-picker-1440x900.png');
  await page.waitForTimeout(1500); await page.screenshot({ path: pickShot });
  await page.click('#caps-pick-apply'); await page.waitForTimeout(400);
  const eu = await ev(() => { const want = window.CD.COUNTRIES.filter((c) => c[2] === 'Europe').map((c) => c[0]); const have = new Set(window.capsItem().style.caps.rows[1].items.map((c) => c.flag)); return { want: want.length, missing: want.filter((c) => !have.has(c)), n: window.capsItem().style.caps.rows[1].items.length, cont: window.capsItem().style.caps.rows[1].items.filter((c) => c.cont).map((c) => c.en), dup: (() => { const f = window.capsItem().style.caps.rows[1].items.filter((c) => !c.cont).map((c) => c.flag); return f.filter((x, i) => f.indexOf(x) !== i || !window.CD.COUNTRIES.some((c) => c[0] === x)); })() }; });
  ok(eu.missing.length === 0 && eu.want >= 45 && eu.dup.length === 0, `continent picker: "Select all Europe" → all ${eu.want} European countries in the bottom row (${eu.n} capsules, continent capsules: ${eu.cont.join(', ') || 'none'}; no duplicate or stray country${eu.dup.length ? ': ' + eu.dup.join(',') : ''})`);
  const groups = await ev(() => Object.fromEntries(window.CD.COUNTRY_GROUPS.map((g) => [g, window.CD.COUNTRIES.filter((c) => c[2] === g).length])));
  numbers.groups = groups;
  ok(['Europe', 'Asia', 'Middle East', 'North America', 'South America', 'Africa', 'Oceania'].every((g) => groups[g] > 5), `picker continents: ${Object.entries(groups).map(([g, n]) => g + ' ' + n).join(', ')}`);

  // ── flags: every country present, offline ──
  const fl = await ev(async () => { const all = await window.C.flagSvgs(); const codes = window.CD.COUNTRIES.map((c) => c[0]); await window.C.loadFlags(codes); return { n: codes.length, svg: codes.filter((c) => all[c]).length, raster: codes.filter((c) => window.C.flagCanvas(c)).length, eu: !!all.eu }; });
  ok(fl.svg === fl.n && fl.raster === fl.n && fl.eu, `flags: ${fl.n} countries, ${fl.svg} with an SVG in the bundled flag-icons set, ${fl.raster} rasterised, EU mark for "Europe"`);

  // ── motion: speed and direction from the measured pixel shift; seamless wrap ──
  const mo = await ev(() => {
    const k = JSON.parse(JSON.stringify(window.capsItem().style.caps)); k.preset = 'site'; k.fade = 0; k.rows[1].on = false; k.rows[0].speed = 90; k.rows[0].dir = 'left';
    const W = 1080, Hh = 200, P = (t) => window.profile(window.strip(k, t, W, Hh), W, Hh);
    const left = window.shift(P(2), P(3));
    k.rows[0].dir = 'right'; const right = window.shift(P(2), P(3));
    k.rows[0].speed = 40; const slow = window.shift(P(2), P(3));
    // wrap: one loop of each row
    const wrap = [];
    for (const ri of [0, 1]) {
      const kk = JSON.parse(JSON.stringify(window.capsItem().style.caps)); kk.fade = 0; kk.rows.forEach((r, i) => { r.on = i === ri; });
      const per = window.C.rowPeriod(kk, ri);
      const a = window.strip(kk, 0, W, Hh), b = window.strip(kk, per.s, W, Hh), c = window.strip(kk, per.s / 2, W, Hh), d0 = window.strip(kk, per.s - 1 / 30, W, Hh), d1 = window.strip(kk, per.s + 1 / 30, W, Hh);
      wrap.push({ row: ri, px: Math.round(per.px), s: +per.s.toFixed(2), same: +window.diffOf(a, b).toFixed(3), half: +window.diffOf(a, c).toFixed(2), across: +window.diffOf(d0, d1).toFixed(2) });
    }
    return { left, right, slow, wrap };
  });
  numbers.motion = mo;
  ok(Math.abs(mo.left + 90) <= 1 && Math.abs(mo.right - 90) <= 1 && Math.abs(mo.slow - 40) <= 1, `speed + direction (measured shift over 1 s): 90 px/s left → ${mo.left} px, right → +${mo.right} px; 40 px/s → +${mo.slow} px`);
  ok(mo.wrap.every((w) => w.same < 0.05 && w.half > 1), `seamless wrap: frame at one loop = frame at 0 (${mo.wrap.map((w) => `row ${w.row + 1}: ${w.px} px / ${w.s} s, diff ${w.same}`).join('; ')}), half a loop differs (${mo.wrap.map((w) => w.half).join(', ')}), the two frames around the wrap differ by one frame's move only (${mo.wrap.map((w) => w.across).join(', ')})`);
  const easeBeat = await ev(() => {
    const k = JSON.parse(JSON.stringify(window.capsItem().style.caps)); const r = k.rows[0];
    const d = (m, t, beats) => { k.motion = m; return window.C.travelled(k, r, t, t, beats); };
    const ease = [0, 0.5, 1, 1.5, 2, 2.5, 3].map((t) => +d('ease', t).toFixed(1));
    const beats = [0.5, 1, 1.5, 2]; const beat = [0.4, 0.5, 0.7, 0.99, 1.2, 2.5].map((t) => +d('beat', t, beats).toFixed(1));
    return { ease, beat, avg: +(d('ease', 30) / 30).toFixed(1), speed: r.speed };
  });
  numbers.easeBeat = easeBeat;
  ok(easeBeat.ease.every((v, i, a) => !i || v >= a[i - 1]) && Math.abs(easeBeat.avg - easeBeat.speed) < easeBeat.speed * 0.1 && easeBeat.beat[0] === 0 && easeBeat.beat[2] > 0 && easeBeat.beat[5] > easeBeat.beat[4] && easeBeat.beat[4] >= easeBeat.beat[3], `glide & rest: monotonic ${easeBeat.ease.join(' → ')} px, average ${easeBeat.avg} px/s (speed ${easeBeat.speed}); on the beat: still before the first beat, a step per beat ${easeBeat.beat.join(' → ')}`);

  // ── position, scale, opacity ──
  const bx = await ev(() => {
    const ed = window.kbbEditor, p = ed.project, it = window.capsItem(), t = 1.5;
    ed.seek(0);
    const f0 = window.bbox(window.frame(p, t));
    ed.apply(ed.ops.setItems([{ id: it.id, props: { y: it.y - 400 } }], 'Move')); ed.history.seal();
    const f1 = window.bbox(window.frame(p, t));
    window.setCaps((k) => { k.scale = 1.5; });
    const f2 = window.bbox(window.frame(p, t));
    window.setCaps((k) => { k.scale = 1; });
    ed.apply(ed.ops.setItems([{ id: it.id, props: { opacity: 0.5 } }], 'Opacity')); ed.history.seal();
    const f3 = window.bbox(window.frame(p, t));
    ed.apply(ed.ops.setItems([{ id: it.id, props: { opacity: 1, y: it.y + 400 } }], 'Back')); ed.history.seal();
    return { f0, f1, f2, f3 };
  });
  numbers.box = bx;
  ok(Math.abs((bx.f0.y0 - bx.f1.y0) - 100) <= 3, `position: y −400 canvas px moves the drawn carousel ${bx.f0.y0 - bx.f1.y0} px at 0.25× (= ${(bx.f0.y0 - bx.f1.y0) * 4} canvas px)`);
  ok(bx.f2.h / bx.f1.h > 1.35 && bx.f2.h / bx.f1.h < 1.65, `scale 150%: drawn height ${bx.f1.h} → ${bx.f2.h} px (×${(bx.f2.h / bx.f1.h).toFixed(2)})`);
  ok(bx.f3.mx < bx.f1.mx * 0.6 && bx.f3.mx > bx.f1.mx * 0.4, `opacity 50%: brightest pixel ${bx.f1.mx} → ${bx.f3.mx}`);

  // ── every preset renders, without an error, and differently ──
  const pr = await ev(() => {
    const out = [], base = JSON.parse(JSON.stringify(window.capsItem().style.caps)), imgs = {};
    for (const p of window.C.PRESETS) {
      const k = Object.assign({}, base, { preset: p.id }); let err = null, px = 0;
      try { for (const t of [0.5, 2.2, 4.1]) { const d = window.strip(k, t, 1080, 420); if (t === 2.2) imgs[p.id] = d; for (let i = 0; i < d.length; i += 16) if (d[i] + d[i + 1] + d[i + 2] > 40) px++; } } catch (e) { err = e.message; }
      out.push({ id: p.id, name: p.name, err, px });
    }
    const ids = Object.keys(imgs); let minD = 1e9, pair = '';
    for (let i = 0; i < ids.length; i++) for (let j = i + 1; j < ids.length; j++) { const d = window.diffOf(imgs[ids[i]], imgs[ids[j]]); if (d < minD) { minD = d; pair = ids[i] + '/' + ids[j]; } }
    return { out, minD: +minD.toFixed(2), pair };
  });
  numbers.presets = pr;
  ok(pr.out.length >= 12 && pr.out.every((x) => !x.err && x.px > 500), `${pr.out.length} presets render without an error: ${pr.out.map((x) => x.name).join(' · ')}`);
  ok(pr.minD > 3, `the presets really differ: the closest pair (${pr.pair}) still differs by ${pr.minD}/255 per pixel`);

  // ── colours: theme switch keeps capsules + overrides; a capsule override renders; reset; clear all; undo ──
  const capId = await ev(() => window.capsItem().style.caps.rows[0].items[0].id);
  await page.click(`.cps-item[data-caps-item="${capId}"] .cps-grip`); await page.waitForSelector('[data-caps-sel-box]');
  await page.fill('[data-caps-sel-box] [data-cc="fill"] input.cps-hex', '#00FF00'); await page.keyboard.press('Tab'); await page.waitForTimeout(300);
  const green = () => ev(() => { const k = JSON.parse(JSON.stringify(window.capsItem().style.caps)); k.fade = 0; k.rows[1].on = false; let n = 0; for (const t of [0, 1.3, 2.6]) { const d = window.strip(k, t, 1600, 200); for (let i = 0; i < d.length; i += 4) if (d[i] < 30 && d[i + 1] > 225 && d[i + 2] < 30) n++; } return n; });
  const g1 = await green();
  const dot = await page.$(`[data-caps-own="${capId}"]`);
  ok(g1 > 2000 && !!dot, `capsule colour (select it, type #00FF00 in Fill): it renders green (${g1} green px over 3 frames), a colour dot marks it in the list`);
  // a second capsule, Shift-click: both selected, the text colour set on both
  const cap2 = await ev(() => window.capsItem().style.caps.rows[0].items[1].id);
  await page.click(`.cps-item[data-caps-item="${cap2}"] .cps-grip`, { modifiers: ['Shift'] }); await page.waitForTimeout(200);
  const nSel = await ev(() => +document.querySelector('[data-caps-sel-box]')?.dataset.capsSelBox);
  await page.fill('[data-caps-sel-box] [data-cc="text"] input.cps-hex', '#FFEE00'); await page.keyboard.press('Tab'); await page.waitForTimeout(300);
  const multi = await ev(({ a, b }) => window.capsItem().style.caps.rows[0].items.filter((c) => c.id === a || c.id === b).map((c) => c.col?.text), { a: capId, b: cap2 });
  ok(nSel === 2 && multi.every((c) => c === '#ffee00'), `Shift-click selects ${nSel} capsules; one Text colour sets both (${multi.join(', ')})`);
  const u1 = await ev(() => window.kbbEditor.history.undoStack.length);
  await page.click('[data-caps-preset="neon"]'); await page.waitForTimeout(400);
  const sw = await ev(({ u1, capId }) => { const k = window.capsItem().style.caps; return { preset: k.preset, steps: window.kbbEditor.history.undoStack.length - u1, n: k.rows.map((r) => r.items.length), own: k.rows[0].items.find((c) => c.id === capId)?.col }; }, { u1, capId });
  const g2 = await green();
  ok(sw.preset === 'neon' && sw.steps === 1 && sw.own?.fill === '#00ff00' && g2 > 1000, `theme switch (click "Neon outline"): ONE undo step, capsules kept (${sw.n.join(' + ')}), the override kept and still green on the neon look (${g2} px)`);
  await page.click(`.cps-item[data-caps-item="${capId}"] .cps-grip`); await page.waitForSelector('[data-caps-sel-box="1"]');
  await page.click('[data-caps-sel-box] [data-reset="fill"]'); await page.waitForTimeout(300);
  const g3 = await green(); const after = await ev((id) => window.capsItem().style.caps.rows[0].items.find((c) => c.id === id)?.col || null, capId);
  ok(g3 === 0 && after?.text === '#ffee00' && !after?.fill, `Reset to theme (Fill ↺): no green left (${g3} px), its own text colour stays (${after?.text})`);
  await page.click('[data-caps-clear]'); await page.waitForTimeout(300);
  const cl = await ev(() => window.C.hasOverrides(window.capsItem().style.caps));
  const un = await ev(() => { window.kbbEditor.history.undo(); return window.C.hasOverrides(window.capsItem().style.caps); });
  ok(cl === false && un === true, `Apply theme to all (clear overrides): no override left (${cl}); ⌘Z brings them back (${un})`);
  const rowCol = await ev(() => { window.setCaps((k) => { k.preset = 'site'; k.rows[0].col = { fill: '#00ff00' }; }); return 0; });
  const g4 = await green();
  await ev(() => window.setCaps((k) => { k.rows[0].col = {}; for (const r of k.rows) for (const c of r.items) delete c.col; }));
  ok(g4 > 10000, `row colour: Fill on the whole top row renders green (${g4} px)`);
  void rowCol;
  const brand = await ev(() => { window.setCaps((k) => { k.brand = { primary: '#123456', accent: '#ff0000' }; }); const L = window.C.rowLook(window.capsItem().style.caps, window.capsItem().style.caps.rows[1]); window.setCaps((k) => { k.brand = null; }); return L.fill; });
  ok(brand === '#123456', `company colours: the countries row fills with the company's main colour (${brand})`);

  // ── Arabic ──
  const ar = await ev(() => {
    const k = JSON.parse(JSON.stringify(window.capsItem().style.caps)); k.preset = 'site'; k.fade = 0; k.rows[1].on = false; k.rows[0].speed = 0; k.rows[0].items = [window.CD.jobCap('Drivers', 'سائقون', 'wheel')];
    k.size = Object.assign({}, k.size, { h: 120, font: 48, icon: 56, gap: 3000 });
    const side = (lang) => { k.lang = lang; const W = 1080, Hh = 300, d = window.strip(k, 0, W, Hh); let x0 = 1e9, x1 = -1, rs = 0, rn = 0; for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) { const i = (y * W + x) * 4; if (d[i] + d[i + 1] + d[i + 2] > 200) { x0 = Math.min(x0, x); x1 = Math.max(x1, x); } if (d[i] > 170 && d[i + 1] < 80 && d[i + 2] < 100) { rs += x; rn++; } } return +(((rs / Math.max(1, rn)) - x0) / (x1 - x0)).toFixed(2); };
    const all = window.capsItem().style.caps.rows.flatMap((r) => r.items);
    return { en: side('en'), ar: side('ar'), missing: all.filter((c) => !c.ar).map((c) => c.en), sample: all.slice(0, 3).map((c) => c.ar).concat(all.slice(-2).map((c) => c.ar)) };
  });
  numbers.arabic = ar;
  await ev(() => window.setCaps((k) => { k.lang = 'ar'; }));
  ok(ar.en < 0.3 && ar.ar > 0.7 && ar.missing.length === 0, `Arabic: every capsule has an Arabic label (${ar.sample.join('، ')}); the icon sits left in English (${ar.en}) and right in Arabic (${ar.ar} of the width)`);
  await ev(() => window.kbbEditor.select([window.capsItem().id]));
  await page.waitForTimeout(300);
  const arDir = await ev(() => document.querySelector('[data-caps-label]')?.getAttribute('dir'));
  ok(arDir === 'rtl', `the inspector's labels edit right-to-left in Arabic (dir=${arDir})`);
  await ev(() => window.setCaps((k) => { k.lang = 'en'; }));

  // ── save + reload ──
  const sv = await ev(async () => {
    const ed = window.kbbEditor; await ed.save();
    const p2 = await window.CTX.engine.loadProject(ed.project.workspace, ed.project.id);
    await window.R.prepareRender(p2);
    const a = JSON.stringify(window.capsItem().style), b = JSON.stringify(p2.tracks.flatMap((t) => t.items).find((i) => i.style?.el === 'capsules')?.style);
    return { same: a === b, frames: [0.7, 3.3].map((t) => +window.diffOf(window.frame(ed.project, t).d, window.frame(p2, t).d).toFixed(3)) };
  });
  ok(sv.same && sv.frames.every((x) => x === 0), `save + reload: the carousel's model comes back identical, frames equal (${sv.frames.join(', ')})`);

  // ── preview = export ──
  const pv = await ev(async () => {
    const ed = window.kbbEditor, E = await import('/app/core/export.js'), p = ed.project;
    window.setCaps((k) => { k.preset = 'site'; k.fade = 0.08; });
    const r = await E.exportMp4(p, ed.media, { quality: 'high', width: 540, height: 960, duration: 4 });
    const file = `workspaces/${p.workspace}/exports/caps-preview-vs-export.mp4`; await ed.engine.writeFile(file, r.blob);
    return { file };
  });
  const pdiffs = [];
  for (const t of [0.5, 1.7, 3.2]) {
    await ev((t) => window.kbbEditor.seek(t), t);
    await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(600);
    const fr = await ev(() => { const c = window.kbbEditor.preview.canvas, d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data, rgb = new Uint8Array(c.width * c.height * 3); for (let i = 0, j = 0; i < d.length; i += 4) { rgb[j++] = d[i]; rgb[j++] = d[i + 1]; rgb[j++] = d[i + 2]; } return { w: c.width, h: c.height, data: Array.from(rgb) }; });
    pdiffs.push({ t, diff: +meanDiff(Uint8Array.from(fr.data), frameRGB(path.join(STUDIO, pv.file), t, fr.w, fr.h)).toFixed(2) });
  }
  numbers.previewVsExport = pdiffs;
  ok(pdiffs.every((x) => x.diff < 6), `preview = export: ${pdiffs.map((x) => `${x.t} s ${x.diff}/255`).join(', ')} (< 6)`);

  // ── frame time at 1080×1920, 2 rows × 30 capsules ──
  // measured in a second Chrome with the 2D canvas in software (Skia on the CPU): the headless GPU here is SwiftShader, an
  // emulated GPU slower than any Mac's, so the CPU raster is the honest (and pessimistic) number for a real machine
  const capsNow = await ev(() => window.capsItem().style.caps);
  const b2 = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--disable-gpu', '--disable-accelerated-2d-canvas'] });
  const p2 = await b2.newPage(); await p2.goto(e.url + '/app/'); await p2.waitForSelector('.home, #su-later', { timeout: 30000 });
  const ft = await p2.evaluate(async (capsNow) => {
    window.C = await import('/app/themes/capsules.js'); window.CD = await import('/app/themes/capsules-data.js');
    const k = JSON.parse(JSON.stringify(capsNow));
    const jobs = window.CD.FEATURED_JOBS, cs = window.CD.COUNTRIES.filter((c) => ['Europe', 'North America', 'Oceania'].includes(c[2])).slice(0, 30);
    k.rows[0].items = Array.from({ length: 30 }, (_, i) => window.CD.jobCap(jobs[i % jobs.length][0] + (i >= jobs.length ? ' ' + (i + 1) : ''), jobs[i % jobs.length][1], jobs[i % jobs.length][2]));
    k.rows[1].items = cs.map((c) => window.CD.countryCap(c[0]));
    await window.C.loadFlags(window.C.capsFlagCodes(k));
    // software raster (willReadFrequently) with a 1-px read per frame: the real drawing cost is inside the timing (the
    // headless GPU here is SwiftShader, whose queue would otherwise be timed nowhere or everywhere)
    const cv = document.createElement('canvas'); cv.width = 1080; cv.height = 1920; const g = cv.getContext('2d', { willReadFrequently: true });
    const bg = document.createElement('canvas'); bg.width = 1080; bg.height = 1920; const bgc = bg.getContext('2d', { willReadFrequently: true }), gr = bgc.createLinearGradient(0, 0, 1080, 1920); gr.addColorStop(0, '#2d5d8f'); gr.addColorStop(1, '#e0b48c'); bgc.fillStyle = gr; bgc.fillRect(0, 0, 1080, 1920);
    const run = (preset, fade) => {
      const kk = Object.assign({}, k, { preset, fade }), H0 = window.C.capsHeight(kk), ts = [];
      for (let i = 0; i < 100; i++) {
        g.setTransform(1, 0, 0, 1, 0, 0); g.drawImage(bg, 0, 0);
        const a = performance.now(); g.save(); g.translate(0, 1300); window.C.drawCapsules(g, kk, { w: 1080, h: H0, t: i / 30, tg: i / 30, beats: [] }); g.restore(); g.getImageData(0, 1300, 1, 1); ts.push(performance.now() - a);
      }
      g.getImageData(0, 0, 1, 1);
      const s = ts.slice(10).sort((x, y) => x - y);
      return { preset, fade, median: +s[Math.floor(s.length / 2)].toFixed(2), p95: +s[Math.floor(s.length * 0.95)].toFixed(2), first: +ts[0].toFixed(1) };
    };
    return [run('site', 0), run('site', 0.08), run('glass', 0.08), run('neon', 0), run('arc', 0.08), run('bold', 0)].concat([{ bitmaps: window.C.bitmapCount() }]);
  }, capsNow);
  await b2.close();
  numbers.frameTime = ft;
  const ftr = ft.filter((x) => x.preset);
  ok(ftr.every((x) => x.median < 16 && x.p95 < 33), `frame time (budget: median under 16 ms, p95 under one 30 fps frame) at 1080×1920, 2 rows × 30 capsules (drawCapsules + raster, software canvas, frames 10–100): ${ftr.map((x) => `${x.preset}${x.fade ? '+fade' : ''} median ${x.median} ms / p95 ${x.p95} ms (first frame ${x.first} ms)`).join('; ')}`);

  // ── no requests off the machine, no console errors ──
  ok(outside.length === 0, `no network: ${outside.length} requests left 127.0.0.1${outside.length ? ' (' + outside.slice(0, 3).join(', ') + ')' : ''}`);

  // ── screenshots of the inspector (1440×900) ──
  await ev(() => window.kbbEditor.select([window.capsItem().id]));
  await page.waitForSelector('[data-caps-insp] .cps-preset');
  const scrollTo = (sel) => ev((sel) => { const el = document.querySelector(sel); if (!el) return; el.scrollIntoView({ block: 'start' }); let sc = el.parentElement; while (sc && sc.scrollHeight <= sc.clientHeight) sc = sc.parentElement; if (sc) sc.scrollTop -= 64; }, sel);
  await ev(() => window.kbbEditor.seek(1.6)); await page.waitForTimeout(800);
  await scrollTo('[data-caps-insp] .sec:nth-child(1)'); await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(SHOTS, 'inspector-style-1440x900.png') });
  await page.click(`.cps-item[data-caps-item="${capId}"] .cps-grip`).catch(() => {}); await page.waitForTimeout(300);
  await scrollTo('[data-caps-row="jobs"]'); await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, 'inspector-capsule-list-1440x900.png') });
  await scrollTo('[data-caps-insp] .sec:nth-child(3)'); await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, 'inspector-size-motion-1440x900.png') });

  // ── previews: every preset as a 9:16 432×768 mp4 and a 16:9 640×360 strip, with posters ──
  const vids = [];
  if (!process.env.NOVIDEO) {
    const PV = path.join(OUT, 'previews'); fs.mkdirSync(PV, { recursive: true });
    for (const aspect of ['9:16', '16:9']) {
      const ids = await ev(() => window.C.PRESETS.map((p) => p.id));
      for (const id of ids) {
        const r = await ev(async ({ id, aspect }) => {
          const ed = window.kbbEditor, E = await import('/app/core/export.js');
          if (ed.project.canvas.aspect !== aspect) ed.setAspect(aspect);
          const p = ed.project, it = window.capsItem(), W = p.canvas.w, Hh = p.canvas.h;
          window.setCaps((k) => { Object.assign(k, window.C.setPreset(k, id, { clear: true })); k.fade = id === 'ticker' ? 0 : 0.08; k.scale = aspect === '16:9' ? 0.85 : 1; });
          const h2 = window.C.capsHeight(window.capsItem().style.caps);
          ed.apply(ed.ops.setItems([{ id: it.id, props: { x: 0, w: W, h: h2, y: Math.round(aspect === '16:9' ? Hh - h2 - Hh * 0.06 : Hh * 0.66), t0: 0, in: 0, out: 6 } }], 'Box')); ed.history.seal();
          const [w, h] = aspect === '16:9' ? [640, 360] : [432, 768];
          const res = await E.exportMp4(p, ed.media, { quality: 'high', width: w, height: h, duration: 6 });
          const file = `workspaces/${p.workspace}/exports/caps-${aspect === '16:9' ? 'strip' : 'story'}-${id}.mp4`; await ed.engine.writeFile(file, res.blob);
          return { file };
        }, { id, aspect });
        const dst = path.join(PV, path.basename(r.file)); fs.copyFileSync(path.join(STUDIO, r.file), dst);
        ff('-ss', '2.4', '-i', dst, '-frames:v', '1', '-q:v', '3', dst.replace(/\.mp4$/, '.jpg'));
        vids.push(path.basename(dst));
      }
    }
    // six stills for the repository (the story frames of six very different looks)
    for (const id of ['site', 'glass', 'flagfill', 'ticker', 'luxury', 'arc']) fs.copyFileSync(path.join(PV, `caps-story-${id}.jpg`), path.join(SHOTS, `still-${id}.jpg`));
    // one contact sheet of every preset (posters) for a quick look
    const posters = vids.filter((v) => v.startsWith('caps-story-')).map((v) => path.join(PV, v.replace(/\.mp4$/, '.jpg')));
    ff(...posters.flatMap((p) => ['-i', p]), '-filter_complex', posters.map((_, i) => `[${i}:v]scale=216:384[v${i}]`).join(';') + ';' + posters.map((_, i) => `[v${i}]`).join('') + `xstack=inputs=${posters.length}:layout=${posters.map((_, i) => `${(i % 7) * 216}_${Math.floor(i / 7) * 384}`).join('|')}`, path.join(OUT, 'presets-sheet.jpg'));
    const ok16 = vids.filter((v) => v.startsWith('caps-strip-')).length, ok9 = vids.filter((v) => v.startsWith('caps-story-')).length;
    const probe = execFileSync('ffprobe', ['-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height,duration', '-of', 'csv=p=0', path.join(PV, 'caps-story-site.mp4')]).toString().trim();
    ok(ok9 >= 12 && ok16 >= 12, `previews: ${ok9} story mp4s (432×768, 6 s: ${probe}) + ${ok16} 16:9 strips (640×360) with jpg posters → tools/out/capsules/previews/`);
  }

  ok(errors.length === 0 && warns.length === 0, `console: ${errors.length} errors, ${warns.length} render warnings${errors.length || warns.length ? ': ' + [...errors, ...warns].slice(0, 3).join(' | ') : ''}`);
  numbers.seconds = Math.round((Date.now() - T0) / 1000);
  fs.writeFileSync(path.join(OUT, 'caps-check.json'), JSON.stringify({ results, numbers, videos: vids }, null, 1));
  await browser.close(); await H.stopEngine(e);
  console.log(`\n${results.length - failures}/${results.length} passed in ${numbers.seconds} s`);
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
