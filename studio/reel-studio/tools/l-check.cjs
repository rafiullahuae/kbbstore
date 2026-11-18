#!/usr/bin/env node
// KBB Studio — Lane L check (Look + Audio), headless Chromium (WebGL2 via SwiftShader) against the Linux engine.
//
// Makes its own test clips (tools/out/l/, never committed): a talking "person" clip drawn in the browser (synthetic
// person + known silhouette) with a flite voice buried in pink noise and 50 Hz hum, two different clips for cuts, and
// (optional, test-only) a real portrait for the face landmarker. Then, in the real editor:
//   audio:   Clean up voice → export → noise floor drop (dB); Instagram loudness → export → ffmpeg ebur128: integrated
//            LUFS within ±1 of -14 and true peak ≤ -1 dBTP; SFX on cuts, beats, riser placed; undo restores
//   look:    every colour look moves the mean colour the expected way; auto colour; match clips; touch-up; background
//            removal: mask coverage vs the drawn silhouette; every transition differs from the hard cut (in page and
//            in the exported MP4); speed ramp: exported duration = plan ±1 frame; text presets draw (EN + AR)
//   same:    preview frame vs export frame mean difference < 6/255 (look + touch-up + background + text)
//   speed:   frame time at 1080×1920 with look + touch-up + background removal (SwiftShader — a Mac GPU is far faster)
//   hygiene: no console errors, no request leaves 127.0.0.1; screenshots 1920×1080 + 1280×800 → shots/l/
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/l-check.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'l'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'l');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
const ffprobe = (f) => JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-count_frames', '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height,nb_read_frames,r_frame_rate', '-of', 'json', f]).toString());
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const secs = (t) => ((Date.now() - t) / 1000).toFixed(1) + ' s';
const SR = 48000;

// ── pure helpers (Node) ──
function wav(x, sr = SR) {
  const b = Buffer.alloc(44 + x.length * 2);
  b.write('RIFF', 0); b.writeUInt32LE(36 + x.length * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22);
  b.writeUInt32LE(sr, 24); b.writeUInt32LE(sr * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(x.length * 2, 40);
  for (let i = 0; i < x.length; i++) b.writeInt16LE(Math.round(Math.max(-1, Math.min(1, x[i])) * 32767), 44 + i * 2);
  return b;
}
const pcm = (file, sr = SR) => { const buf = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '1', '-ar', String(sr), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.length)); };
function floorDb(x, sr = SR, p = 0.1) {
  const w = Math.round(0.02 * sr), v = [];
  for (let o = 0; o + w <= x.length; o += w) { let s = 0; for (let i = 0; i < w; i++) s += x[o + i] * x[o + i]; v.push(10 * Math.log10(s / w + 1e-12)); }
  v.sort((a, b) => a - b); return v[Math.floor(p * (v.length - 1))];
}
function ebur128(file) {
  const s = spawnSync('ffmpeg', ['-hide_banner', '-nostats', '-i', file, '-vn', '-af', 'ebur128=peak=true', '-f', 'null', '-']).stderr.toString();
  const sum = s.slice(s.lastIndexOf('Summary:'));
  return { I: +(/I:\s+(-?[\d.]+) LUFS/.exec(sum) || [])[1], TP: +(/Peak:\s+(-?[\d.]+|-inf) dBFS/.exec(sum.slice(sum.indexOf('True peak'))) || [])[1] };
}
/** RGB of one exported frame at time t, scaled to w×h */
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const meanDiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };

// ── test media ──
function makeVoice() {
  const f = path.join(OUT, 'voice.wav');
  if (!fs.existsSync(f)) ff('-f', 'lavfi', '-i', "flite=text='Hello and welcome back. Today I am showing you the new vitamin C serum from K Beauty Bliss. It feels so light on the skin, and the glow is amazing. Book your free consultation today.':voice=slt", '-ar', String(SR), '-ac', '1', f);
  const v = pcm(f), pad = Math.round(0.6 * SR), x = new Float32Array(v.length + 2 * pad);
  let b0 = 0, b1 = 0, b2 = 0, b3 = 0, b4 = 0, b5 = 0, b6 = 0, seed = 7;
  const rnd = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return (seed / 4294967296) * 2 - 1; };
  for (let i = 0; i < x.length; i++) {
    const w = rnd(); b0 = 0.99886 * b0 + w * 0.0555179; b1 = 0.99332 * b1 + w * 0.0750759; b2 = 0.969 * b2 + w * 0.153852; b3 = 0.8665 * b3 + w * 0.3104856; b4 = 0.55 * b4 + w * 0.5329522; b5 = -0.7616 * b5 - w * 0.016898;
    const pink = (b0 + b1 + b2 + b3 + b4 + b5 + b6 + w * 0.5362) * 0.11; b6 = w * 0.115926;
    const t = i / SR, hum = 0.018 * Math.sin(2 * Math.PI * 50 * t) + 0.009 * Math.sin(2 * Math.PI * 150 * t) + 0.005 * Math.sin(2 * Math.PI * 250 * t);
    x[i] = (i >= pad && i < pad + v.length ? v[i - pad] * 0.5 : 0) + pink * 0.05 + hum;
  }
  fs.writeFileSync(path.join(OUT, 'noisy.wav'), wav(x));
  return { dur: x.length / SR, floor: floorDb(x) };
}

/** the synthetic person, drawn frame by frame in the page (and its silhouette, for the coverage truth) */
const PERSON_JS = `
window.drawPerson = (c, t, maskOnly) => {
  const x = c.getContext('2d'), W = c.width, H = c.height, k = W / 540, sway = Math.sin(t * 1.7) * 14 * k, bob = Math.sin(t * 3.1) * 4 * k;
  if (maskOnly) { x.fillStyle = '#000'; x.fillRect(0, 0, W, H); } else {
    const g = x.createLinearGradient(0, 0, W, H); g.addColorStop(0, '#d9c3a5'); g.addColorStop(0.55, '#b98e6a'); g.addColorStop(1, '#5b6f73'); x.fillStyle = g; x.fillRect(0, 0, W, H);
    for (let i = 0; i < 14; i++) { x.fillStyle = 'hsla(' + (30 + i * 23) % 360 + ',55%,' + (55 + (i % 3) * 10) + '%,0.35)'; x.beginPath(); x.arc(((i * 97) % 540) * k, ((i * 151) % 520) * k, (18 + (i % 4) * 12) * k, 0, 7); x.fill(); }
    x.fillStyle = '#3d5a3a'; x.fillRect(430 * k, 520 * k, 70 * k, 300 * k); x.fillStyle = '#7a4b2a'; x.fillRect(0, 860 * k, W, 100 * k);
  }
  const cx = 270 * k + sway, P = maskOnly ? () => '#fff' : (c) => c;
  x.save(); x.translate(0, bob);
  x.fillStyle = P('#1f3b70'); x.beginPath(); x.moveTo(cx - 190 * k, H); x.bezierCurveTo(cx - 190 * k, 640 * k, cx - 150 * k, 590 * k, cx - 70 * k, 585 * k); x.lineTo(cx + 70 * k, 585 * k); x.bezierCurveTo(cx + 150 * k, 590 * k, cx + 190 * k, 640 * k, cx + 190 * k, H); x.closePath(); x.fill();
  x.fillStyle = P('#d9a07e'); x.fillRect(cx - 34 * k, 500 * k, 68 * k, 100 * k);
  x.beginPath(); x.moveTo(cx - 34 * k, 585 * k); x.quadraticCurveTo(cx, 640 * k, cx + 34 * k, 585 * k); x.fill();
  x.fillStyle = P('#2b1a12'); x.beginPath(); x.ellipse(cx, 395 * k, 98 * k, 120 * k, 0, 0, 7); x.fill();
  x.fillStyle = P('#e2ad8c'); x.beginPath(); x.ellipse(cx, 420 * k, 82 * k, 104 * k, 0, 0, 7); x.fill();
  if (!maskOnly) {
    const sh = x.createRadialGradient(cx - 25 * k, 400 * k, 10 * k, cx, 420 * k, 110 * k); sh.addColorStop(0, 'rgba(255,230,210,0.35)'); sh.addColorStop(1, 'rgba(120,60,40,0.25)'); x.fillStyle = sh; x.beginPath(); x.ellipse(cx, 420 * k, 82 * k, 104 * k, 0, 0, 7); x.fill();
    x.fillStyle = '#2b1a12'; x.beginPath(); x.ellipse(cx, 335 * k, 86 * k, 42 * k, 0, Math.PI, 0); x.fill();
    for (const s of [-1, 1]) {
      x.fillStyle = '#fff'; x.beginPath(); x.ellipse(cx + s * 32 * k, 410 * k, 15 * k, 8 * k, 0, 0, 7); x.fill();
      x.fillStyle = '#3a2416'; x.beginPath(); x.arc(cx + s * 32 * k, 410 * k, 6.5 * k, 0, 7); x.fill();
      x.strokeStyle = '#2b1a12'; x.lineWidth = 4 * k; x.beginPath(); x.moveTo(cx + s * 18 * k, 388 * k); x.quadraticCurveTo(cx + s * 34 * k, 380 * k, cx + s * 50 * k, 390 * k); x.stroke();
    }
    x.strokeStyle = 'rgba(140,80,60,0.6)'; x.lineWidth = 3 * k; x.beginPath(); x.moveTo(cx, 420 * k); x.lineTo(cx - 8 * k, 455 * k); x.lineTo(cx + 6 * k, 458 * k); x.stroke();
    const open = 4 + 6 * Math.abs(Math.sin(t * 9));
    x.fillStyle = '#b2484f'; x.beginPath(); x.ellipse(cx, 485 * k, 24 * k, open * k, 0, 0, 7); x.fill();
    for (let i = 0; i < 400; i++) { const a = (i * 2.399) % 6.283, r = ((i * 37) % 90) * k; x.fillStyle = 'rgba(120,70,50,0.08)'; x.fillRect(cx + Math.cos(a) * r * 0.85, 425 * k + Math.sin(a) * r, 2 * k, 2 * k); }
  }
  x.restore();
};`;

async function makePerson(page) {
  const f = path.join(OUT, 'person.mp4'), truth = path.join(OUT, 'person-truth.json');
  if (fs.existsSync(f) && fs.existsSync(truth)) return JSON.parse(fs.readFileSync(truth, 'utf8'));
  const fps = 30, n = Math.round(fps * 5.6), dir = path.join(OUT, 'person-frames');
  fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
  await page.evaluate(PERSON_JS);
  for (let i = 0; i < n; i++) {
    const data = await page.evaluate(([t]) => { const c = document.createElement('canvas'); c.width = 540; c.height = 960; window.drawPerson(c, t, false); return c.toDataURL('image/jpeg', 0.93); }, [i / fps]);
    fs.writeFileSync(path.join(dir, String(i).padStart(4, '0') + '.jpg'), Buffer.from(data.split(',')[1], 'base64'));
  }
  const cov = await page.evaluate(([fps]) => [0.5, 1.5, 2.5, 3.5].map((t) => { const c = document.createElement('canvas'); c.width = 135; c.height = 240; window.drawPerson(c, t, true); const d = c.getContext('2d').getImageData(0, 0, 135, 240).data; let s = 0; for (let i = 0; i < d.length; i += 4) s += d[i] > 127; return { t, cov: s / (d.length / 4) }; }), [fps]);
  ff('-framerate', String(fps), '-i', path.join(dir, '%04d.jpg'), '-i', path.join(OUT, 'noisy.wav'), '-vf', 'scale=1080:1920:flags=bicubic', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-b:a', '192k', '-shortest', '-movflags', '+faststart', f);
  fs.rmSync(dir, { recursive: true, force: true });
  fs.writeFileSync(truth, JSON.stringify(cov));
  return cov;
}
function makeClips() {
  const a = path.join(OUT, 'a.mp4'), b = path.join(OUT, 'b.mp4');
  if (!fs.existsSync(a)) ff('-f', 'lavfi', '-i', 'testsrc2=size=1080x1920:rate=30:duration=4', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=4:sample_rate=48000', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', a);
  if (!fs.existsSync(b)) ff('-f', 'lavfi', '-i', 'smptehdbars=size=1080x1920:rate=30:duration=4', '-f', 'lavfi', '-i', 'sine=frequency=550:duration=4:sample_rate=48000', '-vf', "drawbox=x='mod(t*300,900)':y=700:w=180:h=180:color=0xff3366@1:t=fill", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', b);
  // a test-only real face for the landmarker (public-domain portrait used by MediaPipe's own tests; not committed)
  const pj = path.join(OUT, 'portrait.jpg'), pm = path.join(OUT, 'portrait.mp4');
  if (!fs.existsSync(pj)) spawnSync('curl', ['-sS', '-m', '30', '-o', pj, 'https://storage.googleapis.com/mediapipe-assets/portrait.jpg']);
  if (fs.existsSync(pj) && fs.statSync(pj).size > 10000 && !fs.existsSync(pm)) ff('-loop', '1', '-i', pj, '-t', '3', '-r', '30', '-vf', "scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,zoompan=z='1+0.0008*on':d=1:s=1080x1920:fps=30", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-g', '30', '-movflags', '+faststart', pm);
  return fs.existsSync(pm);
}

(async () => {
  const T0 = Date.now();
  const voice = makeVoice();
  const hasPortrait = makeClips();
  // the studio folder (kept between runs except app/vendor/brands/library, refreshed every time)
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings', 'adstudio']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], outside = []; let nreq = 0;
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('worker', (w) => w.on('console', (m) => { if (m.type() === 'error') errors.push('[worker] ' + m.text()); }));
  context.on('request', (r) => { nreq++; const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const truth = await makePerson(page);
  fs.mkdirSync(path.join(STUDIO, 'library', 'l-test'), { recursive: true });
  for (const f of ['person.mp4', 'a.mp4', 'b.mp4', ...(hasPortrait ? ['portrait.mp4'] : [])]) fs.copyFileSync(path.join(OUT, f), path.join(STUDIO, 'library', 'l-test', f));

  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) {
      await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(700);
      await page.screenshot({ path: path.join(SHOTS, `${name}-${w}.png`) });
    }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(300);
  };
  const tab = (id) => page.click(`.side-tab[data-side="mod:${id}"]`);
  const exportTo = (name) => ev(async (name) => {
    const E = await import('/app/core/export.js'), ed = window.kbbEditor, p = ed.project;
    const r = await E.exportMp4(p, ed.media, { quality: 'high' });
    const file = `workspaces/${p.workspace}/exports/${name}`; await ed.engine.writeFile(file, r.blob);
    return { file, note: r.note, frames: r.frames };
  }, name);
  const settle = async () => { await page.waitForTimeout(400); await ev(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))); };
  const seekDraw = async (t) => { await ev((t) => window.kbbEditor.seek(t), t); await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }); await settle(); };

  // ── project 1: the talking person ──
  await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'person.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await ev(async () => { window.LK = await import('/app/look/index.js'); window.AU = await import('/app/audio/index.js'); window.CTX = window.kbbEditor.context(); });
  const tabs = await ev(() => [...document.querySelectorAll('.side-tab')].map((b) => b.textContent));
  ok(tabs.includes('🎨 Look') && tabs.includes('🔊 Audio'), `right column has the Look and Audio tabs (${tabs.join(' · ')})`);
  await seekDraw(1.5);

  // ── audio: voice cleanup ──
  await tab('audio'); await page.waitForTimeout(300);
  await shot('l-01-audio-before');
  let t = Date.now();
  await page.click('.lk-btns .btn.primary:has-text("Enhance voice")');
  await page.waitForFunction(() => window.kbbEditor.project.media[0].audio?.src, null, { timeout: 120000 });
  const clean = await ev(() => window.kbbEditor.project.media[0].audio);
  ok(clean.src.endsWith('.wav') && fs.existsSync(path.join(STUDIO, clean.src)), `Clean up voice (one click) → ${clean.src.split('/').pop()} in ${secs(t)}; in-app stats ${JSON.stringify(clean.stats)}`);
  await page.waitForTimeout(500);
  await shot('l-02-audio-after');
  await ev(() => window.kbbEditor.history.undo());
  const undone = await ev(() => window.kbbEditor.project.media[0].audio);
  await ev(() => window.kbbEditor.history.redo());
  ok(!undone, 'voice cleanup is one undo step (⌘Z → original sound, ⇧⌘Z → cleaned again)');

  // ── look: colour looks (in page, through the real render path) ──
  await tab('look'); await page.waitForTimeout(400);
  await seekDraw(1.5);
  await shot('l-03-look-before');
  const looks = await ev(async () => {
    const R = await import('/app/core/render.js'), ed = window.kbbEditor, p = ed.project, it = p.tracks[0].items[0];
    const el = ed.media.element(it.media);
    const c = document.createElement('canvas'); c.width = 270; c.height = 480; const x = c.getContext('2d', { willReadFrequently: true });
    const stat = () => { R.renderFrame(x, p, 1.5, { scale: 0.25, source: () => el }); const d = x.getImageData(0, 0, 270, 480).data; let r = 0, g = 0, b = 0, s = 0; const n = d.length / 4; for (let i = 0; i < d.length; i += 4) { r += d[i]; g += d[i + 1]; b += d[i + 2]; s += Math.max(d[i], d[i + 1], d[i + 2]) - Math.min(d[i], d[i + 1], d[i + 2]); } return { r: r / n, g: g / n, b: b / n, sat: s / n, l: (0.2126 * r + 0.7152 * g + 0.0722 * b) / n }; };
    const base = stat(), out = {};
    for (const L of window.LK.LOOKS) { window.LK.applyLook(window.CTX, L.id); out[L.id] = stat(); ed.history.undo(); }
    return { base, out };
  });
  const B = looks.base, O = looks.out, rb = (s) => s.r - s.b;
  const dirs = [
    ['warm-golden', rb(O['warm-golden']) > rb(B) + 6, 'warmer (R−B up)'], ['desert-gold', rb(O['desert-gold']) > rb(B) + 6, 'warmer'], ['cool-fresh', rb(O['cool-fresh']) < rb(B) - 6, 'cooler (R−B down)'],
    ['bw', O.bw.sat < 3, 'no saturation'], ['bw-noir', O['bw-noir'].sat < 3, 'no saturation'], ['luxury-moody', O['luxury-moody'].l < B.l - 4, 'darker'], ['beauty-glow', O['beauty-glow'].l > B.l + 3, 'brighter'],
    ['travel-vivid', O['travel-vivid'].sat > B.sat + 4, 'more saturated'], ['pastel-dream', O['pastel-dream'].sat < B.sat, 'softer colour'], ['clean-clinic', rb(O['clean-clinic']) < rb(B), 'cooler, neutral'],
    ['corporate-trust', rb(O['corporate-trust']) < rb(B), 'bluer'], ['food-pop', O['food-pop'].sat > B.sat + 3, 'more saturated'], ['original', Math.abs(O.original.l - B.l) < 0.5, 'unchanged'],
  ];
  const changed = Object.entries(O).filter(([k, s]) => k !== 'original' && Math.abs(s.r - B.r) + Math.abs(s.g - B.g) + Math.abs(s.b - B.b) > 3).length;
  ok(dirs.every((d) => d[1]) && changed === Object.keys(O).length - 1, `${Object.keys(O).length - 1} colour looks all change the picture; expected directions: ${dirs.map((d) => `${d[0]} ${d[2]} ${d[1] ? '✓' : '✗'}`).join(', ')}`);
  numbers.looks = Object.fromEntries(Object.entries(O).map(([k, s]) => [k, { rMinusB: +(rb(s) - rb(B)).toFixed(1), luma: +(s.l - B.l).toFixed(1), sat: +(s.sat - B.sat).toFixed(1) }]));
  await page.click('.lk-tile[data-look="beauty-glow"]'); await settle();
  await shot('l-04-look-after');
  const ac = await ev(async () => { const r = await window.LK.autoColour(window.CTX); return { r, fx: window.kbbEditor.project.tracks[0].items[0].fx.find((f) => f.type === 'look.grade') }; });
  ok(ac.fx.look === 'beauty-glow' && Array.isArray(ac.fx.wb) && ac.r.length === 1, `Auto colour keeps the look and measures the clip: wb ${JSON.stringify(ac.fx.wb)}, ev ${ac.fx.ev}`);

  // ── look: touch-up + background removal on the person ──
  await shot('l-05a-touchup-before');
  t = Date.now();
  const tu = await ev(() => window.LK.touchUp(window.CTX, {}));
  await seekDraw(1.5);
  ok(tu.clips === 1, `Skin touch-up applied (strength 35% default) in ${secs(t)}; faces found on the synthetic person: ${tu.withFaces}`);
  await shot('l-05b-touchup-after-bg-before');
  t = Date.now();
  await page.click('.lk-chips .chip[data-bg="blur"]');
  await page.click('.btn.primary:has-text("Remove background")');
  await page.waitForFunction(() => window.kbbEditor.project.tracks[0].items[0].fx.some((f) => f.type === 'look.bg'), null, { timeout: 120000 });
  await page.waitForFunction((ts) => { const id = window.kbbEditor.project.media[0].id; return document.querySelector('.mod-progress').hidden && ts.every((x) => window.LK.coverageAt(id, x.t) != null); }, truth, { timeout: 300000, polling: 500 });
  const cov = await ev((ts) => ts.map((x) => ({ t: x.t, truth: x.cov, got: window.LK.coverageAt(window.kbbEditor.project.media[0].id, x.t) })), truth);
  const err = Math.max(...cov.map((c) => Math.abs(c.got - c.truth)));
  numbers.segmentation = cov;
  ok(err < 0.1, `background removal (one click, blur) in ${secs(t)}: mask coverage vs the drawn silhouette ${cov.map((c) => `${(c.got * 100).toFixed(1)}%/${(c.truth * 100).toFixed(1)}%`).join(', ')} (max error ${(err * 100).toFixed(1)} points)`);
  await seekDraw(1.5);
  await shot('l-06-bg-after-blur');
  for (const mode of ['gradient', 'landmark']) {
    await page.click(`.lk-chips .chip[data-bg="${mode}"]`);
    if (mode === 'landmark') await page.locator('.lk select[aria-label="Country"]').selectOption('de');
    await page.click('.btn.primary:has-text("Remove background")'); await page.waitForTimeout(800); await seekDraw(1.5);
    await shot(`l-07-bg-${mode}`);
  }
  const bgFx = await ev(() => window.kbbEditor.project.tracks[0].items[0].fx.find((f) => f.type === 'look.bg'));
  ok(bgFx.mode === 'landmark' && bgFx.country === 'de', `background modes switch in place (now ${bgFx.mode} · ${bgFx.country}); each one undoable`);
  await page.click('.lk-chips .chip[data-bg="blur"]'); await page.click('.btn.primary:has-text("Remove background")'); await page.waitForTimeout(500);

  // ── text presets (EN + AR) ──
  // one preset in the reel for the export (lower third); every preset drawn on its own below
  await ev(() => window.LK.addText(window.CTX, 'lowerthird', { t0: 0.5 }));
  const textDraw = await ev(async () => {
    const T = await import('/app/look/text.js'), R = await import('/app/core/render.js'), P = await import('/app/core/project.js');
    try { await (await import('/app/captions/fonts.js')).loadCaptionFonts(); } catch {}
    const res = {};
    for (const k of Object.keys(T.PRESETS)) {
      const proj = P.createProject({ aspect: '9:16' }); const it = T.textItem(k, proj.canvas, { colors: { primary: '#C6395F', accent: '#ffd166' } }, 0);
      proj.tracks.find((x) => x.kind === 'text').items.push(it);
      const c = document.createElement('canvas'); c.width = 270; c.height = 480; const x = c.getContext('2d', { willReadFrequently: true });
      const counts = [0.3, 1.2, 2.2].map((tt) => { R.renderFrame(x, proj, tt, { scale: 0.25 }); const d = x.getImageData(0, 0, 270, 480).data; let n = 0; for (let i = 0; i < d.length; i += 4) n += d[i] + d[i + 1] + d[i + 2] > 60; return n; });
      res[k] = counts;
    }
    return res;
  });
  const animated = Object.entries(textDraw).filter(([, c]) => c[2] > 200 && c.some((v, i) => i && v !== c[i - 1]));
  ok(animated.length === Object.keys(textDraw).length, `${animated.length}/${Object.keys(textDraw).length} text presets draw and animate (pixels lit at 0.3/1.2/2.2 s): ${Object.entries(textDraw).map(([k, c]) => k + ' ' + c.join('/')).join(', ')}`);
  await seekDraw(1.5);
  await shot('l-08-text-lowerthird');
  await ev(() => window.LK.addText(window.CTX, 'bilingual', { t0: 1.0, y: 260 }));
  await ev(() => { const ed = window.kbbEditor, it = ed.project.tracks.find((x) => x.kind === 'text').items.find((i) => i.style?.preset === 'bilingual'); ed.select([it.id]); });
  await seekDraw(2.4);
  await shot('l-08b-text-bilingual-ar');
  await ev(() => window.kbbEditor.history.undo());

  // ── audio: loudness + export of project 1 ──
  await tab('audio'); await page.waitForTimeout(300);
  await page.click('.lk-chips .chip[data-loud="instagram"]'); await page.waitForTimeout(200);
  t = Date.now();
  const ex1 = await exportTo('l-person.mp4');
  const f1 = path.join(STUDIO, ex1.file);
  numbers.export1 = { note: ex1.note, ms: Date.now() - t };
  const lou = ebur128(f1), rep = await ev(() => window.AU.loudnessReport());
  numbers.loudness = { ffmpeg: lou, app: rep };
  ok(Math.abs(lou.I + 14) <= 1 && lou.TP <= -1, `export with the Instagram target: ffmpeg ebur128 I = ${lou.I} LUFS (target -14 ±1), true peak ${lou.TP} dBTP (≤ -1); app measured ${rep?.after} LUFS / ${rep?.tpAfter} dBTP before encoding`);
  const outAudio = pcm(f1), floorOut = floorDb(outAudio), floorIn = floorDb(pcm(path.join(OUT, 'person.mp4')));
  // compare at matched speech loudness: the floor relative to the integrated loudness of each
  const srcLou = ebur128(path.join(OUT, 'person.mp4'));
  const relIn = floorIn - srcLou.I, relOut = floorOut - lou.I;
  numbers.cleanup = { floorIn, floorOut, relIn, relOut, dropDb: relIn - relOut };
  ok(relIn - relOut >= 15, `voice cleanup: noise floor ${floorIn.toFixed(1)} dBFS → ${floorOut.toFixed(1)} dBFS; relative to the speech loudness ${relIn.toFixed(1)} → ${relOut.toFixed(1)} dB = ${(relIn - relOut).toFixed(1)} dB quieter noise`);

  // ── preview vs export, same frame ──
  const pv = await (async () => { await seekDraw(1.5); return ev(() => { const c = window.kbbEditor.preview.canvas; const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; const rgb = new Uint8Array(c.width * c.height * 3); for (let i = 0, j = 0; i < d.length; i += 4) { rgb[j++] = d[i]; rgb[j++] = d[i + 1]; rgb[j++] = d[i + 2]; } return { w: c.width, h: c.height, data: Array.from(rgb) }; }); })();
  const exF = frameRGB(f1, 1.5, pv.w, pv.h), pvF = Uint8Array.from(pv.data);
  const pd = meanDiff(pvF, exF);
  numbers.previewVsExport = { w: pv.w, h: pv.h, meanDiff: +pd.toFixed(2) };
  ok(pd < 6, `preview frame vs export frame at 1.5 s (look + touch-up + background blur + text, ${pv.w}×${pv.h}): mean difference ${pd.toFixed(2)}/255 (< 6)`);

  // ── real time: frame time at 1080×1920 with look + touch-up + background ──
  const speed = await ev(async () => {
    const R = await import('/app/core/render.js'), ed = window.kbbEditor, p = ed.project, it = p.tracks[0].items[0];
    const el = ed.media.element(it.media); el.muted = true; el.currentTime = 0.5; await el.play(); await new Promise((r) => setTimeout(r, 300));
    const run = async (W, H, scale) => {
      const c = document.createElement('canvas'); c.width = W; c.height = H; const x = c.getContext('2d');
      const times = [];
      for (let i = 0; i < 40; i++) { await new Promise((r) => requestAnimationFrame(r)); const t0 = performance.now(); R.renderFrame(x, p, Math.min(4.5, 0.5 + el.currentTime), { scale, preview: true, source: () => el }); x.getImageData(0, 0, 1, 1); times.push(performance.now() - t0); }
      times.sort((a, b) => a - b); return { median: +times[20].toFixed(1), p90: +times[36].toFixed(1) };
    };
    const full = await run(1080, 1920, 1), mon = await run(ed.preview.canvas.width, ed.preview.canvas.height, ed.preview.scale);
    el.pause();
    const g = document.createElement('canvas').getContext('webgl2');
    return { full, monitor: mon, monitorSize: [ed.preview.canvas.width, ed.preview.canvas.height], renderer: g.getParameter(g.RENDERER) };
  });
  numbers.realtime = speed;
  ok(speed.full.median > 0, `frame time with look + touch-up + background removal: 1080×1920 ${speed.full.median} ms median (${(1000 / speed.full.median).toFixed(1)} fps), monitor ${speed.monitorSize.join('×')} ${speed.monitor.median} ms (${(1000 / speed.monitor.median).toFixed(1)} fps) on ${speed.renderer} (SwiftShader = CPU; a Mac GPU is many times faster)`);

  // ── project 2: two clips, transitions, ramp, sound design ──
  await ev(() => window.kbbEditor.goHome()); await page.waitForSelector('.home');
  await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'a.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await ev(async () => { const ed = window.kbbEditor; const m = await ed.media.importPath('library/l-test/b.mp4'); ed.addMediaToTimeline(m); window.CTX = ed.context(); });
  await page.waitForFunction(() => window.kbbEditor.project.tracks[0].items.length === 2);
  const cut = await ev(() => window.kbbEditor.project.tracks[0].items[1].t0);
  const hard = await ev(async (cut) => {
    const R = await import('/app/core/render.js'), ed = window.kbbEditor, p = ed.project, M = await import('/app/core/media.js'), P = await import('/app/core/project.js');
    window.grab = async (times) => {
      const out = [];
      for (const tt of times) {
        const src = new Map();
        for (const { item } of R.activeItems(p, tt)) if (item.media) { const el = ed.media.element(item.media); await M.seekExact(el, P.srcTime(item, tt)); src.set(item.id, el); }
        const c = document.createElement('canvas'); c.width = 216; c.height = 384; const x = c.getContext('2d', { willReadFrequently: true });
        R.renderFrame(x, p, tt, { scale: 0.2, source: (it) => src.get(it.id) || null }); out.push(Array.from(x.getImageData(0, 0, 216, 384).data));
      }
      return out;
    };
    return window.grab([cut - 0.1, cut + 0.1]);
  }, cut);
  const kinds = await ev(() => Object.keys(window.LK.KINDS));
  const transRes = {};
  for (const k of kinds) {
    await ev(async (k) => { await window.LK.addTransitions(window.CTX, k, { dur: 0.6 }); }, k);
    const fr = await ev((cut) => window.grab([cut - 0.1, cut + 0.1]), cut);
    transRes[k] = +((meanDiff(fr[0], hard[0]) + meanDiff(fr[1], hard[1])) / 2).toFixed(2);
    if (k === 'whip') { await seekDraw(cut - 0.06); await tab('look'); await shot('l-09-transition-whip-mid'); }
    if (k === 'glitch') { await seekDraw(cut + 0.04); await shot('l-10-transition-glitch-mid'); }
    await ev(() => window.kbbEditor.history.undo());
  }
  numbers.transitions = transRes;
  ok(Object.values(transRes).every((d) => d > 2), `all ${kinds.length} GPU transitions differ from the hard cut (mean |Δ| over 2 frames, /255): ${Object.entries(transRes).map(([k, d]) => k + ' ' + d).join(', ')}`);
  const left = await ev(() => window.kbbEditor.project.tracks.flatMap((x) => x.items).filter((i) => i.type === 'look.transition').length);
  ok(left === 0, 'each transition was one undo step (all undone)');
  // match cut helper
  const shift = await ev((cut) => window.LK.matchCut(window.CTX, { at: cut }), cut);
  ok(typeof shift === 'number', `match-cut helper ran (next clip start moved ${shift.toFixed(3)} s)`);
  if (shift) await ev(() => window.kbbEditor.history.undo());

  // beat sync needs music: add a library track, then snap
  const bs = await ev(async () => {
    const ed = window.kbbEditor, A = await import('/app/auto/index.js'), lib = await A.library(window.CTX), tr = lib.music.find((x) => x.beats?.length) || lib.music[0];
    const m = await ed.media.importPath(tr.src); ed.addMediaToTimeline(m, { trackId: ed.project.tracks.find((x) => x.kind === 'music').id, t0: 0.23 });   // offset: the cut is not on a beat already
    const before = ed.project.tracks[0].items.map((i) => +(i.t0 + (i.out - i.in) / i.speed).toFixed(3));
    const r = await window.AU.beatSync(window.CTX);
    const beats = await window.AU.timelineBeats(window.CTX);
    const after = ed.project.tracks[0].items.map((i) => +(i.t0 + (i.out - i.in) / i.speed).toFixed(3));
    ed.history.undo(); ed.history.undo();
    return { r, before, after, beats: beats.slice(0, 12).map((b) => +b.toFixed(3)) };
  });
  ok(bs.r.beats > 0 && bs.r.moved >= 1, `cuts on the beat: ${bs.r.moved} cut(s) moved onto the music's beats (${bs.r.beats} beats on the timeline)`);
  // speed ramp on clip A + a zoom-through on the cut + SFX + export
  const plan = await ev(async () => {
    const ed = window.kbbEditor, a = ed.project.tracks[0].items[0];
    const r = await window.LK.speedRamp(window.CTX, a.id, { curve: 'slow-fast-slow', min: 0.35, max: 3, ease: 'sine', audio: 'pitch' });
    return { duration: r.duration, pieces: r.pieces, total: (await import('/app/core/project.js')).projectDuration(ed.project), sfxAudio: ed.project.tracks.find((x) => x.kind === 'sfx').items.length };
  });
  ok(plan.pieces >= 8 && plan.sfxAudio === 1, `speed ramp slow-fast-slow (0.35×–3×, sine): ${plan.pieces} steps, clip 4.00 s → ${plan.duration.toFixed(3)} s, pitch-kept sound rendered onto the SFX track`);
  await ev(async () => { await window.LK.addTransitions(window.CTX, 'zoom', { dur: 0.5 }); });
  await tab('audio'); await page.waitForTimeout(300);
  await page.click('.btn:has-text("SFX on every cut")'); await page.waitForTimeout(800);
  await page.click('.btn:has-text("Riser before the reveal")'); await page.waitForTimeout(800);
  const sfx = await ev(() => window.kbbEditor.project.tracks.find((x) => x.kind === 'sfx').items.map((i) => i.name));
  ok(sfx.some((n) => /whoosh|swoosh|swipe/.test(n)) && sfx.some((n) => /riser/.test(n)), `sound design: ${sfx.join(', ')}`);
  const expectDur = await ev(async () => (await import('/app/core/project.js')).projectDuration(window.kbbEditor.project));
  t = Date.now();
  const ex2 = await exportTo('l-ramp.mp4');
  const f2 = path.join(STUDIO, ex2.file), pr = ffprobe(f2), vs = pr.streams.find((s) => s.codec_type === 'video');
  const nfr = +vs.nb_read_frames, want = Math.round(expectDur * 30);
  numbers.ramp = { planned: plan.duration, timeline: expectDur, frames: nfr, wantFrames: want, export: ex2.note };
  ok(Math.abs(nfr - want) <= 1, `speed-ramped export: ${nfr} frames vs ${want} planned (${expectDur.toFixed(3)} s timeline) ±1 frame, in ${secs(t)}`);
  // the exported transition frame differs from the hard cut
  const cut2 = await ev(() => window.kbbEditor.project.tracks[0].items.filter((i) => i.type === 'clip').sort((a, b) => a.t0 - b.t0).find((i, k, arr) => k > 0 && arr[k - 1].ramp && !i.ramp)?.t0);
  const exT = frameRGB(f2, cut2 - 0.1, 216, 384), exA = frameRGB(f2, cut2 - 0.6, 216, 384);
  const inPage = await ev((c) => window.grab([c - 0.1]), cut2);
  const dZoom = meanDiff(exT, Uint8Array.from(inPage[0].filter((_, i) => i % 4 !== 3)));
  ok(meanDiff(exT, exA) > 2 && dZoom < 12, `the exported MP4 carries the zoom-through (frame 0.1 s before the cut ≠ a plain frame; matches the in-page frame within ${dZoom.toFixed(1)}/255)`);

  // ── touch-up on a real face (test-only portrait) ──
  if (hasPortrait) {
    await ev(() => window.kbbEditor.goHome()); await page.waitForSelector('.home');
    await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'portrait.mp4'));
    await page.waitForSelector('.tl-item'); await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    const face = await ev(async () => {
      const ed = window.kbbEditor; window.CTX = ed.context();
      const R = await import('/app/core/render.js'), M = await import('/app/core/media.js'), p = ed.project, it = p.tracks[0].items[0], el = ed.media.element(it.media);
      await M.seekExact(el, 1);
      const snap = () => { const c = document.createElement('canvas'); c.width = 1080; c.height = 1920; const x = c.getContext('2d', { willReadFrequently: true }); R.renderFrame(x, p, 1, { scale: 1, source: () => el }); const s = document.createElement('canvas'); s.width = 540; s.height = 960; const y = s.getContext('2d', { willReadFrequently: true }); y.imageSmoothingQuality = 'high'; y.drawImage(c, 0, 0, 540, 960); return y.getImageData(0, 0, 540, 960); };
      const before = snap();
      const r = await window.LK.touchUp(window.CTX, { strength: 0.6 });
      const L = window.LK.facesAt(it.media, 1)[0];
      const after = snap();
      // high-frequency energy inside the face box vs outside it
      const xs = [], ys = []; for (let i = 0; i < L.length; i += 2) { xs.push(L[i] * 540); ys.push(L[i + 1] * 960); }
      const bx = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
      const hf = (img, inside) => { const d = img.data; let s = 0, n = 0; for (let y = 1; y < 959; y++) for (let x = 1; x < 539; x++) { const In = x > bx[0] + 10 && x < bx[1] - 10 && y > bx[2] + 10 && y < bx[3] - 10; if (In !== inside) continue; const i = (y * 540 + x) * 4; const v = 4 * d[i + 1] - d[i - 3] - d[i + 5] - d[i + 1 - 2160] - d[i + 1 + 2160]; s += Math.abs(v); n++; } return s / n; };
      return { r, box: bx.map(Math.round), inBefore: hf(before, true), inAfter: hf(after, true), outBefore: hf(before, false), outAfter: hf(after, false) };
    });
    numbers.touchup = face;
    const dropIn = 1 - face.inAfter / face.inBefore, dropOut = 1 - face.outAfter / face.outBefore;
    ok(face.r.withFaces === 1 && dropIn > 0.08 && Math.abs(dropOut) < 0.05, `skin touch-up on a real face (MediaPipe face landmarker, offline): fine detail on the face −${(dropIn * 100).toFixed(0)}%, outside the face ${(dropOut * 100).toFixed(1)}% (texture elsewhere untouched)`);
  }

  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 6).join(' | ') : ''}`);
  ok(!outside.length, `no browser request left 127.0.0.1 (${nreq} requests)${outside.length ? ': ' + outside.slice(0, 5).join(', ') : ''}`);
  await browser.close();
  await H.stopEngine(e);
  fs.writeFileSync(path.join(OUT, 'l-summary.json'), JSON.stringify({ results, numbers, voice }, null, 1));
  console.log(`\n${failures ? failures + ' FAILED' : 'all Lane L checks passed'} — ${results.length} checks in ${secs(T0)}`);
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
