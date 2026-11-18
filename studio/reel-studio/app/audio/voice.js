// KBB Studio · audio · "✨ Enhance voice": the voice chain, each stage switchable (pure DSP: runs in the audio worker
// and in Node for tools/enhance-check.cjs). 48 kHz mono in, 48 kHz mono out, same length, no delay.
//
//   enhanceVoice(x, opt, rn) -> {y, stats}
//   opt = { strength 0..1 (light → strong), stages: {noise, hum, reverb, eq, deess, compress, loud}, eq:'clear',
//           deess 0..1, reverb 0..1, lufs -14, tp -1 }
//
// Stages, in order: hum (50/60 Hz + harmonics, only when it is there) → noise (RNNoise; strength sets how far the noise
// goes down: ~14 dB light, ~23 dB medium, full + a voice gate when strong) → room echo (late-reverb suppression,
// amount sets the room size it expects) → voice EQ (low cut + presence) → de-esser → compressor → loudness
// (-14 LUFS integrated, true peak ≤ -1 dBTP, 4× oversampled limiter).
import * as D from './dsp.js';
import { fft } from '../auto/lib/audio.js';

export const STAGES = [
  ['noise', 'Background noise', 'RNNoise (neural, offline): fans, traffic, wind, rooms'],
  ['hum', 'Hum', 'Mains hum at 50 or 60 Hz and its harmonics, found automatically'],
  ['reverb', 'Room echo', 'Takes the room’s echo tail down'],
  ['eq', 'Voice EQ', 'Low cut, less mud, more presence'],
  ['deess', 'De-ess', 'Softens sharp S sounds'],
  ['compress', 'Compressor', 'Evens out loud and quiet words'],
  ['loud', 'Loudness −14 LUFS', 'Social-media loudness with a true-peak limiter at −1 dBTP'],
];
const DUAL_MONO = 10 * Math.log10(2);
export const DEFAULT_VOICE = { v: 2, strength: 0.6, stages: { noise: true, hum: true, reverb: true, eq: true, deess: true, compress: true, loud: true }, eq: 'clear', deess: 0.5, reverb: 0.4, lufs: -14, tp: -1 };
export const strengthLabel = (s) => (s <= 0 ? 'Off' : s < 0.34 ? 'Light' : s < 0.67 ? 'Medium' : 'Strong');
/** how far the noise goes down at a strength (dB; Infinity = all RNNoise takes out) */
export const strengthDb = (s) => (s >= 0.999 ? Infinity : 6 + 34 * s);

// ── hum ──
function bandEnergy(x, f, sr, step = 2) { const y = D.filter(x, D.biquad('bp', f, sr, { q: 40 })); let s = 0; for (let i = 0; i < y.length; i += step) s += y[i] * y[i]; return s; }
/** mains hum: {base: 50|60, db: how far the hum stands above its neighbourhood} or null when there is none */
export function detectHum(x, sr = 48000) {
  // a few seconds is plenty and keeps it quick
  const seg = x.length > sr * 8 ? x.subarray(Math.floor(x.length / 2 - sr * 4), Math.floor(x.length / 2 + sr * 4)) : x;
  let best = null;
  for (const base of [50, 60]) {
    // the fundamental and the 2nd/3rd harmonic, each against its own neighbourhood (speech is broad there, hum is a line)
    let db = -Infinity;
    for (const k of [1, 2, 3]) {
      const on = bandEnergy(seg, base * k, sr), off = (bandEnergy(seg, base * k - base * 0.3, sr) + bandEnergy(seg, base * k + base * 0.3, sr)) / 2;
      db = Math.max(db, 10 * Math.log10((on + 1e-12) / (off + 1e-12)));
    }
    if (!best || db > best.db) best = { base, db };
  }
  return best && best.db > 9 ? { base: best.base, db: +best.db.toFixed(1) } : null;
}
/** zero-phase notches (forwards, then backwards): the hum lines go, the voice's waveform keeps its shape */
export function removeHum(x, base, sr = 48000, harmonics = 6) {
  let y = x;
  for (let k = 1; k <= harmonics; k++) { const c = D.biquad('notch', base * k, sr, { q: 14 * k + 14 }); y = D.filter(y, c); y.reverse(); y = D.filter(y, c); y.reverse(); }
  return y;
}

// ── voice gate (strong noise removal): RNNoise's own voice-activity says where the speech is ──
function voiceGate(y, vad, floorDb, sr = 48000) {
  const F = 480, n = y.length, fl = D.dbToGain(floorDb), out = new Float32Array(n);
  // per frame target: 1 in speech (with 80 ms hold), floor elsewhere
  const tg = new Float32Array(vad.length); let hold = 0;
  for (let f = 0; f < vad.length; f++) { if (vad[f] > 0.4) hold = 8; tg[f] = hold > 0 ? 1 : fl; hold--; }
  const att = Math.exp(-1 / (0.004 * sr)), rel = Math.exp(-1 / (0.06 * sr)); let g = 1;
  for (let i = 0; i < n; i++) { const t = tg[Math.min(tg.length - 1, Math.floor(i / F) + 1)] ?? 1; g = t > g ? att * g + (1 - att) * t : rel * g + (1 - rel) * t; out[i] = y[i] * g; }
  return out;
}

// ── room echo: late-reverb spectral suppression (Lebart et al.): the echo arriving 50 ms+ after the sound, predicted
// from the past spectrum and the room's decay, is taken out per frequency; the direct voice stays ──
const N = 1024, HOP = 256;
const WIN = (() => { const w = new Float32Array(N); for (let i = 0; i < N; i++) w[i] = Math.sqrt(0.5 - 0.5 * Math.cos((2 * Math.PI * i) / N)); return w; })();
export function dereverb(x, sr = 48000, amount = 0.5, rt60 = null) {
  // the decay the suppressor assumes: from the amount (a blind estimate from speech alone is not reliable enough to
  // steer it: decaying vowels look like a room), 0.35 s (small room) to 0.85 s (big hall)
  const T = rt60 ?? 0.35 + 0.5 * amount;
  if (amount <= 0) return { y: x, rt60: T };
  const n = x.length, frames = Math.ceil((n + N) / HOP) + 1, out = new Float32Array(n + 2 * N), norm = new Float32Array(n + 2 * N);
  const delta = (3 * Math.log(10)) / T, Nd = Math.max(2, Math.round((0.05 * sr) / HOP)), decay = Math.exp(-2 * delta * Nd * HOP / sr);
  const gmin = D.dbToGain(-(6 + 12 * amount)), beta = 1 + 1.5 * amount, K = N / 2 + 1;
  const hist = []; const Ps = new Float32Array(K), G = new Float32Array(K).fill(1);
  const re = new Float64Array(N), im = new Float64Array(N);
  for (let f = 0; f < frames; f++) {
    const o = f * HOP - N;
    for (let i = 0; i < N; i++) { const k = o + i; re[i] = (k >= 0 && k < n ? x[k] : 0) * WIN[i]; im[i] = 0; }
    fft(re, im);
    const P = new Float32Array(K);
    for (let k = 0; k < K; k++) { P[k] = re[k] * re[k] + im[k] * im[k]; Ps[k] = 0.55 * Ps[k] + 0.45 * P[k]; }
    hist.push(Ps.slice()); if (hist.length > Nd + 1) hist.shift();
    const old = hist.length > Nd ? hist[0] : null;
    for (let k = 0; k < K; k++) {
      const late = old ? decay * old[k] : 0, g = Math.max(gmin, 1 - beta * late / (Ps[k] + 1e-12));
      G[k] = g < G[k] ? 0.6 * G[k] + 0.4 * g : 0.3 * G[k] + 0.7 * g;   // fast down, quicker up: no smearing
      re[k] *= G[k]; im[k] *= G[k];
      if (k > 0 && k < N / 2) { re[N - k] = re[k]; im[N - k] = -im[k]; }
    }
    // inverse via the forward FFT of the conjugate
    for (let i = 0; i < N; i++) im[i] = -im[i];
    fft(re, im);
    for (let i = 0; i < N; i++) { const k = o + i + N; out[k] += (re[i] / N) * WIN[i]; norm[k] += WIN[i] * WIN[i]; }
  }
  const y = new Float32Array(n);
  for (let i = 0; i < n; i++) y[i] = norm[i + N] > 1e-6 ? out[i + N] / norm[i + N] : 0;
  return { y, rt60: +T.toFixed(2) };
}

/** the whole chain; rn = an RNNoise instance (rnnoise.js) when the noise stage is on */
export function enhanceVoice(x, opt = {}, rn = null) {
  const o = Object.assign({}, DEFAULT_VOICE, opt), S = Object.assign({}, DEFAULT_VOICE.stages, opt.stages || {}), sr = 48000;
  const stats = { floorBefore: D.noiseFloorDb(x, sr), stages: [] };
  let y = x;
  if (S.hum) { const h = detectHum(y, sr); stats.hum = h ? h.base : null; stats.humDb = h ? h.db : 0; if (h) { y = removeHum(y, h.base, sr); stats.stages.push('hum'); } }
  if (S.noise && o.strength > 0 && rn) {
    const r = rn.denoise(y, 1), dB = strengthDb(o.strength);
    if (Number.isFinite(dB)) { const dry = D.dbToGain(-dB), w = 1 - dry, z = r.y; for (let i = 0; i < z.length; i++) z[i] = w * z[i] + dry * y[i]; y = z; }
    else y = r.y;
    if (o.strength > 0.67) y = voiceGate(y, r.vad, -6 - 30 * Math.min(1, (o.strength - 0.67) / 0.33), sr);
    stats.stages.push('noise');
  }
  if (S.reverb && o.reverb > 0) { const r = dereverb(y, sr, o.reverb); stats.rt60 = r.rt60; if (r.y !== y) { y = r.y; stats.stages.push('reverb'); } }
  if (S.eq && o.eq && o.eq !== 'none') { y = D.eq(y, o.eq, sr); stats.stages.push('eq'); }
  if (S.deess && o.deess > 0) { y = D.deess(y, sr, o.deess); stats.stages.push('deess'); }
  if (S.compress) { y = D.level(y, sr, { target: -20 }); y = D.compress(y, sr, { threshold: -24, ratio: 3, makeup: 2 }); stats.stages.push('compress'); }
  if (S.loud) {
    // the voice is ONE channel played on both speakers: BS.1770 adds the channels' power, so a dual-mono reel measures
    // 3.01 LU louder than the mono file. The mono file is levelled to the target minus that, so the REEL hits it.
    const r = D.normalizeLoudness([y], sr, { lufs: o.lufs - DUAL_MONO, tp: o.tp, margin: 0.4 });
    stats.loud = { before: +(r.before + DUAL_MONO).toFixed(2), after: +(r.after + DUAL_MONO).toFixed(2), tp: +r.tpAfter.toFixed(2) }; stats.stages.push('loud');
  } else {
    let pk = 0; for (let i = 0; i < y.length; i++) { const a = Math.abs(y[i]); if (a > pk) pk = a; }
    if (pk > 0.89) { const g = 0.89 / pk; for (let i = 0; i < y.length; i++) y[i] *= g; }
  }
  stats.floorAfter = D.noiseFloorDb(y, sr);
  stats.lufs = D.loudness([y], sr).integrated + DUAL_MONO;   // as the reel plays it (both speakers)
  stats.tp = D.truePeakDb([y]);
  return { y, stats };
}

/** where someone speaks (source seconds): frames well above the clip's own floor, gaps under 0.3 s bridged */
export function speechRanges(x, sr = 16000) {
  const w = Math.round(0.02 * sr), n = Math.floor(x.length / w), e = new Float32Array(n);
  for (let k = 0; k < n; k++) { let s = 0; for (let i = 0; i < w; i++) s += x[k * w + i] ** 2; e[k] = 10 * Math.log10(s / w + 1e-12); }
  const sorted = Float32Array.from(e).sort(), floor = sorted[Math.floor(n * 0.1)] ?? -90, peak = sorted[Math.floor(n * 0.98)] ?? 0;
  const thr = Math.max(floor + 12, peak - 35), out = [];
  for (let k = 0; k < n; k++) if (e[k] > thr) { const t0 = (k * w) / sr, t1 = ((k + 1) * w) / sr, l = out[out.length - 1]; if (l && t0 - l[1] < 0.3) l[1] = t1; else out.push([t0, t1]); }
  return out.filter(([a, b]) => b - a >= 0.15).map(([a, b]) => [+a.toFixed(3), +b.toFixed(3)]);
}
