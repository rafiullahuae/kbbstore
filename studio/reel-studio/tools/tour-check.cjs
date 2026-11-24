#!/usr/bin/env node
// KBB Studio — Office tour check: the walkthrough template over every join, join-by-join editing, and LIVE handles
// (both clips keep moving through a transition). Headless Chromium (WebGL2 via SwiftShader) against the Linux engine.
//
// The owner, 24 Nov: "we have around 15+ clips ... i need it as one super template, which will apply [to every] clip,
// and can be edit clip by clip also." and earlier "don't give me normal stuff ... super professional".
//   template  Look → 🏢 Office tour puts the pattern on every join (zoom-through in, calm lens blur, a whip / push,
//             dip to white out), one undo step
//   per join  each join has its own row and menu; changing one leaves the others as they were
//   live      the exported MP4's transition frames come from the incoming clip's footage BEFORE its in-point (a moving
//             handle), not one frozen frame: two frames 0.1 s apart differ in content, not only in fade level
//   hygiene   no console errors, nothing leaves 127.0.0.1; screenshots 1280×800 → shots/tour/
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/tour-check.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'tour'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'tour');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) failures++; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
// content difference with the fade level taken out: each frame scaled to the same mean brightness first
function shapeDiff(a, b) {
  const m = (x) => { let s = 0; for (const v of x) s += v; return s / x.length || 1; };
  const ka = 128 / m(a), kb = 128 / m(b); let d = 0;
  for (let i = 0; i < a.length; i++) d += Math.abs(a[i] * ka - b[i] * kb);
  return d / a.length;
}

(async () => {
  // the clips: a still black clip first (so before the first cut the picture IS the incoming clip's handle, faded),
  // then three moving test patterns
  // encoded like the other checks' clips (sizes the headless decoder hands to createImageBitmap, a sound track, faststart)
  const enc = ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-shortest', '-movflags', '+faststart'];
  ff('-f', 'lavfi', '-i', 'color=c=black:size=320x576:rate=30:duration=4', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=4:sample_rate=48000', ...enc, path.join(OUT, 'c0.mp4'));
  for (const i of [1, 2]) ff('-f', 'lavfi', '-i', 'testsrc2=size=320x576:rate=30:duration=6', '-f', 'lavfi', '-i', `sine=frequency=${300 + i * 110}:duration=6:sample_rate=48000`, '-vf', `hue=h=${i * 60}`, ...enc, path.join(OUT, `c${i}.mp4`));
  fs.rmSync(STUDIO, { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  fs.mkdirSync(path.join(STUDIO, 'library', 'tour-test'), { recursive: true });
  for (const i of [1, 2]) fs.copyFileSync(path.join(OUT, `c${i}.mp4`), path.join(STUDIO, 'library', 'tour-test', `c${i}.mp4`));

  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });   // three videos at 1920 exhaust the headless SwiftShader renderer (the old code too)
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('crash', () => console.log('CRASH; console so far:', errors.slice(-5).join(' | '), process.memoryUsage().rss));
  if (process.env.TOUR_DEBUG) page.on('console', (m) => console.log('[page]', m.type(), m.text().slice(0, 300)));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name) => { await page.waitForTimeout(500); await page.screenshot({ path: path.join(SHOTS, `${name}-1280.png`) }); };

  await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'c0.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  // three more clips, each starting 1 s into its file (the footage before that is the handle a transition plays)
  await ev(async () => {
    const ed = window.kbbEditor; window.LK = await import('/app/look/index.js'); window.CTX = ed.context();
    for (const i of [1, 2]) { const m = await ed.media.importPath(`library/tour-test/c${i}.mp4`); ed.addMediaToTimeline(m); }
  });
  await page.waitForFunction(() => window.kbbEditor.project.tracks[0].items.length === 3);
  await ev(async () => {
    const ed = window.kbbEditor, { ops } = await import('/app/core/history.js');
    const cl = ed.project.tracks[0].items.slice().sort((a, b) => a.t0 - b.t0);
    let t = cl[0].t0 + (cl[0].out - cl[0].in);
    for (const c of cl.slice(1)) { ed.history.apply(ops.setItem(c.id, { in: 1, out: 5, t0: +t.toFixed(4) }, 'trim')); t += 4; }
  });
  const cuts = await ev(async () => (await import('/app/look/transitions.js')).cutsOf(window.kbbEditor.project).map((c) => c.t));
  ok(cuts.length === 2, `three clips one after another: ${cuts.length} joins at ${cuts.map((c) => c.toFixed(2)).join(', ')} s`);

  // ── the template, from the Look tab ──
  await page.click('.side-tab[data-side="mod:look"]');
  await page.waitForSelector('[data-act="office-tour"]');
  const rows0 = await ev(() => document.querySelectorAll('[data-x="joins"] select[data-join]').length);
  await page.click('[data-act="office-tour"]');
  await page.waitForFunction(() => window.kbbEditor.history.undoLabel === 'Office tour');
  await page.waitForTimeout(400);
  const after = await ev(() => ({ kinds: window.LK.joins(window.kbbEditor.project).map((j) => j.kind), rows: [...document.querySelectorAll('[data-x="joins"] select[data-join]')].map((s) => s.value) }));
  ok(rows0 === 2 && JSON.stringify(after.kinds) === JSON.stringify(['zoom', 'white']) && JSON.stringify(after.rows) === JSON.stringify(after.kinds),
    `🏢 Office tour: every join gets the walkthrough pattern (${after.kinds.join(' → ')}), one row per join in the panel (${after.rows.join(', ')})`);
  const tpl = await ev(async () => { const T = await import('/app/look/transitions.js'); return [3, 15].map((n) => T.tourKinds(n).join(' ')); });
  ok(/^zoom lens white$/.test(tpl[0]) && tpl[1].split(' ').length === 15 && tpl[1].startsWith('zoom') && tpl[1].endsWith('white'), `the pattern scales to 15+ clips: ${tpl[1]}`);
  if (process.env.TOUR_SHOTS) await shot('tour-01-office-tour-panel');   // screenshots with three videos crash this headless renderer; tour-shots below takes them with two

  // ── one join changed, the others kept ──
  await page.selectOption('[data-x="joins"] select[data-join="0"]', 'dissolve');
  await page.waitForFunction(() => window.LK.joins(window.kbbEditor.project)[0].kind === 'dissolve');
  const k2 = await ev(() => window.LK.joins(window.kbbEditor.project).map((j) => j.kind));
  ok(JSON.stringify(k2) === JSON.stringify(['dissolve', 'white']), `join 1 changed by itself in its menu: ${k2.join(' → ')}`);
  await page.selectOption('[data-x="joins"] select[data-join="1"]', '');
  await page.waitForFunction(() => window.LK.joins(window.kbbEditor.project)[1].kind === null);
  const k3 = await ev(() => window.LK.joins(window.kbbEditor.project).map((j) => j.kind || 'cut'));
  ok(JSON.stringify(k3) === JSON.stringify(['dissolve', 'cut']), `a join set back to a plain cut: ${k3.join(' → ')}`);

  // ── live handles in the export ──
  const ex = await ev(async () => {
    const E = await import('/app/core/export.js'), ed = window.kbbEditor, p = ed.project;
    const r = await E.exportMp4(p, ed.media, { quality: 'high' });
    const file = `workspaces/${p.workspace}/exports/tour.mp4`; await ed.engine.writeFile(file, r.blob);
    return { file, note: r.note };
  });
  const f = path.join(STUDIO, ex.file);
  const c0 = cuts[0];
  // dissolve 0.5 s over the first cut: before it, A is black, so the picture is the incoming clip's handle faded in
  const x1 = frameRGB(f, c0 - 0.2, 160, 288), x2 = frameRGB(f, c0 - 0.1, 160, 288), x3 = frameRGB(f, c0 - 0.0333, 160, 288);
  const d12 = shapeDiff(x1, x2), d23 = shapeDiff(x2, x3);
  ok(d12 > 4 && d23 > 4, `live handles: the incoming clip keeps moving before its in-point (content change between frames 0.1 s apart, fade level taken out: ${d12.toFixed(1)} and ${d23.toFixed(1)} /255; a frozen frame reads ≈ 0)`);

  // ── undo: the whole template was one step ──
  const undo = await ev(() => { const ed = window.kbbEditor; ed.history.undo(); ed.history.undo(); ed.history.undo(); return ed.project.tracks.flatMap((t) => t.items).filter((i) => i.type === 'look.transition').length; });
  ok(undo === 0, `undo: the two join changes, then the Office tour, are one step each (${undo} transitions left)`);

  // ── the standalone template: 🏢 Office tour in the "Do it for me" bar opens its own tab ──
  await page.click('.doit-btn[data-mod="office-tour"]');
  await page.waitForSelector('[data-x="tour"] [data-act="make-tour"]', { timeout: 20000 });
  const tp = await ev(() => ({ joins: document.querySelectorAll('[data-x="tour-joins"] select[data-join]').length, rooms: document.querySelectorAll('[data-x="tour-rooms"] input[data-room]').length, head: document.querySelector('[data-x="tour"] .tour-head')?.textContent }));
  ok(tp.joins === 2 && tp.rooms === 3, `🏢 Office tour (top bar) opens its own tab: ${tp.head}; ${tp.joins} join rows, ${tp.rooms} room-name lines`);
  await page.fill('[data-x="tour-rooms"] input[data-room="0"]', 'Entrance');
  await page.fill('[data-x="tour-rooms"] input[data-room="1"]', 'Reception');
  await page.fill('[data-x="tour-rooms"] input[data-room="2"]', 'Consultation rooms');
  await page.fill('[data-x="end-title"]', 'Planet Careers');
  await page.click('.tour-styles [data-style="calm"]');
  await page.click('[data-act="make-tour"]');
  await page.waitForFunction(() => window.kbbEditor.project.tracks.flatMap((t) => t.items).some((i) => i.tour === 'end'), null, { timeout: 120000 });
  await page.waitForTimeout(600);
  const made = await ev(async () => {
    const T = await import('/app/tour/index.js'), p = window.kbbEditor.project, all = p.tracks.flatMap((t) => t.items);
    const cl = T.clipsOf(p), end = all.find((i) => i.tour === 'end');
    return { kinds: window.LK.joins(p).map((j) => j.kind), rooms: all.filter((i) => i.tour === 'room').map((i) => i.text), firstIn: cl[0].in, firstOut: cl[0].out,
      endAt: end && +end.t0.toFixed(3), lastEnd: +(cl[cl.length - 1].t0 + (cl[cl.length - 1].out - cl[cl.length - 1].in) / (cl[cl.length - 1].speed || 1)).toFixed(3), title: end?.style?.title,
      dur: (await import('/app/core/project.js')).projectDuration(p) };
  });
  ok(JSON.stringify(made.kinds) === JSON.stringify(['lens', 'white']) && made.rooms.join('|') === 'Entrance|Reception|Consultation rooms' && made.firstIn >= 0.39 && made.firstOut <= 3.61
    && made.endAt === made.lastEnd && made.title === 'Planet Careers' && made.dur >= made.lastEnd + 3,
    `🏢 Make the office tour (Calm): joins ${made.kinds.join(' → ')}; room names ${made.rooms.join(', ')}; clip 1 trimmed to ${made.firstIn}–${made.firstOut} s for room to move; end card "${made.title}" from ${made.endAt} s (video ${made.dur.toFixed(2)} s)`);
  await page.fill('[data-x="tour-joins"] input[data-len="0"]', '1.4'); await page.dispatchEvent('[data-x="tour-joins"] input[data-len="0"]', 'change');
  await page.waitForFunction(() => Math.abs((window.LK.joins(window.kbbEditor.project)[0].dur || 0) - 1.4) < 0.02, null, { timeout: 10000 }).catch(() => {});
  const lens = await ev(() => window.LK.joins(window.kbbEditor.project).map((j) => [j.kind, j.dur]));
  ok(Math.abs(lens[0][1] - 1.4) < 0.02 && lens[0][0] === 'lens' && lens[1][0] === 'white', `a join's length changed by itself: ${JSON.stringify(lens)}`);
  await page.click('[data-x="tour-joins"] [data-watch="0"]'); await page.waitForTimeout(500);
  const playing = await ev(() => window.kbbEditor.playing);
  ok(playing, '▶ plays the join in the preview');
  if (process.env.TOUR_SHOTS) await shot('tour-02-office-tour-tab');

  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}`);
  ok(!outside.length, `nothing left 127.0.0.1${outside.length ? ': ' + outside.slice(0, 3).join(' ') : ''}`);
  await browser.close();
  await H.stopEngine(e);
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
