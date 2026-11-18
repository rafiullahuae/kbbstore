// KBB Studio — test signals and measurements for tools/enhance-check.cjs (pure Node + ffmpeg's flite voice).
//
//   speech()                         clean speech, 48 kHz mono, 0.6 s of silence either side (flite, voice slt)
//   NOISES[kind](n, seed)            fan/AC hum, street, crowd (babble), wind, keyboard, music bed — deterministic
//   mixAt(s, n, snrDb)               speech + noise scaled to an SNR measured over the speech
//   noiseCut(x, y, s)                dB the noise-only stretches dropped (x before, y after; s = the clean speech)
//   corr(a, b, s)                    correlation of a and b over the speech stretches (1 = the same waveform shape)
//   wav(x) / pcm(file)               16-bit WAV writer, any file -> Float32 mono 48 kHz (ffmpeg)
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const SR = 48000;
const OUT = path.join(__dirname, 'out', 'enhance');
fs.mkdirSync(OUT, { recursive: true });

function rng(seed) { let s = (seed >>> 0) || 1; return () => { s = (Math.imul(s, 1664525) + 1013904223) >>> 0; return s / 4294967296; }; }
const pcm = (file, sr = SR) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '1', '-ar', String(sr), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); };
function wav(x, sr = SR, nCh = 1) {
  const chs = Array.isArray(x) ? x : [x], n = chs[0].length; nCh = chs.length;
  const b = Buffer.alloc(44 + n * nCh * 2);
  b.write('RIFF', 0); b.writeUInt32LE(36 + n * nCh * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(nCh, 22);
  b.writeUInt32LE(sr, 24); b.writeUInt32LE(sr * nCh * 2, 28); b.writeUInt16LE(nCh * 2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(n * nCh * 2, 40);
  let o = 44; for (let i = 0; i < n; i++) for (let c = 0; c < nCh; c++) { b.writeInt16LE(Math.round(Math.max(-1, Math.min(1, chs[c][i])) * 32767), o); o += 2; }
  return b;
}
const TEXT = 'Hello and welcome back. Today I am showing you the new vitamin C serum from K Beauty Bliss. It feels so light on the skin, and the glow is amazing. Book your free consultation today.';
function flite(text, voice, file) {
  if (!fs.existsSync(file)) execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', `flite=text='${text}':voice=${voice}`, '-ar', String(SR), '-ac', '1', file]);
  return pcm(file);
}
function speech() {
  const v = flite(TEXT, 'slt', path.join(OUT, 'flite-slt.wav')), pad = Math.round(0.6 * SR), x = new Float32Array(v.length + 2 * pad);
  for (let i = 0; i < v.length; i++) x[pad + i] = v[i] * 0.5;
  return x;
}

// ── noises (all deterministic) ──
function pinkGen(r) { let b0 = 0, b1 = 0, b2 = 0, b3 = 0, b4 = 0, b5 = 0, b6 = 0; return () => { const w = r() * 2 - 1; b0 = 0.99886 * b0 + w * 0.0555179; b1 = 0.99332 * b1 + w * 0.0750759; b2 = 0.969 * b2 + w * 0.153852; b3 = 0.8665 * b3 + w * 0.3104856; b4 = 0.55 * b4 + w * 0.5329522; b5 = -0.7616 * b5 - w * 0.016898; const p = (b0 + b1 + b2 + b3 + b4 + b5 + b6 + w * 0.5362) * 0.11; b6 = w * 0.115926; return p; }; }
function onePole(fc) { const a = Math.exp(-2 * Math.PI * fc / SR); let y = 0; return (x) => (y = (1 - a) * x + a * y); }
const NOISES = {
  /** a fan / air-con: broadband whoosh (low-passed pink) + 50 Hz mains hum with harmonics + a blade tone */
  fan(n, seed = 1) {
    const r = rng(seed), p = pinkGen(r), lp = onePole(2500), x = new Float32Array(n);
    for (let i = 0; i < n; i++) { const t = i / SR; x[i] = lp(p()) * 1.4 + 0.25 * Math.sin(2 * Math.PI * 50 * t) + 0.12 * Math.sin(2 * Math.PI * 100 * t) + 0.09 * Math.sin(2 * Math.PI * 150 * t) + 0.05 * Math.sin(2 * Math.PI * 250 * t) + 0.04 * Math.sin(2 * Math.PI * 187 * t); }
    return x;
  },
  /** street: low rumble, passing cars (slow swells of brown-ish noise), a horn */
  street(n, seed = 2) {
    const r = rng(seed), p = pinkGen(r), lp = onePole(600), lp2 = onePole(4000), x = new Float32Array(n);
    for (let i = 0; i < n; i++) {
      const t = i / SR, car = 0.5 + 0.5 * Math.sin(2 * Math.PI * t / 5.3) ** 2 + 0.6 * Math.exp(-(((t % 7) - 3.5) ** 2) / 0.8);
      const horn = t > 6.2 && t < 6.7 ? 0.08 * Math.sign(Math.sin(2 * Math.PI * 415 * t)) : 0;
      x[i] = lp(r() * 2 - 1) * 6 * car + lp2(p()) * 0.5 + horn;
    }
    return x;
  },
  /** crowd: babble of three other voices (flite kal, awb, rms) + room murmur */
  crowd(n, seed = 3) {
    const vs = [['kal', 'The market is busy this morning and everybody wants a coffee before work starts.'], ['awb', 'Did you see the match last night, it was incredible, three goals in ten minutes.'], ['rms', 'Please take a number and wait for your turn at the counter, thank you.']];
    const x = new Float32Array(n), r = rng(seed), p = pinkGen(r);
    vs.forEach(([v, txt], k) => { const s = flite(txt, v, path.join(OUT, `flite-${v}.wav`)); const off = Math.round((k * 1.3) * SR); for (let i = 0; i < n; i++) x[i] += s[(i + off) % s.length] * 0.45; });
    for (let i = 0; i < n; i++) x[i] += p() * 0.05;
    return x;
  },
  /** wind: gusty low-frequency rumble on the mic */
  wind(n, seed = 4) {
    const r = rng(seed), lp = onePole(180), lp2 = onePole(900), g = onePole(0.7), x = new Float32Array(n);
    for (let i = 0; i < n; i++) { const gust = Math.max(0.15, g(r() * 2 - 1) * 30 + 0.6 + 0.4 * Math.sin(2 * Math.PI * i / SR / 3.1)); x[i] = (lp(r() * 2 - 1) * 9 + lp2(r() * 2 - 1) * 1.2) * gust; }
    return x;
  },
  /** keyboard: typing clicks, 6-9 a second, each a short noise burst with a resonant body */
  keyboard(n, seed = 5) {
    const r = rng(seed), x = new Float32Array(n);
    let t = 0.05;
    while (t < n / SR) {
      const at = Math.round(t * SR), f = 1800 + r() * 2500, amp = 0.5 + r() * 0.5, len = Math.round(0.025 * SR);
      for (let i = 0; i < len && at + i < n; i++) { const e = Math.exp(-i / (0.004 * SR)); x[at + i] += amp * e * ((r() * 2 - 1) * 0.7 + 0.5 * Math.sin(2 * Math.PI * f * i / SR)); }
      t += 0.1 + r() * 0.08 + (r() < 0.1 ? 0.4 : 0);
    }
    return x;
  },
  /** a music bed: chords (saw-ish pads), a bass line and a kick/hat groove at 100 bpm */
  music(n, seed = 6) {
    const r = rng(seed), x = new Float32Array(n), beat = 0.6, chords = [[57, 60, 64], [53, 57, 60], [48, 52, 55], [55, 59, 62]];
    const f = (m) => 440 * Math.pow(2, (m - 69) / 12), lp = onePole(1800);
    for (let i = 0; i < n; i++) {
      const t = i / SR, bar = Math.floor(t / (beat * 4)) % 4, ch = chords[bar], bt = t % beat;
      let pad = 0; for (const m of ch) { const ph = (t * f(m)) % 1; pad += (2 * ph - 1) * 0.12 + Math.sin(2 * Math.PI * f(m + 12) * t) * 0.04; }
      const bass = Math.sin(2 * Math.PI * f(ch[0] - 24) * t) * 0.35 * Math.exp(-bt * 3);
      const kick = Math.sin(2 * Math.PI * (50 + 120 * Math.exp(-bt * 30)) * bt) * Math.exp(-bt * 12) * 0.8;
      const hat = ((t + beat / 2) % beat) < 0.03 ? (r() * 2 - 1) * 0.15 * Math.exp(-(((t + beat / 2) % beat)) * 120) : 0;
      x[i] = lp(pad) + bass + kick + hat;
    }
    return x;
  },
};

/** frames (20 ms) where the clean speech is active / silent */
function activity(s) {
  const w = Math.round(0.02 * SR), n = Math.floor(s.length / w), act = new Uint8Array(n);
  for (let k = 0; k < n; k++) { let e = 0; for (let i = 0; i < w; i++) e += s[k * w + i] ** 2; act[k] = 10 * Math.log10(e / w + 1e-12) > -45 ? 1 : 0; }
  // grow the speech by 3 frames each side, so word tails never count as "noise"
  const g = act.slice(); for (let k = 0; k < n; k++) if (act[k]) for (let d = -3; d <= 3; d++) if (k + d >= 0 && k + d < n) g[k + d] = 1;
  return { w, act: g };
}
const energy = (x, w, k) => { let e = 0; for (let i = 0; i < w; i++) e += x[k * w + i] ** 2; return e; };
function mixAt(s, nz, snrDb) {
  const { w, act } = activity(s); let es = 0, en = 0;
  for (let k = 0; k < act.length; k++) if (act[k]) { es += energy(s, w, k); en += energy(nz, w, k); }
  const g = Math.sqrt(es / Math.max(1e-12, en) / Math.pow(10, snrDb / 10)), x = new Float32Array(s.length);
  for (let i = 0; i < s.length; i++) x[i] = s[i] + nz[i] * g;
  return x;
}
/** dB the noise-only stretches went down (positive = quieter) */
function noiseCut(x, y, s) {
  const { w, act } = activity(s); let ex = 0, ey = 0;
  for (let k = 0; k < act.length; k++) if (!act[k]) { ex += energy(x, w, k); ey += energy(y, w, k); }
  return 10 * Math.log10(ex / Math.max(1e-12, ey));
}
/** correlation over the speech stretches */
function corr(a, b, s) {
  const { w, act } = activity(s); let ab = 0, aa = 0, bb = 0;
  for (let k = 0; k < act.length; k++) if (act[k]) for (let i = k * w; i < (k + 1) * w; i++) { ab += a[i] * b[i]; aa += a[i] * a[i]; bb += b[i] * b[i]; }
  return ab / Math.sqrt(aa * bb + 1e-20);
}
/** level of a sine-like component at f (dB, Goertzel over the whole signal) */
function toneDb(x, f) {
  const w = 2 * Math.cos(2 * Math.PI * f / SR); let s1 = 0, s2 = 0;
  for (let i = 0; i < x.length; i++) { const s0 = x[i] + w * s1 - s2; s2 = s1; s1 = s0; }
  const p = s1 * s1 + s2 * s2 - w * s1 * s2; return 10 * Math.log10(p / (x.length * x.length) * 4 + 1e-20);
}

module.exports = { SR, OUT, rng, pcm, wav, speech, NOISES, mixAt, noiseCut, corr, toneDb, activity };
