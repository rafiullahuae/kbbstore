#!/usr/bin/env node
// KBB Studio — background removal check (v2: transparent, another clip, green screen, main subject, alpha exports).
//
// Test media is drawn here (tools/out/bg/, never committed), each with its exact ground-truth silhouette:
//   presenter.mp4  8 s on a busy moving background: 0-2 s talking with hand gestures and loose hair strands, 2-4 s fast
//                  side-to-side motion, 4-6 s half out of the frame, 6-8 s two people
//   green.mp4      4 s the presenter on a lit green screen, with green spill on the hair and the edges
//   product.mp4    4 s a serum bottle and a cream jar on a table, slow camera push (no person in it)
//   broll.mp4, picture.png   what goes behind ("Another clip", "Picture")
// Measured (through the real render path, export.js openFrames + renderFrame, the export's own frames):
//   mask accuracy   IoU against the truth per segment, before (v1, the shipped pipeline) and after (v2)
//   edges           mean alpha error in a 6 px band round the truth outline and hair-strand recall, at 540×960
//   flicker         false frame-to-frame change (pixels whose truth did not change), IoU jitter (std of per-frame IoU)
//   modes           none (alpha), colour, picture, another clip (a muted clip on the track below), chroma (IoU + spill)
//   subject         U²-Net-p (optional model, downloaded through the engine's installer, deleted at the end)
//   exports         WebM VP9 with alpha + PNG sequence zip + frame PNG: the alpha channel read back with ffmpeg
//   preview=export  the monitor vs the exported MP4 frame; the WebM's alpha vs the in-page alpha
//   history         one undo step per action, redo, save + reload identical; a v1 project draws exactly as the tree
//                   before this lane drew it (--base <ref>, default 350279b8a, run from a git archive of that tree)
//   speed           look pass at 1080×1920 with v2 on (SwiftShader: a Mac GPU is far faster)
//   pictures        shots/bg/*.png at 1440×900, before/after stills: presenter over a landmark, product over a gradient
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/bg-check.cjs [--base <git ref>] [--no-subject]
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'bg'), STUDIO = path.join(OUT, 'studio'), BASESTUDIO = path.join(OUT, 'studio-base'), SHOTS = path.join(ROOT, 'shots', 'bg');
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const BASE = arg('base', '350279b8a'), SUBJECT = !process.argv.includes('--no-subject'), EDITOR_ONLY = process.argv.includes('--editor-only') || process.argv.includes('--legacy-only');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const secs = (t) => ((Date.now() - t) / 1000).toFixed(1) + ' s';
const FPS = 30, GW = 135, GH = 240, EW = 540, EH = 960;
const pct = (x) => (x * 100).toFixed(1) + '%';

// ── the drawn world (runs in the page) ──
const WORLD_JS = `
window.W = (() => {
  const rnd = (s) => () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; };
  // a presenter: shoulders, neck, head, hair with loose strands, two arms with hands; o.mask draws the silhouette only,
  // o.hairOnly the strands only (for the hair recall), o.spill a green rim (green screen)
  function person(x, k, o) {
    const P = o.mask || o.hairOnly ? () => '#fff' : (c) => c, s = o.s * k, cx = o.cx * k, top = o.top * k, t = o.t;
    x.save(); x.translate(cx, top); x.scale(s, s);
    const body = () => { x.beginPath(); x.moveTo(-190, 1000); x.bezierCurveTo(-190, 250, -150, 195, -70, 190); x.lineTo(70, 190); x.bezierCurveTo(150, 195, 190, 250, 190, 1000); x.closePath(); };
    if (!o.hairOnly) {
      x.fillStyle = P(o.top2 || '#1f3b70'); body(); x.fill();
      // arms: the upper arm from the shoulder, the forearm up in a gesture, an open hand
      for (const sd of [-1, 1]) {
        const ph = t * (o.fast ? 5 : 2.2) + (sd > 0 ? 1.3 : 0), lift = 0.5 + 0.5 * Math.sin(ph);
        const sx = sd * 150, sy = 240, ex = sd * (190 + 20 * lift), ey = 420 - 60 * lift, hx = sd * (120 + 70 * lift), hy = 330 - 210 * lift;
        x.strokeStyle = P(o.top2 || '#1f3b70'); x.lineCap = 'round'; x.lineWidth = 64;
        x.beginPath(); x.moveTo(sx, sy); x.lineTo(ex, ey); x.lineTo(hx, hy + 40); x.stroke();
        x.fillStyle = P('#d9a07e'); x.beginPath(); x.ellipse(hx, hy, 30, 38, sd * 0.3, 0, 7); x.fill();
        x.strokeStyle = P('#d9a07e'); x.lineWidth = 11;
        for (let f = 0; f < 4; f++) { const a = -1.9 + f * 0.32 + sd * 0.2; x.beginPath(); x.moveTo(hx + Math.cos(a) * 22, hy + Math.sin(a) * 26); x.lineTo(hx + Math.cos(a) * 58, hy + Math.sin(a) * 62); x.stroke(); }
      }
      x.fillStyle = P('#d9a07e'); x.fillRect(-34, 90, 68, 110);
      x.fillStyle = P('#2b1a12'); x.beginPath(); x.ellipse(0, -15, 100, 122, 0, 0, 7); x.fill();
      x.fillStyle = P('#e2ad8c'); x.beginPath(); x.ellipse(0, 10, 82, 104, 0, 0, 7); x.fill();
    }
    // loose hair strands round the crown and the sides
    const r = rnd(7); x.strokeStyle = P(o.spill ? '#4b6a2a' : '#2b1a12'); x.lineCap = 'round';
    for (let i = 0; i < 70; i++) {
      const a = Math.PI * (1.02 + 0.96 * r()), len = 18 + 34 * r(), w = 1.6 + 2.2 * r();
      const bx = Math.cos(a) * 96, by = -15 + Math.sin(a) * 118, wob = Math.sin(t * 2 + i) * 6;
      x.lineWidth = w; x.beginPath(); x.moveTo(bx, by);
      x.quadraticCurveTo(bx + Math.cos(a) * len * 0.6 + wob, by + Math.sin(a) * len * 0.6, bx + Math.cos(a + 0.25) * len + wob, by + Math.sin(a + 0.25) * len); x.stroke();
    }
    if (!o.mask && !o.hairOnly) {
      x.fillStyle = '#2b1a12'; x.beginPath(); x.ellipse(0, -75, 86, 42, 0, Math.PI, 0); x.fill();
      for (const sd of [-1, 1]) {
        x.fillStyle = '#fff'; x.beginPath(); x.ellipse(sd * 32, 0, 15, 8, 0, 0, 7); x.fill();
        x.fillStyle = '#3a2416'; x.beginPath(); x.arc(sd * 32, 0, 6.5, 0, 7); x.fill();
      }
      x.fillStyle = '#b2484f'; x.beginPath(); x.ellipse(0, 75, 24, 4 + 6 * Math.abs(Math.sin(t * 9)), 0, 0, 7); x.fill();
      if (o.spill) { x.strokeStyle = 'rgba(70,210,90,0.45)'; x.lineWidth = 10; body(); x.stroke(); x.beginPath(); x.ellipse(0, -15, 100, 122, 0, 0, 7); x.stroke(); }
    }
    x.restore();
  }
  function busy(x, W, H, t) {
    const g = x.createLinearGradient(0, 0, W, H); g.addColorStop(0, '#d9c3a5'); g.addColorStop(0.5, '#7fa3b8'); g.addColorStop(1, '#5b6f73'); x.fillStyle = g; x.fillRect(0, 0, W, H);
    const k = W / 540, r = rnd(3);
    for (let i = 0; i < 40; i++) { const px = (r() * 640 - 50 + t * (10 + 20 * r())) % 640 - 50, py = r() * 960, rr = 12 + 50 * r(); x.fillStyle = 'hsla(' + Math.round(r() * 360) + ',55%,' + Math.round(40 + 30 * r()) + '%,0.55)'; x.beginPath(); x.arc(px * k, py * k, rr * k, 0, 7); x.fill(); }
    x.fillStyle = '#5a3d2b'; for (let i = 0; i < 3; i++) x.fillRect(0, (300 + i * 210) * k, W, 14 * k);
    for (let i = 0; i < 18; i++) { x.fillStyle = 'hsl(' + (i * 47 % 360) + ',60%,55%)'; x.fillRect(((i * 61 + t * 15) % 560 - 20) * k, (240 + (i % 3) * 210) * k, 26 * k, 60 * k); }
    x.fillStyle = 'rgba(255,255,255,0.8)'; x.font = (40 * k) + 'px sans-serif'; x.fillText('SALE 50%', (40 + 20 * Math.sin(t)) * k, 120 * k);
  }
  function green(x, W, H, t) {
    const g = x.createRadialGradient(W * 0.5, H * 0.4, 50, W * 0.5, H * 0.5, H * 0.75); g.addColorStop(0, '#3fd060'); g.addColorStop(1, '#1d8f37'); x.fillStyle = g; x.fillRect(0, 0, W, H);
    const r = rnd(5); for (let i = 0; i < 900; i++) { x.fillStyle = 'rgba(0,40,0,' + (0.05 + 0.08 * r()) + ')'; x.fillRect(r() * W, r() * H, 3, 3); }
  }
  function products(x, k, t, mask) {
    const P = mask ? () => '#fff' : (c) => c, z = 1 + 0.08 * t / 4, cx = 270, base = 720;
    x.save(); x.translate(cx * k, base * k); x.scale(z * k, z * k); x.translate(-cx, -base);
    // serum dropper bottle: amber glass, black dropper, white label
    x.fillStyle = P('#a85a1c'); x.beginPath(); x.roundRect(150, 470, 120, 250, 26); x.fill();
    x.fillStyle = P('#111'); x.beginPath(); x.roundRect(178, 400, 64, 80, 10); x.fill(); x.beginPath(); x.ellipse(210, 392, 26, 30, 0, 0, 7); x.fill();
    if (!mask) { x.fillStyle = '#f6efe6'; x.fillRect(165, 560, 90, 90); x.fillStyle = '#c6395f'; x.font = 'bold 22px sans-serif'; x.fillText('KBB', 185, 600); x.font = '15px sans-serif'; x.fillStyle = '#333'; x.fillText('Serum', 186, 625);
      x.fillStyle = 'rgba(255,255,255,0.35)'; x.fillRect(160, 485, 14, 210); }
    // cream jar: white body, gold lid
    x.fillStyle = P('#f3f0ea'); x.beginPath(); x.roundRect(300, 600, 150, 120, 18); x.fill();
    x.fillStyle = P('#c9a24a'); x.beginPath(); x.roundRect(294, 570, 162, 44, 12); x.fill();
    if (!mask) { x.fillStyle = '#c6395f'; x.font = 'bold 20px sans-serif'; x.fillText('GLOW', 345, 668); }
    x.restore();
  }
  function table(x, W, H, t) {
    const k = W / 540, g = x.createLinearGradient(0, 0, 0, H); g.addColorStop(0, '#efe2d0'); g.addColorStop(1, '#cdb79c'); x.fillStyle = g; x.fillRect(0, 0, W, H);
    x.fillStyle = '#7a8f5c'; for (let i = 0; i < 9; i++) { x.beginPath(); x.ellipse((60 + i * 8) * k, (300 - i * 25) * k, 18 * k, 50 * k, i * 0.4, 0, 7); x.fill(); }
    x.fillStyle = '#b69470'; x.fillRect(320 * k, 260 * k, 200 * k, 12 * k);
    for (let i = 0; i < 4; i++) { x.fillStyle = ['#e7b6c1', '#9fc4d4', '#e9d27c', '#c3a6d8'][i]; x.fillRect((340 + i * 44) * k, 200 * k, 30 * k, 60 * k); }
    const w = x.createLinearGradient(0, 720 * k, 0, H); w.addColorStop(0, '#8a5a3b'); w.addColorStop(1, '#5e3b25'); x.fillStyle = w; x.fillRect(0, 715 * k, W, H - 715 * k);
    for (let i = 0; i < 12; i++) { x.strokeStyle = 'rgba(40,20,10,0.25)'; x.lineWidth = 2 * k; x.beginPath(); x.moveTo(0, (740 + i * 20) * k); x.lineTo(W, (745 + i * 20) * k); x.stroke(); }
  }
  // clip definitions: scene + people at time t
  const clips = {
    presenter: { dur: 8, bg: busy, people: (t) => t < 2 ? [{ cx: 270 + Math.sin(t * 1.7) * 14, top: 420, s: 1, t }]
      : t < 4 ? [{ cx: 270 + Math.sin((t - 2) * 7.5) * 150, top: 420, s: 1, t, fast: true }]
      : t < 6 ? [{ cx: 470 + Math.sin(t) * 10, top: 420, s: 1, t }]
      : [{ cx: 150 + Math.sin(t * 1.3) * 10, top: 470, s: 0.72, t, top2: '#7a2440' }, { cx: 395 + Math.sin(t * 1.1 + 1) * 10, top: 455, s: 0.75, t: t + 0.7 }] },
    green: { dur: 4, bg: green, people: (t) => [{ cx: 270 + Math.sin(t * 1.5) * 18, top: 420, s: 1, t, spill: true }] },
    product: { dur: 4, bg: table, products: true, people: () => [] },
    broll: { dur: 4, bg: busy, people: () => [] },
  };
  function draw(c, name, t, kind) {           // kind: 'rgb' | 'mask' | 'hair'
    const x = c.getContext('2d'), W = c.width, H = c.height, k = W / 540, C = clips[name];
    if (kind === 'rgb') C.bg(x, W, H, t); else { x.fillStyle = '#000'; x.fillRect(0, 0, W, H); }
    if (C.products && kind !== 'hair') products(x, k, t, kind === 'mask');
    for (const p of C.people(t)) person(x, k, Object.assign({}, p, { mask: kind === 'mask', hairOnly: kind === 'hair' }));
  }
  function maskBytes(name, t, w, h, kind = 'mask') {
    const c = document.createElement('canvas'); c.width = w; c.height = h; draw(c, name, t, kind);
    const d = c.getContext('2d').getImageData(0, 0, w, h).data, out = new Uint8Array(w * h);
    for (let i = 0; i < out.length; i++) out[i] = d[i * 4];
    return out;
  }
  const b64 = (u8) => { let s = ''; for (let i = 0; i < u8.length; i += 32768) s += String.fromCharCode.apply(null, u8.subarray(i, i + 32768)); return btoa(s); };
  return { clips, draw, maskBytes, b64 };
})();`;

const HELPERS = async () => {
    window.LK = await import('/app/look/index.js'); window.R = await import('/app/core/render.js'); window.E = await import('/app/core/export.js');
    window.P = await import('/app/core/project.js'); window.MS = (await import('/app/core/media.js')).MediaStore; window.V = await import('/app/look/vision.js');
    window.PROJ = (name, dur, kind = 'video') => {
      const p = window.P.createProject({ aspect: '9:16' });
      const m = { id: 'm-' + name, kind, name: name + (kind === 'video' ? '.mp4' : '.png'), src: 'library/bg-test/' + name + (kind === 'video' ? '.mp4' : '.png'), dur, w: 1080, h: 1920, fps: 30, hasAudio: kind === 'video' };
      p.media.push(m);
      p.tracks.find((x) => x.kind === 'video').items.push(window.P.createItem('clip', { id: 'c-' + name, media: m.id, t0: 0, in: 0, out: dur, x: 0, y: 0, w: 1080, h: 1920 }));
      return p;
    };
    /** alpha maps (base64, w×h) of clip `name` with look.bg `fx` at times ts: the colour-difference trick (white bg − black bg) */
    window.ALPHA = async (name, dur, fx, ts, scale) => {
      const p = window.PROJ(name, dur), it = p.tracks.find((x) => x.kind === 'video').items[0], media = new window.MS({ getProject: () => p, engine: null });
      it.fx = [Object.assign({}, fx, { mode: 'color', color: '#ffffff', wrap: 0 })];
      await window.LK.ensureAnalysis(p);
      const W = Math.round(1080 * scale), Hh = Math.round(1920 * scale), c = document.createElement('canvas'); c.width = W; c.height = Hh;
      const x = c.getContext('2d', { willReadFrequently: true }), f = await window.E.openFrames(p, media, scale), out = [];
      try {
        for (const t of ts) {
          await f.load(t);
          it.fx = [Object.assign({}, fx, { mode: 'color', color: '#ffffff', wrap: 0 })]; window.R.renderFrame(x, p, t, { scale, source: f.source });
          const a = x.getImageData(0, 0, W, Hh).data;
          it.fx = [Object.assign({}, fx, { mode: 'color', color: '#000000', wrap: 0 })]; window.R.renderFrame(x, p, t, { scale, source: f.source });
          const b = x.getImageData(0, 0, W, Hh).data, al = new Uint8Array(W * Hh);
          for (let i = 0, j = 0; i < al.length; i++, j += 4) al[i] = Math.max(0, Math.min(255, 255 - Math.round(((a[j] - b[j]) + (a[j + 1] - b[j + 1]) + (a[j + 2] - b[j + 2])) / 3)));
          out.push(window.W.b64(al));
        }
      } finally { f.close(); }
      return out;
    };
  };

async function makeMedia(page) {
  await page.evaluate(WORLD_JS);
  const made = {};
  for (const name of ['presenter', 'green', 'product', 'broll']) {
    const f = path.join(OUT, name + '.mp4'), gt = path.join(OUT, name + '.gt'), dur = await page.evaluate((n) => window.W.clips[n].dur, name);
    made[name] = { file: f, gt, dur, n: Math.round(dur * FPS) };
    if (fs.existsSync(f) && fs.existsSync(gt)) continue;
    const dir = path.join(OUT, name + '-frames'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
    const n = made[name].n, gts = [];
    for (let i = 0; i < n; i++) {
      const r = await page.evaluate(([nm, t]) => { const c = document.createElement('canvas'); c.width = 540; c.height = 960; window.W.draw(c, nm, t, 'rgb'); return { jpg: c.toDataURL('image/jpeg', 0.93), m: window.W.b64(window.W.maskBytes(nm, t, 135, 240)) }; }, [name, i / FPS]);
      fs.writeFileSync(path.join(dir, String(i).padStart(4, '0') + '.jpg'), Buffer.from(r.jpg.split(',')[1], 'base64'));
      gts.push(Buffer.from(r.m, 'base64'));
    }
    ff('-framerate', String(FPS), '-i', path.join(dir, '%04d.jpg'), '-f', 'lavfi', '-i', `sine=frequency=220:duration=${dur}:sample_rate=48000`, '-vf', 'scale=1080:1920:flags=bicubic', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-b:a', '128k', '-shortest', '-movflags', '+faststart', f);
    fs.writeFileSync(gt, Buffer.concat(gts));
    fs.rmSync(dir, { recursive: true, force: true });
  }
  const pic = path.join(OUT, 'picture.png');
  if (!fs.existsSync(pic)) {
    const d = await page.evaluate(() => { const c = document.createElement('canvas'); c.width = 1080; c.height = 1920; const x = c.getContext('2d');
      const g = x.createLinearGradient(0, 0, 0, 1920); g.addColorStop(0, '#5fb3e6'); g.addColorStop(0.6, '#bfe3f2'); g.addColorStop(0.62, '#e9d29a'); g.addColorStop(1, '#d8b56a'); x.fillStyle = g; x.fillRect(0, 0, 1080, 1920);
      x.fillStyle = '#fff6c9'; x.beginPath(); x.arc(800, 380, 130, 0, 7); x.fill(); x.fillStyle = '#2f7da6'; x.fillRect(0, 1150, 1080, 60);
      return c.toDataURL('image/png'); });
    fs.writeFileSync(pic, Buffer.from(d.split(',')[1], 'base64'));
  }
  return made;
}
const gtFrame = (m, i) => { const b = fs.readFileSync(m.gt); const n = GW * GH; return new Uint8Array(b.buffer, b.byteOffset + i * n, n); };

// ── metrics (Node) ──
function iou(a, g) { let I = 0, U = 0; for (let i = 0; i < a.length; i++) { const x = a[i] > 127, y = g[i] > 127; if (x && y) I++; if (x || y) U++; } return U ? I / U : 1; }
function segStats(alphas, gtOf, from, to) {
  const ious = [], fc = [];
  for (let i = from; i < to; i++) {
    ious.push(iou(alphas[i], gtOf(i)));
    if (i + 1 < to) {
      const a = alphas[i], b = alphas[i + 1], g0 = gtOf(i), g1 = gtOf(i + 1); let n = 0, ch = 0;
      for (let j = 0; j < a.length; j++) { if ((g0[j] > 127) !== (g1[j] > 127)) continue; n++; ch += Math.abs(a[j] - b[j]) / 255; }
      fc.push(ch / Math.max(1, n));
    }
  }
  const mean = (v) => v.reduce((s, x) => s + x, 0) / Math.max(1, v.length);
  const m = mean(ious), sd = Math.sqrt(mean(ious.map((x) => (x - m) ** 2)));
  return { iou: +m.toFixed(4), jitter: +sd.toFixed(4), flicker: +mean(fc).toFixed(5), worst: +Math.min(...ious).toFixed(4) };
}
/** pixels within r px of the truth outline */
function band(g, w, h, r) {
  const out = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const v = g[y * w + x] > 127;
    let edge = false;
    for (let dy = -r; dy <= r && !edge; dy += 2) for (let dx = -r; dx <= r; dx += 2) { const X = x + dx, Y = y + dy; if (X < 0 || Y < 0 || X >= w || Y >= h) continue; if ((g[Y * w + X] > 127) !== v) { edge = true; break; } }
    out[y * w + x] = edge ? 1 : 0;
  }
  return out;
}
const b64u8 = (s) => { const b = Buffer.from(s, 'base64'); return new Uint8Array(b.buffer, b.byteOffset, b.length); };

(async () => {
  const T0 = Date.now();
  // ── the studio folders: this tree and (for the old-project check) the tree before this lane ──
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const e = await H.startEngine(STUDIO);
  const browser = await require('playwright').chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined,
    args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
  const page = await context.newPage();
  page.setDefaultTimeout(240000);
  const errors = [], outside = []; let nreq = 0;
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('worker', (w) => w.on('console', (m) => { if (m.type() === 'error') errors.push('[worker] ' + m.text()); }));
  page.on('dialog', (d) => d.accept());           // the one-time model download question (the test says yes)
  context.on('request', (r) => { nreq++; const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  let t = Date.now();
  const M = await makeMedia(page);
  console.log(`test media ready in ${secs(t)}`);
  const LIB = path.join(STUDIO, 'library', 'bg-test'); fs.mkdirSync(LIB, { recursive: true });
  for (const f of ['presenter.mp4', 'green.mp4', 'product.mp4', 'broll.mp4', 'picture.png']) fs.copyFileSync(path.join(OUT, f), path.join(LIB, f));
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name) => { await page.waitForTimeout(500); await page.screenshot({ path: path.join(SHOTS, name + '.png') }); };
  const settle = async () => { await page.waitForTimeout(300); await ev(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))); };
  const seekDraw = async (tt) => { await ev((x) => window.kbbEditor.seek(x), tt); await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 30000 }); await settle(); };

  // ── 1. measurements on standalone projects (library media, the export's exact frames) ──
  await ev(HELPERS);
  const V1 = { type: 'look.bg', mode: 'color' }, pres = M.presenter;
  let CH = null, subjectOK = false;
  if (!EDITOR_ONLY) {
  const V1 = { type: 'look.bg', mode: 'color' };                               // the shipped pipeline (no q)
  const V2 = { type: 'look.bg', q: 2, key: 'person', feather: 0.1, grow: 0, smooth: 0.5 };
  const V2raw = Object.assign({}, V2, { smooth: 0 });
  const allT = Array.from({ length: pres.n }, (_, i) => i / FPS);
  const SEGS = [['talking + hands', 0, 60], ['fast motion', 60, 120], ['half out of frame', 120, 180], ['two people', 180, 240]];
  const measure = async (fx, label) => {
    const tt = Date.now(), al = (await ev(([fx, ts]) => window.ALPHA('presenter', 8, fx, ts, 0.125), [fx, allT])).map(b64u8);
    const out = { ms: Date.now() - tt };
    for (const [sname, a, b] of SEGS) out[sname] = segStats(al, (i) => gtFrame(pres, i), a, b);
    out.all = segStats(al, (i) => gtFrame(pres, i), 0, pres.n);
    console.log(label, JSON.stringify(out));
    return { out, al };
  };
  t = Date.now();
  const before = await measure(V1, 'v1');
  const afterRaw = await measure(V2raw, 'v2 smooth 0');
  const after = await measure(V2, 'v2');
  numbers.presenter = { before: before.out, v2NoTemporal: afterRaw.out, after: after.out };
  for (const [sname] of SEGS) {
    const b = before.out[sname], a = after.out[sname];
    ok(a.iou >= b.iou - 0.01, `mask IoU · ${sname}: ${pct(b.iou)} → ${pct(a.iou)} (worst frame ${pct(b.worst)} → ${pct(a.worst)})`);
  }
  ok(after.out.all.flicker < before.out.all.flicker * 0.8, `flicker (false frame-to-frame change where the truth is still): ${(before.out.all.flicker * 100).toFixed(3)}% → ${(after.out.all.flicker * 100).toFixed(3)}% of the frame (v2 without the temporal filter ${(afterRaw.out.all.flicker * 100).toFixed(3)}%); IoU jitter ${pct(before.out.all.jitter)} → ${pct(after.out.all.jitter)}`);
  ok(after.out.all.iou >= before.out.all.iou, `whole presenter clip IoU ${pct(before.out.all.iou)} → ${pct(after.out.all.iou)} (${secs(t)})`);

  // edges and hair at 540×960 (talking segment)
  const ET = [0.3, 0.8, 1.3, 1.8];
  const gtE = await ev((ts) => ts.map((x) => ({ m: window.W.b64(window.W.maskBytes('presenter', x, 540, 960)), hair: window.W.b64(window.W.maskBytes('presenter', x, 540, 960, 'hair')) })), ET);
  const edge = {};
  for (const [lab, fx] of [['before', V1], ['after', V2]]) {
    const al = (await ev(([fx, ts]) => window.ALPHA('presenter', 8, fx, ts, 0.5), [fx, ET])).map(b64u8);
    let bs = 0, bn = 0, hs = 0, hn = 0;
    al.forEach((a, i) => {
      const g = b64u8(gtE[i].m), hr = b64u8(gtE[i].hair), bd = band(g, EW, EH, 6);
      for (let j = 0; j < a.length; j++) {
        if (bd[j]) { bs += Math.abs(a[j] - g[j]) / 255; bn++; }
        if (hr[j] > 160) { hs += a[j] > 127 ? 1 : 0; hn++; }
      }
    });
    edge[lab] = { bandMAE: +(bs / bn).toFixed(4), hairRecall: +(hs / hn).toFixed(4) };
    if (lab === 'after') fs.writeFileSync(path.join(OUT, 'alpha-after-540.raw'), Buffer.from(al[1]));
  }
  numbers.edges = edge;
  ok(edge.after.bandMAE < edge.before.bandMAE, `edge quality at 540×960: mean alpha error in the 6 px band round the outline ${pct(edge.before.bandMAE)} → ${pct(edge.after.bandMAE)}; hair strands kept ${pct(edge.before.hairRecall)} → ${pct(edge.after.hairRecall)}`);

  // green screen: person segmenter vs chroma key (IoU) and the green spill left on the person
  const gr = M.green, gT = Array.from({ length: gr.n }, (_, i) => i / FPS).filter((_, i) => i % 3 === 0), gi = (i) => i * 3;
  const keyCol = await ev(async () => { const p = window.PROJ('green', 4); const A = await import('/app/look/analyze.js'); const px = await A.samplePixels(p, p.tracks.find((x) => x.kind === 'video').items[0], 2, 64); return window.LK.autoKeyColor(px); });
  CH = { type: 'look.bg', q: 2, key: 'chroma', keyColor: keyCol, tol: 0.1, soft: 0.08, spill: 0.6, feather: 0.1, grow: 0, smooth: 0.5 };
  const gStat = {};
  for (const [lab, fx] of [['person segmenter (v1)', V1], ['person segmenter (v2)', V2], ['chroma key', CH]]) {
    const al = (await ev(([fx, ts]) => window.ALPHA('green', 4, fx, ts, 0.125), [fx, gT])).map(b64u8);
    gStat[lab] = segStats(al, (i) => gtFrame(gr, gi(i)), 0, al.length);
  }
  // spill: on the person's edge pixels, how much green sticks out above red/blue (source vs keyed output)
  const spill = await ev(async ([fx]) => {
    const p = window.PROJ('green', 4), it = p.tracks.find((x) => x.kind === 'video').items[0], media = new window.MS({ getProject: () => p, engine: null });
    const c = document.createElement('canvas'); c.width = 540; c.height = 960; const x = c.getContext('2d', { willReadFrequently: true }), f = await window.E.openFrames(p, media, 0.5);
    const g = window.W.maskBytes('green', 1.0, 540, 960);
    await f.load(1.0);
    const meas = () => { const d = x.getImageData(0, 0, 540, 960).data; let s = 0, n = 0; for (let i = 0; i < g.length; i++) { if (g[i] < 250) continue; const yy = Math.floor(i / 540), xx = i % 540; let edge = false; for (const [dx, dy] of [[6, 0], [-6, 0], [0, 6], [0, -6]]) { const X = xx + dx, Y = yy + dy; if (X >= 0 && Y >= 0 && X < 540 && Y < 960 && g[Y * 540 + X] < 128) edge = true; } if (!edge) continue; const j = i * 4; s += Math.max(0, d[j + 1] - Math.max(d[j], d[j + 2])); n++; } return s / Math.max(1, n); };
    it.fx = []; window.R.renderFrame(x, p, 1.0, { scale: 0.5, source: f.source }); const src = meas();
    it.fx = [Object.assign({}, fx, { mode: 'color', color: '#808080' })]; window.R.renderFrame(x, p, 1.0, { scale: 0.5, source: f.source }); const out = meas();
    f.close(); return { src, out };
  }, [CH]);
  numbers.green = { keyColor: keyCol, stats: gStat, spill };
  ok(gStat['chroma key'].iou > 0.9 && gStat['chroma key'].iou >= gStat['person segmenter (v2)'].iou, `green screen (auto key colour ${keyCol}): IoU chroma key ${pct(gStat['chroma key'].iou)} vs person segmenter v1 ${pct(gStat['person segmenter (v1)'].iou)} / v2 ${pct(gStat['person segmenter (v2)'].iou)}; flicker ${(gStat['chroma key'].flicker * 100).toFixed(3)}%`);
  ok(spill.out < spill.src * 0.5, `spill suppression: green cast on the person's edge ${spill.src.toFixed(1)} → ${spill.out.toFixed(1)} (mean G above max(R,B), 0-255)`);

  // product: the person segmenter cannot see a bottle; U²-Net-p (optional model) can
  const pr = M.product, pT = Array.from({ length: pr.n }, (_, i) => i / FPS).filter((_, i) => i % 6 === 0), pi = (i) => i * 6;
  const prodPerson = segStats((await ev(([fx, ts]) => window.ALPHA('product', 4, fx, ts, 0.125), [V2, pT])).map(b64u8), (i) => gtFrame(pr, pi(i)), 0, pT.length);
  numbers.product = { person: prodPerson };
  if (SUBJECT) {
    t = Date.now();
    const instP = ev(async () => { const S = await import('/app/look/subject.js'); try { await S.installSubjectModel(); return { ok: await S.subjectReady() }; } catch (e) { return { err: e.message }; } });
    // the app's own one-time question (with the size and the licence), answered Allow
    const allow = page.locator('.modal button.primary:has-text("Allow")');
    await allow.waitFor({ timeout: 30000 }).then(async () => { await shot('bg-00-subject-model-allow'); await allow.click(); }).catch(() => {});
    const inst = await instP;
    const mfile = path.join(STUDIO, 'models', 'BritishWerewolf', 'U-2-Netp', 'onnx', 'model.onnx');
    ok(inst.ok && fs.existsSync(mfile) && fs.statSync(mfile).size === 4574861, `subject model: one Allow, downloaded through the engine's checked installer (${fs.existsSync(mfile) ? fs.statSync(mfile).size : 0} bytes, sha256 verified by the engine) in ${secs(t)}${inst.err ? ' — ' + inst.err : ''}`);
    if (inst.ok) {
      const SUB = Object.assign({}, V2, { key: 'subject' });
      t = Date.now();
      const al = (await ev(([fx, ts]) => window.ALPHA('product', 4, fx, ts, 0.125), [SUB, pT])).map(b64u8);
      const st = segStats(al, (i) => gtFrame(pr, pi(i)), 0, al.length);
      numbers.product.subject = st;
      subjectOK = st.iou > 0.8;
      ok(subjectOK && st.iou > prodPerson.iou + 0.3, `Cut out the main subject (U²-Net-p) on the product clip: IoU ${pct(st.iou)} (the person segmenter: ${pct(prodPerson.iou)}), flicker ${(st.flicker * 100).toFixed(3)}%, ${secs(t)} incl. analysis`);
    }
  }

  // speed: the look pass at 1080×1920 with v2 (refined edges), v1 for reference
  const speed = await ev(async () => {
    const p = window.PROJ('presenter', 8), it = p.tracks.find((x) => x.kind === 'video').items[0], media = new window.MS({ getProject: () => p, engine: null });
    const res = {};
    for (const [lab, fx] of [['v1', { type: 'look.bg', mode: 'gradient', colors: ['#C6395F', '#2A2228'] }], ['v2', { type: 'look.bg', q: 2, key: 'person', mode: 'gradient', colors: ['#C6395F', '#2A2228'], feather: 0.1, smooth: 0.5, wrap: 0.3 }], ['v2 transparent', { type: 'look.bg', q: 2, key: 'person', mode: 'none', feather: 0.1, smooth: 0.5 }]]) {
      it.fx = [fx]; await window.LK.ensureAnalysis(p);
      const f = await window.E.openFrames(p, media, 1); await f.load(1.0); const src = f.source(it);
      const g = window.LK.gl(), gl = g.gl, px = new Uint8Array(4);
      for (let i = 0; i < 3; i++) { window.LK.processLook(src, it, 1.0, p, { box: { w: 1080, h: 1920 }, scale: 1, preview: false }); gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, px); }
      const t0 = performance.now(), N = 6;
      for (let i = 0; i < N; i++) { window.LK.processLook(src, it, 1.0 + i / 30, p, { box: { w: 1080, h: 1920 }, scale: 1, preview: false }); gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, px); }
      res[lab] = +((performance.now() - t0) / N).toFixed(1);
      f.close();
    }
    return res;
  });
  numbers.speedMs = speed;
  ok(speed.v2 < speed.v1 * 3 + 30, `look pass at 1080×1920 (SwiftShader, CPU): v1 ${speed.v1} ms, v2 ${speed.v2} ms, v2 transparent ${speed['v2 transparent']} ms per frame (masks come from the cache; a Mac GPU runs this in a few ms)`);

  }
  // ── 2. an old (v1) project draws exactly as the tree before this lane drew it ──
  try {
    fs.rmSync(BASESTUDIO, { recursive: true, force: true }); fs.mkdirSync(path.join(BASESTUDIO, 'models'), { recursive: true });
    const tar = execFileSync('git', ['-C', execFileSync('git', ['-C', ROOT, 'rev-parse', '--show-toplevel']).toString().trim(), 'archive', `${BASE}:studio/reel-studio`, 'app'], { maxBuffer: 1 << 28 });
    execFileSync('tar', ['-x', '-C', BASESTUDIO], { input: tar });
    for (const d of ['vendor', 'brands', 'library']) fs.cpSync(path.join(STUDIO, d), path.join(BASESTUDIO, d), { recursive: true });
    fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(BASESTUDIO, 'models', 'manifest.json'));
    const eb = await H.startEngine(BASESTUDIO);
    const pb = await context.newPage();
    await pb.goto(eb.url + '/app/'); await pb.waitForLoadState('load'); await pb.waitForTimeout(1500);   // only its modules are used
    const legacyFrames = async (pg) => pg.evaluate(async () => {
      const LK = await import('/app/look/index.js'), R = await import('/app/core/render.js'), E = await import('/app/core/export.js'), P = await import('/app/core/project.js'), MS = (await import('/app/core/media.js')).MediaStore;
      const p = P.createProject({ aspect: '9:16' }), m = { id: 'm-p', kind: 'video', name: 'presenter.mp4', src: 'library/bg-test/presenter.mp4', dur: 8, w: 1080, h: 1920, fps: 30, hasAudio: true };
      p.media.push(m);
      const it = P.createItem('clip', { id: 'c-p', media: m.id, t0: 0, in: 0, out: 8, x: 0, y: 0, w: 1080, h: 1920 });
      p.tracks.find((x) => x.kind === 'video').items.push(it);
      const out = [], media = new MS({ getProject: () => p, engine: null });
      for (const fx of [{ type: 'look.bg', mode: 'blur' }, { type: 'look.bg', mode: 'color', color: '#16161c' }, { type: 'look.bg', mode: 'gradient', colors: ['#C6395F', '#2A2228'] }]) {
        it.fx = [{ type: 'look.grade', look: 'warm-golden', amount: 0.8 }, fx];
        await LK.ensureAnalysis(p);
        const c = document.createElement('canvas'); c.width = 270; c.height = 480; const x = c.getContext('2d', { willReadFrequently: true });
        const rd = await media.openReader(m.id);
        for (const t of [0.5, 2.5]) { const fr = await rd.frame(t); R.renderFrame(x, p, t, { scale: 0.25, source: () => fr }); out.push(Array.from(x.getImageData(0, 0, 270, 480).data.filter((_, i) => i % 4 !== 3))); }
        rd.close?.();
      }
      return out;
    });
    const A = await legacyFrames(page), B = await legacyFrames(pb);
    let maxd = 0, sum = 0, n = 0;
    A.forEach((a, k) => { for (let i = 0; i < a.length; i++) { const d = Math.abs(a[i] - B[k][i]); maxd = Math.max(maxd, d); sum += d; n++; } });
    numbers.legacy = { mean: +(sum / n).toFixed(4), max: maxd, base: BASE };
    ok(sum / n < 0.05 && maxd <= 2, `an old project (v1 background: blur, colour, gradient, under a colour look) draws as the tree before this lane (${BASE}) drew it: mean diff ${(sum / n).toFixed(4)}/255, max ${maxd}`);
    await pb.close(); await H.stopEngine(eb);
  } catch (err) { ok(false, 'old-project comparison could not run: ' + err.message); }

  if (process.argv.includes('--legacy-only')) { await browser.close(); await H.stopEngine(e); process.exit(failures ? 1 : 0); }
  if (!CH) { const kc = '#259d4a'; CH = { type: 'look.bg', q: 2, key: 'chroma', keyColor: kc, tol: 0.1, soft: 0.08, spill: 0.6, feather: 0.1, grow: 0, smooth: 0.5 }; }
  // ── 3. the editor: one click, every mode, placement, exports, history ──
  await page.setInputFiles('.home-drop input[type=file]', pres.file);
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await ev(async () => { window.LK = await import('/app/look/index.js'); window.CTX = window.kbbEditor.context(); window.HI = await import('/app/core/history.js'); });
  const clipId = await ev(() => window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0].id);
  await ev((id) => window.kbbEditor.select([id]), clipId);
  await ev(() => window.kbbEditor.showSide('inspector'));
  await seekDraw(1.0);
  await shot('bg-01-inspector-adjust-before');
  const doit = await ev(() => [...document.querySelectorAll('.doit-btn')].map((b) => b.textContent));
  ok(doit.some((x) => /Remove background/.test(x)), `"Do it for me" has 🧍 Remove background (${doit.join(' · ')})`);
  const nUndo0 = await ev(() => window.kbbEditor.history.undoStack.length);
  t = Date.now();
  await page.click('.adj [data-act="remove-bg"]');
  await page.waitForFunction(() => window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0].fx.some((f) => f.type === 'look.bg' && f.q === 2), null, { timeout: 60000 });
  await page.waitForFunction(() => document.querySelector('.mod-progress')?.hidden !== false && window.LK.hasMask(window.kbbEditor.project.media[0].id), null, { timeout: 300000, polling: 500 });
  await ev(() => window.kbbEditor.inspector.render(true));
  await seekDraw(1.0);
  const nUndo1 = await ev(() => window.kbbEditor.history.undoStack.length);
  ok(nUndo1 === nUndo0 + 1, `Inspector → 🎚 Adjust → 🧍 Remove background: one click, one undo step, person found in ${secs(t)} (30 fps mask track)`);
  await page.evaluate(() => document.querySelector('[data-sec="background"]')?.scrollIntoView());
  await shot('bg-02-inspector-background-blur');

  // the modes, from the inspector's 🧍 Background (each chip applies at once)
  const monitorAt = (x, y) => ev(([x, y]) => { const c = window.kbbEditor.preview.canvas, d = c.getContext('2d').getImageData(Math.round(x * c.width), Math.round(y * c.height), 1, 1).data; return [d[0], d[1], d[2]]; }, [x, y]);
  const BGPT = [0.08, 0.12];   // a point on the background (top-left), PERSONPT on the face
  const PERSONPT = [0.5, 0.42];
  await page.click('[data-sec="background"] .bgp-chips .chip[data-bg="color"]');
  await page.waitForTimeout(400);
  await ev(() => { const it = window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0]; window.LK.setBg(window.CTX, { color: '#2050d0' }, { ids: [it.id] }); window.kbbEditor.history.seal(); });
  await seekDraw(1.0);
  const cBg = await monitorAt(...BGPT), cFace = await monitorAt(...PERSONPT);
  ok(Math.abs(cBg[0] - 0x20) < 12 && Math.abs(cBg[2] - 0xd0) < 12 && cFace[0] > 150, `mode Colour: the background is the colour (${cBg}) and the face stays (${cFace})`);
  await shot('bg-03-mode-colour');
  // picture + another clip need media in the bin
  await page.setInputFiles('input[type=file][accept="video/*,audio/*,image/*"]', [path.join(OUT, 'picture.png'), M.broll.file]);
  await page.waitForFunction(() => window.kbbEditor.project.media.length >= 3, null, { timeout: 60000 });
  await ev(() => window.kbbEditor.inspector.render(true));
  await page.click('[data-sec="background"] .bgp-chips .chip[data-bg="image"]');
  await page.waitForFunction(() => window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0].fx.find((f) => f.type === 'look.bg')?.mode === 'image');
  await page.waitForTimeout(600); await seekDraw(1.0);
  const pBg = await monitorAt(...BGPT);
  ok(pBg[2] > pBg[0] + 40, `mode Picture: the sky picture is behind (${pBg})`);
  await shot('bg-04-mode-picture');
  await page.click('[data-sec="background"] .bgp-chips .chip[data-bg="clip"]');
  await page.waitForFunction(() => window.kbbEditor.project.tracks.some((tr) => tr.items.some((i) => i.media && i.muted && window.kbbEditor.project.media.find((m) => m.id === i.media)?.name.includes('broll'))), null, { timeout: 30000 });
  await page.waitForTimeout(600); await seekDraw(1.0);
  const lay = await ev(() => { const p = window.kbbEditor.project, vi = p.tracks.findIndex((x) => x.kind === 'video'), bi = p.tracks.findIndex((tr) => tr.items.some((i) => i.muted && i.volume === 0)); const it = p.tracks[vi].items[0]; return { vi, bi, name: p.tracks[bi]?.name, n: p.tracks[bi]?.items.length, end: Math.max(...p.tracks[bi].items.map((i) => i.t0 + (i.out - i.in))), clipEnd: it.t0 + (it.out - it.in) }; });
  const clBg = await monitorAt(...BGPT);
  ok(lay.bi >= 0 && lay.bi < lay.vi && Math.abs(lay.end - lay.clipEnd) < 0.05, `mode Another clip: the b-roll goes on a track UNDER the presenter (“${lay.name}”, ${lay.n} piece${lay.n > 1 ? 's' : ''} looped to ${lay.end.toFixed(2)} s = the clip's end), muted; the monitor shows it behind (${clBg})`);
  await shot('bg-05-mode-another-clip');
  await page.click('[data-sec="background"] .bgp-chips .chip[data-bg="none"]');
  await page.waitForTimeout(500); await seekDraw(1.0);
  const nBg = await monitorAt(...BGPT);
  ok(Math.abs(nBg[0] - clBg[0]) < 20 && Math.abs(nBg[2] - clBg[2]) < 20, `mode None (transparent): the person floats over the track below (the b-roll still shows: ${nBg})`);
  // placement: smaller, bottom right, over the b-roll
  await page.click('[data-sec="background"] .bgp-chips .chip[data-place="right"]');
  await page.waitForTimeout(400); await seekDraw(1.0);
  const box = await ev(() => { const it = window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0]; return [it.x, it.y, it.w, it.h]; });
  ok(box[0] > 200 && box[1] > 400 && box[0] + box[2] === 1080 && box[1] + box[3] === 1920, `placement: Right puts the cut-out at the bottom right, ${Math.round(box[2] / 10.8)}% size (box ${box.join(', ')}), drawn over the b-roll`);
  await shot('bg-06-transparent-placed-over-broll');
  await page.click('[data-sec="background"] .bgp-chips .chip[data-place="full"]'); await page.waitForTimeout(300);

  // undo / redo
  const fxNow = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
  await ev(() => window.kbbEditor.history.undo());
  const fxUndo = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
  await ev(() => window.kbbEditor.history.redo());
  const fxRedo = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
  ok(fxUndo !== fxNow && fxRedo === fxNow, 'undo / redo: each background change is one step and comes back identical');

  // transparent exports: the cut-out clip as WebM with alpha, as PNGs, and one frame
  t = Date.now();
  const exW = await ev(async (id) => { const r = await window.LK.exportCutout(window.CTX, { what: 'clip', format: 'webm', ids: [id] }); return { file: r.file, note: r.note, frames: r.frames }; }, clipId);
  const wf = path.join(STUDIO, exW.file);
  const probe = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'stream=codec_name,codec_type,width,height:stream_tags=alpha_mode', '-of', 'json', wf]).toString()).streams;
  const vs = probe.find((s) => s.codec_type === 'video'), as = probe.find((s) => s.codec_type === 'audio');
  const rgbaAt = (file, tt, w, h, dec = []) => { const b = execFileSync('ffmpeg', ['-v', 'error', ...dec, '-ss', tt.toFixed(3), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgba', '-'], { maxBuffer: 1 << 28 }); return new Uint8Array(b.buffer, b.byteOffset, b.length); };
  const wa = rgbaAt(wf, 1.0, GW, GH, ['-c:v', 'libvpx-vp9']);
  const walpha = new Uint8Array(GW * GH); let a0 = 0, a255 = 0; for (let i = 0; i < walpha.length; i++) { walpha[i] = wa[i * 4 + 3]; a0 += walpha[i] < 8; a255 += walpha[i] > 247; }
  const webIoU = iou(walpha, gtFrame(pres, 30));
  numbers.webm = { file: path.basename(wf), size: fs.statSync(wf).size, codec: vs?.codec_name, alpha_mode: vs?.tags?.alpha_mode, audio: as?.codec_name, transparentShare: +(a0 / walpha.length).toFixed(3), opaqueShare: +(a255 / walpha.length).toFixed(3), iouVsTruth: +webIoU.toFixed(4), note: exW.note };
  ok(vs?.codec_name === 'vp9' && vs?.tags?.alpha_mode === '1' && a0 / walpha.length > 0.3 && a255 / walpha.length > 0.15 && webIoU > 0.8,
    `⬇ Cut-out video: WebM VP9 with alpha (${exW.note}), ffprobe alpha_mode=${vs?.tags?.alpha_mode}, sound ${as?.codec_name || 'none'}; libvpx decodes RGBA: ${pct(a0 / walpha.length)} fully transparent, ${pct(a255 / walpha.length)} opaque, IoU vs the truth ${pct(webIoU)} (${secs(t)})`);
  // export = preview: the WebM's alpha vs the in-page alpha of the same frame
  const pageAl = b64u8((await ev(async ([id]) => {
    const ed = window.kbbEditor, p = ed.project, R = await import('/app/core/render.js'), E = await import('/app/core/export.js');
    const c = document.createElement('canvas'); c.width = 135; c.height = 240; const x = c.getContext('2d', { willReadFrequently: true });
    const P2 = structuredClone(p); for (const tr of P2.tracks) tr.items = tr.items.filter((i) => i.id === id);
    const f = await E.openFrames(P2, ed.media, 0.125); await f.load(1.0); R.renderFrame(x, P2, 1.0, { scale: 0.125, source: f.source, transparent: true }); f.close();
    const d = x.getImageData(0, 0, 135, 240).data, a = new Uint8Array(135 * 240); for (let i = 0; i < a.length; i++) a[i] = d[i * 4 + 3]; return [window.W ? window.W.b64(a) : btoa(String.fromCharCode(...a))];
  }, [clipId]))[0]);
  let ad = 0; for (let i = 0; i < pageAl.length; i++) ad += Math.abs(pageAl[i] - walpha[i]);
  ok(ad / pageAl.length < 6, `export = preview (alpha): the WebM's alpha vs the page's at 1.0 s, mean diff ${(ad / pageAl.length).toFixed(2)}/255`);
  t = Date.now();
  const exP = await ev(async (id) => { const r = await window.LK.exportCutout(window.CTX, { what: 'clip', format: 'png', ids: [id] }); return { file: r.file, note: r.note, frames: r.frames }; }, clipId);
  const zf = path.join(STUDIO, exP.file), zdir = path.join(OUT, 'zip'); fs.rmSync(zdir, { recursive: true, force: true }); fs.mkdirSync(zdir);
  const uz = spawnSync('unzip', ['-q', '-o', zf, '-d', zdir]);
  const pngs = fs.existsSync(zdir) ? fs.readdirSync(zdir).filter((x) => x.endsWith('.png')).sort() : [];
  let pngInfo = null;
  if (pngs.length) {
    const pf = path.join(zdir, pngs[30] || pngs[0]);
    const pp = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'stream=pix_fmt,width,height', '-of', 'json', pf]).toString()).streams[0];
    const pa = rgbaAt(pf, 0, GW, GH); let z = 0; const al = new Uint8Array(GW * GH); for (let i = 0; i < al.length; i++) { al[i] = pa[i * 4 + 3]; z += al[i] < 8; }
    pngInfo = { pix_fmt: pp.pix_fmt, size: [pp.width, pp.height], transparent: +(z / al.length).toFixed(3), iou: +iou(al, gtFrame(pres, 30)).toFixed(4) };
  }
  numbers.png = { frames: pngs.length, unzip: uz.status, zipBytes: fs.existsSync(zf) ? fs.statSync(zf).size : 0, ...pngInfo };
  ok(uz.status === 0 && pngs.length === exP.frames && pngInfo?.pix_fmt === 'rgba' && pngInfo.transparent > 0.3 && pngInfo.iou > 0.8, `⬇ PNG sequence: a valid zip (unzip ${uz.status === 0 ? 'OK' : 'failed'}) of ${pngs.length} RGBA PNGs ${pngInfo?.size?.join('×')}, frame 31: ${pct(pngInfo?.transparent || 0)} transparent, IoU vs the truth ${pct(pngInfo?.iou || 0)} (${secs(t)})`);
  await seekDraw(1.0);
  const exF = await ev(async (id) => (await window.LK.exportFrame(window.CTX, { ids: [id] })).file, clipId);
  const ff1 = path.join(STUDIO, exF), fp = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'stream=pix_fmt,width,height', '-of', 'json', ff1]).toString()).streams[0];
  const fa = rgbaAt(ff1, 0, GW, GH); let fz = 0; for (let i = 0; i < GW * GH; i++) fz += fa[i * 4 + 3] < 8;
  ok(fp.pix_fmt === 'rgba' && fp.width === 1080 && fz / (GW * GH) > 0.3, `⬇ This frame (PNG): ${fp.width}×${fp.height} ${fp.pix_fmt}, ${pct(fz / (GW * GH))} transparent`);
  fs.copyFileSync(ff1, path.join(OUT, 'frame-cutout.png'));

  // preview = export (MP4): the monitor vs the exported frame, background Picture
  await page.click('[data-sec="background"] .bgp-chips .chip[data-bg="gradient"]'); await page.waitForTimeout(500);
  await seekDraw(1.0);
  const mon = await ev(() => { const c = window.kbbEditor.preview.canvas, x = document.createElement('canvas'); x.width = 135; x.height = 240; const g = x.getContext('2d'); g.imageSmoothingQuality = 'high'; g.drawImage(c, 0, 0, 135, 240); return Array.from(g.getImageData(0, 0, 135, 240).data.filter((_, i) => i % 4 !== 3)); });
  const mp4 = await ev(async () => { const E = await import('/app/core/export.js'), ed = window.kbbEditor, p = ed.project; const r = await E.exportMp4(p, ed.media, { quality: 'high', duration: 1.5 }); const file = `workspaces/${p.workspace}/exports/bg-same.mp4`; await ed.engine.writeFile(file, r.blob); return file; });
  const ex = rgbaAt(path.join(STUDIO, mp4), 1.0, GW, GH), exRGB = Array.from(ex.filter((_, i) => i % 4 !== 3));
  let md = 0; for (let i = 0; i < mon.length; i++) md += Math.abs(mon[i] - exRGB[i]); md /= mon.length;
  numbers.previewVsExport = +md.toFixed(2);
  ok(md < 6, `preview = export: the monitor vs the exported MP4 at 1.0 s (v2 cut-out over the brand gradient), mean diff ${md.toFixed(2)}/255`);

  // save + reload: identical project, same picture
  const before2 = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
  { const d = await ev(() => window.kbbEditor.preview.canvas.toDataURL('image/png')); fs.writeFileSync(path.join(OUT, 'reload-before.png'), Buffer.from(d.split(',')[1], 'base64')); }
  const fr0 = await ev(() => { const c = window.kbbEditor.preview.canvas; return Array.from(c.getContext('2d').getImageData(0, 0, c.width, c.height).data.filter((_, i) => i % 16 === 0)); });
  await ev(() => window.kbbEditor.save());
  await page.reload();
  await page.waitForSelector('.tl-item', { timeout: 60000 }); await page.waitForFunction(() => window.kbbEditor?.project);
  await ev(async () => { window.LK = await import('/app/look/index.js'); window.CTX = window.kbbEditor.context(); });
  await page.evaluate(WORLD_JS); await ev(HELPERS);
  await page.waitForFunction(() => window.LK.maskCoverageAt(window.kbbEditor.project.media[0].id, 1.0) != null && window.LK.maskCoverageAt(window.kbbEditor.project.media[0].id, 1.04) != null, null, { timeout: 300000, polling: 500 });
  await seekDraw(1.0); await page.waitForTimeout(800); await seekDraw(1.0);
  const after2 = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
  { const d = await ev(() => window.kbbEditor.preview.canvas.toDataURL('image/png')); fs.writeFileSync(path.join(OUT, 'reload-after.png'), Buffer.from(d.split(',')[1], 'base64')); }
  const fr1 = await ev(() => { const c = window.kbbEditor.preview.canvas; return Array.from(c.getContext('2d').getImageData(0, 0, c.width, c.height).data.filter((_, i) => i % 16 === 0)); });
  let rd = 0; for (let i = 0; i < fr0.length; i++) rd += Math.abs(fr0[i] - fr1[i]); rd = fr0.length === fr1.length ? rd / fr0.length : 999;
  ok(after2 === before2 && rd < 1.5, `save + reload: the background settings reopen ${after2 === before2 ? 'identical' : 'DIFFERENT'} and the monitor draws the same picture (mean diff ${rd.toFixed(2)}/255)`);

  // the Look tab section (Cut out / Background / Edges / Export), and the landmark still for the portrait ad
  await page.click('.side-tab[data-side="mod:look"]'); await page.waitForTimeout(400);
  await ev(() => [...document.querySelectorAll('.lk details.sec summary')].find((s) => s.textContent === 'Background')?.scrollIntoView());
  await shot('bg-07-look-tab-background');
  await page.click('.lk-chips .chip[data-bg="landmark"]');
  await page.locator('.lk select[aria-label="Country"]').selectOption('fr').catch(() => page.locator('.lk select[aria-label="Country"]').selectOption({ index: 0 }));
  await page.click('.btn.primary:has-text("Remove background")');
  await page.waitForFunction(() => window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0].fx.find((f) => f.type === 'look.bg')?.mode === 'landmark');
  await page.waitForTimeout(800); await seekDraw(1.0);
  await shot('bg-08-portrait-ad-landmark');

  // ── stills: before / after (presenter over a landmark; product over the brand gradient) ──
  const still = async (name, clip, dur, fx, tt, extra = null) => {
    const r = await ev(async ([clip, dur, fx, tt]) => {
      const p = window.PROJ(clip, dur), it = p.tracks.find((x) => x.kind === 'video').items[0], media = new window.MS({ getProject: () => p, engine: null });
      const c = document.createElement('canvas'); c.width = 540; c.height = 960; const x = c.getContext('2d');
      const f = await window.E.openFrames(p, media, 0.5); await f.load(tt);
      it.fx = []; window.R.renderFrame(x, p, tt, { scale: 0.5, source: f.source }); const a = c.toDataURL('image/png');
      it.fx = [fx]; await window.LK.ensureAnalysis(p); await window.R.prepareRender(p);
      const LKf = await import('/app/look/fx.js'); await LKf.loadBackground(fx);
      window.R.renderFrame(x, p, tt, { scale: 0.5, source: f.source }); const b = c.toDataURL('image/png'); f.close();
      return [a, b];
    }, [clip, dur, fx, tt]);
    const fa = path.join(OUT, name + '-a.png'), fb = path.join(OUT, name + '-b.png');
    fs.writeFileSync(fa, Buffer.from(r[0].split(',')[1], 'base64')); fs.writeFileSync(fb, Buffer.from(r[1].split(',')[1], 'base64'));
    ff('-i', fa, '-i', fb, '-filter_complex', "[0][1]hstack=inputs=2,drawtext=text='before':x=20:y=20:fontsize=34:fontcolor=white:box=1:boxcolor=black@0.5,drawtext=text='after':x=560:y=20:fontsize=34:fontcolor=white:box=1:boxcolor=black@0.5", path.join(SHOTS, name + '.png'));
  };
  // the classic portrait ad: a landmark scene on the track below, the presenter transparent over it, placed bottom right
  {
    const scene = await ev(async () => {
      const img = await new Promise((r) => { const i = new Image(); i.onload = () => r(i); i.onerror = () => r(null); i.src = '/brands/planet-careers/countries/landmarks/eiffel.svg'; });
      const c = document.createElement('canvas'); c.width = 1080; c.height = 1920; const x = c.getContext('2d');
      const g = x.createLinearGradient(0, 0, 0, 1920); g.addColorStop(0, '#2b5fa8'); g.addColorStop(0.55, '#f2a65a'); g.addColorStop(1, '#3a2340'); x.fillStyle = g; x.fillRect(0, 0, 1080, 1920);
      const sun = x.createRadialGradient(760, 760, 0, 760, 760, 520); sun.addColorStop(0, 'rgba(255,236,190,0.9)'); sun.addColorStop(1, 'rgba(255,236,190,0)'); x.fillStyle = sun; x.fillRect(0, 0, 1080, 1920);
      if (img) { x.globalAlpha = 0.92; x.drawImage(img, -140, 360, 1100, 1100 * (img.naturalHeight || 1) / (img.naturalWidth || 1)); x.globalAlpha = 1; }
      x.fillStyle = '#ffffff'; x.font = '800 92px sans-serif'; x.fillText('Work in', 70, 230); x.fillStyle = '#ffd166'; x.fillText('France', 70, 340);
      return c.toDataURL('image/png');
    });
    fs.writeFileSync(path.join(LIB, 'landmark-fr.png'), Buffer.from(scene.split(',')[1], 'base64'));
    const r = await ev(async () => {
      const p = window.PROJ('presenter', 8), it = p.tracks.find((x) => x.kind === 'video').items[0], media = new window.MS({ getProject: () => p, engine: null });
      const c = document.createElement('canvas'); c.width = 540; c.height = 960; const x = c.getContext('2d');
      const f0 = await window.E.openFrames(p, media, 0.5); await f0.load(1.2); window.R.renderFrame(x, p, 1.2, { scale: 0.5, source: f0.source }); const a = c.toDataURL('image/png'); f0.close();
      const m = { id: 'm-scene', kind: 'image', name: 'landmark-fr.png', src: 'library/bg-test/landmark-fr.png', w: 1080, h: 1920 };
      p.media.push(m);
      const tr = window.P.createTrack('overlay', 'Background'); tr.items.push(window.P.createItem('clip', { media: m.id, t0: 0, in: 0, out: 8, x: 0, y: 0, w: 1080, h: 1920 }));
      p.tracks.unshift(tr);
      Object.assign(it, { x: 300, y: 560, w: 780, h: 1360, fx: [{ type: 'look.bg', q: 2, key: 'person', mode: 'none', feather: 0.1, smooth: 0.5 }] });
      await window.LK.ensureAnalysis(p);
      const f = await window.E.openFrames(p, media, 0.5); await f.load(1.2); window.R.renderFrame(x, p, 1.2, { scale: 0.5, source: f.source }); const b = c.toDataURL('image/png'); f.close();
      return [a, b];
    });
    const fa = path.join(OUT, 'ad-a.png'), fb = path.join(OUT, 'ad-b.png');
    fs.writeFileSync(fa, Buffer.from(r[0].split(',')[1], 'base64')); fs.writeFileSync(fb, Buffer.from(r[1].split(',')[1], 'base64'));
    ff('-i', fa, '-i', fb, '-filter_complex', "[0][1]hstack=inputs=2,drawtext=text='before':x=20:y=20:fontsize=34:fontcolor=white:box=1:boxcolor=black@0.5,drawtext=text='after':x=560:y=20:fontsize=34:fontcolor=white:box=1:boxcolor=black@0.5", path.join(SHOTS, 'bg-still-presenter-over-landmark.png'));
  }
  await still('bg-still-presenter-landmark-mode', 'presenter', 8, { type: 'look.bg', q: 2, key: 'person', mode: 'landmark', country: 'fr', feather: 0.1, smooth: 0.5, wrap: 0.35 }, 1.2);
  await still('bg-still-green-screen-over-brand-gradient', 'green', 4, Object.assign({}, CH, { mode: 'gradient', colors: ['#C6395F', '#2A2228'], wrap: 0.3 }), 1.0);
  if (subjectOK) await still('bg-still-product-over-brand-gradient', 'product', 4, { type: 'look.bg', q: 2, key: 'subject', mode: 'gradient', colors: ['#C6395F', '#2A2228'], feather: 0.2, smooth: 0.5, wrap: 0.25 }, 2.0);
  ok(fs.existsSync(path.join(SHOTS, 'bg-still-presenter-over-landmark.png')), 'before/after stills written to shots/bg/');

  // hygiene
  const errs = errors.filter((x) => !/No available adapters|AudioContext was not allowed|net::ERR_ABORTED|favicon/.test(x));
  ok(!errs.length, `no console errors (${errs.length}${errs.length ? ': ' + errs.slice(0, 3).join(' | ') : ''})`);
  const offsite = outside.filter((u) => !/^https:\/\/(huggingface\.co|[\w.-]+\.hf\.co|cdn-lfs\.huggingface\.co|cas-bridge\.xethub\.hf\.co)\//.test(u));
  ok(!offsite.length, `no request leaves 127.0.0.1 from the page (${nreq} requests; the model download goes through the engine)${offsite.length ? ': ' + offsite.slice(0, 3).join(' ') : ''}`);

  // clean up: the downloaded model
  fs.rmSync(path.join(STUDIO, 'models', 'BritishWerewolf'), { recursive: true, force: true });
  await browser.close(); await H.stopEngine(e);
  numbers.seconds = Math.round((Date.now() - T0) / 1000);
  fs.writeFileSync(path.join(OUT, 'bg-summary.json'), JSON.stringify({ results, numbers }, null, 1));
  console.log(`\n${results.length - failures}/${results.length} passed in ${numbers.seconds} s`);
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
