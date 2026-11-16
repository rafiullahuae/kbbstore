// KBB Studio · automation · audio analysis (pure ESM: runs in a Worker, in the page, or in Node).
// Inputs are mono Float32Array PCM + sample rate. All times are seconds.

export function fft(re, im) { // in-place radix-2
  const n = re.length;
  for (let i = 1, j = 0; i < n; i++) {
    let bit = n >> 1; for (; j & bit; bit >>= 1) j ^= bit; j ^= bit;
    if (i < j) { let t = re[i]; re[i] = re[j]; re[j] = t; t = im[i]; im[i] = im[j]; im[j] = t; }
  }
  for (let len = 2; len <= n; len <<= 1) {
    const a = -2 * Math.PI / len, wr = Math.cos(a), wi = Math.sin(a);
    for (let i = 0; i < n; i += len) {
      let cr = 1, ci = 0;
      for (let k = 0; k < len / 2; k++) {
        const ur = re[i + k], ui = im[i + k], j = i + k + len / 2;
        const vr = re[j] * cr - im[j] * ci, vi = re[j] * ci + im[j] * cr;
        re[i + k] = ur + vr; im[i + k] = ui + vi; re[j] = ur - vr; im[j] = ui - vi;
        const nr = cr * wr - ci * wi; ci = cr * wi + ci * wr; cr = nr;
      }
    }
  }
}

const dB = v => 10 * Math.log10(v + 1e-12);
function percentile(arr, p) { const a = Array.from(arr).sort((x, y) => x - y); return a.length ? a[Math.min(a.length - 1, Math.max(0, Math.floor(p * (a.length - 1))))] : 0; }

/** Short-term energy in dBFS per frame. */
export function energyCurve(x, sr, hopS = 0.01, winS = 0.025) {
  const hop = Math.max(1, Math.round(hopS * sr)), win = Math.round(winS * sr), n = Math.max(0, Math.floor((x.length - win) / hop) + 1);
  const db = new Float32Array(n);
  for (let f = 0; f < n; f++) { let s = 0; const o = f * hop; for (let i = 0; i < win; i++) s += x[o + i] * x[o + i]; db[f] = dB(s / win); }
  return { hop: hop / sr, db };
}

// ---- ITU-R BS.1770 K-weighting + gated integrated loudness ----
function kCoeffs(sr) { // pre-filter (shelf) + RLB highpass, bilinear from the 48 kHz analog design
  const f0 = 1681.974450955533, G = 3.999843853973347, Q = 0.7071752369554196;
  const K = Math.tan(Math.PI * f0 / sr), Vh = Math.pow(10, G / 20), Vb = Math.pow(Vh, 0.4996667741545416);
  const a0 = 1 + K / Q + K * K;
  const s = { b: [(Vh + Vb * K / Q + K * K) / a0, 2 * (K * K - Vh) / a0, (Vh - Vb * K / Q + K * K) / a0], a: [2 * (K * K - 1) / a0, (1 - K / Q + K * K) / a0] };
  const f1 = 38.13547087602444, Q1 = 0.5003270373238773, K1 = Math.tan(Math.PI * f1 / sr), a01 = 1 + K1 / Q1 + K1 * K1;
  const h = { b: [1, -2, 1], a: [2 * (K1 * K1 - 1) / a01, (1 - K1 / Q1 + K1 * K1) / a01] };
  return [s, h];
}
function kWeight(x, sr) {
  let y = x;
  for (const c of kCoeffs(sr)) {
    const o = new Float32Array(y.length); let x1 = 0, x2 = 0, y1 = 0, y2 = 0;
    for (let i = 0; i < y.length; i++) { const v = c.b[0] * y[i] + c.b[1] * x1 + c.b[2] * x2 - c.a[0] * y1 - c.a[1] * y2; x2 = x1; x1 = y[i]; y2 = y1; y1 = v; o[i] = v; }
    y = o;
  }
  return y;
}
/** Integrated loudness (LUFS) of 1+ channels; also returns momentary (400 ms, 100 ms hop) curve. */
export function loudness(channels, sr) {
  const ks = channels.map(c => kWeight(c, sr)), blk = Math.round(0.4 * sr), hop = Math.round(0.1 * sr);
  const ms = [];
  for (let o = 0; o + blk <= ks[0].length; o += hop) { let s = 0; for (const k of ks) { for (let i = 0; i < blk; i++) s += k[o + i] * k[o + i]; } ms.push(s / blk); }
  const L = m => -0.691 + 10 * Math.log10(m + 1e-15);
  const abs = ms.filter(m => L(m) > -70);
  if (!abs.length) return { integrated: -70, momentary: ms.map(L) };
  const rel = L(abs.reduce((a, b) => a + b, 0) / abs.length) - 10;
  const g = abs.filter(m => L(m) > rel);
  return { integrated: L(g.reduce((a, b) => a + b, 0) / g.length), momentary: ms.map(L) };
}

/** Voice activity: speech + silence segments, adaptive to the clip's own noise floor. */
export function vad(x, sr, opt = {}) {
  const { minSilence = 0.35, minSpeech = 0.08, bridge = 0.18 } = opt;
  const { hop, db } = energyCurve(x, sr, 0.01, 0.02);
  const floor = percentile(db, 0.1), peak = percentile(db, 0.97);
  const thr = Math.max(floor + Math.max(6, 0.3 * (peak - floor)), opt.absFloorDb ?? -55);
  const on = new Uint8Array(db.length); let st = 0;
  for (let i = 0; i < db.length; i++) { if (!st && db[i] > thr) st = 1; else if (st && db[i] < thr - 3) st = 0; on[i] = st; }
  let runs = []; let s0 = -1;
  for (let i = 0; i <= on.length; i++) { const v = i < on.length ? on[i] : 0; if (v && s0 < 0) s0 = i; if (!v && s0 >= 0) { runs.push([s0 * hop, i * hop + 0.02]); s0 = -1; } }
  const merged = [];
  for (const r of runs) { const l = merged[merged.length - 1]; if (l && r[0] - l[1] < bridge) l[1] = r[1]; else merged.push(r.slice()); }
  const speech = merged.filter(r => r[1] - r[0] >= minSpeech);
  const dur = x.length / sr, silences = []; let t = 0;
  for (const r of speech) { if (r[0] - t >= minSilence) silences.push([t, r[0]]); t = r[1]; }
  if (dur - t >= minSilence) silences.push([t, dur]);
  return { speech, silences, floorDb: floor, thrDb: thr, hop, db };
}

// ---- fillers ----
export function normWord(w) {
  return String(w || '').toLowerCase()
    .replace(/[ً-ْٰـ]/g, '') // Arabic diacritics + tatweel
    .replace(/[إأآا]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
    .replace(/[^\p{L}\p{N}']+/gu, '');
}
const HARD = new Set(['um', 'umm', 'ummm', 'uh', 'uhh', 'uhm', 'er', 'erm', 'err', 'ah', 'ahh', 'hmm', 'hm', 'mm', 'mmm', 'eh',
  'امم', 'اممم', 'ام', 'اه', 'ااه', 'اا', 'ااا', 'ممم', 'مم', 'همم', 'هممم', 'ءء', 'اهه']);
const SOFT = new Set(['like', 'basically', 'actually', 'literally', 'so', 'well', 'okay', 'ok', 'right', 'anyway',
  'يعني', 'طيب', 'اوكي', 'والله', 'خلاص', 'ايه', 'matlab', 'मतलब', 'مطلب']);
const SOFT2 = [['you', 'know'], ['i', 'mean'], ['kind', 'of'], ['sort', 'of'], ['you', 'see']];
const STOP_REPEAT = new Set(['very', 'really', 'so', 'no', 'bye', 'ha', 'la', 'لا', 'جدا']);
/** Filler words from word timestamps. kind: 'hard' (cut by default) | 'soft' (listed, unchecked) | 'repeat'. */
export function fillers(words = []) {
  const out = [], n = words.map(w => normWord(w.w));
  for (let i = 0; i < words.length; i++) {
    const w = n[i]; if (!w) continue;
    if (HARD.has(w)) { out.push({ i, j: i, kind: 'hard', text: words[i].w }); continue; }
    const two = SOFT2.find(p => p[0] === w && n[i + 1] === p[1]);
    if (two) { out.push({ i, j: i + 1, kind: 'soft', text: words[i].w + ' ' + words[i + 1].w }); i++; continue; }
    if (SOFT.has(w)) { out.push({ i, j: i, kind: 'soft', text: words[i].w }); continue; }
    if (i > 0 && w === n[i - 1] && !STOP_REPEAT.has(w) && words[i].t0 - words[i - 1].t1 < 0.6) out.push({ i: i - 1, j: i - 1, kind: 'repeat', text: words[i - 1].w });
  }
  return out.map(f => ({ ...f, t0: words[f.i].t0, t1: words[f.j].t1 }));
}

// ---- onsets, tempo, beats ----
/** Spectral-flux onset envelope (+ low band flux for downbeats). */
export function onsetEnvelope(x, sr) {
  let N = 1; while (N < 0.046 * sr) N <<= 1;
  const hop = N >> 2, frames = Math.max(0, Math.floor((x.length - N) / hop) + 1);
  const win = new Float32Array(N); for (let i = 0; i < N; i++) win[i] = 0.5 - 0.5 * Math.cos(2 * Math.PI * i / N);
  const maxBin = Math.min(N / 2, Math.round(8000 / sr * N)), lowBin = Math.max(2, Math.round(160 / sr * N));
  const env = new Float32Array(frames), low = new Float32Array(frames);
  let prev = new Float32Array(maxBin); const re = new Float32Array(N), im = new Float32Array(N);
  for (let f = 0; f < frames; f++) {
    const o = f * hop; for (let i = 0; i < N; i++) { re[i] = x[o + i] * win[i]; im[i] = 0; }
    fft(re, im);
    let s = 0, sl = 0; const cur = new Float32Array(maxBin);
    for (let k = 1; k < maxBin; k++) { const m = Math.log1p(100 * Math.hypot(re[k], im[k])); cur[k] = m; const d = m - prev[k]; if (d > 0) { s += d; if (k < lowBin) sl += d; } }
    env[f] = s; low[f] = sl; prev = cur;
  }
  const hopS = hop / sr, w = Math.max(1, Math.round(0.25 / hopS));
  const norm = a => { const o = new Float32Array(a.length); let acc = 0; for (let i = 0; i < a.length; i++) { acc += a[i]; if (i >= w) acc -= a[i - w]; o[i] = Math.max(0, a[i] - acc / Math.min(i + 1, w)); } let sd = 0; for (const v of o) sd += v * v; sd = Math.sqrt(sd / (o.length || 1)) || 1; for (let i = 0; i < o.length; i++) o[i] /= sd; return o; };
  return { hop: hopS, env: norm(env), low: norm(low), offset: N / 2 / sr };
}
export function estimateTempo(env, hopS, lo = 60, hi = 190) {
  const maxLag = Math.ceil(60 / lo / hopS) * 2 + 2, ac = new Float32Array(maxLag + 1);
  for (let l = 1; l <= maxLag; l++) { let s = 0; for (let i = l; i < env.length; i++) s += env[i] * env[i - l]; ac[l] = s / (env.length - l); }
  let best = 0, bestL = 0;
  for (let l = Math.floor(60 / hi / hopS); l <= Math.ceil(60 / lo / hopS); l++) {
    const bpm = 60 / (l * hopS), prior = Math.exp(-0.5 * Math.pow(Math.log2(bpm / 112) / 0.9, 2));
    const sc = (ac[l] + 0.5 * (ac[2 * l] || 0) + 0.25 * (ac[Math.round(l / 2)] || 0)) * prior;
    if (sc > best) { best = sc; bestL = l; }
  }
  const y0 = ac[bestL - 1] || 0, y1 = ac[bestL], y2 = ac[bestL + 1] || 0, den = y0 - 2 * y1 + y2;
  const lag = bestL + (den ? 0.5 * (y0 - y2) / den : 0);
  return { bpm: 60 / (lag * hopS), period: lag, strength: best };
}
/** Beat grid: tempo + dynamic-programming beat tracker (Ellis 2007) + downbeat phase. */
export function beatGrid(x, sr, opt = {}) {
  const { hop, env, low, offset } = onsetEnvelope(x, sr);
  const tmp = estimateTempo(env, hop, opt.minBpm || 60, opt.maxBpm || 190);
  const P = tmp.period, alpha = opt.tightness || 120, n = env.length;
  const C = new Float32Array(n), B = new Int32Array(n).fill(-1);
  for (let t = 0; t < n; t++) {
    let best = 0, bi = -1;
    for (let tau = Math.max(0, Math.floor(t - 2 * P)); tau <= t - Math.round(P / 2); tau++) {
      const v = C[tau] - alpha * Math.pow(Math.log((t - tau) / P), 2);
      if (bi < 0 || v > best) { best = v; bi = tau; }
    }
    C[t] = env[t] + (bi >= 0 ? Math.max(0, best) : 0); B[t] = bi >= 0 && best > 0 ? bi : -1;
  }
  let end = n - 1, bv = -Infinity;
  for (let t = Math.max(0, Math.floor(n - P)); t < n; t++) if (C[t] > bv) { bv = C[t]; end = t; }
  const idx = []; for (let t = end; t >= 0; t = B[t]) { idx.push(t); if (B[t] < 0) break; }
  idx.reverse();
  const beats = idx.map(i => {
    const y0 = env[i - 1] || 0, y1 = env[i], y2 = env[i + 1] || 0, d = y0 - 2 * y1 + y2;
    return +(Math.max(0, (i + (d < 0 ? 0.5 * (y0 - y2) / d : 0)) * hop + offset + 0.005)).toFixed(4);
  });
  let ph = 0, pv = -1;
  for (let k = 0; k < 4; k++) { let s = 0; for (let b = k; b < idx.length; b += 4) s += low[idx[b]] + 0.3 * env[idx[b]]; if (s > pv) { pv = s; ph = k; } }
  const bpm = beats.length > 2 ? 60 * (beats.length - 1) / (beats[beats.length - 1] - beats[0]) : tmp.bpm;
  return { bpm: +bpm.toFixed(2), beats, downbeats: beats.filter((_, i) => i % 4 === ph), beatsPerBar: 4, confidence: +Math.min(1, tmp.strength / 2).toFixed(3) };
}

/** Everything the automation needs about a voice/video soundtrack, in one pass. */
export function analyseSpeechTrack(x, sr, words) {
  const v = vad(x, sr);
  const lo = loudness([x], sr);
  return { duration: x.length / sr, speech: v.speech, silences: v.silences, floorDb: v.floorDb, thrDb: v.thrDb,
    energy: { hop: v.hop, db: v.db }, lufs: lo.integrated, fillers: words ? fillers(words) : [] };
}
