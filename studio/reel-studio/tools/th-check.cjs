#!/usr/bin/env node
// KBB Studio — Themes lane check: animated themes + hover previews, headless Chromium (WebGL2 via SwiftShader) against
// the Linux engine, in the real editor.
//
//   themes:  every theme applies (ONE undo step) and renders 3 frames without a console error in 9:16, 1:1 and 16:9;
//            contact sheets shots/th/themes-*.jpg (3 frames per theme)
//   safe:    the overlay layer is fully transparent inside the injected face box and every caption box (pixel check)
//   beats:   beat-reactive pulses peak within ±1 frame of each beat
//   same:    preview frame vs export frame mean difference < 6/255 (theme + sting + title + transitions)
//   suggest: Europe / Germany for a German study-visa transcript, petals/beauty for a KBB transcript
//   hover:   Themes / Templates / Look / transitions / Create formats / Music / SFX: starts within 300 ms, stops on leave,
//            one at a time, no request storm while sweeping, frame time reported
//   shots:   the Themes tab and hover previews at 1920×1080 + 1280×800 → shots/th/
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/th-check.cjs   (QUICK=1: fewer themes)
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'th'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'th');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const QUICK = !!process.env.QUICK;
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const meanDiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };

function makeClips() {
  const a = path.join(OUT, 'a.mp4'), b = path.join(OUT, 'b.mp4');
  if (!fs.existsSync(a)) ff('-f', 'lavfi', '-i', 'gradients=size=1080x1920:rate=30:duration=5:speed=0.02:c0=0x7a5c8f:c1=0xd9a07e:c2=0x2f4f6f:nb_colors=3', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=5:sample_rate=48000', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', a);
  if (!fs.existsSync(b)) ff('-f', 'lavfi', '-i', 'gradients=size=1080x1920:rate=30:duration=4:speed=0.03:c0=0x204060:c1=0x80a0c0:nb_colors=2', '-f', 'lavfi', '-i', 'sine=frequency=550:duration=4:sample_rate=48000', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', b);
}

const GERMAN = 'Hi everyone. If you want to study in Germany, the German student visa starts with an admission letter from a university, for example in Berlin or Munich. Europe has great public universities and Germany is one of the best.';
const KBB = 'Hello and welcome back. Today I am showing you the new vitamin C serum from K Beauty Bliss. It feels so light on the skin, and the glow is amazing. My skincare routine is complete.';
// the injected face (canvas-normalised centre + size) — a talking head in the upper middle
const FACE = { x: 0.5, y: 0.36, w: 0.34, h: 0.22 };

(async () => {
  const T0 = Date.now();
  makeClips();
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  fs.mkdirSync(path.join(STUDIO, 'library', 'th-test'), { recursive: true });
  for (const f of ['a.mp4', 'b.mp4']) fs.copyFileSync(path.join(OUT, f), path.join(STUDIO, 'library', 'th-test', f));
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], warns = [], outside = [], reqLog = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); if (m.type() === 'warning' && /\[(render|themes|hover)\]/.test(m.text())) warns.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); reqLog.push({ u, at: Date.now() }); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  // read a theme layer's pixels through a scratch canvas: calling getImageData on the layer itself would make Chrome move
  // that canvas to the CPU for the rest of the session, which is not how the app runs
  await page.addInitScript(() => { window.__px = (cv, x, y, w, h) => { const r = window.__rd || (window.__rd = document.createElement('canvas')); r.width = w; r.height = h; const g = r.getContext('2d', { willReadFrequently: true }); g.drawImage(cv, x, y, w, h, 0, 0, w, h); return g.getImageData(0, 0, w, h).data; }; });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name, before) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) {
      await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(600);
      if (before) await before();
      await page.screenshot({ path: path.join(SHOTS, `${name}-${w}x${h}.png`) });
    }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(300);
  };

  // ── the reel: two clips (one cut), music for beats, captions ──
  await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'a.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  const setup = await ev(async ({ GERMAN }) => {
    const ed = window.kbbEditor; window.TH = await import('/app/themes/index.js'); window.CTX = ed.context();
    const m = await ed.media.importPath('library/th-test/b.mp4'); ed.addMediaToTimeline(m);
    const A = await import('/app/auto/index.js'); await A.addMusic(window.CTX, { trackId: 'glow-up' }).catch(() => A.addMusic(window.CTX));
    const C = await import('/app/captions/commands.js'), M = await import('/app/captions/model.js');
    let t = 0.3; const words = GERMAN.split(' ').slice(0, 26).map((w) => { const o = { t0: +t.toFixed(2), t1: +(t + 0.28).toFixed(2), w, conf: 1 }; t += 0.32; return o; });
    const style = 'word-box'; ed.apply(C.setCaptions({ style, lang: 'en', words, lines: M.buildLines(words, style, ed.project.canvas) }, ed.project.canvas));
    if (!ed.project.tracks.some((tr) => tr.kind === 'caption' && tr.items.some((i) => i.type === 'caption'))) { const tr = ed.project.tracks.find((x) => x.kind === 'caption'); const { createItem } = await import('/app/core/project.js'); ed.apply(ed.ops.addItems([{ trackId: tr.id, item: createItem('caption', { t0: 0, in: 0, out: ed.duration(), x: 0, y: 0, w: ed.project.canvas.w, h: ed.project.canvas.h }) }])); }
    return { dur: ed.duration(), clips: ed.project.tracks.find((x) => x.kind === 'video').items.length, music: ed.project.tracks.find((x) => x.kind === 'music').items.length, lines: ed.project.captions.lines.length, beats: (await window.TH.beatsOf(window.CTX)).length };
  }, { GERMAN });
  ok(setup.clips === 2 && setup.music > 0 && setup.lines > 0 && setup.beats > 4, `test reel: ${setup.clips} clips (one cut), music with ${setup.beats} beats, ${setup.lines} caption lines, ${setup.dur.toFixed(1)} s`);

  // ── the Themes tab is there ──
  const tabs = await ev(() => [...document.querySelectorAll('.side-tab')].map((b) => b.textContent));
  ok(tabs.some((x) => /Themes/.test(x)), `right column has the ✨ Themes tab (${tabs.join(' · ')})`);
  const nThemes = await ev(() => window.TH.THEMES.length);
  const groups = await ev(() => Object.fromEntries(window.TH.GROUPS.map((g) => [g, window.TH.THEMES.filter((t) => t.group === g).length])));
  numbers.themes = { count: nThemes, groups };
  ok(nThemes >= 30, `${nThemes} themes (≥ 30): ${Object.entries(groups).map(([g, n]) => g + ' ' + n).join(', ')}`);

  // ── suggestions ──
  const sg = await ev(({ GERMAN, KBB }) => ({ de: window.TH.suggestThemes({ text: GERMAN, workspace: { id: 'planet-careers', industries: ['immigration'] } }), kbb: window.TH.suggestThemes({ text: KBB, workspace: { id: 'kbb', industries: ['beauty'] } }), live: window.TH.suggest(window.CTX) }), { GERMAN, KBB });
  numbers.suggest = sg;
  ok(['europe', 'country-de'].includes(sg.de[0]?.id) && sg.de.some((x) => x.id === 'europe'), `German study-visa transcript → ${sg.de.map((x) => x.id + ' ' + x.score).join(', ')} (Europe/Germany first, Europe in the top 3)`);
  ok(sg.kbb[0]?.group === undefined && /^beauty-/.test(sg.kbb[0]?.id), `KBB transcript → ${sg.kbb.map((x) => x.id + ' ' + x.score).join(', ')} (petals/beauty first)`);
  ok(['europe', 'country-de'].includes(sg.live[0]?.id), `in the editor, the reel's own captions suggest ${sg.live.map((x) => x.id).join(', ')}`);

  // ── apply = one undo step ──
  const undo = await ev(async ({ FACE }) => {
    const ed = window.kbbEditor, n0 = ed.project.tracks.reduce((s, t) => s + t.items.length, 0), u0 = ed.history.undoStack.length;
    const r = await window.TH.apply(window.CTX, 'europe', { faces: [FACE] });
    const n1 = ed.project.tracks.reduce((s, t) => s + t.items.length, 0), u1 = ed.history.undoStack.length;
    ed.history.undo(); const n2 = ed.project.tracks.reduce((s, t) => s + t.items.length, 0);
    ed.history.redo(); const n3 = ed.project.tracks.reduce((s, t) => s + t.items.length, 0);
    const r2 = await window.TH.apply(window.CTX, 'country-de', { faces: [FACE] }); const n4 = ed.project.tracks.reduce((s, t) => s + t.items.length, 0);
    return { r, n0, n1, n2, n3, n4, steps: u1 - u0, types: ed.project.tracks.flatMap((t) => t.items.filter((i) => i.auto === 'theme').map((i) => i.type)) };
  }, { FACE });
  numbers.apply = undo;
  ok(undo.steps === 1 && undo.n2 === undo.n0 && undo.n3 === undo.n1 && undo.n1 > undo.n0, `apply Europe = ONE undo step: +${undo.n1 - undo.n0} items (overlay, sting, outro, ${undo.r.transitions} transition, title, ${undo.r.sfx} sounds); undo → ${undo.n2} items, redo → ${undo.n3}`);
  ok(undo.n4 === undo.n1 - 0 || undo.n4 > undo.n0, `applying a second theme replaces the first (${undo.types.filter((x) => x === 'theme.overlay').length} overlay on the reel)`);
  ok(undo.types.filter((x) => x === 'theme.overlay').length === 1 && undo.types.includes('theme.sting') && undo.types.includes('theme.outro') && undo.types.includes('auto.transition') && undo.types.includes('look.text') && undo.types.includes('audio'), `a theme places: ${[...new Set(undo.types)].join(', ')}`);

  // ── the theme follows the reel: trim the tail, the overlay ends at the new reel end (and the outro closes it); undo
  //    brings both back, redo cuts them again. Without the project-change hook in themes/index.js the overlay keeps its
  //    old end (and holds the reel open) until the next panel tweak.
  const follow = await ev(() => {
    const ed = window.kbbEditor, p = ed.project, TAG = 'theme', all = () => p.tracks.flatMap((t) => t.items);
    const end = (i) => +(i.t0 + (i.out - i.in) / (i.speed || 1)).toFixed(3);
    const reel = () => Math.max(...all().filter((i) => i.auto !== TAG).map(end));
    const snap = () => ({ reel: reel(), ov: end(all().find((i) => i.type === 'theme.overlay')), outro: end(all().find((i) => i.type === 'theme.outro')), dur: +ed.duration().toFixed(3) });
    const s0 = snap(), cut = 1.5;
    // a trim: everything that runs to the reel's end (the last clip, the music, the caption item) loses 1.5 s at its tail
    const list = all().filter((i) => i.auto !== TAG && Math.abs(end(i) - s0.reel) < 0.05).map((i) => ({ id: i.id, props: { out: +(i.out - cut * (i.speed || 1)).toFixed(3) } }));
    ed.history.apply(ed.ops.setItems(list, 'Trim')); ed.history.seal();
    const s1 = snap(); ed.history.undo(); const s2 = snap(); ed.history.redo(); const s3 = snap(); ed.history.undo();
    return { n: list.length, s0, s1, s2, s3 };
  });
  numbers.follow = follow;
  const fits = (s) => Math.abs(s.ov - s.reel) < 0.002 && Math.abs(s.outro - s.reel) < 0.002 && Math.abs(s.dur - s.reel) < 0.002;
  ok(follow.n > 0 && fits(follow.s0) && fits(follow.s1) && follow.s1.reel < follow.s0.reel - 1.4, `trim ${follow.n} items by 1.5 s: reel ${follow.s0.reel} → ${follow.s1.reel} s, theme overlay ends at ${follow.s1.ov} s, outro at ${follow.s1.outro} s, reel length ${follow.s1.dur} s`);
  ok(fits(follow.s2) && Math.abs(follow.s2.reel - follow.s0.reel) < 0.002 && fits(follow.s3) && Math.abs(follow.s3.reel - follow.s1.reel) < 0.002, `undo → overlay ends at ${follow.s2.ov} s = reel ${follow.s2.reel} s; redo → ${follow.s3.ov} s = reel ${follow.s3.reel} s`);

  // ── intensity and colours: subtle < normal < bold; brand kit colours replace the theme's ──
  const knobs = await ev(async ({ FACE }) => {
    const ed = window.kbbEditor, D = await import('/app/themes/draw.js');
    await window.TH.apply(window.CTX, 'beauty-petals', { faces: [FACE], sfx: false });
    const sum = () => { const p = ed.project, ov = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay'); let s = 0; for (const t of [1, 2, 3, 4]) { const L = D.renderThemeLayer(p, ov, t, 216, 384, new DOMMatrix([0.2, 0, 0, 0.2, 0, 0])); const d = window.__px(L.canvas, 0, 0, 216, 384); for (let i = 3; i < d.length; i += 4) s += d[i]; } return s; };
    const out = {};
    for (const k of ['subtle', 'normal', 'bold']) { window.TH.tune(window.CTX, { intensity: k }); out[k] = sum(); }
    window.TH.tune(window.CTX, { colorMode: 'brand' });
    const ov = ed.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay');
    out.brand = ov.style.colors.primary; out.ws = window.CTX.workspace.colors.primary;
    return out;
  }, { FACE });
  numbers.knobs = knobs;
  ok(knobs.subtle < knobs.normal && knobs.normal < knobs.bold, `intensity: overlay coverage subtle ${knobs.subtle} < normal ${knobs.normal} < bold ${knobs.bold}`);
  ok(knobs.brand === knobs.ws, `colours → Brand kit: the theme draws in the company's primary ${knobs.brand}`);

  // ── every theme × aspect: applies, renders 3 frames, stays off the face and the captions ──
  const aspects = ['9:16', '1:1', '16:9'];
  const all = { renders: 0, failedApply: [], overlap: [], maxAlphaIn: 0, frameMs: [], sheets: [] };
  for (const aspect of aspects) {
    const r = await ev(async ({ aspect, FACE, quick, sheet }) => {
      const ed = window.kbbEditor, R = await import('/app/core/render.js'), D = await import('/app/themes/draw.js');
      ed.setAspect(aspect); await new Promise((res) => setTimeout(res, 50));
      const p = ed.project, W = p.canvas.w, H = p.canvas.h, sc = 0.2, w = Math.round(W * sc), hh = Math.round(H * sc);
      const v = p.tracks.find((t) => t.kind === 'video').items[0];
      const bm = await ed.media.frameAt(v.media, 1.2, 540);
      const src = (item) => (item.type === 'clip' ? bm : null);
      const cv = document.createElement('canvas'); cv.width = w; cv.height = hh; const g = cv.getContext('2d');
      const out = { renders: 0, failedApply: [], overlap: [], maxAlphaIn: 0, frameMs: [], sheet: null };
      const list = quick ? window.TH.THEMES.filter((_, i) => i % 6 === 0) : window.TH.THEMES;
      const times = [1.6, 3.1, 6.3];
      const tw = 120, th = Math.round(tw * H / W), cols = 6, perSheet = 10;
      const sheets = [];
      for (let k = 0; k < list.length; k++) {
        const T = list[k];
        try { await window.TH.apply(window.CTX, T.id, { faces: [FACE], sfx: false }); } catch (e) { out.failedApply.push(T.id + ': ' + e.message); continue; }
        const ov = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay');
        await D.loadAssets(ov.style); await R.prepareRender(p);
        if (sheet && (k % perSheet === 0)) { const s = document.createElement('canvas'); s.width = cols * tw; s.height = Math.ceil(perSheet / 2) * (th + 22); const sg2 = s.getContext('2d'); sg2.fillStyle = '#111'; sg2.fillRect(0, 0, s.width, s.height); sheets.push(s); }
        for (let j = 0; j < times.length; j++) {
          const t = times[j], a = performance.now();
          R.renderFrame(g, p, t, { scale: sc, source: src });
          out.frameMs.push(performance.now() - a); out.renders++;
          // safe check on the overlay's own layer: alpha inside the face box and every caption box
          const L = D.renderThemeLayer(p, ov, t, w, hh, new DOMMatrix([sc, 0, 0, sc, 0, 0]));
          const boxes = [{ kind: 'face', x: (FACE.x - FACE.w / 2) * W, y: (FACE.y - FACE.h / 2) * H, w: FACE.w * W, h: FACE.h * H }, ...L.boxes.filter((b) => b.kind === 'caption')];
          for (const b of boxes) {
            const x0 = Math.max(0, Math.floor(b.x * sc)), y0 = Math.max(0, Math.floor(b.y * sc)), x1 = Math.min(w, Math.ceil((b.x + b.w) * sc)), y1 = Math.min(hh, Math.ceil((b.y + b.h) * sc));
            if (x1 <= x0 || y1 <= y0) continue;
            const d = window.__px(L.canvas, x0, y0, x1 - x0, y1 - y0); let mx = 0; for (let i = 3; i < d.length; i += 4) if (d[i] > mx) mx = d[i];
            out.maxAlphaIn = Math.max(out.maxAlphaIn, mx);
            if (mx > 2) out.overlap.push(`${T.id} ${b.kind} t=${t} alpha ${mx}`);
          }
          if (sheet) { const s = sheets[sheets.length - 1], slot = k % perSheet, cx = ((slot % 2) * 3 + j) * tw, cy = Math.floor(slot / 2) * (th + 22); const sg2 = s.getContext('2d'); sg2.drawImage(cv, cx, cy + 20, tw, th); if (j === 0) { sg2.fillStyle = '#fff'; sg2.font = '600 13px sans-serif'; sg2.fillText(T.name, cx + 4, cy + 15); } }
        }
      }
      out.sheet = sheets.map((s) => s.toDataURL('image/jpeg', 0.86));
      return out;
    }, { aspect, FACE, quick: QUICK, sheet: false });
    all.renders += r.renders; all.failedApply.push(...r.failedApply); all.overlap.push(...r.overlap); all.maxAlphaIn = Math.max(all.maxAlphaIn, r.maxAlphaIn); all.frameMs.push(...r.frameMs);
    ok(!r.failedApply.length, `${aspect}: every theme applied (${r.renders / 3} themes${r.failedApply.length ? '; failed: ' + r.failedApply.join('; ') : ''})`);
  }
  const fm = all.frameMs.slice().sort((a, b) => a - b);
  numbers.render = { renders: all.renders, medianMs: +fm[Math.floor(fm.length / 2)].toFixed(2), p95Ms: +fm[Math.floor(fm.length * 0.95)].toFixed(2), scale: 0.2 };
  ok(all.renders >= nThemes * 9 * (QUICK ? 0 : 1) && !warns.length && !errors.length, `${all.renders} frames rendered (3 per theme × 3 aspects) with no console error or render warning${warns.length ? ': ' + warns.slice(0, 3).join(' | ') : ''}${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}; median ${numbers.render.medianMs} ms / p95 ${numbers.render.p95Ms} ms per frame at 0.2×`);
  ok(!all.overlap.length, `face box + caption boxes stay clear in every theme, frame and aspect (max alpha inside ${all.maxAlphaIn}/255${all.overlap.length ? '; ' + all.overlap.slice(0, 4).join('; ') : ''})`);
  // contact sheets: every theme on the sample clip (a talking head; its face stays clear), 3 frames each
  const sheets = await ev(async ({ quick }) => {
    const Hv = await import('/app/themes/hover.js'), list = quick ? window.TH.THEMES.filter((_, i) => i % 6 === 0) : window.TH.THEMES;
    const tw = 180, th = 320, perSheet = 10, out = [];
    let s = null, g = null;
    const cv = document.createElement('canvas'); cv.width = tw; cv.height = th; const cg = cv.getContext('2d');
    for (let k = 0; k < list.length; k++) {
      if (k % perSheet === 0) { if (s) out.push(s.toDataURL('image/jpeg', 0.86)); s = document.createElement('canvas'); s.width = tw * 6; s.height = Math.ceil(Math.min(perSheet, list.length - k) / 2) * (th + 24); g = s.getContext('2d'); g.fillStyle = '#111'; g.fillRect(0, 0, s.width, s.height); }
      const spec = Hv.themeSpec(list[k].id, { opts: { sting: false } }); await spec.prepare();
      [0.9, 2.1, 3.3].forEach((t, j) => { spec.draw(cg, tw, th, t); const slot = k % perSheet, x = ((slot % 2) * 3 + j) * tw, y = Math.floor(slot / 2) * (th + 24); g.drawImage(cv, x, y + 24); if (!j) { g.fillStyle = '#fff'; g.font = '600 15px sans-serif'; g.fillText(`${list[k].icon || ''} ${list[k].name}  ·  ${list[k].group}`, x + 6, y + 17); } });
    }
    if (s) out.push(s.toDataURL('image/jpeg', 0.86));
    return out;
  }, { quick: QUICK });
  for (const f of fs.readdirSync(SHOTS).filter((f) => /^themes-\d+\.jpg$/.test(f))) fs.rmSync(path.join(SHOTS, f));
  sheets.forEach((d, i) => { const f = path.join(SHOTS, `themes-${String(i + 1).padStart(2, '0')}.jpg`); fs.writeFileSync(f, Buffer.from(d.split(',')[1], 'base64')); all.sheets.push(path.basename(f)); });
  ok(all.sheets.length >= 1, `contact sheets (3 frames per theme on the sample clip): ${all.sheets.join(', ')}`);

  // ── beats: pulses align with the beat grid ±1 frame ──
  const beat = await ev(async ({ FACE }) => {
    const ed = window.kbbEditor, D = await import('/app/themes/draw.js'); ed.setAspect('9:16');
    const out = [];
    for (const id of ['europe', 'beauty-petals', 'offers', 'fitness']) {
      await window.TH.apply(window.CTX, id, { faces: [FACE], sfx: false, beat: true });
      const p = ed.project, ov = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay'), fps = 30, sc = 0.15, W = p.canvas.w, H = p.canvas.h, w = Math.round(W * sc), h = Math.round(H * sc);
      const beats = ov.style.beats.filter((b) => b > 0.5 && b < ed.duration() - 0.5).slice(0, 8);
      for (const b of beats) {
        const f0 = Math.round(b * fps), vals = [];
        for (let f = f0 - 4; f <= f0 + 4; f++) {
          const L = D.renderThemeLayer(p, ov, f / fps, w, h, new DOMMatrix([sc, 0, 0, sc, 0, 0])), d = window.__px(L.canvas, 0, Math.round(h * 0.3), Math.round(w * 0.04), Math.round(h * 0.4));
          let s = 0; for (let i = 3; i < d.length; i += 4) s += d[i]; vals.push({ f, s });
        }
        const peak = vals.reduce((a, c) => (c.s > a.s ? c : a), vals[0]);
        out.push({ id, beat: b, beatFrame: +(b * fps).toFixed(2), peakFrame: peak.f, off: +(peak.f - b * fps).toFixed(2), contrast: +(peak.s / Math.max(1, Math.min(...vals.map((x) => x.s)))).toFixed(2) });
      }
    }
    return out;
  }, { FACE });
  numbers.beats = beat;
  const offs = beat.map((x) => Math.abs(x.off));
  ok(beat.length >= 12 && offs.every((o) => o <= 1), `beat pulses: ${beat.length} beats in 4 themes, peak frame within ±1 frame of the beat (max |offset| ${Math.max(...offs).toFixed(2)} frames, pulse contrast ×${Math.min(...beat.map((x) => x.contrast))}–×${Math.max(...beat.map((x) => x.contrast))})`);

  // ── preview = export ──
  const pv = await ev(async ({ FACE }) => {
    const ed = window.kbbEditor; ed.setAspect('9:16');
    await window.TH.apply(window.CTX, 'country-de', { faces: [FACE] });
    const E = await import('/app/core/export.js'), p = ed.project;
    const r = await E.exportMp4(p, ed.media, { quality: 'high', width: 540, height: 960, duration: 4 });
    const file = `workspaces/${p.workspace}/exports/th-preview-vs-export.mp4`; await ed.engine.writeFile(file, r.blob);
    return { file, note: r.note };
  }, { FACE });
  const out = path.join(STUDIO, pv.file);
  const pdiffs = [];
  for (const t of [0.5, 1.5, 2.6]) {
    await ev((t) => window.kbbEditor.seek(t), t);
    await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(500);
    const fr = await ev(() => { const c = window.kbbEditor.preview.canvas, d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data, rgb = new Uint8Array(c.width * c.height * 3); for (let i = 0, j = 0; i < d.length; i += 4) { rgb[j++] = d[i]; rgb[j++] = d[i + 1]; rgb[j++] = d[i + 2]; } return { w: c.width, h: c.height, data: Array.from(rgb) }; });
    pdiffs.push({ t, w: fr.w, h: fr.h, diff: +meanDiff(Uint8Array.from(fr.data), frameRGB(out, t, fr.w, fr.h)).toFixed(2) });
  }
  const base = await ev(async () => { const ed = window.kbbEditor; window.TH.remove(window.CTX); const E = await import('/app/core/export.js'), p = ed.project; const r = await E.exportMp4(p, ed.media, { quality: 'high', width: 540, height: 960, duration: 4 }); const file = `workspaces/${p.workspace}/exports/th-baseline.mp4`; await ed.engine.writeFile(file, r.blob); return file; });
  const bdiffs = [];
  for (const t of [0.5, 1.5, 2.6]) {
    await ev((t) => window.kbbEditor.seek(t), t);
    await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(500);
    const fr = await ev(() => { const c = window.kbbEditor.preview.canvas, d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data, rgb = new Uint8Array(c.width * c.height * 3); for (let i = 0, j = 0; i < d.length; i += 4) { rgb[j++] = d[i]; rgb[j++] = d[i + 1]; rgb[j++] = d[i + 2]; } return { w: c.width, h: c.height, data: Array.from(rgb) }; });
    bdiffs.push({ t, diff: +meanDiff(Uint8Array.from(fr.data), frameRGB(path.join(STUDIO, base), t, fr.w, fr.h)).toFixed(2) });
  }
  numbers.previewVsExport = { note: pv.note, frames: pdiffs, withoutTheme: bdiffs };
  ok(pdiffs.every((x) => x.diff < 6), `preview frame vs export frame (Germany theme + sting + title + transition): ${pdiffs.map((x) => `${x.t} s ${x.diff}/255`).join(', ')} (< 6) at ${pdiffs[0].w}×${pdiffs[0].h}; the same reel without the theme: ${bdiffs.map((x) => x.diff).join(', ')}`);

  // ── the Themes tab (with Germany on the reel) ──
  const titleClear = await ev(async ({ FACE }) => {
    await window.TH.apply(window.CTX, 'country-de', { faces: [FACE] }); window.kbbEditor.seek(2.4);
    const p = window.kbbEditor.project, W = p.canvas.w, H = p.canvas.h, R = await import('/app/captions/render.js');
    const it = p.tracks.flatMap((t) => t.items).find((i) => i.auto === 'theme' && i.type === 'look.text'), cap = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'caption');
    const g = document.createElement('canvas').getContext('2d'); let area = 0;
    for (let t = it.t0; t < it.t0 + it.out - it.in; t += 0.1) {
      const boxes = [{ x: (FACE.x - FACE.w / 2) * W, y: (FACE.y - FACE.h / 2) * H, w: FACE.w * W, h: FACE.h * H }, ...R.captionBlocks(g, p, t, cap).map((b) => b.box)];
      for (const o of boxes) area += Math.max(0, Math.min(o.x + o.w, it.x + it.w) - Math.max(o.x, it.x)) * Math.max(0, Math.min(o.y + o.h, it.y + it.h) - Math.max(o.y, it.y));
    }
    return { y: it.y / H, h: it.h / H, area };
  }, { FACE });
  ok(titleClear.area === 0, `the themed title is placed clear of the face and the captions for its whole duration (top ${titleClear.y.toFixed(2)}·H, overlap ${titleClear.area} px²)`);
  // what a theme costs in a real 1080×1920 export: ms per exported frame with the overlay vs the same reel without it
  const cost = await ev(async ({ FACE }) => {
    const ed = window.kbbEditor, E = await import('/app/core/export.js'), out = {};
    const run = async () => { const r = await E.exportMp4(ed.project, ed.media, { quality: 'standard', duration: 2 }); return (r.seconds * 1000) / r.frames; };
    const keep = window.TH.overlayOf(ed.project)?.style.theme;
    window.TH.remove(window.CTX); out.none = +(await run()).toFixed(1);
    for (const id of ['europe', 'country-gb', 'new-year', 'beauty-petals']) {
      await window.TH.apply(window.CTX, id, { faces: [FACE], sfx: false, sting: false, outro: false, transitions: false, title: false });
      out[id] = +(await run()).toFixed(1);
    }
    if (keep) await window.TH.apply(window.CTX, keep, { faces: [FACE] });
    return out;
  }, { FACE });
  numbers.exportMsPerFrame = cost;
  const extra = Object.entries(cost).filter(([k]) => k !== 'none').map(([k, v]) => [k, +(v - cost.none).toFixed(1)]);
  ok(extra.every(([, v]) => v < cost.none * 0.6), `1080×1920 export cost: ${cost.none} ms/frame without a theme; a theme adds ${extra.map(([k, v]) => k + ' +' + v).join(', ')} ms/frame (budget: < 60 % of a plain frame; measured on a software GPU shared with other jobs — a Mac's GPU is far faster)`);
  await page.click('.side-tab[data-side="mod:themes"]');
  await page.waitForSelector('.th-grid .th-tile', { timeout: 30000 });
  await page.waitForTimeout(1500);
  await shot('01-themes-tab');
  await shot('01b-themes-elements', async () => { await page.locator('.th-card[data-applied]').scrollIntoViewIfNeeded(); await page.waitForTimeout(300); });
  const elRows = await page.locator('.th-card[data-applied] .th-el').count();
  // tune one element from the panel: one coalesced undo step, preview follows
  const tuned = await ev(async () => { const ed = window.kbbEditor, u0 = ed.history.undoStack.length; const cb = document.querySelector('.th-el input[type=checkbox]'); cb.click(); await new Promise((r) => setTimeout(r, 50)); const ov = ed.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay'); const off = ov.style.layers[cb.dataset.layer]?.on === false; ed.history.undo(); const ov2 = ed.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay'); return { layer: cb.dataset.layer, off, steps: ed.history.undoStack.length - u0 + 1, back: ov2.style.layers[cb.dataset.layer]?.on !== false }; });
  ok(elRows >= 4 && tuned.off && tuned.back, `per-element controls: ${elRows} elements (on/off, density, speed, colour); hiding “${tuned.layer}” is one undoable step and ⌘Z brings it back`);
  const tiles = await page.locator('.th-grid .th-tile').count();
  ok(tiles === nThemes, `the gallery shows all ${tiles} themes, suggestions on top (${await ev(() => [...document.querySelectorAll('.th-sugg .th-tile')].map((b) => b.dataset.theme).join(', '))})`);

  // ── hover previews ──
  const hoverTest = async (label, sel, { audio = false, n = 3 } = {}) => {
    const els = page.locator(sel); const count = await els.count(); const res = { label, count, lat: [], stopped: true, single: true, moving: true };
    for (let i = 0; i < Math.min(n, count); i++) {
      const el = els.nth(i); await el.scrollIntoViewIfNeeded();
      await ev(() => { window.__kbbHover.starts.length = 0; });
      await el.hover(); await page.waitForTimeout(audio ? 700 : 450);
      const st = await ev(() => ({ active: window.__kbbHover.active, lat: window.__kbbHover.starts.at(-1)?.latency, on: document.querySelectorAll('.hp-on').length, aud: (() => { const a = [...document.querySelectorAll('audio')].concat(window.__hpAudio || []); return null; })() }));
      res.lat.push(st.lat ?? 9999); if (st.on > 1) res.single = false; if (!st.active) res.moving = false;
    }
    await page.mouse.move(5, 1075); await page.waitForTimeout(250);
    const after = await ev(() => ({ active: window.__kbbHover.active, on: document.querySelectorAll('.hp-on').length }));
    res.stopped = !after.active && after.on === 0;
    return res;
  };
  const hv = {};
  // themes: also check the tile actually animates (two grabs differ)
  const t0el = page.locator('.th-grid .th-tile').nth(4); await t0el.hover(); await page.waitForTimeout(300);
  const a1 = await ev(() => document.querySelectorAll('.th-grid .th-tile')[4].querySelector('canvas').toDataURL()); await page.waitForTimeout(400);
  const a2 = await ev(() => document.querySelectorAll('.th-grid .th-tile')[4].querySelector('canvas').toDataURL());
  await shot('02-hover-theme', async () => { await page.locator('.th-grid .th-tile').nth(4).hover(); await page.waitForTimeout(500); });
  hv.themes = await hoverTest('Themes gallery', '.th-grid .th-tile', { n: 5 });
  hv.themes.animates = a1 !== a2;
  // a sweep across 20 tiles at mouse speed: no request storm, no pile-up
  const tileBoxes = await ev(() => [...document.querySelectorAll('.th-grid .th-tile')].slice(0, 24).map((b) => { const r = b.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; }));
  const rq0 = reqLog.length;
  for (const [x, y] of tileBoxes) { await page.mouse.move(x, y, { steps: 2 }); await page.waitForTimeout(25); }
  await page.waitForTimeout(300); const sweepReq = reqLog.length - rq0, sweepStarts = await ev(() => window.__kbbHover.starts.length);
  await page.mouse.move(5, 1075);
  numbers.sweep = { tiles: tileBoxes.length, requests: sweepReq, starts: sweepStarts };
  // templates (left panel)
  await page.click('.bin-tab:has-text("Templates")'); await page.waitForSelector('.recipe-row');
  hv.templates = await hoverTest('Templates (recipes)', '.recipe-row', { n: 3 });
  await shot('03-hover-template', async () => { await page.locator('.recipe-row').nth(1).hover(); await page.waitForTimeout(900); });
  // music + sfx (audio)
  await page.click('.bin-tab:has-text("Music")'); await page.waitForSelector('.lib-row');
  await page.click('[data-hp-toggle="sound"]');
  hv.music = await hoverTest('Music', '.lib-row', { audio: true, n: 2 });
  const audioState = await ev(async () => { const a = document.querySelector('.lib-row'); a.dispatchEvent(new PointerEvent('pointerenter', { pointerType: 'mouse' })); await new Promise((r) => setTimeout(r, 900)); const s = window.__kbbHover.active; a.dispatchEvent(new PointerEvent('pointerleave', { pointerType: 'mouse' })); return s; });
  await shot('04-hover-music', async () => { await page.locator('.lib-row').nth(2).hover(); await page.waitForTimeout(500); });
  await page.click('.bin-tab:has-text("SFX")'); await page.waitForSelector('.lib-row');
  hv.sfx = await hoverTest('SFX', '.lib-row', { audio: true, n: 2 });
  // look tiles + transition chips
  await page.click('.side-tab[data-side="mod:look"]'); await page.waitForSelector('.lk-tile');
  hv.looks = await hoverTest('Colour looks', '.lk-tile', { n: 3 });
  hv.transitions = await hoverTest('Transitions', '.lk-chips .chip[data-trans]', { n: 3 });
  await shot('05-hover-transition', async () => { const c = page.locator('.lk-chips .chip[data-trans]').nth(2); await c.scrollIntoViewIfNeeded(); await c.hover(); await page.waitForTimeout(1300); });
  // Create → formats
  await page.click('.side-tab[data-side="mod:create"]'); await page.waitForSelector('[data-cr="formats"] select');
  hv.formats = await hoverTest('Create → formats', '[data-cr="formats"] select', { n: 1 });
  await shot('06-hover-format', async () => { const s = page.locator('[data-cr="formats"] select'); await s.scrollIntoViewIfNeeded(); await s.hover(); await page.waitForTimeout(1200); });
  // keyboard: focus plays it
  await page.click('.side-tab[data-side="mod:themes"]'); await page.waitForSelector('.th-grid .th-tile');
  await ev(() => document.querySelectorAll('.th-grid .th-tile')[2].focus()); await page.waitForTimeout(200);
  const focusPlay = await ev(() => window.__kbbHover.active);
  await ev(() => document.activeElement.blur()); await page.waitForTimeout(100);
  const focusStop = await ev(() => window.__kbbHover.active);
  // frames actually shown per second while one theme tile is hovered for 2 s
  await page.click('.side-tab[data-side="mod:themes"]'); await page.waitForSelector('.th-grid .th-tile');
  const tile = page.locator('.th-grid .th-tile').nth(1); await tile.scrollIntoViewIfNeeded(); await page.mouse.move(5, 1075); await page.waitForTimeout(300);
  const idleFps = await ev(async () => { let raf = 0; const t0 = performance.now(); const tick = () => { raf++; if (performance.now() - t0 < 2000) requestAnimationFrame(tick); }; requestAnimationFrame(tick); await new Promise((r) => setTimeout(r, 2050)); return +(raf / 2).toFixed(1); });
  await tile.hover(); await page.waitForTimeout(300);
  const forced = await ev(async () => { const a0 = window.__kbbHover.active, f0 = window.__kbbHover.frames, t0 = performance.now(); let raf = 0; const tick = () => { raf++; if (performance.now() - t0 < 2000) requestAnimationFrame(tick); }; requestAnimationFrame(tick); await new Promise((r) => setTimeout(r, 2050)); return { fps: +((window.__kbbHover.frames - f0) / ((performance.now() - t0) / 1000)).toFixed(1), pageFps: +(raf / 2).toFixed(1), still: window.__kbbHover.active === a0 && !!a0 }; });
  await page.mouse.move(5, 1075);
  const cpu = await ev(() => ({ frameMs: +window.__kbbHover.frameMs.toFixed(2), maxFrameMs: +window.__kbbHover.maxFrameMs.toFixed(2), frames: window.__kbbHover.frames }));
  Object.assign(cpu, forced, { idleFps });
  numbers.hover = { ...Object.fromEntries(Object.entries(hv).map(([k, v]) => [k, { ...v, lat: v.lat.map((x) => +(+x).toFixed(1)) }])), cpu, audioState, focusPlay, focusStop };
  for (const [k, v] of Object.entries(hv)) ok(v.count > 0 && v.lat.every((x) => x <= 300) && v.stopped && v.single && v.moving, `hover ${v.label}: ${v.count} tiles; started in ${v.lat.map((x) => Math.round(x) + ' ms').join(', ')} (≤ 300 ms), one at a time, stops on leave${v.animates === undefined ? '' : v.animates ? ', the tile animates' : ', DID NOT ANIMATE'}`);
  ok(hv.themes.animates, 'a hovered theme tile animates (two grabs 400 ms apart differ)');
  ok(sweepReq <= 12 && sweepStarts <= tileBoxes.length, `sweeping the mouse across ${tileBoxes.length} tiles: ${sweepReq} requests, ${sweepStarts} previews started (one at a time, no storm)`);
  ok(/^audio:/.test(audioState || ''), `music hover plays an audio preview (${audioState}); sound is off until 🔊 is pressed`);
  ok(/^theme:/.test(focusPlay || '') && !focusStop, `keyboard: focusing a tile plays it (${focusPlay}), blur stops it`);
  ok(cpu.frameMs < 40 && cpu.still && cpu.fps >= cpu.pageFps * 0.9 && cpu.pageFps >= 15, `hover preview CPU: ${cpu.frameMs} ms of script per frame on average (worst ${cpu.maxFrameMs} ms: a GPU look's first frame), ${cpu.fps} frames/s delivered on a hovered theme tile (the page itself ran at ${cpu.pageFps} frames/s while hovering, ${cpu.idleFps} without); ${cpu.frames} frames in all (SwiftShader, one preview at a time)`);

  // ── hygiene ──
  ok(!errors.length, `no console errors (${errors.length}${errors.length ? ': ' + errors.slice(0, 4).join(' | ') : ''})`);
  ok(!outside.length, `no request left 127.0.0.1 (${outside.length})`);
  numbers.seconds = Math.round((Date.now() - T0) / 1000);
  fs.writeFileSync(path.join(SHOTS, 'results.txt'), results.join('\n') + '\n\n' + JSON.stringify(numbers, null, 1) + '\n');
  console.log(JSON.stringify({ render: numbers.render, sweep: numbers.sweep, cpu: numbers.hover?.cpu, previewVsExport: numbers.previewVsExport?.frames }, null, 0));
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASSED', `(${numbers.seconds} s)`);
  await browser.close(); await H.stopEngine(e);
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
