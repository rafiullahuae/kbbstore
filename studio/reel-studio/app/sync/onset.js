// KBB Studio · word-perfect sync — acoustic onsets (pure functions, no DOM, no model).
//
// The aligner says which 20 ms frame a word starts in; the sound itself says which 5 ms. Each word's start is
// snapped to the moment its energy rises out of the quiet before it (and its end to where it falls back), when
// there IS such a rise inside a small window — between two words said in one breath there is none, and the
// aligner's time stays. Words stay in order and never overlap.

export const HOP = 0.005;            // envelope resolution (s)

/** RMS envelope in dB, one value per 5 ms (10 ms window) of mono audio at `sr` Hz; plus spectral flux */
export function envelope(audio, sr = 16000) {
  const hop = Math.round(sr * HOP), win = hop * 2, n = Math.max(0, Math.floor((audio.length - win) / hop) + 1);
  const db = new Float32Array(n);
  for (let f = 0; f < n; f++) {
    let s = 0; const o = f * hop;
    for (let i = 0; i < win; i++) { const v = audio[o + i]; s += v * v; }
    db[f] = 10 * Math.log10(s / win + 1e-10);
  }
  // spectral flux: positive change of log energy in 4 bands (cheap one-pole splits), summed
  const flux = new Float32Array(n);
  const a1 = Math.exp(-2 * Math.PI * 300 / sr), a2 = Math.exp(-2 * Math.PI * 1200 / sr), a3 = Math.exp(-2 * Math.PI * 3500 / sr);
  let l1 = 0, l2 = 0, l3 = 0; const be = [0, 0, 0, 0], prev = [-100, -100, -100, -100];
  for (let f = 0; f < n; f++) {
    be[0] = be[1] = be[2] = be[3] = 0;
    for (let i = f * hop, e = i + hop; i < e && i < audio.length; i++) {
      const x = audio[i];
      l1 = a1 * l1 + (1 - a1) * x; l2 = a2 * l2 + (1 - a2) * x; l3 = a3 * l3 + (1 - a3) * x;
      const b0 = l1, b1 = l2 - l1, b2 = l3 - l2, b3 = x - l3;
      be[0] += b0 * b0; be[1] += b1 * b1; be[2] += b2 * b2; be[3] += b3 * b3;
    }
    let fl = 0;
    for (let k = 0; k < 4; k++) { const d = 10 * Math.log10(be[k] / hop + 1e-10); if (f && d > prev[k]) fl += d - prev[k]; prev[k] = d; }
    flux[f] = fl;
  }
  return { db, flux, hop: HOP, n };
}

/** the quietest moment between lo and hi seconds (lowest 30 ms average) */
export function quietestBetween(env, lo, hi) {
  const a = Math.max(0, Math.floor(lo / env.hop)), b = Math.min(env.n - 1, Math.ceil(hi / env.hop));
  if (b <= a) return (lo + hi) / 2;
  let best = a, bv = Infinity;
  for (let f = a; f <= b; f++) {
    let s = 0, k = 0;
    for (let j = f - 3; j <= f + 3; j++) if (j >= 0 && j < env.n) { s += env.db[j]; k++; }
    const v = s / k; if (v < bv) { bv = v; best = f; }
  }
  return best * env.hop + env.hop;   // centre of the 10 ms window
}

const at = (env, t) => Math.max(0, Math.min(env.n - 1, Math.round(t / env.hop)));
const peakIn = (env, t0, t1) => { let pk = -Infinity; for (let f = at(env, t0), z = at(env, t1); f <= z; f++) pk = Math.max(pk, env.db[f]); return pk; };
const floorIn = (env, t0, t1) => { let v = Infinity; for (let f = at(env, t0), z = at(env, t1); f <= z; f++) v = Math.min(v, env.db[f]); return v; };

/**
 * Where a word's sound starts near t. `peak` = the word's own loudness (dB). The threshold is max(quiet + 6, peak - 30)
 * with `quiet` the quietest frame in [t - reach, t + reach]; when that quiet is not at least `minRise` below the
 * peak the word runs on from the one before (no onset to find): null.
 *  - t in quiet (the aligner started the word in the pause before it): the first frame after t above the threshold;
 *  - t in sound (started late): the frame after the last quiet one before t.
 * Never further than `reach` from t.
 */
export function onsetNear(env, t, peak, { reach = 0.12, minRise = 15 } = {}) {
  const q = floorIn(env, t - reach, t + reach);
  if (!(peak - q >= minRise)) return null;
  const thr = Math.max(q + 6, peak - 30), f0 = at(env, t), R = Math.round(reach / env.hop);
  if (env.db[f0] < thr) {
    for (let f = f0; f <= Math.min(env.n - 1, f0 + R); f++) if (env.db[f] >= thr) return f * env.hop + env.hop / 2;
    return null;
  }
  for (let f = f0; f >= Math.max(0, f0 - R); f--) if (env.db[f] < thr) return (f + 1) * env.hop + env.hop / 2;
  return null;
}

/** Where a word's sound ends near t (same rule, mirrored); `limit` = the next word's start (never past it). */
export function offsetNear(env, t, peak, limit = Infinity, { reach = 0.12, minFall = 15 } = {}) {
  const hi = Math.min(t + reach * 2, limit);
  const q = floorIn(env, t - reach, Math.max(t, hi));
  if (!(peak - q >= minFall)) return null;
  const thr = Math.max(q + 6, peak - 30), f0 = at(env, t), R = Math.round(reach / env.hop), F = at(env, hi);
  if (env.db[f0] < thr) {
    for (let f = f0; f >= Math.max(0, f0 - R); f--) if (env.db[f] >= thr) return f * env.hop + env.hop / 2;
    return null;
  }
  for (let f = f0; f <= F; f++) if (env.db[f] < thr) return (f - 1) * env.hop + env.hop / 2;
  return null;
}

/** strongest flux peak within ±w of t (connected speech: a new syllable's attack), or null when flat */
export function fluxPeakNear(env, t, w = 0.03, min = 8) {
  const a = at(env, t - w), z = at(env, t + w);
  let best = null, bv = min;
  for (let f = a; f <= z; f++) if (env.flux[f] > bv) { bv = env.flux[f]; best = f; }
  return best == null ? null : best * env.hop;
}

/**
 * Snap aligned words to the sound. words: [{t0,t1}] (s on the audio's clock).
 * Returns new [{t0,t1,snapped}] — monotonic, no overlaps, every word at least 40 ms.
 */
export function snapWords(words, env, { maxShift = 0.12 } = {}) {
  const out = words.map((w) => ({ t0: w.t0, t1: w.t1, snapped: false }));
  for (let i = 0; i < out.length; i++) {
    const w = out[i], next = words[i + 1], prevEnd = i ? out[i - 1].t0 + 0.04 : 0;
    const peak = peakIn(env, w.t0, Math.max(w.t0 + 0.06, w.t1));
    let on = onsetNear(env, w.t0, peak);
    if (on == null) { const fp = fluxPeakNear(env, w.t0); if (fp != null) on = fp; }
    if (on != null && Math.abs(on - w.t0) <= maxShift && on > prevEnd && on < w.t1 - 0.03) { w.t0 = on; w.snapped = true; }
    const off = offsetNear(env, w.t1, peak, next ? next.t0 : Infinity);
    if (off != null && Math.abs(off - w.t1) <= maxShift * 2 && off > w.t0 + 0.04) w.t1 = off;
  }
  for (let i = 0; i < out.length; i++) {
    const w = out[i], p = out[i - 1];
    if (p && w.t0 < p.t0 + 0.04) w.t0 = p.t0 + 0.04;
    if (p && p.t1 > w.t0) p.t1 = w.t0;
    if (w.t1 < w.t0 + 0.04) w.t1 = w.t0 + 0.04;
  }
  return out;
}
