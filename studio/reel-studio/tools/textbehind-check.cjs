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
//   footage   the owner's kind of clips (19 Nov, "not working properly"): an iPhone portrait clip (landscape pixels +
//             a 90° display rotation; decoder and <video> paths), the clip scaled and moved on the canvas, 4K, 60 s: the
//             cut-out must line up with the person (IoU with his true outline) in the export AND on the monitor; the
//             status line must move from "Finding the person…" to ready by itself
//   popup     the editor beside the video (themes/textbehind-popup.js): opens from ⚡ One click, Place elements ＋, the
//             Inspector's ✎ button, a double-click on the video and on the timeline, and (asked) from the toast after
//             every country I mention; the frame stays fully visible at 1440×900 and 1280×800; changes are live on the
//             monitor before Apply (a draft); Cancel / Esc = byte-identical project; Apply / ⌘↵ = ONE undo step; a drag
//             on the video stays live; ▶ Play preview loops the element; it remembers where it was put, never over the frame
//   hygiene   no console errors, nothing leaves 127.0.0.1
//   shots     shots/textbehind/*.png at 1440×900 (and the editor's four tabs at 1280×800)
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/textbehind-check.cjs   (TB_ONLY=base,footage,popup: parts)
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
// TB_ONLY=footage,popup runs only those parts (the base part is 'base'); unset runs everything
const ONLY = process.env.TB_ONLY ? new Set(process.env.TB_ONLY.split(',')) : null;
const want = (k) => !ONLY || ONLY.has(k);

// ── the owner's footage (19 Nov: "the text behind … is not working properly"): clips the way his iPhone makes them ──
// VP9 in MP4 for the clips the decoder reads (this test Chromium has no H.264 in WebCodecs; on his Mac H.264 / HEVC take
// that same decoder path), plus one H.264 copy for the <video> element path. The presenter is drawn at 4–6 s of the
// bg-check world, where he stands OFF-CENTRE (right third): a cut-out turned, mirrored or squeezed would miss him.
const FOOT = path.join(OUT, 'footage'), PA = 4, PD = 2;
const vp9 = (...a) => ff(...a, '-c:v', 'libvpx-vp9', '-b:v', '3M', '-deadline', 'realtime', '-cpu-used', '8', '-g', '15', '-pix_fmt', 'yuv420p', '-an');
async function makeFootage(ev) {
  const frames = path.join(FOOT, 'frames'), up = path.join(FOOT, 'up.mp4');
  fs.mkdirSync(FOOT, { recursive: true });
  if (!fs.existsSync(path.join(frames, 'done'))) {
    fs.rmSync(frames, { recursive: true, force: true }); fs.mkdirSync(frames, { recursive: true });
    for (let i = 0; i < PD * FPS; i++) {
      const jpg = await ev((t) => { const c = document.createElement('canvas'); c.width = 540; c.height = 960; window.W.draw(c, 'presenter', t, 'rgb'); return c.toDataURL('image/jpeg', 0.93); }, PA + i / FPS);
      fs.writeFileSync(path.join(frames, String(i).padStart(4, '0') + '.jpg'), Buffer.from(jpg.split(',')[1], 'base64'));
    }
    fs.writeFileSync(path.join(frames, 'done'), '');
  }
  const src = ['-framerate', String(FPS), '-i', path.join(frames, '%04d.jpg')];
  if (!fs.existsSync(up)) vp9(...src, '-f', 'mp4', up);
  // an iPhone portrait clip: LANDSCAPE pixels (the picture turned a quarter left) plus "show this turned" in the track
  // header; encode the pixels, then stream-copy with the display matrix (rotate-check.cjs does the same)
  const tagged = (name, flat, enc) => {
    const out = path.join(FOOT, name);
    if (fs.existsSync(out)) return out;
    if (!fs.existsSync(flat)) enc(flat);
    // ffmpeg's -display_rotation is counter-clockwise: find the one that shows him upright again (ffmpeg applies it too)
    const ref = frameRGB(up, 0.5, 54, 96);
    let best = null;
    for (const r of [90, 270]) {
      const f = path.join(FOOT, `try${r}-${name}`); ff('-display_rotation', String(r), '-i', flat, '-c', 'copy', '-f', 'mp4', f);
      const g = frameRGB(f, 0.5, 54, 96); let d = 0; for (let i = 0; i < g.length; i++) d += Math.abs(g[i] - ref[i]);
      if (!best || d < best.d) best = { d, f };
    }
    fs.renameSync(best.f, out);
    for (const r of [90, 270]) fs.rmSync(path.join(FOOT, `try${r}-${name}`), { force: true });
    return out;
  };
  const iphone = tagged('iphone.mp4', path.join(FOOT, 'flat.mp4'), (f) => vp9(...src, '-vf', 'transpose=2', '-f', 'mp4', f));
  const iphone264 = tagged('iphone-h264.mp4', path.join(FOOT, 'flat-h264.mp4'), (f) => ff(...src, '-vf', 'transpose=2', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-an', '-movflags', '+faststart', f));
  // 4K as an iPhone films it: 3840×2160 pixels + the turn
  const k4 = tagged('iphone-4k.mp4', path.join(FOOT, 'flat-4k.mp4'), (f) => vp9(...src, '-vf', 'transpose=2,scale=3840:2160:flags=bicubic', '-b:v', '12M', '-f', 'mp4', f));
  // a long clip: 60 s (the 2 s presenter, again and again), turned like the others
  const long = tagged('iphone-60s.mp4', path.join(FOOT, 'flat-60s.mp4'), (f) => ff('-stream_loop', '29', '-i', path.join(FOOT, 'flat.mp4'), '-c', 'copy', '-f', 'mp4', f));
  return { up, iphone, iphone264, k4, long };
}

/** the page-side helpers every footage case uses (window.FT.*) */
const FOOT_JS = async () => {
  const ed = window.kbbEditor;
  const R = await import('/app/core/render.js'), E = await import('/app/core/export.js'), V = await import('/app/look/vision.js');
  const TB = await import('/app/themes/textbehind.js'), TC = await import('/app/themes/textbehind-cmds.js'), Hm = await import('/app/core/history.js');
  const items = () => ed.project.tracks.flatMap((t) => t.items), find = (id) => items().find((i) => i.id === id);
  const scale = 0.25;
  /** the project at t, export path (decoded frames) or the monitor's own sources */
  const render = async (t, mode, skip = null, transparent = false) => {
    const p = ed.project, W = Math.round(p.canvas.w * scale), Hh = Math.round(p.canvas.h * scale), c = document.createElement('canvas'); c.width = W; c.height = Hh;
    const x = c.getContext('2d', { willReadFrequently: true });
    if (mode === 'export') {
      await R.prepareRender(p);
      const f = await E.openFrames(p, ed.media, scale);
      try { await f.load(t); R.renderFrame(x, p, t, { scale, source: f.source, skip: skip ? new Set(skip) : null, transparent }); } finally { f.close(); }
    } else R.renderFrame(x, p, t, { scale, preview: true, source: ed.preview.source, skip: skip ? new Set(skip) : null, transparent });
    return { W, H: Hh, d: x.getImageData(0, 0, W, Hh).data, url: () => c.toDataURL('image/png') };
  };
  /** the drawn presenter's true silhouette at source time s, placed into the clip's box (cover fit), at the render size */
  const truth = (s, clip) => {
    const p = ed.project, W = Math.round(p.canvas.w * scale), Hh = Math.round(p.canvas.h * scale);
    const a = document.createElement('canvas'); a.width = 540; a.height = 960; window.W.draw(a, 'presenter', 4 + (((s % 2) + 2) % 2), 'mask');
    const c = document.createElement('canvas'); c.width = W; c.height = Hh; const x = c.getContext('2d', { willReadFrequently: true });
    x.fillStyle = '#000'; x.fillRect(0, 0, W, Hh);
    const bw = clip.w * scale, bh = clip.h * scale, k = Math.max(bw / 540, bh / 960), vw = bw / k, vh = bh / k;
    x.drawImage(a, (540 - vw) / 2, (960 - vh) / 2, vw, vh, clip.x * scale, clip.y * scale, bw, bh);
    const d = x.getImageData(0, 0, W, Hh).data, m = new Uint8Array(W * Hh); for (let i = 0; i < m.length; i++) m[i] = d[i * 4] > 127 ? 1 : 0;
    return m;
  };
  const morph = (m, W, Hh, r, grow) => { const o = new Uint8Array(m.length); for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) { let v = grow ? 0 : 1; for (let dy = -r; dy <= r && (grow ? !v : v); dy++) for (let dx = -r; dx <= r; dx++) { const yy = Math.min(Hh - 1, Math.max(0, y + dy)), xx = Math.min(W - 1, Math.max(0, x + dx)); if (grow ? m[yy * W + xx] : !m[yy * W + xx]) { v = grow ? 1 : 0; break; } } o[y * W + x] = v; } return o; };
  /** the cut-out the element uses, placed like its clip (what is laid back over the words), alpha > 127 */
  const placed = (id, t) => {
    const p = ed.project, it = find(id), P = TB.personAt(p, it, t, TB.tbOf(it.style)); if (P.state !== 'ok') return { state: P.state };
    const W = Math.round(p.canvas.w * scale), Hh = Math.round(p.canvas.h * scale), c = document.createElement('canvas'); c.width = W; c.height = Hh;
    const x = c.getContext('2d', { willReadFrequently: true }); x.setTransform(scale, 0, 0, scale, 0, 0);
    R.drawItem(x, { ...P.host.clip, fx: [], look: null, opacity: 1, fadeIn: 0, fadeOut: 0 }, t, p, { w: p.canvas.w, h: p.canvas.h, scale, preview: false, track: P.host.track, project: p, source: () => P.cv });
    const d = x.getImageData(0, 0, W, Hh).data, m = new Uint8Array(W * Hh); for (let i = 0; i < m.length; i++) m[i] = d[i * 4 + 3] > 127 ? 1 : 0;
    return { state: 'ok', m };
  };
  window.FT = {
    ed, R, E, V, TB, TC, Hm, items, find, render, truth, placed, morph,
    clip: () => items().find((i) => i.type === 'clip'),
    /** add the element over [t0, t0 + dur) and wait for the person (the same catch-up the editor runs) */
    async add(t0, dur, opts = {}) {
      const it = TC.addTextBehind(ed.context(), { t0, dur, code: 'europe', ...opts });
      ed.history.seal();
      await V.ensureAnalysis(ed.project);
      return it.id;
    },
    /** does the cut-out line up with the person? IoU of the placed mask and the true silhouette, and the share of
     *  the cut-out that lands on him */
    align(id, t) {
      const it = find(id), cl = TB.hostClip(ed.project, it, t)?.clip; if (!cl) return { state: 'noclip' };
      const P = placed(id, t); if (P.state !== 'ok') return { state: P.state };
      const tr = truth((t - cl.t0) * (cl.speed || 1) + (cl.in || 0), cl);
      let I = 0, U = 0, M = 0, T = 0; for (let i = 0; i < tr.length; i++) { const a = P.m[i], b = tr[i]; I += a & b; U += a | b; M += a; T += b; }
      return { state: 'ok', iou: +(I / Math.max(1, U)).toFixed(3), onHim: +(I / Math.max(1, M)).toFixed(3), mask: M, truth: T };
    },
    /** word ∩ person shows the person, word without him shows the words (as textbehind-check's measureBehind) */
    async behind(id, t, mode) {
      const cl = TB.hostClip(ed.project, find(id), t).clip, s = (t - cl.t0) * (cl.speed || 1) + (cl.in || 0);
      const withEl = await render(t, mode), base = await render(t, mode, [id]);
      const others = items().filter((i) => i.id !== id).map((i) => i.id), tm = await render(t, 'export', others, true);
      const W = withEl.W, Hh = withEl.H, tr = truth(s, cl), inner = morph(tr, W, Hh, 3, false), outer = morph(tr, W, Hh, 3, true);
      let nP = 0, dP = 0, nT = 0, dT = 0;
      for (let i = 0; i < W * Hh; i++) {
        if (tm.d[i * 4 + 3] <= 128) continue;
        if (inner[i]) { nP++; dP += (Math.abs(withEl.d[i * 4] - base.d[i * 4]) + Math.abs(withEl.d[i * 4 + 1] - base.d[i * 4 + 1]) + Math.abs(withEl.d[i * 4 + 2] - base.d[i * 4 + 2])) / 3; }
        else if (!outer[i]) { nT++; dT += (Math.abs(withEl.d[i * 4] - tm.d[i * 4]) + Math.abs(withEl.d[i * 4 + 1] - tm.d[i * 4 + 1]) + Math.abs(withEl.d[i * 4 + 2] - tm.d[i * 4 + 2])) / 3; }
      }
      return { nP, dP: +(dP / Math.max(1, nP)).toFixed(2), nT, dT: +(dT / Math.max(1, nT)).toFixed(2), png: withEl.url() };
    },
  };
  return true;
};
const STUDIO_OF = (e) => e.spawnargs[e.spawnargs.indexOf('--dir') + 1];
/** a new project from the home screen with `file` on the timeline */
async function openClip(page, e, file) {
  await page.goto(e.url + '/app/'); await page.waitForSelector('.home', { timeout: 30000 });
  await page.setInputFiles('.home-drop input[type=file]', file);
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await page.evaluate(WORLD_JS); await page.evaluate(FOOT_JS);
}
/** seek the monitor and let its sources settle (the <video> seeked, the decoder caught up) */
async function settle(page, t) {
  await page.evaluate((t) => { window.kbbEditor.select([]); window.kbbEditor.seek(t); }, t);
  await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(900);
}

async function footageChecks(page, e, ev, shot) {
  await ev(WORLD_JS);
  const F = await makeFootage(ev);
  const probe = execFileSync('ffprobe', ['-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:stream_side_data=rotation', '-of', 'json', F.iphone]).toString();
  const meta = JSON.parse(probe).streams[0];
  ok(meta.width === 960 && meta.height === 540 && Math.abs(meta.side_data_list?.[0]?.rotation) === 90, `the iPhone-style test clip: ${meta.width}×${meta.height} pixels + a ${meta.side_data_list?.[0]?.rotation}° display rotation (shown 540×960 upright, the presenter off-centre)`);
  const cases = [
    ['iphone', F.iphone, 'iPhone portrait clip (landscape pixels + 90° tag, decoder path)', null],
    ['iphone-h264', F.iphone264, 'the same clip in H.264 (the <video> element path)', null],
    ['scaled', F.iphone, 'the iPhone clip scaled to 60 % and moved on the canvas', (c) => ({ x: Math.round(c.x + c.w * 0.3), y: Math.round(c.y + c.h * 0.25), w: Math.round(c.w * 0.6), h: Math.round(c.h * 0.6) })],
    ['4k', F.k4, '4K iPhone clip (3840×2160 + 90° tag)', null],
  ];
  const out = {};
  for (const [key, file, label, move] of cases) {
    await openClip(page, e, file);
    const r = await ev(async (moved) => {
      const FT = window.FT, ed = FT.ed, c = FT.clip();
      if (moved) { const b = new Function('c', 'return (' + moved + ')(c)')(c); ed.apply(FT.Hm.ops.setItems([{ id: c.id, props: b }], 'Move clip')); ed.history.seal(); }
      const md = ed.project.media[0];
      const id = await FT.add(0.2, 1.6);
      FT.TC.placeTb(ed.context(), id, 'head'); ed.history.seal();
      const st = FT.TB.tbStatus(ed.project, FT.find(id), 1.0);
      return { id, st, media: [md.w, md.h], clip: [c.x, c.y, c.w, c.h], align: [FT.align(id, 0.5), FT.align(id, 1.0), FT.align(id, 1.5)] };
    }, move ? move.toString() : null);
    const ex = await ev((id) => window.FT.behind(id, 1.0, 'export'), r.id);
    await settle(page, 1.0);
    const pv = await ev((id) => window.FT.behind(id, 1.0, 'preview'), r.id);
    fs.writeFileSync(path.join(FOOT, `${key}-export.png`), Buffer.from(ex.png.split(',')[1], 'base64'));
    fs.writeFileSync(path.join(FOOT, `${key}-preview.png`), Buffer.from(pv.png.split(',')[1], 'base64'));
    out[key] = { ...r, ex, pv };
    const A = r.align;
    ok(r.st === 'ok' && A.every((a) => a.state === 'ok' && a.iou > 0.8 && a.onHim > 0.9), `${label}: media ${r.media.join('×')}, the cut-out lines up with the person (IoU with his true outline ${A.map((a) => a.iou).join(' / ')} at 0.5 / 1.0 / 1.5 s, > 0.8; share of the cut-out on him ${A.map((a) => a.onHim).join(' / ')}, > 0.9)`);
    ok(ex.nP > 150 && ex.dP < 14 && ex.nT > 600 && ex.dT < 22, `${label}, EXPORT: word ∩ person (${ex.nP} px) shows him (${ex.dP}/255), word alone (${ex.nT} px) shows the words (${ex.dT}/255)`);
    ok(pv.nP > 150 && pv.dP < 14 && pv.nT > 600 && pv.dT < 22, `${label}, PREVIEW: word ∩ person (${pv.nP} px) shows him (${pv.dP}/255), word alone (${pv.nT} px) shows the words (${pv.dT}/255)`);
    if (key === 'iphone') { await ev((id) => { window.kbbEditor.select([id]); }, r.id); await page.waitForTimeout(600); await shot('iphone-clip-behind-1440x900'); }
  }

  // ── a long clip: 60 s, the element near the end, and every country he mentions across the minute ──
  await openClip(page, e, F.long);
  const lg = await ev(async () => {
    const FT = window.FT, ed = FT.ed, t0 = performance.now();
    const id = await FT.add(50.2, 2.6); const ms = performance.now() - t0;
    FT.TC.placeTb(ed.context(), id, 'head'); ed.history.seal();
    return { id, ms: Math.round(ms), dur: ed.duration(), st: FT.TB.tbStatus(ed.project, FT.find(id), 51.5), align: FT.align(id, 51.5) };
  });
  ok(lg.dur > 59 && lg.st === 'ok' && lg.align.iou > 0.8, `60 s clip, element at 50.2 s: the person is found (${lg.ms} ms for that stretch) and the cut-out lines up (IoU ${lg.align.iou})`);

  // ── the status moves from "Finding the person…" to ready by itself, where he reads it ──
  await openClip(page, e, F.iphone);
  await ev(() => { window.kbbEditor.seek(1.0); window.kbbEditor.showSide('mod:themes'); });
  await page.waitForSelector('[data-tb-add]'); await page.click('[data-tb-add]');
  const st0 = await ev(() => ({ dom: document.querySelector('[data-tb-status]')?.dataset.tbStatus || null }));
  await page.waitForFunction(() => { const FT = window.FT, it = FT.items().find((i) => i.style?.el === 'tbehind'); return it && FT.TB.tbStatus(FT.ed.project, it, 1.0) === 'ok'; }, null, { timeout: 60000 });
  await page.waitForTimeout(1500);
  const st1 = await ev(() => ({ dom: document.querySelector('[data-tb-status]')?.dataset.tbStatus || null, text: document.querySelector('[data-tb-status]')?.textContent || '' }));
  ok(st1.dom === 'ok', `the status line moves on by itself: “${st0.dom}” when added → “${st1.dom}” once the person is found (${st1.text.slice(0, 60)})`);
  return { F, out };
}

// ════════ the popup: its own editor beside the video ════════
async function popupChecks(page, e, ev, shot, clip) {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.evaluate(() => { try { localStorage.removeItem('kbbstudio.tbEditor.rect'); } catch {} }).catch(() => {});
  await openClip(page, e, clip);
  await page.evaluate(() => { try { localStorage.removeItem('kbbstudio.tbEditor.rect'); } catch {} });
  const J = () => ev(() => JSON.stringify(window.kbbEditor.project));
  const steps = () => ev(() => window.kbbEditor.history.undoStack.length);
  const isOpen = () => ev(() => !!document.querySelector('.tbp'));
  /** the popup's box and the video frame's box, in the window */
  const geo = () => ev(() => {
    const r = (el) => { const b = el.getBoundingClientRect(); return { x: Math.round(b.left), y: Math.round(b.top), r: Math.round(b.right), b: Math.round(b.bottom), w: Math.round(b.width), h: Math.round(b.height) }; };
    const p = document.querySelector('.tbp'), s = window.kbbEditor.preview.stage;
    const P = r(p), S = r(s), over = Math.max(0, Math.min(P.r, S.r) - Math.max(P.x, S.x)) * Math.max(0, Math.min(P.b, S.b) - Math.max(P.y, S.y));
    // the frame is fully visible: inside the window, and the topmost thing at its corners and centre is the video
    const pts = [[S.x + 3, S.y + 3], [S.r - 3, S.y + 3], [S.x + 3, S.b - 3], [S.r - 3, S.b - 3], [(S.x + S.r) / 2, (S.y + S.b) / 2]];
    const top = pts.every(([x, y]) => { const el = document.elementFromPoint(x, y); return !!el && s.contains(el); });
    return { P, S, over, top, inWin: S.x >= 0 && S.y >= 0 && S.r <= innerWidth && S.b <= innerHeight && P.x >= 0 && P.y >= 0 && P.r <= innerWidth && P.b <= innerHeight, vw: innerWidth, vh: innerHeight };
  });
  /** the monitor's own pixels (what he sees), small */
  const monitor = () => ev(() => { const c = document.createElement('canvas'); c.width = 90; c.height = 160; const g = c.getContext('2d', { willReadFrequently: true }); g.drawImage(window.kbbEditor.preview.canvas, 0, 0, 90, 160); return Array.from(g.getImageData(0, 0, 90, 160).data); });
  const mdiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return +(s / a.length).toFixed(2); };
  const settled = async () => { await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {}); await page.waitForTimeout(500); };

  // ── 1. opening it: ⚡ One click → 🔤 Text behind me ──
  await ev(() => { window.kbbEditor.seek(1.0); window.kbbEditor.showSide('mod:themes'); });
  await page.waitForSelector('[data-tb-add]'); const s0 = await steps();
  await page.click('[data-tb-add]');
  await page.waitForSelector('.tbp [data-tbp-tab="words"].on', { timeout: 5000 }).catch(() => {});
  const o1 = await ev(() => ({ open: !!document.querySelector('.tbp'), tab: document.querySelector('.tbp-tab.on')?.dataset.tbpTab, id: document.querySelector('.tbp')?.dataset.tbp, el: window.FT.items().find((i) => i.style?.el === 'tbehind')?.id }));
  ok(o1.open && o1.tab === 'words' && o1.id === o1.el && (await steps()) === s0 + 1, `⚡ One click → 🔤 Text behind me adds it (ONE step) and opens its editor beside the video on the Words tab`);
  const ID = o1.el;
  // added 1 s before the clip ends: it ends with the clip, the reel does not grow black seconds
  const sp = await ev((id) => { const it = window.FT.find(id), c = window.FT.clip(); return { t0: it.t0, end: +(it.t0 + it.out - it.in).toFixed(3), clipEnd: +(c.t0 + c.out - c.in).toFixed(3), reel: +window.kbbEditor.duration().toFixed(3) }; }, ID);
  ok(sp.end <= sp.clipEnd + 1e-3 && sp.reel === sp.clipEnd, `added at the playhead 1 s before the clip ends, it ends with the video (${sp.t0}–${sp.end} s, clip ends ${sp.clipEnd} s) and the reel stays ${sp.reel} s (it used to run 3 s into nothing: words in front of nobody, black seconds at the end of the export)`);
  // the words are on the video when the editor opens (added at the playhead, its first frame is the start of its rise)
  await settled();
  const shown = await ev(async (id) => { const FT = window.FT, ed = FT.ed, a = await FT.render(ed.t, 'preview'), b = await FT.render(ed.t, 'preview', [id]); let d = 0; for (let i = 0; i < a.d.length; i++) d += Math.abs(a.d[i] - b.d[i]); const it = FT.find(id); return { t: +ed.t.toFixed(2), t0: it.t0, d: +(d / a.d.length).toFixed(2) }; }, ID);
  ok(shown.d > 2, `the words show on the video as the editor opens: playhead ${shown.t} s (the element starts at ${shown.t0} s, where its rise begins from nothing), the monitor differs from the frame without it by ${shown.d}/255`);
  // the words fully in (past their 0.6 s rise), over the clip
  await ev(async (id) => { const ed = window.kbbEditor, it = window.FT.find(id); ed.seek(it.t0 + 0.8); window.FT.TC.placeTb(ed.context(), id, 'head'); }, ID);
  await ev(() => window.FT.V.ensureAnalysis(window.kbbEditor.project));
  await settled();

  // ── 2. the frame stays fully visible, at 1440×900 and at 1280×800, on every tab ──
  const vis = [];
  for (const [w, hh] of [[1440, 900], [1280, 800]]) {
    await page.setViewportSize({ width: w, height: hh }); await page.waitForTimeout(700);
    for (const [k] of [['words'], ['look'], ['pos'], ['cut']]) {
      await page.click(`.tbp [data-tbp-tab="${k}"]`); await page.waitForTimeout(250);
      const g = await geo(); vis.push({ w, k, ...g });
      await page.mouse.move(5, hh - 5);
      await shot(`popup-${k}-${w}x${hh}`);
    }
  }
  const bad = vis.filter((g) => g.over > 0 || !g.top || !g.inWin);
  const g14 = vis.find((g) => g.w === 1440), g12 = vis.find((g) => g.w === 1280);
  ok(!bad.length, `the video frame stays fully visible with the editor open (every tab): 1440×900 frame ${g14.S.w}×${g14.S.h} at ${g14.S.x},${g14.S.y}, editor ${g14.P.w}×${g14.P.h} at ${g14.P.x},${g14.P.y}; 1280×800 frame ${g12.S.w}×${g12.S.h} at ${g12.S.x},${g12.S.y}, editor ${g12.P.w}×${g12.P.h} at ${g12.P.x},${g12.P.y}; overlap ${vis.map((g) => g.over).join('/')} px², the video on top at its corners${bad.length ? ' — BAD: ' + JSON.stringify(bad[0]) : ''}`);
  await page.setViewportSize({ width: 1440, height: 900 }); await page.waitForTimeout(500);
  await page.click('.tbp [data-tbp-apply]'); await page.waitForTimeout(200);

  // ── 3. live: a change shows on the video at once, as a draft (no undo step yet) ──
  await settled();
  const before = await J(), sOpen = await steps(), m0 = await monitor();
  await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp');
  await page.click('.tbp [data-tbp-tab="look"]'); await page.click('.tbp [data-tb-fill="black"]'); await settled();
  const m1 = await monitor(), sLive = await steps(), fillNow = await ev((id) => window.FT.find(id).style.tb.fill.colors[0], ID);
  ok(mdiff(m0, m1) > 1 && sLive === sOpen && fillNow === '#111111', `changes show on the video before Apply: Look → Black changes the monitor by ${mdiff(m0, m1)}/255 (mean), still ${sLive - sOpen} new undo steps (a draft; fill ${fillNow})`);

  // ── 4. Cancel puts it back exactly ──
  await page.click('.tbp [data-tbp-tab="words"]');
  await page.fill('.tbp [data-tbp-word]', 'JOBS'); await page.waitForTimeout(150);
  await page.click('.tbp [data-tbp-tab="pos"]'); await page.click('.tbp [data-tb-quick="lower"]'); await page.waitForTimeout(150);
  const mid = await J();
  await page.click('.tbp [data-tbp-cancel]'); await page.waitForTimeout(300); await settled();
  const afterCancel = await J(), m2 = await monitor();
  const firstDiff = (a, b) => { let i = 0; while (i < a.length && a[i] === b[i]) i++; return [a.slice(Math.max(0, i - 120), i + 80), b.slice(Math.max(0, i - 120), i + 80)]; };
  const cdbg = { diff: afterCancel === before ? null : firstDiff(before, afterCancel), changed: mid !== before, same: afterCancel === before, open: await isOpen(), steps: (await steps()) - sOpen, mon: mdiff(m0, m2) };
  ok(mid !== before && afterCancel === before && !(await isOpen()) && (await steps()) === sOpen && mdiff(m0, m2) < 1.5, `Cancel puts the element back exactly: the project is byte-identical to before (${before.length} bytes), no undo step, the monitor as before (${mdiff(m0, m2)}/255) ${JSON.stringify(cdbg)}`);

  // ── 5. Apply = ONE undo step; undo it = byte-identical again ──
  await page.click('.tl-item[data-id="' + ID + '"]', { force: true }).catch(() => {});
  await ev((id) => { window.kbbEditor.select([id]); window.kbbEditor.showSide('inspector'); }, ID); await page.waitForSelector('[data-tb-edit]');
  const insp = await ev(() => ({ rows: document.querySelectorAll('.insp-body details.sec').length, secs: [...document.querySelectorAll('.insp-body details.sec > summary')].map((s) => s.textContent.trim()), sum: document.querySelector('[data-tb-summary]')?.textContent || '' }));
  await shot('inspector-summary-1440x900');
  await page.click('[data-tb-edit]'); await page.waitForSelector('.tbp');
  ok(insp.secs.join('|') === 'Theme element|Text behind the host' && /EUROPE/.test(insp.sum), `the Inspector keeps only Start / Duration and a short summary (“${insp.sum}”) with ✎ Edit text behind…, which opens the editor (${insp.secs.join(', ')})`);
  await page.click('.tbp [data-tbp-tab="look"]'); await page.click('.tbp [data-tb-fill="gold"]');
  await page.selectOption('.tbp [data-tb-font]', 'Bebas Neue');
  await page.evaluate(() => { const r = document.querySelector('.tbp [data-tb-shadow]'); r.value = '0.8'; r.dispatchEvent(new Event('input', { bubbles: true })); r.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.click('.tbp [data-tbp-tab="words"]'); await page.fill('.tbp [data-tbp-word]', 'GERMANY'); await page.waitForTimeout(100);
  await page.click('.tbp [data-tbp-tab="pos"]'); await page.click('.tbp [data-tb-quick="centre"]');
  const sA = await steps(); await page.click('.tbp [data-tbp-apply]'); await page.waitForTimeout(300);
  const applied = await ev((id) => { const it = window.FT.find(id); return { word: it.style.text?.word, font: it.style.tb.font, fill: it.style.tb.fill.colors[0], shadow: it.style.tb.shadow }; }, ID), sB = await steps(), lbl = await ev(() => window.kbbEditor.history.undoLabel);
  // (an undo stamps the project's "modified" time, as every undo does: compared without it)
  const noMod = (j) => j.replace(/"modified":"[^"]*"/, '');
  await ev(() => window.kbbEditor.history.undo()); const undone = noMod(await J());
  await ev(() => window.kbbEditor.history.redo()); const redone = await ev((id) => window.FT.find(id).style.text?.word, ID);
  ok(sB === sA + 1 && applied.word === 'GERMANY' && applied.font === 'Bebas Neue' && applied.shadow === 0.8 && undone === noMod(before) && redone === 'GERMANY', `Apply keeps five changes (gold, Bebas Neue, shadow 80 %, “GERMANY”, centre) as ONE undo step “${lbl}”; ⌘Z puts the project back byte-identical (but its “modified” time), redo brings them back ${JSON.stringify({ sA, sB, applied, undoneSame: undone === noMod(before), redone })}`);

  // ── 6. keys: Esc = Cancel, ⌘↵ = Apply ──
  const k0 = await J(), ks0 = await steps();
  await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp');
  const viaTl = await isOpen();
  await page.click('.tbp [data-tbp-tab="look"]'); await page.click('.tbp [data-tb-fill="white"]'); await page.keyboard.press('Escape'); await page.waitForTimeout(200);
  const esc = { open: await isOpen(), same: (await J()) === k0, steps: (await steps()) - ks0 };
  await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp');
  await page.click('.tbp [data-tbp-tab="look"]'); await page.click('.tbp [data-tb-fill="white"]'); await page.keyboard.press('Control+Enter'); await page.waitForTimeout(200);
  const ent = { open: await isOpen(), steps: (await steps()) - ks0, fill: await ev((id) => window.FT.find(id).style.tb.fill.colors[0], ID) };
  ok(viaTl && !esc.open && esc.same && esc.steps === 0 && !ent.open && ent.steps === 1 && ent.fill === '#ffffff', `double-click on the timeline opens it; Esc cancels (byte-identical, no step), ⌘↵ applies (one step, white kept)`);

  // ── 7. double-click on the video: opens on Words with the word under the pointer ready to type ──
  await settled();
  const wbox = await ev(async (id) => { const T = await import('/app/themes/text.js'); const lb = T.lastBoxes(id)?.boxes?.find((b) => b.key === 'word'); const it = window.FT.find(id), pv = window.kbbEditor.preview, r = pv.stage.getBoundingClientRect(), k = pv.k; return lb ? { x: r.left + (it.x + lb.x + lb.w / 2) * k, y: r.top + (it.y + lb.y + lb.h / 2) * k } : null; }, ID);
  await page.mouse.dblclick(wbox.x, wbox.y); await page.waitForSelector('.tbp'); await page.waitForTimeout(250);
  const dv = await ev(() => ({ tab: document.querySelector('.tbp-tab.on')?.dataset.tbpTab, focus: document.activeElement?.dataset?.tbpWord === '1', val: document.activeElement?.value }));
  ok(dv.tab === 'words' && dv.focus && dv.val === 'GERMANY', `double-click on the word on the video opens the editor on Words with that word ready to type (“${dv.val}”)`);

  // ── 8. drag on the video while it is open: live, part of the same draft ──
  await page.click('.tbp [data-tbp-tab="pos"]');
  const d0 = await ev((id) => { const it = window.FT.find(id); return { x: it.x, y: it.y, s: window.kbbEditor.history.undoStack.length }; }, ID);
  await page.mouse.move(wbox.x, wbox.y); await page.mouse.down(); await page.mouse.move(wbox.x + 30, wbox.y + 40, { steps: 6 }); await page.mouse.up(); await page.waitForTimeout(300);
  const d1 = await ev((id) => { const it = window.FT.find(id); return { x: it.x, y: it.y, s: window.kbbEditor.history.undoStack.length, fx: +document.querySelector('.tbp [data-tb-pos="x"]').value, fy: +document.querySelector('.tbp [data-tb-pos="y"]').value, open: !!document.querySelector('.tbp') }; }, ID);
  await page.click('.tbp [data-tbp-apply]'); await page.waitForTimeout(200); const d2 = await steps();
  ok(d1.open && (d1.x !== d0.x || d1.y !== d0.y) && d1.s === d0.s && d1.fx === d1.x && d1.fy === d1.y && d2 === d0.s + 1, `dragging the words on the video while it is open moves them live (${d0.x},${d0.y} → ${d1.x},${d1.y}; the X / Y fields follow), still a draft, and Apply makes it one step`);

  // ── 9. ▶ Play preview: plays the element's stretch again and again; the playhead loops inside it ──
  await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp');
  await page.click('.tbp [data-tbp-play]');
  const span = await ev((id) => { const it = window.FT.find(id); return [it.t0, it.t0 + (it.out - it.in) / (it.speed || 1)]; }, ID);
  const ts = []; for (let i = 0; i < 16; i++) { await page.waitForTimeout(250); ts.push(await ev(() => +window.kbbEditor.t.toFixed(2))); }
  const playing = await ev(() => window.kbbEditor.playing);
  await page.click('.tbp [data-tbp-play]'); await page.waitForTimeout(200);
  const stopped = !(await ev(() => window.kbbEditor.playing));
  const wrapped = ts.some((t, i) => i > 0 && t < ts[i - 1]);
  ok(playing && stopped && wrapped && ts.every((t) => t >= span[0] - 0.05 && t <= span[1] + 0.15), `▶ Play preview plays the element’s ${span[0].toFixed(1)}–${span[1].toFixed(1)} s on the video and loops it (playhead ${ts.slice(0, 8).join(', ')} …); pressed again it stops`);
  await page.click('.tbp [data-tbp-cancel]');

  // ── 10. remembers where he put it; never over the frame ──
  await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp');
  const g0 = await geo();
  const hd = await ev(() => { const b = document.querySelector('.tbp-head').getBoundingClientRect(); return { x: b.left + 60, y: b.top + 14 }; });
  await page.mouse.move(hd.x, hd.y); await page.mouse.down(); await page.mouse.move(80, hd.y + 10, { steps: 8 }); await page.mouse.up(); await page.waitForTimeout(200);
  const gL = await geo(); await page.click('.tbp [data-tbp-cancel]'); await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp');
  const gR = await geo();
  // dragged right onto the frame: it settles beside it
  const hd2 = await ev(() => { const b = document.querySelector('.tbp-head').getBoundingClientRect(); return { x: b.left + 60, y: b.top + 14 }; });
  await page.mouse.move(hd2.x, hd2.y); await page.mouse.down(); await page.mouse.move(gR.S.x + gR.S.w / 2, hd2.y, { steps: 8 }); await page.mouse.up(); await page.waitForTimeout(200);
  const gO = await geo();
  ok(gL.P.x < g0.P.x && gL.over === 0 && gR.P.x === gL.P.x && gR.P.y === gL.P.y && gO.over === 0 && gO.top, `it can be moved (dragged to x ${gL.P.x}), is reopened where he left it (x ${gR.P.x}), and dropped onto the frame it settles beside it (x ${gO.P.x}, overlap ${gO.over} px²)`);
  await page.click('.tbp [data-tbp-cancel]');
  await page.evaluate(() => { try { localStorage.removeItem('kbbstudio.tbEditor.rect'); } catch {} });

  // ── 10b. a fresh studio: the person needs no download (bundled); the main subject asks for its model, never "Finding…" ──
  const modelsDir = fs.readdirSync(path.join(STUDIO_OF(e), 'models'));
  await ev((id) => { const it = window.FT.find(id); window.kbbEditor.seek(it.t0 + (it.out - it.in) / 2); }, ID);
  await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp');
  await page.click('.tbp [data-tbp-tab="cut"]');
  const cutP = await ev(() => document.querySelector('.tbp [data-tb-status]')?.dataset.tbStatus);
  await page.click('.tbp .tb-key [data-v="subject"]'); await page.waitForTimeout(300);
  const cutS = await ev(() => ({ st: document.querySelector('.tbp [data-tb-status]')?.dataset.tbStatus, btn: !!document.querySelector('.tbp [data-tb-model]'), chip: document.querySelector('.tbp [data-tbp-chip]')?.textContent || '' }));
  await shot('popup-cut-subject-model-1440x900');
  await page.click('.tbp [data-tbp-cancel]');
  ok(!modelsDir.some((f) => /selfie|segment/i.test(f)) && cutP === 'ok' && cutS.st === 'model' && cutS.btn && /model/.test(cutS.chip), `fresh studio (models folder: ${modelsDir.join(', ')}): the person is found with nothing to download (the segmenter is bundled): “${cutP}”; 📦 Main subject says it needs its model, with 📦 Install (“${cutS.chip}”), not “Finding the person…”`);

  // ── 10c. an analysis that fails says so and offers to try again (it used to read "Finding the person…" for ever) ──
  await page.dblclick('.tl-item[data-id="' + ID + '"]'); await page.waitForSelector('.tbp'); await page.click('.tbp [data-tbp-tab="cut"]');
  const fl = await ev(async () => {
    const FT = window.FT, B = await import('/app/core/bus.js'), md = FT.ed.project.media[0];
    FT.V.forget(md.id);                                     // the masks gone: as before the analysis
    B.bus.emit('vision:failed', { message: 'the decoder could not read this clip' });
    const s1 = document.querySelector('.tbp [data-tb-status]')?.dataset.tbStatus, txt = document.querySelector('.tbp [data-tb-status]')?.textContent || '', retry = !!document.querySelector('.tbp [data-tb-find]');
    return { s1, txt, retry };
  });
  await page.click('.tbp [data-tb-find]');
  await page.waitForFunction(() => document.querySelector('.tbp [data-tb-status]')?.dataset.tbStatus === 'ok', null, { timeout: 30000 }).catch(() => {});
  const fl2 = await ev(() => document.querySelector('.tbp [data-tb-status]')?.dataset.tbStatus);
  await page.click('.tbp [data-tbp-cancel]');
  ok(fl.s1 === 'failed' && /could not read/.test(fl.txt) && fl.retry && fl2 === 'ok', `a failed person search is said in the editor (“${fl.txt.slice(0, 70)}”) with ↻ Try again, which finds him: status ${fl.s1} → ${fl2}`);

  // ── 11. Place elements ＋ opens it; every country I mention asks first (a toast with Edit) ──
  await ev(() => { window.kbbEditor.select([]); window.kbbEditor.seek(0.4); window.kbbEditor.showSide('mod:themes'); });
  await page.waitForSelector('[data-add="tbehind"]'); await page.click('[data-add="tbehind"]'); await page.waitForTimeout(300);
  const viaPlace = await isOpen(); if (viaPlace) await page.click('.tbp [data-tbp-cancel]');
  await ev(async () => {
    const ed = window.kbbEditor, C = await import('/app/captions/commands.js'), M = await import('/app/captions/model.js');
    const words = [['Jobs', 0.1], ['in', 0.3], ['Germany.', 0.5], ['And', 1.0], ['Canada!', 1.2]].map(([w, t]) => ({ t0: t, t1: +(t + 0.18).toFixed(2), w, conf: 1 }));
    ed.apply(C.setCaptions({ style: 'word-box', lang: 'en', words, lines: M.buildLines(words, 'word-box', ed.project.canvas) }, ed.project.canvas)); ed.history.seal();
  });
  await page.click('[data-tb-mentions]'); await page.waitForTimeout(300);
  const mt = await ev(() => ({ open: !!document.querySelector('.tbp'), toast: !!document.querySelector('[data-tbp-toast-edit]'), n: window.FT.TC.mentionTbs(window.kbbEditor.project).map((i) => i.id) }));
  await page.click('[data-tbp-toast-edit]'); await page.waitForSelector('.tbp');
  const mt2 = await ev(() => document.querySelector('.tbp')?.dataset.tbp);
  ok(viaPlace && !mt.open && mt.toast && mt.n.length === 2 && mt2 === mt.n[0], `Place elements ＋ opens it too; “every country I mention” places ${mt.n.length} and does NOT open it, but its toast’s ✎ Edit opens the first one`);
  await page.click('.tbp [data-tbp-cancel]');
  return ID;
}

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

  if (want('base')) {
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
  await ev((id) => { window.ED.select([id]); window.ED.seek(1.2); window.ED.showSide?.('inspector'); }, ID); await page.waitForTimeout(600);
  await page.click('[data-tb-edit]'); await page.waitForSelector('.tbp [data-tb-car-list]'); await page.waitForTimeout(500);
  await shot('carousel-editor-1440x900');
  await page.click('.tbp [data-tb-pick]');
  await page.waitForSelector('[data-tb-countries]');
  await page.click('[data-tb-group="Europe"]'); await page.click('[data-tb-all="Europe"]');
  await page.waitForTimeout(300); await shot('carousel-country-picker-1440x900');
  const nEu = await ev(() => window.CD.COUNTRIES.filter((c) => c[2] === 'Europe').length);
  const s0 = await ev(() => window.steps());
  await page.click('#tb-pick-apply'); await page.waitForTimeout(500);
  const pk = await ev((id) => ({ n: window.find(id).style.tb.car.items.length, s: window.steps(), flags: window.find(id).style.tb.car.items.filter((x) => x.flag).length, rows: document.querySelectorAll('.tbp [data-tb-car-list] .tb-li').length }), ID);
  await page.click('.tbp [data-tbp-apply]'); await page.waitForTimeout(300);
  const pkS = await ev(() => window.steps());
  ok(pk.n === nEu + 2 && pk.s === s0 && pkS - s0 === 1 && pk.rows === pk.n, `“Select all Europe” in the country picker fills the carousel: ${pk.n} words (the ${nEu} European countries, plus Europe and Australia already there), ${pk.rows} rows in the editor, live as a draft; Apply makes it ONE undo step`);
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

  }

  if (want('footage')) await footageChecks(page, e, ev, shot);
  if (want('popup')) { await ev(WORLD_JS); const F = await makeFootage(ev); await popupChecks(page, e, ev, shot, F.iphone); }

  // ════════ hygiene ════════
  ok(errors.length === 0, `no console errors${errors.length ? ': ' + errors.slice(0, 4).join(' | ') : ''}`);
  ok(outside.length === 0, `nothing leaves 127.0.0.1${outside.length ? ': ' + outside.slice(0, 4).join(' ') : ''}`);

  await browser.close(); await H.stopEngine(e);
  console.log(`\n${failures ? failures + ' FAILED' : 'ALL PASSED'} in ${((Date.now() - T0) / 1000).toFixed(0)} s`);
  fs.writeFileSync(path.join(OUT, 'check.txt'), results.join('\n') + '\n');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
