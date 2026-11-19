#!/usr/bin/env node
// KBB Studio — TEXT BEHIND THE HOST check (19 Nov 2026). Headless Chromium against the Linux engine, in the real editor.
//
// Test media: the drawn presenter of the background-removal check (tools/bg-check.cjs WORLD_JS: a person-shaped
// presenter on a busy moving background, with its exact ground-truth silhouette). It is synthetic, but the person is
// found by the REAL MediaPipe selfie segmenter, offline, exactly as for a filmed clip.
//
//   behind    the word is drawn; where the person stands (truth, eroded) the frame shows the PERSON (≈ the frame
//             without the element); where the word is and the person is not (truth, dilated) it shows the TEXT; the
//             real background is untouched outside the word
//   two       a two-line "JOBS IN / EUROPE": both lines drawn, both behind the person
//   move      moving the element moves the word by the same px; resizing it changes the word's width; behind the head
//   carousel  a two-word carousel shows word 1, then word 2 at the right times (matched against single-word renders);
//             "Select all Europe" in the country picker fills the list (UI)
//   auto      "Text behind me for every country I mention": EUROPE and AUSTRALIA at their sentences' times, ONE undo
//             step; a caption edit (Australia → Canada) re-derives it in the same step as the edit; "JOBS IN above"
//   same      preview frame = export frame (mean difference) with the element and the carousel on
//   pending   before the person is found, the word still draws (no blank, no crash) and the status says so
//   keep      undo is one step per action; save + reload draws the same pixels
//   hygiene   no console errors, nothing leaves 127.0.0.1
//   shots     shots/textbehind/*.png at 1440×900
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/textbehind-check.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'textbehind'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'textbehind');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0; const results = [];
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const WORLD_JS = /const WORLD_JS = `([\s\S]*?)`;\n/.exec(fs.readFileSync(path.join(__dirname, 'bg-check.cjs'), 'utf8'))[1];
const FPS = 30, DUR = 4;

(async () => {
  const T0 = Date.now();
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings', 'adstudio']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const LIB = path.join(STUDIO, 'library', 'tb-test'); fs.mkdirSync(LIB, { recursive: true });
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(180000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/No available adapters|AudioContext was not allowed|net::ERR_ABORTED/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name) => { await page.waitForTimeout(400); await page.screenshot({ path: path.join(SHOTS, name + '.png') }); };
  const reveal = (sel) => ev((sel) => { const el = document.querySelector(sel); if (!el) return false; el.scrollIntoView({ block: 'start' }); let p = el.parentElement; while (p && p.scrollHeight <= p.clientHeight + 2) p = p.parentElement; if (p) p.scrollTop -= 70; return true; }, sel);

  // ── the presenter clip (4 s), drawn and encoded once ──
  await ev(WORLD_JS);
  const clip = path.join(OUT, 'presenter.mp4');
  if (!fs.existsSync(clip)) {
    const dir = path.join(OUT, 'frames'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
    for (let i = 0; i < DUR * FPS; i++) {
      const jpg = await ev((t) => { const c = document.createElement('canvas'); c.width = 540; c.height = 960; window.W.draw(c, 'presenter', t, 'rgb'); return c.toDataURL('image/jpeg', 0.93); }, i / FPS);
      fs.writeFileSync(path.join(dir, String(i).padStart(4, '0') + '.jpg'), Buffer.from(jpg.split(',')[1], 'base64'));
    }
    ff('-framerate', String(FPS), '-i', path.join(dir, '%04d.jpg'), '-f', 'lavfi', '-i', `sine=frequency=220:duration=${DUR}:sample_rate=48000`, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '16', '-pix_fmt', 'yuv420p', '-g', '15', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', clip);
    fs.rmSync(dir, { recursive: true, force: true });
  }
  fs.copyFileSync(clip, path.join(LIB, 'presenter.mp4'));
  await page.setInputFiles('.home-drop input[type=file]', path.join(LIB, 'presenter.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  const setup = await ev(async () => {
    const ed = window.kbbEditor; window.ED = ed; window.CTX = ed.context();
    window.TB = await import('/app/themes/textbehind.js'); window.TC = await import('/app/themes/textbehind-cmds.js');
    window.R = await import('/app/core/render.js'); window.E = await import('/app/core/export.js'); window.P = await import('/app/core/project.js'); window.V = await import('/app/look/vision.js');
    window.EL = await import('/app/themes/elements.js'); window.CD = await import('/app/themes/capsules-data.js');
    window.items = () => ed.project.tracks.flatMap((t) => t.items);
    window.find = (id) => window.items().find((i) => i.id === id);
    window.steps = () => ed.history.undoStack.length;
    /** the project at t through the export's own path (decoded frames), at `scale`; skip: item ids left out; alpha: transparent */
    window.RENDER = async (p, t, scale = 0.25, skip = null, transparent = false) => {
      const W = Math.round(p.canvas.w * scale), Hh = Math.round(p.canvas.h * scale), c = document.createElement('canvas'); c.width = W; c.height = Hh;
      const x = c.getContext('2d', { willReadFrequently: true });
      await window.R.prepareRender(p);
      const f = await window.E.openFrames(p, ed.media, scale);
      try { await f.load(t); window.R.renderFrame(x, p, t, { scale, source: f.source, skip: skip ? new Set(skip) : null, transparent }); } finally { f.close(); }
      return { W, H: Hh, d: x.getImageData(0, 0, W, Hh).data, url: () => c.toDataURL('image/png') };
    };
    /** only the element (transparent, the clip left out: nothing to cut out, so the plain words) -> alpha > 128 */
    window.TEXTMASK = async (id, t, scale = 0.25) => {
      const p = ed.project, clipIds = window.items().filter((i) => i.type === 'clip').map((i) => i.id), others = window.items().filter((i) => i.id !== id && i.type !== 'clip').map((i) => i.id);
      const r = await window.RENDER(p, t, scale, [...clipIds, ...others], true), m = new Uint8Array(r.W * r.H);
      for (let i = 0; i < m.length; i++) m[i] = r.d[i * 4 + 3] > 128 ? 1 : 0;
      return { W: r.W, H: r.H, m, rgba: r.d };
    };
    /** the ground-truth person at the render's size */
    window.TRUTH = (t, W, Hh) => { const c = document.createElement('canvas'); c.width = W; c.height = Hh; window.W.draw(c, 'presenter', t, 'mask'); const d = c.getContext('2d').getImageData(0, 0, W, Hh).data, m = new Uint8Array(W * Hh); for (let i = 0; i < m.length; i++) m[i] = d[i * 4] > 127 ? 1 : 0; return m; };
    window.MORPH = (m, W, Hh, r, grow) => { const o = new Uint8Array(m.length); for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) { let v = grow ? 0 : 1; for (let dy = -r; dy <= r && (grow ? !v : v); dy++) for (let dx = -r; dx <= r; dx++) { const yy = Math.min(Hh - 1, Math.max(0, y + dy)), xx = Math.min(W - 1, Math.max(0, x + dx)); if (grow ? m[yy * W + xx] : !m[yy * W + xx]) { v = grow ? 1 : 0; break; } } o[y * W + x] = v; } return o; };
    return { canvas: [ed.project.canvas.w, ed.project.canvas.h], dur: ed.duration(), ws: ed.workspace?.id };
  });
  ok(setup.dur > 3.9, `test reel: the drawn presenter, ${setup.dur.toFixed(2)} s on a ${setup.canvas.join('×')} canvas (${setup.ws})`);

  // ════════ pending: the word draws before the person is found ════════
  const pend = await ev(async () => {
    const ed = window.ED, s0 = window.steps();
    const it = window.TC.addTextBehind(window.CTX, { t0: 0.2, dur: 3.4, code: 'europe' });
    const s1 = window.steps();
    ed.seek(1.0); await new Promise((r) => setTimeout(r, 50));
    const st = window.TB.tbStatus(ed.project, window.find(it.id), 1.0);
    let threw = null; try { ed.preview.draw(); } catch (e) { threw = e.message; }
    const tm = await window.TEXTMASK(it.id, 1.0); let n = 0; for (const v of tm.m) n += v;
    return { id: it.id, st, threw, ink: n, steps: s1 - s0, name: window.find(it.id).name, word: window.TB.defaultWord(window.find(it.id).style), el: window.find(it.id).style.el };
  });
  ok(pend.el === 'tbehind' && pend.steps === 1 && pend.word === 'EUROPE', `🔤 Text behind me: one element, ONE undo step, default word ${pend.word} (${pend.name})`);
  ok(!pend.threw && pend.ink > 500 && /pending|ok/.test(pend.st), `before the person is found the word still draws (${pend.ink} px of ink at 270×480), status “${pend.st}”, no throw`);
  await page.waitForTimeout(300);
  await shot('pending-status-1440x900');
  const ID = pend.id; await ev((id) => { window.ID = id; }, ID);

  // ════════ behind: person in front, text where there is no person, background untouched ════════
  const an = await ev(async (id) => { const t = Date.now(); await window.V.ensureAnalysis(window.ED.project); return { ms: Date.now() - t, st: window.TB.tbStatus(window.ED.project, window.find(id), 1.0) }; }, ID);
  ok(an.st === 'ok', `the person is found offline (MediaPipe selfie segmenter, ${an.ms} ms for the stretch the element covers): status ${an.st}`);
  const place = await ev((id) => window.TC.placeTb(window.CTX, id, 'head'), ID);
  ok(!!place?.head, `🙂 Behind the head: head found at y ${place?.head ? Math.round(place.head.top) : '—'} px, element at y ${place?.y} px`);
  const measureBehind = async (id, t) => ev(async ([id, t]) => {
    const p = window.ED.project, withEl = await window.RENDER(p, t), base = await window.RENDER(p, t, 0.25, [id]), tm = await window.TEXTMASK(id, t);
    const W = withEl.W, Hh = withEl.H, truth = window.TRUTH(t, W, Hh), inner = window.MORPH(truth, W, Hh, 4, false), outer = window.MORPH(truth, W, Hh, 4, true);
    // "outside the word": beyond the words' ink box with room for their shadow (25 px at this size)
    let bx0 = 1e9, by0 = 1e9, bx1 = -1, by1 = -1; for (let i = 0; i < tm.m.length; i++) if (tm.m[i]) { const x = i % W, y = (i / W) | 0; bx0 = Math.min(bx0, x); by0 = Math.min(by0, y); bx1 = Math.max(bx1, x); by1 = Math.max(by1, y); }
    const inBox = (i) => { const x = i % W, y = (i / W) | 0; return x >= bx0 - 25 && x <= bx1 + 25 && y >= by0 - 25 && y <= by1 + 25; };
    let nP = 0, dP = 0, nT = 0, dT = 0, nB = 0, dB = 0;
    for (let i = 0; i < W * Hh; i++) {
      const d = (Math.abs(withEl.d[i * 4] - base.d[i * 4]) + Math.abs(withEl.d[i * 4 + 1] - base.d[i * 4 + 1]) + Math.abs(withEl.d[i * 4 + 2] - base.d[i * 4 + 2])) / 3;
      if (tm.m[i] && inner[i]) { nP++; dP += d; }                       // word ∩ person: shows the person
      else if (tm.m[i] && !outer[i]) {                                   // word, no person: shows the text
        nT++; dT += (Math.abs(withEl.d[i * 4] - tm.rgba[i * 4]) + Math.abs(withEl.d[i * 4 + 1] - tm.rgba[i * 4 + 1]) + Math.abs(withEl.d[i * 4 + 2] - tm.rgba[i * 4 + 2])) / 3;
      } else if (!inBox(i)) { nB++; dB += d; }                       // outside the word: the real background, untouched
    }
    return { nP, dP: +(dP / Math.max(1, nP)).toFixed(2), nT, dT: +(dT / Math.max(1, nT)).toFixed(2), nB, dB: +(dB / Math.max(1, nB)).toFixed(3), png: withEl.url() };
  }, [id, t]);
  const B = await measureBehind(ID, 1.0);
  fs.writeFileSync(path.join(OUT, 'behind-1.0s.png'), Buffer.from(B.png.split(',')[1], 'base64'));
  ok(B.nP > 400 && B.dP < 12, `word ∩ person (${B.nP} px): the PERSON shows, mean difference from the frame without the word ${B.dP}/255 (< 12)`);
  ok(B.nT > 1500 && B.dT < 20, `word outside the person (${B.nT} px): the TEXT shows, mean difference from the plain word ${B.dT}/255 (< 20)`);
  ok(B.nB > 50000 && B.dB < 0.5, `outside the word (${B.nB} px) the real background is untouched: mean difference ${B.dB}/255`);

  // ════════ two lines: JOBS IN / EUROPE, both behind the person ════════
  const two = await ev(async (id) => {
    window.TC.setTb(window.CTX, id, { top: { on: true, size: 0.34 } }, 'Top line on', false);
    window.ED.history.seal();
    return { top: window.find(id).style.tb.top.on };
  }, ID);
  const twoGrow = await ev(async (id) => { const { ops } = await import('/app/core/history.js'); const it = window.find(id); window.ED.apply(ops.setItems([{ id, props: { h: Math.round(it.h * 1.35), y: Math.round(it.y - it.h * 0.35) } }], 'Taller')); window.ED.history.seal(); return window.find(id).h; }, ID);
  const B2 = await measureBehind(ID, 1.0);
  const lines = await ev(async (id) => {
    const tm = await window.TEXTMASK(id, 1.0), rows = []; for (let y = 0; y < tm.H; y++) { let n = 0; for (let x = 0; x < tm.W; x++) n += tm.m[y * tm.W + x]; rows.push(n); }
    // ink bands: runs of rows with ink, separated by at least one empty row
    const bands = []; let b = null; rows.forEach((n, y) => { if (n > 0) { if (!b) b = [y, y]; else b[1] = y; } else if (b) { bands.push(b); b = null; } }); if (b) bands.push(b);
    return bands.filter((x) => x[1] - x[0] > 3);
  }, ID);
  fs.writeFileSync(path.join(OUT, 'two-lines-1.0s.png'), Buffer.from(B2.png.split(',')[1], 'base64'));
  ok(two.top && lines.length === 2 && lines[0][1] - lines[0][0] < lines[1][1] - lines[1][0], `two lines “JOBS IN / EUROPE”: ${lines.length} ink bands (rows ${lines.map((x) => x.join('–')).join(' and ')}), the top one smaller (box h ${twoGrow})`);
  ok(B2.nP > 400 && B2.dP < 12 && B2.nT > 1500 && B2.dT < 20, `both lines behind the person: word ∩ person ${B2.nP} px shows the person (${B2.dP}/255), word alone ${B2.nT} px shows the text (${B2.dT}/255)`);
  await ev((id) => { window.ED.select([id]); window.ED.seek(1.0); window.ED.showSide?.('inspector'); }, ID); await page.waitForTimeout(1200);
  await shot('jobs-in-europe-two-lines-1440x900');

  // ════════ move / resize ════════
  const mv = await ev(async (id) => {
    const { ops } = await import('/app/core/history.js');
    const box = (m, W) => { let x0 = 1e9, x1 = -1, y0 = 1e9, y1 = -1; for (let i = 0; i < m.length; i++) if (m[i]) { const x = i % W, y = (i / W) | 0; x0 = Math.min(x0, x); x1 = Math.max(x1, x); y0 = Math.min(y0, y); y1 = Math.max(y1, y); } return [x0, y0, x1, y1]; };
    const a = await window.TEXTMASK(id, 1.0), A = box(a.m, a.W), it = window.find(id);
    const s0 = window.steps(); window.ED.apply(ops.setItems([{ id, props: { x: it.x + 120, y: it.y + 200 } }], 'Move')); window.ED.history.seal();
    const b = await window.TEXTMASK(id, 1.0), Bx = box(b.m, b.W);
    const s1 = window.steps(); window.TC.sizeTb(window.CTX, id, 0.6); window.ED.history.seal();
    const c = await window.TEXTMASK(id, 1.0), C = box(c.m, c.W), s2 = window.steps();
    window.ED.history.undo(); const back1 = window.find(id).w; window.ED.history.undo(); const back2 = [window.find(id).x, window.find(id).y];
    return { A, B: Bx, C, it: [it.x, it.y, it.w], move: s1 - s0, size: s2 - s1, back1, back2 };
  }, ID);
  const dx = (mv.B[0] - mv.A[0]) * 4, dy = (mv.B[1] - mv.A[1]) * 4, wa = mv.A[2] - mv.A[0], wc = mv.C[2] - mv.C[0];
  ok(Math.abs(dx - 120) <= 8 && Math.abs(dy - 200) <= 8, `moving the element +120, +200 px moves the word by ${dx}, ${dy} px`);
  ok(wc < wa * 0.8 && mv.move === 1 && mv.size === 1 && mv.back1 === mv.it[2] && mv.back2[0] === mv.it[0] && mv.back2[1] === mv.it[1], `resizing to 60% of the frame narrows the word (${wa * 4} → ${wc * 4} px); move and resize are one undo step each and undo puts the box back`);

  // ════════ carousel: word 1, then word 2 ════════
  const car = await ev(async (id) => {
    const it0 = window.find(id);
    const items = [window.CD.continentCap('europe'), window.CD.countryCap('au')];
    window.TC.setTb(window.CTX, id, { mode: 'carousel', car: { items, style: 'up', per: 1.0, order: 'list', loop: true } }, 'Carousel', false);
    window.ED.history.seal();
    // single-word references at the same box: EUROPE and AUSTRALIA
    const ref = async (word) => {
      const r = window.TC.addTextBehind(window.CTX, { t0: it0.t0, dur: it0.out - it0.in, text: { word, top: 'JOBS IN' }, box: { x: it0.x, y: it0.y, w: it0.w, h: it0.h }, tb: JSON.parse(JSON.stringify({ ...window.find(id).style.tb, mode: 'word' })) });
      return r.id;
    };
    const rE = await ref('EUROPE'), rA = await ref('AUSTRALIA');
    const diff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += a[i] !== b[i] ? 1 : 0; return s; };
    const out = [];
    for (const t of [it0.t0 + 0.75, it0.t0 + 1.75, it0.t0 + 2.75]) {
      const c = await window.TEXTMASK(id, t), e = await window.TEXTMASK(rE, t), a = await window.TEXTMASK(rA, t);
      out.push({ t: +t.toFixed(2), vsE: diff(c.m, e.m), vsA: diff(c.m, a.m) });
    }
    window.ED.apply((await import('/app/core/history.js')).ops.removeItems([rE, rA], 'refs')); window.ED.history.seal();
    return { out, words: window.find(id).style.tb.car.items.map((x) => x.en) };
  }, ID);
  const [c1, c2, c3] = car.out;
  ok(c1.vsE < c1.vsA * 0.1 && c2.vsA < c2.vsE * 0.1 && c3.vsE < c3.vsA * 0.1, `carousel ${car.words.join(' → ')} (1 s per word, loop): at ${c1.t} s it is EUROPE (differs from the EUROPE render by ${c1.vsE} px, AUSTRALIA ${c1.vsA}), at ${c2.t} s AUSTRALIA (${c2.vsA} vs ${c2.vsE}), at ${c3.t} s EUROPE again (${c3.vsE} vs ${c3.vsA})`);
  const B3 = await measureBehind(ID, 1.95);
  ok(B3.nP > 300 && B3.dP < 12, `the carousel word is behind the person too (word ∩ person ${B3.nP} px, ${B3.dP}/255)`);

  // ── the picker: Select all Europe fills the list (the real dialog) ──
  await ev((id) => { window.ED.select([id]); window.ED.seek(1.2); window.ED.showSide?.('inspector'); }, ID); await page.waitForTimeout(900);
  await reveal('[data-tb-car-list]'); await shot('carousel-inspector-1440x900');
  await page.click('[data-tb-pick]');
  await page.waitForSelector('[data-tb-countries]');
  await page.click('[data-tb-group="Europe"]'); await page.click('[data-tb-all="Europe"]');
  await page.waitForTimeout(300); await shot('carousel-country-picker-1440x900');
  const nEu = await ev(() => window.CD.COUNTRIES.filter((c) => c[2] === 'Europe').length);
  const s0 = await ev(() => window.steps());
  await page.click('#tb-pick-apply'); await page.waitForTimeout(500);
  const pk = await ev((id) => ({ n: window.find(id).style.tb.car.items.length, s: window.steps(), flags: window.find(id).style.tb.car.items.filter((x) => x.flag).length, rows: document.querySelectorAll('[data-tb-car-list] .tb-li').length }), ID);
  ok(pk.n === nEu + 2 && pk.s - s0 === 1 && pk.rows === pk.n, `“Select all Europe” in the country picker fills the carousel: ${pk.n} words (the ${nEu} European countries, plus Europe and Australia already there) in ONE undo step, ${pk.rows} rows in the Inspector`);
  // back to two words for the export
  await ev((id) => { window.ED.history.undo(); }, ID);

  // ════════ same: preview = export ════════
  const same = await ev(async () => {
    const ed = window.ED;
    // 0.6 s per word: word 1, word 2, word 1 inside the presenter's first two (calmer) seconds
    window.TC.setTb(window.CTX, window.ID, { car: { per: 0.6 } }, 'Per word', false); ed.history.seal();
    await window.V.ensureAnalysis(ed.project); await window.R.prepareRender(ed.project);
    const r = await window.E.exportMp4(ed.project, ed.media, { quality: 'high', width: 540, height: 960 });
    const file = `workspaces/${ed.project.workspace}/exports/tb-same.mp4`; await ed.engine.writeFile(file, r.blob);
    return file;
  });
  const sameOut = [];
  for (const t of [0.6, 1.15, 1.75]) {
    await ev((t) => { window.ED.select([]); window.ED.seek(t); }, t);
    await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(900);
    const pv = await ev(() => { const c = document.createElement('canvas'); c.width = 135; c.height = 240; const g = c.getContext('2d', { willReadFrequently: true }); g.drawImage(window.ED.preview.canvas, 0, 0, 135, 240); return Array.from(g.getImageData(0, 0, 135, 240).data).filter((_, i) => i % 4 !== 3); });
    const ex = frameRGB(path.join(STUDIO, same), t, 135, 240);
    let s = 0; for (let i = 0; i < ex.length; i++) s += Math.abs(ex[i] - pv[i]);
    for (const [nm, px] of [['pv', pv], ['ex', ex]]) fs.writeFileSync(path.join(OUT, `same-${t}-${nm}.ppm`), Buffer.concat([Buffer.from('P6\n135 240\n255\n'), Buffer.from(Uint8Array.from(px))]));
    sameOut.push([t, +(s / ex.length).toFixed(2)]);
  }
  ok(sameOut.every(([, d]) => d < 6), `preview = export, two lines + carousel behind the host (EUROPE at 0.6 s, AUSTRALIA at 1.15 s, EUROPE at 1.75 s): mean difference ${sameOut.map(([t, d]) => `${t}s ${d}`).join(', ')} (/255, < 6)`);

  // ── speed: the monitor's frame with the element vs without it (the mask is sampled from the cache, never re-run) ──
  const perf = await ev(async () => {
    const ed = window.ED, p = ed.project, scale = 0.5, c = document.createElement('canvas'); c.width = p.canvas.w * scale; c.height = p.canvas.h * scale;
    const x = c.getContext('2d'), f = await window.E.openFrames(p, ed.media, scale), out = {};
    try {
      for (const [k, skip] of [['on', null], ['off', new Set([window.ID])], ['on2', null]]) {
        const ms = [];
        for (let i = 0; i < 24; i++) { const t = 0.5 + i * 0.05; await f.load(t); const t0 = performance.now(); window.R.renderFrame(x, p, t, { scale, source: f.source, skip, preview: true }); x.getImageData(0, 0, 1, 1); ms.push(performance.now() - t0); }
        ms.sort((a, b) => a - b); out[k] = +ms[12].toFixed(2);
      }
    } finally { f.close(); }
    return { on: Math.min(out.on, out.on2), off: out.off, w: c.width, h: c.height };
  });
  ok(perf.on - perf.off < 12, `speed: a ${perf.w}×${perf.h} frame takes ${perf.on} ms (median) with two lines + carousel behind the host, ${perf.off} ms without: +${(perf.on - perf.off).toFixed(2)} ms (each frame flushed; masks from the cache; budget +12 ms on SwiftShader's software GPU)`);

  // ════════ auto: every country I mention ════════
  const auto = await ev(async () => {
    const ed = window.ED, C = await import('/app/captions/commands.js'), M = await import('/app/captions/model.js');
    const said = [['We', 0.2], ['have', 0.4], ['jobs', 0.6], ['in', 0.8], ['Europe.', 1.0], ['And', 2.0], ['also', 2.2], ['in', 2.4], ['Australia!', 2.6], ['Apply', 3.3], ['today.', 3.6]];
    const words = said.map(([w, t]) => ({ t0: t, t1: +(t + 0.18).toFixed(2), w, conf: 1 }));
    ed.apply(C.setCaptions({ style: 'word-box', lang: 'en', words, lines: M.buildLines(words, 'word-box', ed.project.canvas) }, ed.project.canvas)); ed.history.seal();
    const s0 = window.steps(), r = window.TC.textBehindMentions(window.CTX, { top: true }), s1 = window.steps();
    const list = window.TC.mentionTbs(ed.project).map((i) => ({ code: i.style.code, word: window.TB.defaultWord(i.style), top: i.style.tb.top.on, t0: i.t0, t1: +(i.t0 + i.out).toFixed(2) }));
    const w2 = words.map((w) => (w.w === 'Australia!' ? { ...w, w: 'Canada!' } : w));
    const s2 = window.steps(); ed.apply(C.setCaptions({ ...ed.project.captions, words: w2, lines: M.buildLines(w2, 'word-box', ed.project.canvas) }, ed.project.canvas)); ed.history.seal();
    const after = window.TC.mentionTbs(ed.project).map((i) => window.TB.defaultWord(i.style)), s3 = window.steps();
    ed.history.undo(); const undone = window.TC.mentionTbs(ed.project).map((i) => window.TB.defaultWord(i.style)); ed.history.redo();
    // Arabic: the name in the caption's own script
    const wa = [{ t0: 0.3, t1: 0.6, w: 'وظائف', conf: 1 }, { t0: 0.7, t1: 1.0, w: 'في', conf: 1 }, { t0: 1.1, t1: 1.5, w: 'أوروبا', conf: 1 }];
    ed.apply(C.setCaptions({ style: 'word-box', lang: 'ar', words: wa, lines: M.buildLines(wa, 'word-box', ed.project.canvas) }, ed.project.canvas)); ed.history.seal();
    const ar = window.TC.mentionTbs(ed.project).map((i) => window.TB.defaultWord(i.style));
    ed.history.undo();
    return { n: r.n, steps: s1 - s0, list, after, undone, editSteps: s3 - s2, ar };
  });
  const L = auto.list;
  ok(auto.steps === 1 && L.length === 2 && L[0].word === 'EUROPE' && L[1].word === 'AUSTRALIA', `Text behind me for every country I mention: ${L.map((x) => `${x.word} ${x.t0}–${x.t1} s`).join(' · ')} in ONE undo step`);
  ok(Math.abs(L[0].t0 - 0.2) < 0.01 && L[0].t1 >= 1.17 && L[0].t1 <= 2.0 && Math.abs(L[1].t0 - 2.0) < 0.01 && L[1].t1 >= 2.77 && L[1].t1 <= 3.3, `each spans the sentence it was said in (“We have jobs in Europe.” 0.2–1.18 s, “And also in Australia!” 2.0–2.78 s; at least 1.2 s on screen)`);
  ok(L.every((x) => x.top), `“JOBS IN above” puts the top line on each one`);
  ok(auto.after[1] === 'CANADA' && auto.editSteps === 1 && auto.undone[1] === 'AUSTRALIA', `a caption edit (Australia → Canada) follows in the same undo step (${auto.after.join(', ')}); undo brings ${auto.undone[1]} back`);
  ok(auto.ar.length === 1 && auto.ar[0] === 'أوروبا', `an Arabic caption gives the word in Arabic: ${auto.ar.join(', ')}`);
  await ev(() => { window.ED.select([]); window.ED.showSide?.('mod:themes'); }); await page.waitForTimeout(900);
  await ev(() => document.querySelector('[data-tb-mentions]')?.scrollIntoView({ block: 'center' })); await page.waitForTimeout(3200);
  await page.hover('[data-tb-mentions]'); await page.waitForTimeout(900); await shot('one-click-text-behind-1440x900');
  // Place elements lists it
  const listed = await ev(() => !!document.querySelector('[data-place="tbehind"]'));
  ok(listed, 'Themes → Place elements lists “Text behind the host” (drag or ＋, hover preview)');

  // ════════ the effect, on the monitor: EUROPE behind the host ════════
  await ev(async (id) => {
    const ed = window.ED, mine = window.TC.mentionTbs(ed.project).map((i) => i.id);
    if (mine.length) { const { ops } = await import('/app/core/history.js'); ed.apply(ops.removeItems(mine, 'clean')); ed.history.seal(); }
    window.TC.setTb(window.CTX, id, { mode: 'word', top: { on: false } }, 'One word', false); ed.history.seal();
    window.TC.placeTb(window.CTX, id, 'head'); ed.history.seal();
    ed.select([id]); ed.seek(1.3); ed.showSide?.('inspector');
  }, ID);
  await page.mouse.move(700, 880); await page.waitForTimeout(1300);
  await shot('europe-behind-host-1440x900');
  await reveal('[data-tb-status]'); await shot('inspector-text-behind-1440x900');
  await reveal('[data-tb-fill]'); await shot('inspector-look-1440x900');
  // the word's box is recorded by the monitor: a double-click on it opens the editor on the video
  const boxes = await ev(async (id) => { const T = await import('/app/themes/text.js'); return T.lastBoxes(id)?.boxes?.map((b) => b.key) || []; }, ID);
  ok(boxes.includes('word'), `the word's box is recorded on the monitor (double-click edits it on the video): ${boxes.join(', ')}`);

  // ════════ keep: save + reload ════════
  const before = await ev(async () => { const r = await window.RENDER(window.ED.project, 1.3, 0.2); return Array.from(r.d); });
  await ev(() => window.ED.save());
  await page.goto(e.url + '/app/'); await page.waitForSelector('.recent'); await page.click('.recent'); await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project?.tracks);
  const after = await ev(async () => {
    const ed = window.kbbEditor; window.ED = ed; window.R = await import('/app/core/render.js'); window.E = await import('/app/core/export.js'); window.V = await import('/app/look/vision.js');
    await window.V.ensureAnalysis(ed.project);
    const scale = 0.2, p = ed.project, W = Math.round(p.canvas.w * scale), Hh = Math.round(p.canvas.h * scale), c = document.createElement('canvas'); c.width = W; c.height = Hh; const x = c.getContext('2d', { willReadFrequently: true });
    await window.R.prepareRender(p); const f = await window.E.openFrames(p, ed.media, scale); try { await f.load(1.3); window.R.renderFrame(x, p, 1.3, { scale, source: f.source }); } finally { f.close(); }
    return Array.from(x.getImageData(0, 0, W, Hh).data);
  });
  let dk = 0; for (let i = 0; i < before.length; i++) dk += Math.abs(before[i] - after[i]);
  ok(dk / before.length < 1, `save + reload draws the same frame (mean difference ${(dk / before.length).toFixed(3)}/255)`);

  // ════════ hygiene ════════
  ok(errors.length === 0, `no console errors${errors.length ? ': ' + errors.slice(0, 4).join(' | ') : ''}`);
  ok(outside.length === 0, `nothing leaves 127.0.0.1${outside.length ? ': ' + outside.slice(0, 4).join(' ') : ''}`);

  await browser.close(); await H.stopEngine(e);
  console.log(`\n${failures ? failures + ' FAILED' : 'ALL PASSED'} in ${((Date.now() - T0) / 1000).toFixed(0)} s`);
  fs.writeFileSync(path.join(OUT, 'check.txt'), results.join('\n') + '\n');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
