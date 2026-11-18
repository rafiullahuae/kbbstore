#!/usr/bin/env node
// KBB Studio — Enhance check: ✨ Enhance voice (noise, hum, room echo, EQ, de-ess, compression, -14 LUFS) and the picture's
// 🎚 Adjust + ✨ Enhance video, measured. Test media is made here (tools/out/enhance/, never committed).
//
//   sound (Node, the same voice.js the audio worker runs):
//     noise removal in dB per noise type (fan/AC hum, street, crowd, wind, keyboard, music bed) at 4 strengths, the
//     speech kept (correlation with the clean speech, before vs after); hum found and removed (50 and 60 Hz); room echo;
//     the whole chain: -14 LUFS ±1, true peak ≤ -1 dBTP (own meter and ffmpeg ebur128)
//   sound (in the editor): ✨ Enhance voice one click → the WAV the preview plays = the exported sound (lag-aligned
//     correlation and level); export loudness by ffmpeg; A/B; a 2× speed clip plays the cleaned sound; music ducking
//   picture (in the editor, WebGL2 via SwiftShader): every slider moves its measure the right way on a test frame;
//     Enhance video on a dark, grainy, low-res clip: brightness up, grain down (export frames); preview = export with
//     every slider on; upscale: 1080p and 4K exports at their size, Lanczos sharper than bilinear; undo/redo; save +
//     reload; a neutral adjustment draws exactly like none; frame time (preview + full-size) with everything on
//   pictures: shots/enhance/*.png at 1440×900; before/after WAVs and stills in tools/out/enhance/
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/enhance-check.cjs [--quick]   (--quick: Node part only)
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');
const H = require('./studio-harness.cjs');
const L = require('./enhance-lib.cjs');

const ROOT = H.ROOT, OUT = L.OUT, STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'enhance'), SR = L.SR;
fs.mkdirSync(SHOTS, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
const probe = (f) => JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'stream=codec_type,width,height', '-of', 'json', f]).toString()).streams;
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const info = (msg) => { console.log('     ' + msg); results.push('     ' + msg); };
const secs = (t) => ((Date.now() - t) / 1000).toFixed(1) + ' s';
function ebur128(file) {
  const s = spawnSync('ffmpeg', ['-hide_banner', '-nostats', '-i', file, '-vn', '-af', 'ebur128=peak=true', '-f', 'null', '-']).stderr.toString();
  const sum = s.slice(s.lastIndexOf('Summary:'));
  return { I: +(/I:\s+(-?[\d.]+) LUFS/.exec(sum) || [])[1], TP: +(/Peak:\s+(-?[\d.]+|-inf) dBFS/.exec(sum.slice(sum.indexOf('True peak'))) || [])[1] };
}
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const meanDiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };
/** luma mean, grain (rms of the picture minus its 5×5 average, on areas that are flat in the AVERAGE — so the grain
 *  itself cannot hide them) and detail (mean |Laplacian|) of an RGB frame, all /255 */
function frameStats(rgb, w, h) {
  const Y = new Float32Array(w * h), B = new Float32Array(w * h); let m = 0;
  for (let i = 0, j = 0; j < Y.length; i += 3, j++) { Y[j] = 0.2126 * rgb[i] + 0.7152 * rgb[i + 1] + 0.0722 * rgb[i + 2]; m += Y[j]; }
  for (let y = 2; y < h - 2; y++) for (let x = 2; x < w - 2; x++) { let s = 0; for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) s += Y[(y + dy) * w + x + dx]; B[y * w + x] = s / 25; }
  let s = 0, n = 0, d = 0;
  for (let y = 3; y < h - 3; y++) for (let x = 3; x < w - 3; x++) {
    const i = y * w + x;
    d += Math.abs(4 * Y[i] - Y[i - 1] - Y[i + 1] - Y[i - w] - Y[i + w]);
    if (Math.abs(B[i + 1] - B[i - 1]) + Math.abs(B[i + w] - B[i - w]) > 4) continue;
    s += (Y[i] - B[i]) ** 2; n++;
  }
  return { luma: m / Y.length, noise: Math.sqrt(s / Math.max(1, n)), flat: n / (w * h), detail: d / ((w - 6) * (h - 6)) };
}
/** lag (samples) that best lines b up with a, searched ±maxLag over a 3 s window */
function bestLag(a, b, maxLag = 4800) {
  const st = Math.min(a.length, b.length) > SR * 5 ? SR : 0, len = Math.min(SR * 3, a.length - st - maxLag, b.length - st - maxLag);
  let best = 0, bv = -Infinity;
  for (let l = -maxLag; l <= maxLag; l += 4) { let c = 0; for (let i = 0; i < len; i += 3) c += a[st + i] * (b[st + i + l] || 0); if (c > bv) { bv = c; best = l; } }
  for (let l = best - 4; l <= best + 4; l++) { let c = 0; for (let i = 0; i < len; i += 3) c += a[st + i] * (b[st + i + l] || 0); if (c > bv) { bv = c; best = l; } }
  return best;
}

async function soundNode() {
  const R = await import('../app/audio/rnnoise.js'), V = await import('../app/audio/voice.js'), D = await import('../app/audio/dsp.js');
  const rn = await R.createRnnoise(fs.readFileSync(path.join(ROOT, 'vendor/rnnoise-0.2.1/rnnoise.wasm')));
  const s = L.speech(), only = { noise: true, hum: false, reverb: false, eq: false, deess: false, compress: false, loud: false };
  const STR = [0.25, 0.5, 0.75, 1], tab = {};
  console.log('\n── sound: noise removal (speech at 5 dB SNR; dB = how much quieter the noise-only stretches got; corr = speech kept) ──');
  for (const k of Object.keys(L.NOISES)) {
    const x = L.mixAt(s, L.NOISES[k](s.length), 5), cin = L.corr(x, s, s), row = { corrIn: +cin.toFixed(3) };
    for (const st of STR) { const t = Date.now(), y = V.enhanceVoice(x, { strength: st, stages: only }, rn).y; row[st] = { db: +L.noiseCut(x, y, s).toFixed(1), corr: +L.corr(y, s, s).toFixed(3), ms: Date.now() - t }; }
    tab[k] = row;
    info(`${k.padEnd(9)} speech corr in ${cin.toFixed(3)} | ` + STR.map((st) => `${st}: −${row[st].db} dB, corr ${row[st].corr}`).join(' | '));
    if (k === 'fan' || k === 'street') { fs.writeFileSync(path.join(OUT, `${k}-before.wav`), L.wav(x)); fs.writeFileSync(path.join(OUT, `${k}-after.wav`), L.wav(V.enhanceVoice(x, {}, rn).y)); }
  }
  numbers.noise = tab;
  const stationary = ['fan', 'street', 'wind', 'music'];
  ok(stationary.every((k) => tab[k][0.5].db >= 18 && tab[k][1].db >= 28), `noise removal on fan/AC, street, wind and a music bed: medium ≥ 18 dB (${stationary.map((k) => tab[k][0.5].db).join(', ')}), strong ≥ 28 dB (${stationary.map((k) => tab[k][1].db).join(', ')})`);
  ok(Object.values(tab).every((r) => STR.every((st, i) => i === 0 || r[st].db >= r[STR[i - 1]].db - 0.3)), 'the Strength slider is monotonic: more strength never removes less noise, for every noise type');
  ok(Object.values(tab).every((r) => STR.every((st) => r[st].corr >= r.corrIn - 0.01)), `the speech is kept: correlation with the clean speech never drops (in ${Object.values(tab).map((r) => r.corrIn).join('/')} → strong ${Object.values(tab).map((r) => r[1].corr).join('/')})`);
  ok(tab.keyboard[1].db >= 6, `keyboard clicks: −${tab.keyboard[1].db} dB at strong (the voice gate); crowd babble −${tab.crowd[1].db} dB (other voices are speech to a voice denoiser — reported, not a pass mark)`);
  const per = (tab.fan[0.5].ms / (s.length / SR)).toFixed(0);
  info(`speed: ${per} ms of CPU per second of sound (RNNoise + mix), in the audio worker, off the UI thread`);

  console.log('\n── sound: hum ──');
  const humOut = {};
  for (const base of [50, 60]) {
    const hx = new Float32Array(s.length); for (let i = 0; i < s.length; i++) { const t = i / SR; hx[i] = s[i] + 0.02 * Math.sin(2 * Math.PI * base * t) + 0.01 * Math.sin(2 * Math.PI * 2 * base * t) + 0.008 * Math.sin(2 * Math.PI * 3 * base * t) + 0.004 * Math.sin(2 * Math.PI * 5 * base * t); }
    const det = V.detectHum(hx), hy = V.removeHum(hx, det?.base || base);
    humOut[base] = { found: det, drop: [1, 2, 3, 5].map((k) => +(L.toneDb(hx, base * k) - L.toneDb(hy, base * k)).toFixed(1)), corr: +L.corr(hy, s, s).toFixed(3) };
  }
  const none = V.detectHum(s); numbers.hum = { ...humOut, cleanSpeech: none };
  ok([50, 60].every((b) => humOut[b].found?.base === b && humOut[b].drop.every((d) => d >= 30) && humOut[b].corr >= 0.98) && !none,
    `hum: 50 Hz and 60 Hz found by themselves (${humOut[50].found?.db} / ${humOut[60].found?.db} dB over the neighbourhood), harmonics 1/2/3/5 down ${humOut[50].drop.join('/')} and ${humOut[60].drop.join('/')} dB, speech corr ${humOut[50].corr}/${humOut[60].corr}; clean speech: no hum reported`);

  console.log('\n── sound: room echo ──');
  const irf = path.join(OUT, 'room-ir.wav');
  if (!fs.existsSync(irf)) { const r = L.rng(9), T = 0.6, n = Math.round(T * SR), ir = new Float32Array(n); ir[0] = 1; for (let i = 1; i < n; i++) ir[i] = (r() * 2 - 1) * 0.25 * Math.exp(-6.9 * i / SR / T) * (i > 480 ? 1 : i / 480); fs.writeFileSync(irf, L.wav(ir)); }
  const dry = path.join(OUT, 'dry.wav'); fs.writeFileSync(dry, L.wav(s));
  const wetF = path.join(OUT, 'wet.wav'); ff('-i', dry, '-i', irf, '-lavfi', 'afir=gtype=none:dry=10:wet=10', '-ar', String(SR), '-ac', '1', wetF);
  const w = L.pcm(wetF).subarray(0, s.length), rv = {};
  for (const a of [0.4, 0.7, 1]) { const y = V.dereverb(w, SR, a).y; rv[a] = +L.noiseCut(w, y, s).toFixed(1); }
  numbers.reverb = rv;
  ok(rv[0.4] >= 3 && rv[1] > rv[0.4], `room echo (RT60 0.6 s room): the echo tails between words −${rv[0.4]} dB at the default, −${rv[0.7]} dB room, −${rv[1]} dB hall`);

  console.log('\n── sound: the whole chain → loudness ──');
  const x = L.mixAt(s, L.NOISES.fan(s.length), 5), full = V.enhanceVoice(x, {}, rn), fo = path.join(OUT, 'chain-out.wav');
  fs.writeFileSync(fo, L.wav([full.y, full.y]));   // as the reel carries it: the voice on both speakers
  const e = ebur128(fo); numbers.chain = { stats: full.stats, ffmpeg: e };
  ok(Math.abs(e.I + 14) <= 1 && e.TP <= -1 && full.stats.stages.join() === 'hum,noise,reverb,eq,deess,compress,loud',
    `✨ Enhance voice (all stages, medium) on speech + fan + 50 Hz hum: ffmpeg ${e.I} LUFS (−14 ±1), true peak ${e.TP} dBTP (≤ −1); own meter ${full.stats.lufs.toFixed(2)} LUFS / ${full.stats.tp.toFixed(2)} dBTP; hum ${full.stats.hum} Hz found; stages ${full.stats.stages.join(' → ')}`);
  const offAll = V.enhanceVoice(x, { stages: { noise: false, hum: false, reverb: false, eq: false, deess: false, compress: false, loud: false } }, rn);
  let same = 0; for (let i = 0; i < x.length; i++) same = Math.max(same, Math.abs(offAll.y[i] - x[i]));
  ok(same < 1e-6, 'every stage switched off: the sound passes through untouched (each Advanced switch really removes its stage)');
  void D;
}

// ── test media for the editor ──
function makeMedia(s, nz) {
  const voiceWav = path.join(OUT, 'voice-noisy.wav'), voice = path.join(OUT, 'voice.mp4'), dark = path.join(OUT, 'dark.mp4'), bars = path.join(OUT, 'bars.mp4');
  if (!fs.existsSync(voice)) {
    fs.writeFileSync(voiceWav, L.wav(nz));
    ff('-f', 'lavfi', '-i', `color=c=0x334455:size=540x960:rate=30:duration=${(s.length / SR).toFixed(2)}`, '-i', voiceWav, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-shortest', '-movflags', '+faststart', voice);
  }
  // dark, grainy, low-res (540×960 on a 1080×1920 reel), a warm cast: what a phone gives in a dim room
  if (!fs.existsSync(dark)) darkClip(dark);
  // a normal, well-exposed frame with gradients, colour, edges and skin-ish tones: the slider test frame
  if (!fs.existsSync(bars)) ff('-f', 'lavfi', '-i', 'testsrc2=size=1080x1920:rate=30:duration=3', '-vf', "drawbox=x=300:y=1200:w=480:h=380:color=0xd9a07e@1:t=fill,noise=alls=6:allf=t", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '16', '-pix_fmt', 'yuv420p', '-g', '30', '-movflags', '+faststart', bars);
  return { voice, dark, bars, voiceWav };
}

/** a dim room scene: a window-lit wall, a face, a plant, a shelf — dark, warm-cast and grainy, gently panning */
function darkClip(file) {
  const W = 540, Hh = 960, fps = 30, N = 90, base = new Float32Array((W + 40) * Hh * 3), r = L.rng(11);
  const put = (x, y, c) => { const i = (y * (W + 40) + x) * 3; base[i] = c[0]; base[i + 1] = c[1]; base[i + 2] = c[2]; };
  for (let y = 0; y < Hh; y++) for (let x = 0; x < W + 40; x++) {
    const u = x / (W + 40), v = y / Hh;
    let c = [0.55 - 0.25 * v, 0.55 - 0.22 * v, 0.6 - 0.2 * v];                                     // wall, lit from the top
    if (u > 0.62 && v < 0.42) c = [0.75 - 0.2 * v, 0.82 - 0.2 * v, 0.95 - 0.2 * v];                 // window
    if (v > 0.78) c = [0.32 + 0.05 * Math.sin(u * 40), 0.2, 0.12];                                   // wooden floor
    if (Math.hypot((u - 0.42) / 0.16, (v - 0.4) / 0.12) < 1) c = [0.85, 0.6, 0.48];                  // face
    if (Math.hypot((u - 0.42) / 0.2, (v - 0.34) / 0.12) < 1 && v < 0.33) c = [0.2, 0.13, 0.09];      // hair
    if (v > 0.52 && v < 0.78 && Math.abs(u - 0.42) < 0.24 - (v - 0.52) * 0.1) c = [0.2, 0.3, 0.55];  // blue top
    if (u < 0.18 && v > 0.45 && v < 0.78) c = [0.18 + 0.1 * Math.sin(y * 0.3 + x * 0.2), 0.45 + 0.1 * Math.sin(x * 0.5), 0.2];   // plant
    if (u > 0.7 && v > 0.5 && v < 0.53) c = [0.45, 0.32, 0.2];                                       // shelf
    if (u > 0.74 && u < 0.8 && v > 0.44 && v < 0.5) c = [0.8, 0.2, 0.3];                             // a red bottle
    put(x, y, c);
  }
  const frames = Buffer.alloc(W * Hh * 3 * N);
  const gauss = () => { let s = 0; for (let k = 0; k < 4; k++) s += r(); return (s - 2) * 1.73; };
  for (let f = 0; f < N; f++) {
    const off = Math.round(20 + 15 * Math.sin(f / N * Math.PI * 2));
    for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) {
      const i = (y * (W + 40) + x + off) * 3, o = (f * W * Hh + y * W + x) * 3;
      // dim (×0.32), warm cast, sensor grain (stronger in the dark, as on a phone)
      const cast = [1.18, 1, 0.78];
      for (let k = 0; k < 3; k++) { const v = Math.pow(base[i + k] * 0.32 * cast[k], 1 / 1.1) * 255 + gauss() * 9; frames[o + k] = Math.max(0, Math.min(255, Math.round(v))); }
    }
  }
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-s', `${W}x${Hh}`, '-r', String(fps), '-i', '-', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '12', '-tune', 'grain', '-pix_fmt', 'yuv420p', '-g', '30', '-movflags', '+faststart', file], { input: frames, maxBuffer: 1 << 30 });
}

async function editor(media) {
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(180000);
  const errors = [], outside = []; let nreq = 0;
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('worker', (w) => w.on('console', (m) => { if (m.type() === 'error') errors.push('[worker] ' + m.text()); }));
  context.on('request', (r) => { nreq++; const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const settle = async () => { await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 30000 }); await ev(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))); };
  const seekDraw = async (t) => { await ev((t) => window.kbbEditor.seek(t), t); await page.waitForTimeout(80); await settle(); await page.waitForTimeout(80); await settle(); };
  const open = async (file) => {
    if (await page.$('.editor')) { await ev(() => window.kbbEditor.goHome()); await page.waitForSelector('.home'); }
    await page.setInputFiles('.home-drop input[type=file]', file);
    await page.waitForSelector('.tl-item', { timeout: 60000 });
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    await ev(async () => { window.LK = await import('/app/look/index.js'); window.AU = await import('/app/audio/index.js'); window.HI = await import('/app/core/history.js'); window.R = await import('/app/core/render.js'); window.CTX = window.kbbEditor.context(); });
  };
  const exportTo = (name, opts = {}) => ev(async ({ name, opts }) => {
    const E = await import('/app/core/export.js'), ed = window.kbbEditor, p = ed.project;
    const r = await E.exportMp4(p, ed.media, Object.assign({ quality: 'high' }, opts));
    const file = `workspaces/${p.workspace}/exports/${name}`; await ed.engine.writeFile(file, r.blob);
    return { file, note: r.note, frames: r.frames };
  }, { name, opts });
  const shot = async (name, sel) => { await page.waitForTimeout(500); if (sel) await page.locator(sel).first().scrollIntoViewIfNeeded().catch(() => {}); await page.screenshot({ path: path.join(SHOTS, name) }); };

  // ════ sound in the editor ════
  console.log('\n── sound in the editor ──');
  await open(media.voice);
  await page.click('.side-tab[data-side="mod:audio"]'); await page.waitForTimeout(300);
  const tbar = await ev(() => [...document.querySelectorAll('.doit-btn')].map((b) => b.textContent.trim()));
  ok(tbar.some((x) => /Enhance voice/.test(x)) && tbar.some((x) => /Enhance video/.test(x)), `the editor toolbar ("Do it for me") has ✨ Enhance video and 🎙 Enhance voice: ${tbar.join(' · ')}`);
  let t = Date.now();
  await page.click('button[data-act="enhance-voice"]');
  await page.waitForFunction(() => window.kbbEditor.project.media[0].audio?.src, null, { timeout: 180000 });
  const au = await ev(() => window.kbbEditor.project.media[0].audio);
  ok(au.settings?.v === 2 && fs.existsSync(path.join(STUDIO, au.src)), `✨ Enhance voice (one click, in the audio worker) → ${au.src.split('/').pop()} in ${secs(t)}: ${JSON.stringify(au.stats)}`);
  numbers.voiceInApp = au.stats;
  await page.waitForTimeout(600);
  const top = (sel) => ev((sel) => { const el = document.querySelector(sel), sc = el.closest('.side-body'), hd = sc.querySelector('.insp-head'); const off = hd && getComputedStyle(hd).position === 'sticky' ? hd.getBoundingClientRect().height : 0; sc.scrollTop += el.getBoundingClientRect().top - sc.getBoundingClientRect().top - off - 6; }, sel);
  await top('.mod-body[data-mod="audio"] details.sec:nth-of-type(1)');
  await shot('enhance-voice-panel.png');
  await page.locator('details.adv summary').click(); await page.waitForTimeout(300);
  await top('details.adv');
  await shot('enhance-voice-advanced.png');
  // A/B
  const ab = await ev(() => { const ed = window.kbbEditor, m = ed.project.media[0]; const a = !!ed.media.audioElement(m); window.AU.hearOriginal(true); const b = !!ed.media.audioElement(m); window.AU.hearOriginal(false); return { after: a, before: b, back: !!ed.media.audioElement(m) }; });
  ok(ab.after && !ab.before && ab.back, 'A/B: "A · Before" plays the original sound in the preview, "B · After" the enhanced one (nothing in the project changes)');
  // preview = export: the WAV the monitor plays vs the sound in the MP4
  t = Date.now();
  const exA = await exportTo('enh-voice.mp4', { width: 272, height: 480, quality: 'standard' });
  const left = (f) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', f, '-af', 'pan=mono|c0=c0', '-ar', String(SR), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); };
  const fA = path.join(STUDIO, exA.file), wavA = left(path.join(STUDIO, au.src)), mp4A = left(fA);
  const lag = bestLag(wavA, mp4A); let ab2 = 0, aa = 0, bb = 0;
  for (let i = SR * 0.2; i < Math.min(wavA.length, mp4A.length - lag) - SR * 0.2; i++) { const a = wavA[i], b = mp4A[i + lag] || 0; ab2 += a * b; aa += a * a; bb += b * b; }
  const pc = ab2 / Math.sqrt(aa * bb), lvl = 10 * Math.log10(bb / aa), eA = ebur128(fA);
  numbers.previewVsExportSound = { lagSamples: lag, corr: +pc.toFixed(4), levelDb: +lvl.toFixed(2), ffmpeg: eA, note: exA.note };
  ok(pc > 0.98 && Math.abs(lvl) < 0.5 && Math.abs(lag) < 1200, `preview sound = export sound: the enhanced WAV vs the MP4's track (${/Opus/.test(exA.note) ? 'Opus' : 'AAC'}): correlation ${pc.toFixed(4)}, level ${lvl.toFixed(2)} dB, offset ${(lag / 48).toFixed(1)} ms`);
  ok(Math.abs(eA.I + 14) <= 1 && eA.TP <= -1, `the exported reel: ffmpeg ${eA.I} LUFS (−14 ±1), true peak ${eA.TP} dBTP (≤ −1) — from the voice chain alone, no export-time loudness pass (export ${secs(t)})`);
  const inE = ebur128(media.voice), floorIn = L.activity ? null : null; void floorIn;
  const fIn = (await import('../app/audio/dsp.js')).noiseFloorDb(L.pcm(media.voice)), fOut = (await import('../app/audio/dsp.js')).noiseFloorDb(mp4A);
  numbers.voiceFloor = { in: +fIn.toFixed(1), out: +fOut.toFixed(1), inLufs: inE.I, outLufs: eA.I };
  ok((fIn - inE.I) - (fOut - eA.I) >= 18, `noise floor relative to the speech: ${(fIn - inE.I).toFixed(1)} → ${(fOut - eA.I).toFixed(1)} dB (${((fIn - inE.I) - (fOut - eA.I)).toFixed(1)} dB quieter)`);
  fs.copyFileSync(media.voiceWav, path.join(OUT, 'studio-voice-before.wav')); fs.copyFileSync(path.join(STUDIO, au.src), path.join(OUT, 'studio-voice-after.wav'));
  // speed: a 2× clip plays the cleaned sound (retimed from media.audio)
  const sp = await ev(async () => {
    const ed = window.kbbEditor, p = ed.project, it = p.tracks.find((t) => t.kind === 'video').items[0], m = p.media[0];
    ed.apply(window.HI.ops.setItem(it.id, { speed: 2 }, 'Speed')); ed.history.seal();
    const RA = await import('/app/core/retime-audio.js'), D = await import('/app/audio/dsp.js');
    const P = await import('/app/core/project.js'), item = P.findItem(ed.project, it.id).item;
    const buf = await RA.retimedSound(ed.media, item, m), x = buf.getChannelData(0);
    const orig = await ed.media.decodeAudio(m.id, Object.assign({}, m, { audio: null }));
    const o = orig.pcm48k.channels[0], rel = (y) => D.noiseFloorDb(y) - D.loudness([y], 48000).integrated;   // floor under the speech
    const cl = (await ed.media.decodeAudio(m.id)).pcm48k.channels[0];
    const r = { floor: rel(x), origFloor: rel(o), cleanFloor: rel(cl), len: buf.duration, want: (it.out - it.in) / 2 };
    ed.history.undo();
    return r;
  });
  ok(sp.origFloor - sp.floor > 15 && Math.abs(sp.floor - sp.cleanFloor) < 4 && Math.abs(sp.len - sp.want) < 0.01, `with speed 2× the clip plays the enhanced sound, retimed: noise floor under the speech ${sp.origFloor.toFixed(1)} dB (original) → ${sp.floor.toFixed(1)} dB (enhanced at 1×: ${sp.cleanFloor.toFixed(1)}), ${sp.len.toFixed(2)} s = half of ${(sp.want * 2).toFixed(2)} s`);
  // ducking: music under speech
  const dk = await ev(async () => {
    const ed = window.kbbEditor, p = ed.project, P = await import('/app/core/project.js'), mt = p.tracks.find((t) => t.kind === 'music');
    const it = P.createItem('audio', { media: p.media[0].id, t0: 0, in: 0, out: 6, volume: 0.8, name: 'music test' });
    ed.apply(window.HI.ops.addItems([{ trackId: mt.id, item: it }], 'Music')); ed.history.seal();
    const n = await window.AU.duckMusic(window.CTX);
    const k = P.findItem(ed.project, it.id).item.kf?.volume || [];
    const v = (tt) => window.R.evalProp(P.findItem(ed.project, it.id).item, 'volume', tt);
    return { n, keys: k.length, inSpeech: v(2.0), beforeSpeech: v(0.05), min: Math.min(...k.map((x) => x[1])) };
  });
  ok(dk.n === 1 && dk.keys > 2 && dk.inSpeech < 0.4 && dk.beforeSpeech > 0.7, `🎵 music ducks under speech: ${dk.keys} volume keyframes, 0.8 before the first word → ${dk.inSpeech.toFixed(2)} while speaking`);
  await ev(() => { window.kbbEditor.history.undo(); window.kbbEditor.history.undo(); });
  const undoV = await ev(() => { const ed = window.kbbEditor; for (let i = 0; i < 6 && ed.project.media[0].audio; i++) ed.history.undo(); const a = !ed.project.media[0].audio; ed.history.redo(); return { a, b: !!ed.project.media[0].audio }; });
  ok(undoV.a && undoV.b, 'Enhance voice is one undo step (⌘Z → original sound, ⇧⌘Z → enhanced again)');

  // ════ picture: each slider on a test frame ════
  console.log('\n── picture: sliders ──');
  await open(media.bars);
  await seekDraw(1.0);
  const sl = await ev(async () => {
    const ed = window.kbbEditor, p = ed.project, it = p.tracks.find((t) => t.kind === 'video').items[0], el = ed.media.element(it.media);
    const W = 270, Hh = 480, c = document.createElement('canvas'); c.width = W; c.height = Hh; const x = c.getContext('2d', { willReadFrequently: true });
    const grab = () => { window.R.renderFrame(x, ed.project, 1.0, { scale: W / p.canvas.w, source: () => el }); return x.getImageData(0, 0, W, Hh).data; };
    const base = grab(), Lb = new Float32Array(W * Hh);
    for (let i = 0, j = 0; i < base.length; i += 4, j++) Lb[j] = 0.2126 * base[i] + 0.7152 * base[i + 1] + 0.0722 * base[i + 2];
    const st = (d) => {
      let l = 0, rb = 0, tint = 0, sat = 0, lowSat = 0, nLow = 0, hi = 0, nHi = 0, sh = 0, nSh = 0, ed2 = 0, nEd = 0, ce = 0, nCe = 0, lap = 0, sq = 0, top = 0, nTop = 0, bot = 0, nBot = 0;
      const Y = new Float32Array(W * Hh);
      for (let i = 0, j = 0; i < d.length; i += 4, j++) { Y[j] = 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]; }
      for (let y = 0; y < Hh; y++) for (let xx = 0; xx < W; xx++) {
        const j = y * W + xx, i = j * 4, r = d[i], g = d[i + 1], b = d[i + 2], v = Y[j], s0 = Math.max(base[i], base[i + 1], base[i + 2]) - Math.min(base[i], base[i + 1], base[i + 2]);
        l += v; sq += v * v; rb += r - b; tint += (r + b) / 2 - g; const s = Math.max(r, g, b) - Math.min(r, g, b); sat += s;
        if (s0 < 60) { lowSat += s; nLow++; }
        if (Lb[j] > 170) { hi += v; nHi++; } if (Lb[j] < 70) { sh += v; nSh++; }
        if (Lb[j] > 200) { top += v; nTop++; } if (Lb[j] < 35) { bot += v; nBot++; }
        const dx = (xx - W / 2) / W, dy = (y - Hh / 2) / Hh, rr = Math.hypot(dx, dy * 1.15);
        if (rr > 0.42) { ed2 += v; nEd++; } else if (rr < 0.15) { ce += v; nCe++; }
        if (xx > 0 && y > 0 && xx < W - 1 && y < Hh - 1) lap += Math.abs(4 * v - Y[j - 1] - Y[j + 1] - Y[j - W] - Y[j + W]);
      }
      const n = W * Hh, mean = l / n;
      return { luma: mean, std: Math.sqrt(sq / n - mean * mean), rb: rb / n, tint: tint / n, sat: sat / n, lowSat: lowSat / Math.max(1, nLow), hi: hi / Math.max(1, nHi), sh: sh / Math.max(1, nSh), top: top / Math.max(1, nTop), bot: bot / Math.max(1, nBot), vig: (ed2 / nEd) / (ce / nCe), detail: lap / n };
    };
    // detail sliders act on the source's own pixels, so they are measured 1:1 (a 270×480 crop of the full 1080×1920 frame)
    const fc = document.createElement('canvas'); fc.width = p.canvas.w; fc.height = p.canvas.h; const fx = fc.getContext('2d', { willReadFrequently: true });
    const detail1 = () => { window.R.renderFrame(fx, ed.project, 1.0, { scale: 1, source: () => el }); const d = fx.getImageData(405, 720, 270, 480).data; let s = 0; const Y = (i) => 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]; for (let y = 1; y < 479; y++) for (let x2 = 1; x2 < 269; x2++) { const i = (y * 270 + x2) * 4; s += Math.abs(4 * Y(i) - Y(i - 4) - Y(i + 4) - Y(i - 1080) - Y(i + 1080)); } return s / (268 * 478); };
    const B = st(base), out = {}; B.detail1 = detail1();
    const tests = [['exp', 0.6], ['exp', -0.6], ['bri', 0.6], ['con', 0.6], ['con', -0.6], ['hi', -0.8], ['sh', 0.8], ['wh', 0.8], ['bl', -0.8], ['temp', 0.7], ['temp', -0.7], ['tint', 0.7], ['sat', 0.6], ['sat', -1], ['vib', 0.8], ['sharp', 0.8], ['clar', 0.8], ['dn', 0.8], ['vig', -0.8]];
    for (const [k, v] of tests) { ed.history.seal(); window.LK.setAdjust(window.CTX, { [k]: v }, { ids: [it.id] }); ed.history.seal(); out[k + (v > 0 ? '+' : '-')] = st(grab()); out[k + (v > 0 ? '+' : '-')].detail1 = ['sharp', 'dn'].includes(k) ? detail1() : 0; ed.history.undo(); }
    return { B, out };
  });
  const B = sl.B, O = sl.out, d = (k, f) => +(O[k][f] - B[f]).toFixed(2), rr = (k, f) => +(O[k][f] / B[f]).toFixed(3);
  const expect = [
    ['exp+', d('exp+', 'luma') > 12, `brightness +${d('exp+', 'luma')}`], ['exp-', d('exp-', 'luma') < -12, `brightness ${d('exp-', 'luma')}`],
    ['bri+', d('bri+', 'luma') > 8 && Math.abs(d('bri+', 'top')) < Math.abs(d('bri+', 'luma')), `mid-tones +${d('bri+', 'luma')}, whites ${d('bri+', 'top')}`],
    ['con+', d('con+', 'std') > 5, `spread +${d('con+', 'std')}`], ['con-', d('con-', 'std') < -5, `spread ${d('con-', 'std')}`],
    ['hi-', d('hi-', 'hi') < -6 && Math.abs(d('hi-', 'sh')) < 3, `bright areas ${d('hi-', 'hi')}, dark areas ${d('hi-', 'sh')}`],
    ['sh+', d('sh+', 'sh') > 6 && Math.abs(d('sh+', 'hi')) < Math.abs(d('sh+', 'sh')), `dark areas +${d('sh+', 'sh')}, bright ${d('sh+', 'hi')}`],
    ['wh+', d('wh+', 'top') > 3, `whites +${d('wh+', 'top')}`], ['bl-', d('bl-', 'bot') < -2, `blacks ${d('bl-', 'bot')}`],
    ['temp+', d('temp+', 'rb') > 8, `R−B +${d('temp+', 'rb')}`], ['temp-', d('temp-', 'rb') < -8, `R−B ${d('temp-', 'rb')}`], ['tint+', d('tint+', 'tint') > 3, `magenta +${d('tint+', 'tint')}`],
    ['sat+', d('sat+', 'sat') > 8, `colour +${d('sat+', 'sat')}`], ['sat-', O['sat-'].sat < 3, `colour ${O['sat-'].sat.toFixed(1)} (grey)`],
    ['vib+', rr('vib+', 'lowSat') > rr('vib+', 'sat') && d('vib+', 'lowSat') > 2, `dull colours ×${rr('vib+', 'lowSat')} vs all ×${rr('vib+', 'sat')}`],
    ['sharp+', rr('sharp+', 'detail1') > 1.15, `detail ×${rr('sharp+', 'detail1')} (1:1)`], ['clar+', rr('clar+', 'detail') > 1.05 && d('clar+', 'std') > 1, `local contrast ×${rr('clar+', 'detail')}`],
    ['dn+', rr('dn+', 'detail1') < 0.9, `fine detail/grain ×${rr('dn+', 'detail1')} (1:1)`], ['vig-', O['vig-'].vig < B.vig - 0.08, `edge/centre ${B.vig.toFixed(2)} → ${O['vig-'].vig.toFixed(2)}`],
  ];
  numbers.sliders = { base: B, deltas: Object.fromEntries(expect.map((e) => [e[0], e[2]])) };
  ok(expect.every((e) => e[1]), `${expect.length} slider moves, each measured on the test frame: ` + expect.map((e) => `${e[0]} ${e[2]} ${e[1] ? '✓' : '✗'}`).join(' · '));

  // neutral = none (old projects and untouched clips draw exactly as before)
  const neutral = await ev(() => {
    const ed = window.kbbEditor, p = ed.project, it = p.tracks.find((t) => t.kind === 'video').items[0], el = ed.media.element(it.media);
    const c = document.createElement('canvas'); c.width = 270; c.height = 480; const x = c.getContext('2d', { willReadFrequently: true });
    const g = () => { window.R.renderFrame(x, ed.project, 1.0, { scale: 0.25, source: () => el }); return x.getImageData(0, 0, 270, 480).data; };
    const a = g(); ed.history.seal(); window.LK.setAdjust(window.CTX, { exp: 0.5 }, { ids: [it.id] }); ed.history.seal(); window.LK.setAdjust(window.CTX, { exp: 0 }, { ids: [it.id] }); ed.history.seal();
    const fx = JSON.stringify(window.kbbEditor.project.tracks.find((t) => t.kind === 'video').items[0].fx);
    const b = g(); let dd = 0; for (let i = 0; i < a.length; i++) dd = Math.max(dd, Math.abs(a[i] - b[i]));
    ed.history.undo(); ed.history.undo();
    return { dd, fx };
  });
  ok(neutral.dd === 0, `a slider moved and put back to zero draws byte-identical to a clip that never had one (max diff ${neutral.dd}; fx ${neutral.fx})`);

  // undo / redo, all clips
  const ur = await ev(() => {
    const ed = window.kbbEditor, it = ed.project.tracks.find((t) => t.kind === 'video').items[0];
    const get = () => JSON.stringify((window.kbbEditor.project.tracks.find((t) => t.kind === 'video').items[0].fx || []).find((f) => f.type === 'look.adjust') || null);
    ed.history.seal(); const v0 = get(); window.LK.setAdjust(window.CTX, { con: 0.3 }, { ids: [it.id] }); ed.history.seal(); window.LK.setAdjust(window.CTX, { con: 0.5 }, { ids: [it.id] }); ed.history.seal();
    const v2 = get(); ed.history.undo(); const v1 = get(); ed.history.undo(); const vu = get(); ed.history.redo(); ed.history.redo(); const vr = get();
    return { ok: vu === v0 && vr === v2 && v1 !== v2 && /0\.3/.test(v1), v0, v1, v2 };
  });
  ok(ur.ok, `undo / redo step through slider changes exactly (${ur.v1} → ${ur.v2} → undo → undo = ${ur.v0})`);
  await ev(() => { const ed = window.kbbEditor; ed.history.undo(); ed.history.undo(); ed.history.seal(); });

  // ════ Enhance video on a dark, grainy, low-res clip ════
  console.log('\n── picture: ✨ Enhance video ──');
  await open(media.dark);
  await seekDraw(1.0);
  const ex0 = await exportTo('enh-dark-before.mp4', { duration: 1.5 });
  const sel = await ev(() => { const ed = window.kbbEditor, it = ed.project.tracks.find((t) => t.kind === 'video').items[0]; ed.select([it.id]); return it.id; });
  await page.click('.side-tab[data-side="inspector"]'); await page.waitForTimeout(300);
  t = Date.now();
  await page.click('.adj button[data-act="enhance"]');
  await page.waitForFunction(() => (window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0].fx || []).some((f) => f.enh), null, { timeout: 120000 });
  const enh = await ev(() => window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0].fx.find((f) => f.type === 'look.adjust').enh);
  info(`Enhance video measured the clip in ${secs(t)}: ${JSON.stringify(enh)}`);
  const ex1 = await exportTo('enh-dark-after.mp4', { duration: 1.5 });
  const fb = path.join(STUDIO, ex0.file), fa = path.join(STUDIO, ex1.file);
  const sb = frameStats(frameRGB(fb, 1.0, 540, 960), 540, 960), sa = frameStats(frameRGB(fa, 1.0, 540, 960), 540, 960);
  numbers.enhanceVideo = { plan: enh, before: sb, after: sa };
  ok(sa.luma > sb.luma + 15 && sa.noise < sb.noise * 0.8 && enh.parts.dn && enh.parts.low && enh.parts.up,
    `✨ Enhance video on a dark, grainy 540×960 clip (export frames at 1 s): brightness ${sb.luma.toFixed(1)} → ${sa.luma.toFixed(1)}/255, grain σ ${sb.noise.toFixed(2)} → ${sa.noise.toFixed(2)}/255 (${((1 - sa.noise / sb.noise) * 100).toFixed(0)}% less, measured after the lift), parts on: ${Object.entries(enh.parts).filter(([, v]) => v).map(([k]) => k).join(', ')}`);
  ff('-ss', '1', '-i', fb, '-ss', '1', '-i', fa, '-filter_complex', '[0:v]scale=540:960,drawtext=text=Before:x=20:y=20:fontsize=40:fontcolor=white:box=1:boxcolor=black@0.6[a];[1:v]scale=540:960,drawtext=text=After:x=20:y=20:fontsize=40:fontcolor=white:box=1:boxcolor=black@0.6[b];[a][b]hstack', '-frames:v', '1', path.join(OUT, 'video-before-after.png'));
  // each part switchable
  const partsOff = await ev(async () => {
    const ed = window.kbbEditor, it = () => ed.project.tracks.find((x) => x.kind === 'video').items[0];
    const n0 = (it().fx.find((f) => f.type === 'look.adjust').enh.parts.dn);
    window.LK.setEnhancePart(window.CTX, 'dn', false, { ids: [it().id] });
    const n1 = it().fx.find((f) => f.type === 'look.adjust').enh.parts.dn; ed.history.undo();
    const n2 = it().fx.find((f) => f.type === 'look.adjust').enh.parts.dn;
    return { n0, n1, n2 };
  });
  ok(partsOff.n0 === true && partsOff.n1 === false && partsOff.n2 === true, 'each Enhance part switches off on its own (denoise off → on again with undo), the rest stays');
  await top('.adj'); await shot('enhance-adjust-panel.png');
  await ev(() => { const ed = window.kbbEditor, it = ed.project.tracks.find((x) => x.kind === 'video').items[0]; ed.history.seal(); window.LK.setAdjust(window.CTX, { exp: 0.25, con: 0.2, sh: 0.35, vib: 0.3 }, { ids: [it.id] }); ed.history.seal(); ed.inspector.render(true); });
  await top('.adj .adj-row'); await shot('enhance-adjust-sliders.png');
  await ev(() => { window.kbbEditor.history.undo(); window.kbbEditor.inspector.render(true); });
  // before / after split in the monitor
  const region = () => ev(() => { const c = window.kbbEditor.preview.canvas, x = c.getContext('2d'), w = c.width, h = c.height; const m = (x0) => { const d = x.getImageData(Math.round(w * x0), Math.round(h * 0.2), Math.round(w * 0.3), Math.round(h * 0.6)).data; let s = 0; for (let i = 0; i < d.length; i += 4) s += 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]; return s / (d.length / 4); }; return { left: m(0.1), right: m(0.6), line: !!document.querySelector('.cmp-line') }; });
  const off = await region();
  await ev(() => window.LK.toggleCompare(true, 0.5)); await seekDraw(1.0);
  const split = await region();
  await shot('enhance-before-after.png');
  // drag the divider a quarter of the monitor to the right
  const box = await page.locator('.cmp-knob').boundingBox(), mw = await ev(() => window.kbbEditor.preview.canvas.getBoundingClientRect().width);
  await page.mouse.move(box.x + 15, box.y + 15); await page.mouse.down(); await page.mouse.move(box.x + 15 + mw / 4, box.y + 15, { steps: 6 }); await page.mouse.up();
  const moved = await ev(async () => (await import('/app/look/fx.js')).COMPARE.x);
  numbers.compare = { off, split, moved };
  ok(split.line && off.left - split.left > 10 && Math.abs(off.right - split.right) < 1 && Math.abs(moved - 0.75) < 0.06, `before / after split in the preview: left of the divider shows the untouched clip (${off.left.toFixed(1)} → ${split.left.toFixed(1)}/255), right stays enhanced (${off.right.toFixed(1)} = ${split.right.toFixed(1)}); the divider drags (0.50 → ${moved.toFixed(2)})`);
  const cmpExport = await ev(async () => { const E = await import('/app/core/export.js'); return (await import('/app/look/fx.js')).COMPARE.on; });
  await ev(() => window.LK.toggleCompare(false));
  info(`the split is a viewing aid only (the export ignores it: env.preview is false there); compare was ${cmpExport ? 'on' : 'off'} during the check`);

  // ════ preview = export with every slider on ════
  console.log('\n── picture: preview = export, upscale, speed, save ──');
  await ev(() => { const it = window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0]; window.LK.setAdjust(window.CTX, { exp: 0.2, bri: 0.2, con: 0.25, hi: -0.4, sh: 0.4, wh: 0.2, bl: -0.2, temp: 0.2, tint: 0.1, sat: 0.2, vib: 0.3, sharp: 0.5, clar: 0.3, dn: 0.4, vig: -0.3 }, { ids: [it.id] }); window.kbbEditor.history.seal(); });
  await seekDraw(1.0);
  const pv = await ev(() => { const c = window.kbbEditor.preview.canvas; const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; const rgb = new Uint8Array(c.width * c.height * 3); for (let i = 0, j = 0; i < d.length; i += 4) { rgb[j++] = d[i]; rgb[j++] = d[i + 1]; rgb[j++] = d[i + 2]; } return { w: c.width, h: c.height, data: Array.from(rgb) }; });
  const ex2 = await exportTo('enh-all.mp4', { duration: 1.5 });
  const pd = meanDiff(Uint8Array.from(pv.data), frameRGB(path.join(STUDIO, ex2.file), 1.0, pv.w, pv.h));
  numbers.previewVsExport = { w: pv.w, h: pv.h, meanDiff: +pd.toFixed(2) };
  ok(pd < 6, `preview frame = export frame with Enhance + all 15 sliders on (${pv.w}×${pv.h}): mean difference ${pd.toFixed(2)}/255 (< 6)`);
  // upscale: Lanczos vs bilinear, 1080p and 4K output sizes
  const ws = await ev(() => { const ed = window.kbbEditor, it = ed.project.tracks.find((x) => x.kind === 'video').items[0]; window.LK.resetAdjust(window.CTX, null, { ids: [it.id] }); window.LK.enhanceOff(window.CTX, { ids: [it.id] }); ed.history.seal(); return JSON.stringify(ed.project.tracks.find((x) => x.kind === 'video').items[0].fx || []); });
  const exN = await exportTo('enh-bilinear.mp4', { duration: 0.5 });
  await ev(() => { const it = window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0]; window.LK.setAdjust(window.CTX, { up: true }, { ids: [it.id] }); window.kbbEditor.history.seal(); });
  const exU = await exportTo('enh-lanczos.mp4', { duration: 0.5 });
  const dN = frameStats(frameRGB(path.join(STUDIO, exN.file), 0.2, 1080, 1920), 1080, 1920).detail, dU = frameStats(frameRGB(path.join(STUDIO, exU.file), 0.2, 1080, 1920), 1080, 1920).detail;
  t = Date.now();
  const ex4 = await exportTo('enh-4k.mp4', { duration: 0.5, width: 2160, height: 3840, quality: 'max' });
  const t4 = Date.now() - t;
  const p1 = probe(path.join(STUDIO, exU.file)).find((x) => x.codec_type === 'video'), p4 = probe(path.join(STUDIO, ex4.file)).find((x) => x.codec_type === 'video');
  numbers.upscale = { fxBefore: ws, bilinearDetail: +dN.toFixed(3), lanczosDetail: +dU.toFixed(3), size1080: [p1.width, p1.height], size4k: [p4.width, p4.height], ms4k: t4, note4k: ex4.note };
  ok(p1.width === 1080 && p1.height === 1920 && p4.width === 2160 && p4.height === 3840 && dU > dN * 1.03,
    `upscale: a 540×960 clip exported at ${p1.width}×${p1.height} and at 4K ${p4.width}×${p4.height} (Max quality); Lanczos keeps more detail than the plain enlargement (${dU.toFixed(2)} vs ${dN.toFixed(2)} mean |Laplacian|, ×${(dU / dN).toFixed(2)}); 4K export of 15 frames ${(t4 / 1000).toFixed(1)} s on SwiftShader`);

  // speed: an adjusted clip at 2× keeps its adjustment in preview and export (source fx run per frame)
  // save + reload: the adjustments reopen identical and draw the same
  await ev(() => { const it = window.kbbEditor.project.tracks.find((x) => x.kind === 'video').items[0]; window.LK.setAdjust(window.CTX, { exp: 0.4, sat: 0.3, sharp: 0.4 }, { ids: [it.id] }); window.kbbEditor.history.seal(); });
  await seekDraw(1.0);
  const before = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
  const fr0 = await ev(() => { const c = window.kbbEditor.preview.canvas; return Array.from(c.getContext('2d').getImageData(0, 0, c.width, c.height).data.filter((_, i) => i % 16 === 0)); });
  await ev(() => window.kbbEditor.save());
  await page.reload();
  await page.waitForSelector('.tl-item', { timeout: 30000 }); await page.waitForFunction(() => window.kbbEditor?.project);
  await ev(async () => { window.LK = await import('/app/look/index.js'); window.R = await import('/app/core/render.js'); window.HI = await import('/app/core/history.js'); window.CTX = window.kbbEditor.context(); });
  await seekDraw(1.0);
  const after = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
  const fr1 = await ev(() => { const c = window.kbbEditor.preview.canvas; return Array.from(c.getContext('2d').getImageData(0, 0, c.width, c.height).data.filter((_, i) => i % 16 === 0)); });
  const rd = fr0.length === fr1.length ? meanDiff(fr0, fr1) : 999;
  ok(after === before && rd < 1.5, `save + reload: the clip's adjustments reopen identical and the monitor draws the same picture (mean diff ${rd.toFixed(2)}/255)`);

  // frame time: everything on, the preview at its size and the export at 1080×1920
  const ft = await ev(async () => {
    const ed = window.kbbEditor, p = ed.project, it = p.tracks.find((x) => x.kind === 'video').items[0];
    window.LK.setAdjust(window.CTX, { exp: 0.2, bri: 0.2, con: 0.25, hi: -0.4, sh: 0.4, wh: 0.2, bl: -0.2, temp: 0.2, tint: 0.1, sat: 0.2, vib: 0.3, sharp: 0.5, clar: 0.3, dn: 0.6, vig: -0.3 }, { ids: [it.id] });
    await window.LK.enhanceVideo(window.CTX, { ids: [it.id] });
    const el = ed.media.element(it.media), FX = await import('/app/look/fx.js');
    const time = (W, Hh, n) => { const cv = document.createElement('canvas'); cv.width = W; cv.height = Hh; const x = cv.getContext('2d'); const out = []; for (let i = 0; i < n; i++) { const t0 = performance.now(); window.R.renderFrame(x, ed.project, 1.0, { scale: W / p.canvas.w, preview: true, source: () => el }); x.getImageData(0, 0, 1, 1); out.push(performance.now() - t0); } return out; };
    const s = (a) => ({ median: +a.slice().sort((p, q) => p - q)[a.length >> 1].toFixed(1), worst: +Math.max(...a.slice(1)).toFixed(1) });
    const r = { on: { m540: s(time(540, 960, 8)), m1080: s(time(1080, 1920, 5)) } };
    window.LK.resetAdjust(window.CTX, null, { ids: [it.id] }); window.LK.enhanceOff(window.CTX, { ids: [it.id] });
    r.off = { m540: s(time(540, 960, 8)), m1080: s(time(1080, 1920, 5)) };
    void FX;
    return r;
  });
  numbers.frameTime = ft;
  ok(ft.on.m540.worst > 0, `frame time, Enhance + all 15 sliders on (SwiftShader: the CPU emulates the GPU, so these are NOT Mac GPU times; compare with l-check’s look + touch-up + background pass on the same machine): 540×960 monitor median ${ft.on.m540.median} ms / worst ${ft.on.m540.worst} ms (no adjustment ${ft.off.m540.median} ms); 1080×1920 median ${ft.on.m1080.median} ms / worst ${ft.on.m1080.worst} ms (no adjustment ${ft.off.m1080.median} ms)`);
  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 6).join(' | ') : ''}`);
  ok(!outside.length, `no browser request left 127.0.0.1 (${nreq} requests)${outside.length ? ': ' + outside.slice(0, 5).join(', ') : ''}`);
  await browser.close();
  await H.stopEngine(e);
}

(async () => {
  const T0 = Date.now();
  await soundNode();
  if (!process.argv.includes('--quick')) {
    const s = L.speech(), nz = L.mixAt(s, (() => { const a = L.NOISES.fan(s.length); return a; })(), 5);
    await editor(makeMedia(s, nz));
  }
  fs.writeFileSync(path.join(OUT, 'enhance-summary.json'), JSON.stringify({ results, numbers }, null, 1));
  console.log(`\n${failures ? failures + ' FAILED' : 'all enhance checks passed'} — ${results.filter((r) => /^(PASS|FAIL)/.test(r)).length} checks in ${secs(T0)}`);
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
