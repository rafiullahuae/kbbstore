#!/usr/bin/env node
// KBB Studio · Lane V test media (into tools/out/v/, never committed). Talking videos whose WORD TIMINGS ARE KNOWN:
// every word is synthesised on its own (flite for English, MMS-TTS Arabic run offline in Chromium) and laid down with
// designed gaps — tight joins (0.04 s), breaths (0.18 / 0.26 s, a little breath noise in some), sentence pauses
// (0.45 s) and one long still, silent stretch (a dead zone). Some words are said 9 dB louder (RMS; stressed). A skin-toned
// "face" moves over a dark background (the skin-tone subject finder sees it; nothing else in the frame is skin-like),
// and the 9:16 clips open on 0.5 s of black (the first-frame check must catch it).
//   v-en-916.mp4 / .json   ~24 s 1080×1920, KBB beauty talk (steps, claims, CTA)
//   v-pc-169.mp4 / .json   ~16 s 1920×1080, Planet Careers visa talk, the face low and to the right
//   v-ar-916.mp4 / .json   ~12 s 1080×1920, Arabic beauty/visa talk
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/v-media.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const OUT = path.join(H.ROOT, 'tools', 'out', 'v');
const CACHE = path.join(H.ROOT, 'tools', 'out', 'int-cache');
fs.mkdirSync(OUT, { recursive: true });
const SR = 16000;
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
const pcm = (file) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '1', '-ar', String(SR), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); };
function wav(x) {
  const b = Buffer.alloc(44 + x.length * 2);
  b.write('RIFF', 0); b.writeUInt32LE(36 + x.length * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22);
  b.writeUInt32LE(SR, 24); b.writeUInt32LE(SR * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(x.length * 2, 40);
  for (let i = 0; i < x.length; i++) b.writeInt16LE(Math.round(Math.max(-1, Math.min(1, x[i])) * 32767), 44 + i * 2);
  return b;
}
/** cut the silence around one synthesised word, normalise its peak */
function trimWord(x, rmsDb) {
  let pk = 0; for (const v of x) pk = Math.max(pk, Math.abs(v));
  const thr = pk * 0.02; let a = 0, b = x.length - 1;
  while (a < b && Math.abs(x[a]) < thr) a++; while (b > a && Math.abs(x[b]) < thr) b--;
  a = Math.max(0, a - 40); b = Math.min(x.length - 1, b + 40);
  // loudness by RMS (what a listener hears), peaks soft-limited so a stressed word never clips
  let s = 0; for (let i = a; i <= b; i++) s += x[i] * x[i];
  const g = Math.pow(10, rmsDb / 20) / (Math.sqrt(s / (b - a + 1)) || 1), o = x.slice(a, b + 1).map((v) => 0.95 * Math.tanh(v * g / 0.95));
  for (let i = 0; i < 48 && i < o.length; i++) { o[i] *= i / 48; o[o.length - 1 - i] *= i / 48; }
  return o;
}
let seed = 7; const rnd = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
/** lay words on a timeline. script = [[word, gapAfter, loud?, speakAs?], ...] -> {audio, words, silent:[a,b]} */
function lay(script, wordAudio, lead) {
  const parts = [new Float32Array(Math.round(lead * SR))], words = [];
  let t = lead;
  script.forEach(([w, gap, loud], i) => {
    const a = trimWord(wordAudio[i], loud ? -12 : -21);   // stressed words 9 dB louder
    words.push({ w, t0: +t.toFixed(3), t1: +(t + a.length / SR).toFixed(3), loud: !!loud });
    parts.push(a); t += a.length / SR;
    const g = new Float32Array(Math.round(gap * SR));
    // a soft breath in some of the breath-sized gaps (far below the voice, like a real one)
    if (gap >= 0.15 && gap < 0.3 && i % 2) for (let k = 0; k < g.length; k++) g[k] = (rnd() * 2 - 1) * 0.004 * Math.sin(Math.PI * k / g.length);
    parts.push(g); t += gap;
  });
  const n = parts.reduce((s, p) => s + p.length, 0), audio = new Float32Array(n); let o = 0; for (const p of parts) { audio.set(p, o); o += p.length; }
  return { audio, words, duration: +(n / SR).toFixed(3) };
}
function flite(word, voice) {
  const tf = path.join(OUT, 'w.txt'), wf = path.join(OUT, 'w.wav');
  fs.writeFileSync(tf, word);
  ff('-f', 'lavfi', '-i', `flite=textfile=${tf}:voice=${voice}`, '-ar', String(SR), '-ac', '1', wf);
  const x = pcm(wf); fs.rmSync(tf); fs.rmSync(wf); return x;
}
/** a 64x80 skin-toned face (RGBA PNG) */
function facePng(file) {
  const W = 220, Hh = 280, raw = Buffer.alloc(W * Hh * 4);
  for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) {
    const i = (y * W + x) * 4, dx = (x - W / 2) / (W / 2), dy = (y - Hh / 2) / (Hh / 2), inside = dx * dx + dy * dy <= 1;
    let r = 224, g = 168, b = 132, a = inside ? 255 : 0;
    const eye = (ex, ey) => (x - ex) ** 2 + (y - ey) ** 2 < 14 ** 2;
    if (inside && (eye(W * 0.33, Hh * 0.42) || eye(W * 0.67, Hh * 0.42))) { r = 40; g = 30; b = 30; }
    if (inside && Math.abs(y - Hh * 0.7) < 6 && Math.abs(x - W / 2) < W * 0.2) { r = 150; g = 60; b = 70; }
    raw[i] = r; raw[i + 1] = g; raw[i + 2] = b; raw[i + 3] = a;
  }
  const rf = file + '.rgba'; fs.writeFileSync(rf, raw);
  ff('-f', 'rawvideo', '-pix_fmt', 'rgba', '-s', `${W}x${Hh}`, '-i', rf, file); fs.rmSync(rf);
}
/** background (dark navy/teal, slow motion, a texture so frames are sharp) + the moving face + the voice */
function video(file, wavFile, dur, { w, h, fx, fy, black = 0, still = null, scale = 1 }) {
  const face = path.join(OUT, 'face.png'); if (!fs.existsSync(face)) facePng(face);
  const stillExpr = still ? `*(1-between(t,${still[0]},${still[1]}))` : '';
  const fw = Math.round(220 * scale), fh = Math.round(280 * scale);
  const flt = `[0:v]drawgrid=w=${Math.round(w / 9)}:h=${Math.round(w / 9)}:t=2:c=white@0.10[bg];[1:v]scale=${fw}:${fh}[f];` +
    `[bg][f]overlay=x='${fx}*W-w/2+${Math.round(w * 0.05)}*sin(t*1.1)${stillExpr}':y='${fy}*H-h/2+${Math.round(h * 0.015)}*cos(t*0.9)${stillExpr}':format=auto` +
    (black ? `,drawbox=x=0:y=0:w=iw:h=ih:color=black:t=fill:enable='lt(t,${black})'` : '') + '[v]';
  ff('-f', 'lavfi', '-i', `gradients=s=${w}x${h}:c0=0x0b2545:c1=0x13505e:c2=0x1d2a6b:nb_colors=3:speed=${still ? 0.004 : 0.012}:d=${dur.toFixed(3)}:r=30:seed=4`,
    '-loop', '1', '-i', face, '-i', wavFile, '-filter_complex', flt, '-map', '[v]', '-map', '2:a', '-t', dur.toFixed(3),
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-movflags', '+faststart', file);
}

// [word, gap after, loud]
const EN = [
  ['Stop', 0.26, 1], ['scrolling.', 0.45],
  ['Nobody', 0.04], ['tells', 0.04], ['you', 0.18], ['this', 0.04], ['about', 0.04], ['vitamin', 0.04], ['C', 0.04], ['serum.', 0.45],
  ['First,', 0.26], ['cleanse', 0.04], ['your', 0.04], ['skin', 0.18], ['gently.', 0.45],
  ['Second,', 0.26], ['apply', 0.04], ['three', 0.04], ['drops', 0.18], ['of', 0.04], ['serum.', 0.45],
  ['Finally,', 0.26], ['use', 0.04], ['sunscreen', 0.04], ['every', 0.18], ['single', 0.04], ['day.', 3.4],
  ['After', 0.04], ['seven', 0.18, 1], ['days', 0.04], ['my', 0.04], ['skin', 0.04], ['looks', 0.18], ['brighter.', 0.45, 1],
  ['This', 0.04], ['serum', 0.04], ['cures', 0.04], ['acne', 0.18], ['with', 0.04], ['no', 0.04], ['side', 0.04], ['effects.', 0.45],
  ['Comment', 0.18], ['GLOW', 0.04, 1], ['for', 0.04], ['the', 0.04], ['link.', 0.6],
];
const PC = [
  ['Want', 0.04], ['to', 0.04], ['study', 0.04], ['in', 0.04], ['Germany?', 0.45, 1],
  ['We', 0.04], ['offer', 0.04], ['a', 0.04], ['guaranteed', 0.04], ['visa', 0.18], ['for', 0.04], ['every', 0.04], ['student.', 0.45],
  ['First,', 0.26], ['prepare', 0.04], ['your', 0.04], ['passport.', 0.45],
  ['Second,', 0.26], ['book', 0.04], ['a', 0.04], ['free', 0.04, 1], ['consultation.', 0.45],
  ['Then', 0.18], ['we', 0.04], ['handle', 0.04], ['your', 0.04], ['application.', 0.6],
];
const AR = [['هذا', 0.04], ['السيروم', 0.18, 1], ['يعالج', 0.04], ['حب', 0.04], ['الشباب', 0.45], ['احجز', 0.18], ['استشارة', 0.04], ['مجانية', 0.26, 1], ['اليوم', 0.45],
  ['أولا', 0.26], ['نظفي', 0.04], ['بشرتك', 0.45], ['ثانيا', 0.26], ['ضعي', 0.04], ['السيروم', 0.6]];

async function arabicWords(list) {
  const repo = 'Xenova/mms-tts-ara', files = ['config.json', 'tokenizer.json', 'tokenizer_config.json', 'onnx/model.onnx'];
  for (const f of files) { const p = path.join(CACHE, repo, f); if (fs.existsSync(p) && fs.statSync(p).size > 0) continue; fs.mkdirSync(path.dirname(p), { recursive: true }); execFileSync('curl', ['-sSfL', '--retry', '3', '-o', p, `https://huggingface.co/${repo}/resolve/main/${f}`]); }
  const dir = H.makeStudio();
  fs.cpSync(path.join(CACHE, repo), path.join(dir, 'models', repo), { recursive: true });
  const e = await H.startEngine(dir), b = await H.launchChrome();
  try {
    const page = await b.newPage();
    await page.goto(e.url + '/app/captions/dev/index.html');
    await page.waitForFunction(() => window.R2);
    const arrs = await page.evaluate(async (ws) => { const out = []; for (const w of ws) out.push(Array.from(await R2.tts([w], 'ara', 0))); return out; }, list);
    return arrs.map((a) => new Float32Array(a));
  } finally { await b.close(); await H.stopEngine(e); fs.rmSync(dir, { recursive: true, force: true }); }
}

(async () => {
  const t0 = Date.now();
  const jobs = [
    { name: 'v-en-916', script: EN, voice: 'slt', lead: 0.7, opt: { w: 1080, h: 1920, fx: 0.5, fy: 0.42, black: 0.5, scale: 1.6 }, lang: 'en', ws: 'kbb' },
    { name: 'v-pc-169', script: PC, voice: 'rms', lead: 0.4, opt: { w: 1920, h: 1080, fx: 0.62, fy: 0.62, scale: 1.1 }, lang: 'en', ws: 'planet-careers' },
    { name: 'v-ar-916', script: AR, voice: null, lead: 0.4, opt: { w: 1080, h: 1920, fx: 0.5, fy: 0.45, black: 0.4, scale: 1.6 }, lang: 'ar', ws: 'kbb' },
  ];
  for (const j of jobs) {
    const mp4 = path.join(OUT, j.name + '.mp4'), meta = path.join(OUT, j.name + '.json');
    if (fs.existsSync(mp4) && fs.existsSync(meta)) { console.log(j.name, 'exists'); continue; }
    const audio = j.voice ? j.script.map(([w]) => flite(w.replace(/[^\w\s']/g, ' ').replace(/\bC\b/, 'see'), j.voice)) : await arabicWords(j.script.map(([w]) => w));
    const L = lay(j.script, audio, j.lead);
    const longGap = j.script.findIndex(([, g]) => g > 3);
    const still = longGap >= 0 ? [L.words[longGap].t1 + 0.2, L.words[longGap + 1].t0 - 0.2] : null;
    const wf = path.join(OUT, j.name + '.wav'); fs.writeFileSync(wf, wav(L.audio));
    video(mp4, wf, L.duration, { ...j.opt, still });
    fs.rmSync(wf);
    fs.writeFileSync(meta, JSON.stringify({ name: j.name, lang: j.lang, ws: j.ws, duration: L.duration, words: L.words, still, face: { x: j.opt.fx, y: j.opt.fy }, black: j.opt.black || 0 }, null, 1));
    console.log(j.name, L.duration.toFixed(2), 's', L.words.length, 'words', (fs.statSync(mp4).size / 1e6).toFixed(1), 'MB');
  }
  console.log(`done in ${((Date.now() - t0) / 1000).toFixed(0)} s`);
})().catch((e) => { console.error(e); process.exit(1); });
