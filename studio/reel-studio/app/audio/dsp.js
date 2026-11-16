// KBB Studio · audio · pure DSP (no DOM: runs in the audio worker, in the page and in Node for the checks).
// All signals are Float32Array, sample rate 48 kHz unless given. Every stage is offline and deterministic, so what is
// rendered once into the cleaned-voice file is exactly what the preview plays and the export mixes.
import { loudness } from '../auto/lib/audio.js';

export { loudness };
export const dbToGain = (d) => Math.pow(10, d / 20);
export const gainToDb = (g) => 20 * Math.log10(Math.max(1e-12, g));

// ── biquads (RBJ cookbook), transposed direct form II ──
export function biquad(type, f0, sr, { q = 0.7071, gain = 0 } = {}) {
  const A = Math.pow(10, gain / 40), w = (2 * Math.PI * f0) / sr, cs = Math.cos(w), sn = Math.sin(w), al = sn / (2 * q);
  let b0, b1, b2, a0, a1, a2;
  switch (type) {
    case 'hp': b0 = (1 + cs) / 2; b1 = -(1 + cs); b2 = (1 + cs) / 2; a0 = 1 + al; a1 = -2 * cs; a2 = 1 - al; break;
    case 'lp': b0 = (1 - cs) / 2; b1 = 1 - cs; b2 = (1 - cs) / 2; a0 = 1 + al; a1 = -2 * cs; a2 = 1 - al; break;
    case 'bp': b0 = al; b1 = 0; b2 = -al; a0 = 1 + al; a1 = -2 * cs; a2 = 1 - al; break;
    case 'peak': b0 = 1 + al * A; b1 = -2 * cs; b2 = 1 - al * A; a0 = 1 + al / A; a1 = -2 * cs; a2 = 1 - al / A; break;
    case 'notch': b0 = 1; b1 = -2 * cs; b2 = 1; a0 = 1 + al; a1 = -2 * cs; a2 = 1 - al; break;
    case 'lowshelf': { const s = 2 * Math.sqrt(A) * al; b0 = A * ((A + 1) - (A - 1) * cs + s); b1 = 2 * A * ((A - 1) - (A + 1) * cs); b2 = A * ((A + 1) - (A - 1) * cs - s); a0 = (A + 1) + (A - 1) * cs + s; a1 = -2 * ((A - 1) + (A + 1) * cs); a2 = (A + 1) + (A - 1) * cs - s; break; }
    case 'highshelf': { const s = 2 * Math.sqrt(A) * al; b0 = A * ((A + 1) + (A - 1) * cs + s); b1 = -2 * A * ((A - 1) + (A + 1) * cs); b2 = A * ((A + 1) + (A - 1) * cs - s); a0 = (A + 1) - (A - 1) * cs + s; a1 = 2 * ((A - 1) - (A + 1) * cs); a2 = (A + 1) - (A - 1) * cs - s; break; }
    default: throw new Error('biquad type ' + type);
  }
  return { b0: b0 / a0, b1: b1 / a0, b2: b2 / a0, a1: a1 / a0, a2: a2 / a0 };
}
export function filter(x, c, out = new Float32Array(x.length)) {
  let z1 = 0, z2 = 0;
  for (let i = 0; i < x.length; i++) { const v = x[i], y = c.b0 * v + z1; z1 = c.b1 * v - c.a1 * y + z2; z2 = c.b2 * v - c.a2 * y; out[i] = y; }
  return out;
}
export const filterChain = (x, list, sr) => list.reduce((y, [t, f, o]) => filter(y, biquad(t, f, sr, o)), x);

/** voice EQ presets: [type, freq, {q, gain}] */
export const EQ_PRESETS = {
  none: { label: 'No EQ', bands: [] },
  warm: { label: 'Voice · warm', bands: [['hp', 70, { q: 0.7 }], ['lowshelf', 200, { gain: 2.5 }], ['peak', 3000, { q: 1, gain: -1 }], ['highshelf', 10000, { gain: -1.5 }]] },
  clear: { label: 'Voice · clear', bands: [['hp', 90, { q: 0.7 }], ['peak', 250, { q: 1, gain: -2.5 }], ['peak', 3500, { q: 1, gain: 3 }], ['highshelf', 10000, { gain: 2 }]] },
  phone: { label: 'Phone-mic fix', bands: [['hp', 60, { q: 0.7 }], ['lowshelf', 180, { gain: 4 }], ['peak', 1000, { q: 0.8, gain: -2.5 }], ['peak', 4000, { q: 1, gain: 2 }], ['highshelf', 9000, { gain: 3 }]] },
  podcast: { label: 'Podcast', bands: [['hp', 80, { q: 0.7 }], ['lowshelf', 120, { gain: 3 }], ['peak', 400, { q: 1.1, gain: -2.5 }], ['peak', 5000, { q: 1, gain: 2.5 }], ['highshelf', 12000, { gain: 1.5 }]] },
};
export function eq(x, preset, sr = 48000) { const p = EQ_PRESETS[preset]; return p && p.bands.length ? filterChain(x, p.bands, sr) : x; }

/** mains hum: notches at 50/60 Hz (auto-picked) and 4 harmonics */
export function dehum(x, sr = 48000) {
  const e = (f) => { let s = 0; const y = filter(x, biquad('bp', f, sr, { q: 30 })); for (let i = 0; i < y.length; i += 4) s += y[i] * y[i]; return s; };
  const base = e(50) >= e(60) ? 50 : 60;
  let y = x;
  for (let k = 1; k <= 5; k++) y = filter(y, biquad('notch', base * k, sr, { q: 18 + 4 * k }));
  return { y, base };
}

/** envelope follower in dB with attack/release (s) */
function follower(x, sr, att, rel, rms = false) {
  const a = Math.exp(-1 / (att * sr)), r = Math.exp(-1 / (rel * sr)), env = new Float32Array(x.length);
  let e = 0;
  for (let i = 0; i < x.length; i++) { const v = rms ? x[i] * x[i] : Math.abs(x[i]); e = v > e ? a * e + (1 - a) * v : r * e + (1 - r) * v; env[i] = e; }
  if (rms) for (let i = 0; i < env.length; i++) env[i] = Math.sqrt(env[i]);
  return env;
}

/** de-esser: dynamic cut of 5 kHz+ when it is loud relative to the rest. amount 0..1 */
export function deess(x, sr = 48000, amount = 0.5) {
  if (amount <= 0) return x;
  const hi = filter(x, biquad('hp', 5200, sr, { q: 0.7 })), lo = new Float32Array(x.length);
  for (let i = 0; i < x.length; i++) lo[i] = x[i] - hi[i];
  const det = filter(x, biquad('bp', 7000, sr, { q: 1.2 }));
  const eS = follower(det, sr, 0.001, 0.06, true), eF = follower(x, sr, 0.005, 0.08, true);
  const out = new Float32Array(x.length), ratio = 1 + amount * 5, thr = -6 - 10 * (1 - amount);   // dB of sibilance over the full band
  let g = 1; const sm = Math.exp(-1 / (0.002 * sr));
  for (let i = 0; i < x.length; i++) {
    const over = gainToDb(eS[i] + 1e-9) - gainToDb(eF[i] + 1e-9) - thr;
    const target = over > 0 ? dbToGain(-over * (1 - 1 / ratio)) : 1;
    g = target < g ? target : sm * g + (1 - sm) * target;
    out[i] = lo[i] + hi[i] * g;
  }
  return out;
}

/** compressor (feed-forward, soft knee). returns new array */
export function compress(x, sr = 48000, { threshold = -24, ratio = 3, knee = 6, attack = 0.005, release = 0.12, makeup = 0 } = {}) {
  const env = follower(x, sr, attack, release), out = new Float32Array(x.length), mk = dbToGain(makeup);
  for (let i = 0; i < x.length; i++) {
    const l = gainToDb(env[i] + 1e-9), o = l - threshold;
    let gr = 0;
    if (2 * o > knee) gr = o * (1 - 1 / ratio);
    else if (2 * Math.abs(o) <= knee) gr = ((1 - 1 / ratio) * (o + knee / 2) ** 2) / (2 * knee);
    out[i] = x[i] * dbToGain(-gr) * mk;
  }
  return out;
}

/** levelling: slow gain riding toward targetDb RMS on speech, ignoring silence (gated), ±maxDb */
export function level(x, sr = 48000, { target = -20, maxDb = 12, gate = -50 } = {}) {
  const win = Math.round(0.4 * sr), hop = Math.round(0.05 * sr), n = Math.max(1, Math.ceil(x.length / hop));
  const gains = new Float32Array(n + 1);
  let cur = 0; let have = false;
  for (let k = 0; k < n; k++) {
    const c = k * hop, a = Math.max(0, c - win / 2), b = Math.min(x.length, c + win / 2);
    let s = 0; for (let i = a; i < b; i++) s += x[i] * x[i];
    const db = 10 * Math.log10(s / Math.max(1, b - a) + 1e-12);
    if (db > gate) { const want = Math.max(-maxDb, Math.min(maxDb, target - db)); cur = have ? cur + (want - cur) * (want < cur ? 0.35 : 0.12) : want; have = true; }
    gains[k] = cur;
  }
  // before the first speech: hold the first speech gain
  const first = gains.findIndex((g, i) => i > 0 && g !== 0); if (first > 0) for (let k = 0; k < first; k++) gains[k] = gains[first];
  gains[n] = gains[n - 1];
  const out = new Float32Array(x.length);
  for (let i = 0; i < x.length; i++) { const f = i / hop, k = Math.floor(f), u = f - k; out[i] = x[i] * dbToGain(gains[k] + (gains[Math.min(n, k + 1)] - gains[k]) * u); }
  return out;
}

// ── true peak (4x oversampled, windowed-sinc) ──
const OS = 4, TAPS = 12;
const PHASES = (() => {
  const ph = [];
  for (let p = 0; p < OS; p++) {
    const f = p / OS, k = [];
    for (let j = -TAPS + 1; j <= TAPS; j++) { const x = j - f, s = x === 0 ? 1 : Math.sin(Math.PI * x) / (Math.PI * x), w = 0.5 + 0.5 * Math.cos((Math.PI * x) / TAPS); k.push(Math.abs(x) >= TAPS ? 0 : s * w); }
    ph.push(k);
  }
  return ph;
})();
/** |x| upsampled 4x, per input sample: the largest inter-sample peak around it */
export function truePeakEnvelope(x) {
  const out = new Float32Array(x.length);
  for (let i = 0; i < x.length; i++) {
    let m = Math.abs(x[i]);
    for (let p = 1; p < OS; p++) {
      const k = PHASES[p]; let s = 0;
      for (let j = 0; j < k.length; j++) { const idx = i + (j - TAPS + 1); if (idx >= 0 && idx < x.length) s += x[idx] * k[j]; }
      const a = Math.abs(s); if (a > m) m = a;
    }
    out[i] = m;
  }
  return out;
}
export function truePeakDb(channels) {
  let m = 0;
  for (const c of channels) { const e = truePeakEnvelope(c); for (let i = 0; i < e.length; i++) if (e[i] > m) m = e[i]; }
  return gainToDb(m);
}

/** stereo-linked true-peak limiter with lookahead; ceiling in dBTP. channels modified in place */
export function limit(channels, sr = 48000, { ceiling = -1, lookahead = 0.005, release = 0.08 } = {}) {
  const n = channels[0].length, c = dbToGain(ceiling), la = Math.max(1, Math.round(lookahead * sr));
  const pk = new Float32Array(n);
  for (const ch of channels) { const e = truePeakEnvelope(ch); for (let i = 0; i < n; i++) if (e[i] > pk[i]) pk[i] = e[i]; }
  // required gain per sample, then a backward min over the lookahead window so the gain is down BEFORE the peak
  const need = new Float32Array(n);
  for (let i = 0; i < n; i++) need[i] = pk[i] > c ? c / pk[i] : 1;
  const minAhead = new Float32Array(n), dq = new Int32Array(n);   // sliding-window minimum over [i, i+la]
  for (let i = n - 1, hd = 0, tl = 0; i >= 0; i--) {
    while (tl > hd && need[dq[tl - 1]] >= need[i]) tl--;
    dq[tl++] = i;
    while (dq[hd] > i + la) hd++;
    minAhead[i] = need[dq[hd]];
  }
  // gain: never above what any sample in the lookahead needs; recovers smoothly (release)
  const rel = Math.exp(-1 / (release * sr));
  let g = 1;
  const gain = new Float32Array(n);
  for (let i = 0; i < n; i++) { const t = minAhead[i]; g = t < g ? t : rel * g + (1 - rel) * t; gain[i] = g; }
  for (const ch of channels) for (let i = 0; i < n; i++) ch[i] *= gain[i];
  return channels;
}

/** platform loudness: integrated LUFS to target, then true-peak ceiling. channels modified in place */
export function normalizeLoudness(channels, sr = 48000, { lufs = -14, tp = -1, margin = 0.3 } = {}) {
  const before = loudness(channels, sr).integrated;
  if (!(before > -69)) return { before, after: before, gainDb: 0, tpBefore: truePeakDb(channels), tpAfter: truePeakDb(channels) };
  const g = dbToGain(lufs - before);
  for (const ch of channels) for (let i = 0; i < ch.length; i++) ch[i] *= g;
  const tpBefore = truePeakDb(channels);
  if (tpBefore > tp - margin) limit(channels, sr, { ceiling: tp - margin });
  // the limiter takes a little loudness off: one corrective pass (gain up, limit again)
  let after = loudness(channels, sr).integrated;
  if (Math.abs(after - lufs) > 0.4) {
    const g2 = dbToGain(lufs - after);
    for (const ch of channels) for (let i = 0; i < ch.length; i++) ch[i] *= g2;
    if (truePeakDb(channels) > tp - margin) limit(channels, sr, { ceiling: tp - margin });
    after = loudness(channels, sr).integrated;
  }
  return { before, after, gainDb: lufs - before, tpBefore, tpAfter: truePeakDb(channels) };
}

/** noise floor estimate in dBFS: 10th percentile of 20 ms RMS frames */
export function noiseFloorDb(x, sr = 48000, p = 0.1) {
  const w = Math.round(0.02 * sr), v = [];
  for (let o = 0; o + w <= x.length; o += w) { let s = 0; for (let i = 0; i < w; i++) s += x[o + i] * x[o + i]; v.push(10 * Math.log10(s / w + 1e-12)); }
  v.sort((a, b) => a - b);
  return v.length ? v[Math.floor(p * (v.length - 1))] : -120;
}

// ── WSOLA time stretch (pitch kept). rate > 1 = faster (shorter) ──
export function stretch(x, rate, sr = 48000) {
  if (Math.abs(rate - 1) < 1e-3) return x.slice();
  const N = Math.round(0.04 * sr) & ~1, Hs = N / 2, tol = Math.round(0.01 * sr);
  const outLen = Math.max(1, Math.round(x.length / rate)), out = new Float32Array(outLen + N), norm = new Float32Array(outLen + N);
  const win = new Float32Array(N); for (let i = 0; i < N; i++) win[i] = 0.5 - 0.5 * Math.cos((2 * Math.PI * i) / N);
  let prev = 0;
  for (let o = 0, k = 0; o < outLen; o += Hs, k++) {
    const nominal = Math.round(o * rate);
    let best = nominal;
    if (k > 0) {
      // the frame whose start best continues the natural continuation of the previous frame
      const nat = prev + Hs; let bc = -Infinity;
      for (let d = -tol; d <= tol; d += 2) {
        const s = nominal + d; if (s < 0 || s + Hs >= x.length) continue;
        let c = 0; for (let i = 0; i < Hs; i += 4) c += (x[nat + i] || 0) * x[s + i];
        if (c > bc) { bc = c; best = s; }
      }
    }
    best = Math.max(0, Math.min(x.length - 1, best));
    for (let i = 0; i < N && o + i < out.length; i++) { const v = x[best + i] || 0; out[o + i] += v * win[i]; norm[o + i] += win[i]; }
    prev = best;
  }
  const y = new Float32Array(outLen);
  for (let i = 0; i < outLen; i++) y[i] = norm[i] > 1e-3 ? out[i] / norm[i] : 0;
  return y;
}

/** linear resample by a ratio (used for a muted-pitch "tape" speed change) */
export function resample(x, rate) {
  const n = Math.max(1, Math.round(x.length / rate)), y = new Float32Array(n);
  for (let i = 0; i < n; i++) { const f = i * rate, k = Math.floor(f), u = f - k; y[i] = (x[k] || 0) * (1 - u) + (x[k + 1] || 0) * u; }
  return y;
}

/** 16-bit PCM WAV (interleaved) from channels */
export function wav(channels, sr = 48000) {
  const nCh = channels.length, n = channels[0].length, buf = new ArrayBuffer(44 + n * nCh * 2), v = new DataView(buf);
  const s = (o, t) => { for (let i = 0; i < t.length; i++) v.setUint8(o + i, t.charCodeAt(i)); };
  s(0, 'RIFF'); v.setUint32(4, 36 + n * nCh * 2, true); s(8, 'WAVEfmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, nCh, true);
  v.setUint32(24, sr, true); v.setUint32(28, sr * nCh * 2, true); v.setUint16(32, nCh * 2, true); v.setUint16(34, 16, true); s(36, 'data'); v.setUint32(40, n * nCh * 2, true);
  let o = 44;
  for (let i = 0; i < n; i++) for (let c = 0; c < nCh; c++) { const x = Math.max(-1, Math.min(1, channels[c][i])); v.setInt16(o, Math.round(x * 32767), true); o += 2; }
  return buf;
}
