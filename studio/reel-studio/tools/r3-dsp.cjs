'use strict';
// R3 offline DSP kit: oscillators, instruments, drums, filters, reverb, limiter.
// Deterministic (seeded PRNG only), no dependencies. Used by r3-music.cjs and r3-sfx.cjs.

const SR = 44100;
const TAU = Math.PI * 2;

function rng(seed) {
  let s = (seed >>> 0) || 0x9e3779b9;
  return () => { s ^= s << 13; s >>>= 0; s ^= s >>> 17; s ^= s << 5; s >>>= 0; return s / 4294967296; };
}
function hash(str) { let h = 2166136261; for (let i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
const mtof = m => 440 * Math.pow(2, (m - 69) / 12);
const dbToGain = d => Math.pow(10, d / 20);

// ---------- band-limited wavetables ----------
const TBL = 4096;
const tcache = new Map();
const HB = [1, 2, 3, 4, 6, 8, 12, 16, 24, 32, 48, 64, 96];
function table(type, f) {
  const maxH = Math.max(1, Math.floor(SR * 0.45 / f));
  let h = 1; for (const b of HB) if (b <= maxH) h = b;
  const key = type + h;
  let t = tcache.get(key);
  if (t) return t;
  t = new Float32Array(TBL + 1);
  for (let k = 1; k <= h; k++) {
    let a = 0;
    if (type === 'saw') a = 1 / k;
    else if (type === 'square') a = k % 2 ? 1 / k : 0;
    else if (type === 'tri') a = k % 2 ? ((((k - 1) / 2) % 2) ? -1 : 1) / (k * k) : 0;
    else if (type === 'organ') a = [1, .5, .3, .2, 0, .1, 0, .08][k - 1] || 0;
    if (!a) continue;
    for (let i = 0; i < TBL; i++) t[i] += a * Math.sin(TAU * k * i / TBL);
  }
  let m = 0; for (let i = 0; i < TBL; i++) m = Math.max(m, Math.abs(t[i]));
  for (let i = 0; i < TBL; i++) t[i] /= m || 1;
  t[TBL] = t[0];
  tcache.set(key, t);
  return t;
}
// render an oscillator into a new array; vib = [rateHz, depthSemis, delayS]
function osc(type, f, n, opt = {}) {
  const out = new Float32Array(n);
  const t = type === 'sine' ? null : table(type, f * (opt.bend ? Math.max(1, opt.bend) : 1));
  let ph = opt.phase || 0;
  const vib = opt.vib;
  for (let i = 0; i < n; i++) {
    let ff = f;
    if (vib) { const tt = i / SR; const d = vib[2] ? Math.min(1, tt / vib[2]) : 1; ff = f * Math.pow(2, vib[1] * d * Math.sin(TAU * vib[0] * tt) / 12); }
    if (opt.glide) ff *= 1 + opt.glide * Math.exp(-i / SR * 30);
    ph += ff / SR; ph -= Math.floor(ph);
    if (!t) out[i] = Math.sin(TAU * ph);
    else { const x = ph * TBL; const j = x | 0; const fr = x - j; out[i] = t[j] + (t[j + 1] - t[j]) * fr; }
  }
  return out;
}

// ---------- filters ----------
function biquad(x, type, fc, q = 0.707, gainDb = 0) {
  const w = TAU * Math.min(fc, SR * 0.49) / SR, cw = Math.cos(w), sw = Math.sin(w), al = sw / (2 * q);
  const A = Math.pow(10, gainDb / 40);
  let b0, b1, b2, a0, a1, a2;
  if (type === 'lp') { b0 = (1 - cw) / 2; b1 = 1 - cw; b2 = b0; a0 = 1 + al; a1 = -2 * cw; a2 = 1 - al; }
  else if (type === 'hp') { b0 = (1 + cw) / 2; b1 = -(1 + cw); b2 = b0; a0 = 1 + al; a1 = -2 * cw; a2 = 1 - al; }
  else if (type === 'bp') { b0 = al; b1 = 0; b2 = -al; a0 = 1 + al; a1 = -2 * cw; a2 = 1 - al; }
  else if (type === 'peak') { b0 = 1 + al * A; b1 = -2 * cw; b2 = 1 - al * A; a0 = 1 + al / A; a1 = -2 * cw; a2 = 1 - al / A; }
  else if (type === 'lowshelf' || type === 'highshelf') {
    const s = type === 'lowshelf' ? 1 : -1, sq = 2 * Math.sqrt(A) * al;
    b0 = A * ((A + 1) - s * (A - 1) * cw + sq); b1 = s * 2 * A * ((A - 1) - s * (A + 1) * cw); b2 = A * ((A + 1) - s * (A - 1) * cw - sq);
    a0 = (A + 1) + s * (A - 1) * cw + sq; a1 = -s * 2 * ((A - 1) + s * (A + 1) * cw); a2 = (A + 1) + s * (A - 1) * cw - sq;
  }
  b0 /= a0; b1 /= a0; b2 /= a0; a1 /= a0; a2 /= a0;
  const y = new Float32Array(x.length);
  let x1 = 0, x2 = 0, y1 = 0, y2 = 0;
  for (let i = 0; i < x.length; i++) { const v = b0 * x[i] + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2; x2 = x1; x1 = x[i]; y2 = y1; y1 = v; y[i] = v; }
  return y;
}
// TPT state-variable lowpass with cutoff envelope fcFn(i) (updated every 16 samples)
function svfLP(x, fcFn, q = 0.8) {
  const y = new Float32Array(x.length);
  let ic1 = 0, ic2 = 0, g = 0, k = 1 / q, a1 = 0, a2 = 0, a3 = 0;
  for (let i = 0; i < x.length; i++) {
    if ((i & 15) === 0) { g = Math.tan(Math.PI * Math.min(fcFn(i), SR * 0.45) / SR); a1 = 1 / (1 + g * (g + k)); a2 = g * a1; a3 = g * a2; }
    const v3 = x[i] - ic2, v1 = a1 * ic1 + a2 * v3, v2 = ic2 + a2 * ic1 + a3 * v3;
    ic1 = 2 * v1 - ic1; ic2 = 2 * v2 - ic2; y[i] = v2;
  }
  return y;
}
function noise(n, r) { const o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = r() * 2 - 1; return o; }

// ---------- envelopes ----------
function adsr(n, a, d, s, r, holdS) {
  const e = new Float32Array(n), A = a * SR, D = d * SR, H = holdS * SR, R = r * SR;
  let lvl = 0, relFrom = -1, relLvl = 0;
  for (let i = 0; i < n; i++) {
    if (i < H) {
      if (i < A) lvl = i / A;
      else if (i < A + D) lvl = 1 - (1 - s) * ((i - A) / D);
      else lvl = s;
    } else {
      if (relFrom < 0) { relFrom = i; relLvl = lvl; }
      lvl = relLvl * Math.max(0, 1 - (i - relFrom) / R);
    }
    e[i] = lvl;
  }
  return e;
}
const mul = (x, e) => { for (let i = 0; i < x.length; i++) x[i] *= e[i]; return x; };
const expEnv = (n, rate, att = 0.002) => { const e = new Float32Array(n), A = att * SR; for (let i = 0; i < n; i++) e[i] = (i < A ? i / A : 1) * Math.exp(-rate * i / SR); return e; };
function releaseTail(x, startS, relS) { const s = Math.floor(startS * SR), R = relS * SR; for (let i = s; i < x.length; i++) x[i] *= Math.max(0, 1 - (i - s) / R); return x; }

// ---------- instruments (return mono Float32Array) ----------
const I = {};
I.epiano = (f, dur, vel = 0.8) => {
  const n = Math.floor((dur + 1.2) * SR), o = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    const t = i / SR, idx = (0.9 + vel) * Math.exp(-t * 2.5);
    const m = Math.sin(TAU * f * t);
    let v = Math.sin(TAU * f * t + idx * m) + 0.06 * Math.sin(TAU * f * 14 * t) * Math.exp(-t * 25);
    v *= Math.exp(-t * (0.9 + f / 900)) * Math.min(1, t / 0.003);
    o[i] = v;
  }
  return releaseTail(o, dur, 0.35);
};
I.piano = (f, dur, vel = 0.8) => {
  const n = Math.floor((dur + 1.5) * SR), o = new Float32Array(n);
  const P = [[1, 1], [2, .45], [3, .25], [4, .14], [5, .08], [6, .05]];
  const inh = 0.0004;
  for (const [k, a] of P) {
    const fk = f * k * Math.sqrt(1 + inh * k * k); if (fk > SR * 0.45) continue;
    const dec = 0.7 + k * 0.6 + f / 600;
    for (let i = 0; i < n; i++) { const t = i / SR; o[i] += a * Math.sin(TAU * fk * t) * Math.exp(-t * dec); }
  }
  for (let i = 0; i < Math.min(n, 400); i++) o[i] *= i / 400;
  return mul(releaseTail(o, dur, 0.25), new Float32Array(n).fill(0.6 * (0.6 + 0.4 * vel)));
};
I.bell = (f, dur, vel = 0.7) => {
  const n = Math.floor((dur + 2.5) * SR), o = new Float32Array(n);
  const P = [[1, 1, 1.2], [2.0, .5, 2], [2.76, .35, 3], [5.4, .2, 5], [8.93, .1, 8]];
  for (const [r, a, d] of P) { const fk = f * r; if (fk > SR * .45) continue; for (let i = 0; i < n; i++) { const t = i / SR; o[i] += a * Math.sin(TAU * fk * t) * Math.exp(-t * d); } }
  for (let i = 0; i < 100; i++) o[i] *= i / 100;
  return mul(o, new Float32Array(n).fill(0.45 * vel));
};
I.marimba = (f, dur, vel = 0.8) => {
  const n = Math.floor(1.2 * SR), o = new Float32Array(n);
  const P = [[1, 1, 4], [3.93, .35, 12], [10.7, .12, 30]];
  for (const [r, a, d] of P) { const fk = f * r; if (fk > SR * .45) continue; for (let i = 0; i < n; i++) { const t = i / SR; o[i] += a * Math.sin(TAU * fk * t) * Math.exp(-t * d); } }
  for (let i = 0; i < 60; i++) o[i] *= i / 60;
  return mul(o, new Float32Array(n).fill(0.6 * vel));
};
function supersaw(f, n, detune, types = ['saw', 'saw']) {
  const a = osc(types[0], f * Math.pow(2, detune / 1200), n), b = osc(types[1], f * Math.pow(2, -detune / 1200), n, { phase: 0.37 });
  const c = osc('tri', f / 2, n, { phase: 0.11 });
  const o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = 0.4 * a[i] + 0.4 * b[i] + 0.35 * c[i];
  return o;
}
I.pad = (f, dur, vel = 0.6, cut = 1800) => {
  const rel = 1.4, n = Math.floor((dur + rel) * SR);
  let o = supersaw(f, n, 9);
  o = biquad(o, 'lp', cut, 0.6); o = biquad(o, 'lp', cut * 1.3, 0.5);
  return mul(o, mul(adsr(n, 0.7, 0.5, 0.85, rel, dur), new Float32Array(n).fill(0.32 * vel)));
};
I.warmpad = (f, dur, vel = 0.6) => I.pad(f, dur, vel, 1000);
I.strings = (f, dur, vel = 0.7) => {
  const rel = 1.2, n = Math.floor((dur + rel) * SR);
  const a = osc('saw', f * 1.003, n, { vib: [5.2, 0.08, 0.4] }), b = osc('saw', f * 0.997, n, { vib: [4.7, 0.07, 0.5], phase: .5 });
  let o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = a[i] + b[i];
  o = biquad(biquad(o, 'lp', 2600, 0.6), 'hp', 120);
  return mul(o, mul(adsr(n, 0.45, 0.3, 0.9, rel, dur), new Float32Array(n).fill(0.22 * vel)));
};
I.brass = (f, dur, vel = 0.8) => { // cinematic "braam"
  const rel = 0.8, n = Math.floor((dur + rel) * SR);
  let o = supersaw(f, n, 14);
  o = svfLP(o, i => 250 + 1600 * Math.min(1, i / (SR * 0.25)) * Math.exp(-i / SR * 0.6), 0.9);
  return mul(o, mul(adsr(n, 0.08, 0.6, 0.7, rel, dur), new Float32Array(n).fill(0.4 * vel)));
};
I.bass = (f, dur, vel = 0.9, kind = 'sub') => {
  const rel = 0.12, n = Math.floor((dur + rel) * SR);
  let o;
  if (kind === 'sub') { const s = osc('sine', f, n), t = osc('tri', f, n); o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = s[i] * 0.85 + t[i] * 0.25; }
  else if (kind === 'pluck') { o = svfLP(osc('saw', f, n), i => 180 + 1400 * Math.exp(-i / SR * 9), 1.1); }
  else { const a = osc('square', f, n), b = osc('saw', f * 1.004, n); o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = 0.5 * a[i] + 0.5 * b[i]; o = svfLP(o, i => 300 + 900 * Math.exp(-i / SR * 6), 0.9); }
  return mul(o, mul(adsr(n, 0.006, 0.2, 0.75, rel, dur), new Float32Array(n).fill(0.55 * vel)));
};
// Karplus-Strong pluck; bright 0..1
I.pluck = (f, dur, vel = 0.8, bright = 0.5, r = Math.random, decay = 0.996) => {
  const n = Math.floor((dur + 1.5) * SR), N = Math.max(2, Math.round(SR / f)), buf = new Float32Array(N), o = new Float32Array(n);
  let prev = 0; for (let i = 0; i < N; i++) { const w = r() * 2 - 1; prev = prev + bright * (w - prev); buf[i] = prev; }
  let p = 0, last = 0;
  const fb = Math.min(0.9995, decay + f / 2e6);
  for (let i = 0; i < n; i++) { const v = buf[p]; const nv = fb * 0.5 * (v + last); last = v; buf[p] = nv; o[i] = v; p = (p + 1) % N; }
  const hp = biquad(o, 'hp', 70);
  return mul(releaseTail(hp, dur + 0.4, 0.6), new Float32Array(n).fill(0.9 * vel));
};
I.guitar = (f, dur, vel, r) => I.pluck(f, dur, vel, 0.45, r, 0.997);
I.harp = (f, dur, vel, r) => I.pluck(f, dur, vel, 0.3, r, 0.998);
I.oud = (f, dur, vel, r) => { // bright short pluck + body resonance, tremolo picking on long notes
  if (dur > 0.45) {
    const n = Math.floor((dur + 1) * SR), o = new Float32Array(n), step = 0.11;
    for (let t = 0, k = 0; t < dur; t += step, k++) { const p = I.pluck(f, step, vel * (k ? 0.55 : 1), 0.75, r, 0.993), s = Math.floor(t * SR); for (let i = 0; i < p.length && s + i < n; i++) o[s + i] += p[i]; }
    return biquad(o, 'peak', 220, 1.2, 4);
  }
  return biquad(I.pluck(f, dur, vel, 0.75, r, 0.993), 'peak', 220, 1.2, 4);
};
I.flute = (f, dur, vel = 0.7, r = Math.random, breath = 0.05) => {
  const rel = 0.25, n = Math.floor((dur + rel) * SR);
  const s = osc('sine', f, n, { vib: [5, 0.12, 0.25] }), h = osc('sine', f * 2, n, { vib: [5, 0.12, 0.25] });
  const nz = biquad(noise(n, r), 'bp', f * 2, 2);
  const o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = s[i] + 0.18 * h[i] + breath * 6 * nz[i];
  return mul(o, mul(adsr(n, 0.06, 0.15, 0.8, rel, dur), new Float32Array(n).fill(0.35 * vel)));
};
I.ney = (f, dur, vel, r) => I.flute(f, dur, vel, r, 0.11);
I.lead = (f, dur, vel = 0.7) => {
  const rel = 0.15, n = Math.floor((dur + rel) * SR);
  let o = osc('square', f, n, { vib: [5.5, 0.1, 0.3] });
  o = biquad(o, 'lp', Math.min(5000, f * 5), 0.7);
  return mul(o, mul(adsr(n, 0.01, 0.15, 0.6, rel, dur), new Float32Array(n).fill(0.18 * vel)));
};
I.whistle = (f, dur, vel = 0.7) => {
  const rel = 0.1, n = Math.floor((dur + rel) * SR);
  const o = osc('sine', f, n, { vib: [6, 0.15, 0.15], glide: -0.02 });
  return mul(o, mul(adsr(n, 0.02, 0.1, 0.8, rel, dur), new Float32Array(n).fill(0.3 * vel)));
};

// ---------- drums ----------
const D = {};
D.kick = (r, soft = 0) => {
  const n = Math.floor(0.45 * SR), o = new Float32Array(n); let ph = 0;
  for (let i = 0; i < n; i++) { const t = i / SR; ph += (46 + 110 * Math.exp(-t * 28)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * (6 + soft * 4)); }
  const c = biquad(noise(220, r), 'hp', 2500); for (let i = 0; i < 220; i++) o[i] += c[i] * (1 - i / 220) * (0.35 - soft * 0.25);
  for (let i = 0; i < n; i++) o[i] = Math.tanh(o[i] * 1.6) * 0.9;
  return o;
};
D.snare = (r, soft = 0) => {
  const n = Math.floor(0.3 * SR), nz = biquad(biquad(noise(n, r), 'hp', 900), 'lp', 7000), o = new Float32Array(n);
  for (let i = 0; i < n; i++) { const t = i / SR; o[i] = nz[i] * Math.exp(-t * (20 + soft * 10)) * 0.75 + Math.sin(TAU * 185 * t) * Math.exp(-t * 28) * 0.55; }
  return o;
};
D.rim = r => { const n = Math.floor(0.08 * SR), nz = biquad(noise(n, r), 'bp', 1800, 3), o = new Float32Array(n); for (let i = 0; i < n; i++) { const t = i / SR; o[i] = (nz[i] * 1.5 + Math.sin(TAU * 820 * t) * 0.6) * Math.exp(-t * 60); } return o; };
D.clap = r => {
  const n = Math.floor(0.35 * SR), nz = biquad(noise(n, r), 'bp', 1300, 1.2), o = new Float32Array(n);
  for (let i = 0; i < n; i++) { const t = i / SR; let e = Math.exp(-t * 14) * 0.7; for (const d of [0, 0.011, 0.022]) if (t >= d && t < d + 0.009) e = Math.max(e, 1 - (t - d) / 0.009); o[i] = nz[i] * e * 1.4; }
  return o;
};
D.hat = (r, open = 0) => { const n = Math.floor((open ? 0.45 : 0.08) * SR), nz = biquad(biquad(noise(n, r), 'hp', 7000), 'hp', 7000), o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = nz[i] * Math.exp(-i / SR * (open ? 9 : 55)) * 0.5; return o; };
D.shaker = r => { const n = Math.floor(0.09 * SR), nz = biquad(noise(n, r), 'hp', 5000), o = new Float32Array(n); for (let i = 0; i < n; i++) { const t = i / SR; o[i] = nz[i] * Math.min(1, t / 0.012) * Math.exp(-t * 45) * 0.35; } return o; };
D.doum = () => { const n = Math.floor(0.5 * SR), o = new Float32Array(n); let ph = 0; for (let i = 0; i < n; i++) { const t = i / SR; ph += (82 + 30 * Math.exp(-t * 20)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * 7) * 0.95; } return o; };
D.tek = r => { const n = Math.floor(0.12 * SR), nz = biquad(noise(n, r), 'bp', 3200, 1.5), o = new Float32Array(n); for (let i = 0; i < n; i++) { const t = i / SR; o[i] = (nz[i] * 1.2 + Math.sin(TAU * 540 * t) * 0.5) * Math.exp(-t * 45); } return o; };
D.ka = r => { const o = D.tek(r); for (let i = 0; i < o.length; i++) o[i] *= 0.5; return o; };
D.tom = (r, f = 110) => { const n = Math.floor(0.6 * SR), o = new Float32Array(n); let ph = 0; for (let i = 0; i < n; i++) { const t = i / SR; ph += f * (1 + 0.5 * Math.exp(-t * 15)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * 6); } return o; };
D.boom = r => { // taiko-ish
  const n = Math.floor(1.4 * SR), o = new Float32Array(n), nz = biquad(noise(n, r), 'lp', 400); let ph = 0;
  for (let i = 0; i < n; i++) { const t = i / SR; ph += (55 + 40 * Math.exp(-t * 12)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * 2.6) + nz[i] * Math.exp(-t * 7) * 0.6; }
  return o;
};
D.crash = r => {
  const n = Math.floor(2.5 * SR), nz = biquad(noise(n, r), 'hp', 3500), o = new Float32Array(n);
  const fr = [3127, 4219, 5381, 6011, 7333];
  for (let i = 0; i < n; i++) { const t = i / SR; let m = 0; for (const f of fr) m += Math.sign(Math.sin(TAU * f * t)); o[i] = (nz[i] * 0.8 + m * 0.03) * Math.exp(-t * 1.6) * 0.5; }
  return biquad(o, 'hp', 2500);
};
D.vinyl = (n, r) => { const o = new Float32Array(n), hiss = biquad(noise(n, r), 'bp', 3000, 0.5); for (let i = 0; i < n; i++) { o[i] = hiss[i] * 0.012; if (r() < 0.00025) { const a = (r() * 0.5 + 0.2); for (let k = 0; k < 40 && i + k < n; k++) o[i + k] += a * (r() * 2 - 1) * Math.exp(-k / 6); } } return o; };

// ---------- mixing ----------
class Bus {
  constructor(n) { this.L = new Float32Array(n); this.R = new Float32Array(n); this.n = n; }
  add(sig, startS, gain = 1, pan = 0) {
    const s = Math.round(startS * SR), gl = gain * Math.cos((pan + 1) * Math.PI / 4), gr = gain * Math.sin((pan + 1) * Math.PI / 4);
    for (let i = 0; i < sig.length; i++) { const j = s + i; if (j < 0) continue; if (j >= this.n) break; this.L[j] += sig[i] * gl * 1.414; this.R[j] += sig[i] * gr * 1.414; }
  }
  mix(other, g = 1) { for (let i = 0; i < this.n; i++) { this.L[i] += other.L[i] * g; this.R[i] += other.R[i] * g; } }
  map(fn) { this.L = fn(this.L); this.R = fn(this.R); return this; }
  gainEnv(env) { for (let i = 0; i < this.n; i++) { this.L[i] *= env[i]; this.R[i] *= env[i]; } }
}
// Freeverb
function reverb(bus, room = 0.82, damp = 0.35, wet = 1) {
  const combs = [1116, 1188, 1277, 1356, 1422, 1491, 1557, 1617], aps = [556, 441, 341, 225];
  const out = new Bus(bus.n);
  for (const [ch, spread] of [['L', 0], ['R', 23]]) {
    const x = bus[ch], y = out[ch];
    for (const c of combs) {
      const N = c + spread, buf = new Float32Array(N); let p = 0, st = 0;
      for (let i = 0; i < bus.n; i++) { const v = buf[p]; st = v * (1 - damp) + st * damp; buf[p] = x[i] * 0.015 + st * room; y[i] += v; p = (p + 1) % N; }
    }
    for (const a of aps) {
      const N = a + spread, buf = new Float32Array(N); let p = 0;
      for (let i = 0; i < bus.n; i++) { const b = buf[p], v = -y[i] + b; buf[p] = y[i] + b * 0.5; y[i] = v; p = (p + 1) % N; }
    }
    for (let i = 0; i < bus.n; i++) y[i] *= wet;
  }
  return out;
}
function delay(bus, timeS, fb = 0.35, wet = 0.3, lpHz = 3500) {
  const out = new Bus(bus.n), N = Math.round(timeS * SR);
  for (const [ch, off] of [['L', 0], ['R', Math.round(N / 2)]]) {
    const x = bus[ch], y = out[ch], buf = new Float32Array(N + off); let p = 0, lp = 0, a = Math.exp(-TAU * lpHz / SR);
    for (let i = 0; i < bus.n; i++) { const v = buf[p]; lp = (1 - a) * v + a * lp; buf[p] = x[i] + lp * fb; y[i] = v * wet; p = (p + 1) % buf.length; }
  }
  return out;
}
// lookahead brickwall limiter (sample-peak ceiling), stereo-linked
function limiter(bus, ceilDb = -1.3, laS = 0.005, relS = 0.08) {
  const n = bus.n, ceil = dbToGain(ceilDb), req = new Float32Array(n), la = Math.round(laS * SR);
  for (let i = 0; i < n; i++) { const p = Math.max(Math.abs(bus.L[i]), Math.abs(bus.R[i])); req[i] = p > ceil ? ceil / p : 1; }
  // sliding min over [i, i+la]
  const mn = new Float32Array(n), dq = new Int32Array(n + la + 1); let h = 0, t = 0;
  for (let j = 0; j < n + la; j++) {
    if (j < n) { while (t > h && req[dq[t - 1]] >= req[j]) t--; dq[t++] = j; }
    const i = j - la; if (i < 0) continue;
    while (dq[h] < i) h++;
    mn[i] = req[dq[h]];
  }
  const rc = Math.exp(-1 / (relS * SR)); let env = 1; const g = new Float32Array(n);
  for (let i = 0; i < n; i++) { env = mn[i] < env ? mn[i] : mn[i] + (env - mn[i]) * rc; g[i] = env; }
  // box smooth (attack ramp) — stays <= because the min looked ahead
  const sm = new Float32Array(n); let acc = 0;
  for (let i = 0; i < n; i++) { acc += g[i]; if (i >= la) acc -= g[i - la]; sm[i] = Math.min(g[i], acc / Math.min(i + 1, la)); }
  // delay-free: use min of smoothed & required to guarantee ceiling
  for (let i = 0; i < n; i++) { const gg = Math.min(sm[i], mn[i]); bus.L[i] *= gg; bus.R[i] *= gg; }
  return bus;
}

// ---------- WAV ----------
function wav16(bus) {
  const n = bus.n, buf = Buffer.alloc(44 + n * 4);
  buf.write('RIFF', 0); buf.writeUInt32LE(36 + n * 4, 4); buf.write('WAVE', 8); buf.write('fmt ', 12);
  buf.writeUInt32LE(16, 16); buf.writeUInt16LE(1, 20); buf.writeUInt16LE(2, 22); buf.writeUInt32LE(SR, 24); buf.writeUInt32LE(SR * 4, 28); buf.writeUInt16LE(4, 32); buf.writeUInt16LE(16, 34);
  buf.write('data', 36); buf.writeUInt32LE(n * 4, 40);
  let seed = 12345; const tpdf = () => { seed = (seed * 1103515245 + 12345) >>> 0; return seed / 4294967296; };
  for (let i = 0; i < n; i++) {
    const l = Math.max(-1, Math.min(1, bus.L[i])), r = Math.max(-1, Math.min(1, bus.R[i]));
    buf.writeInt16LE(Math.round(l * 32766 + (tpdf() - tpdf())), 44 + i * 4); buf.writeInt16LE(Math.round(r * 32766 + (tpdf() - tpdf())), 46 + i * 4);
  }
  return buf;
}

module.exports = { SR, TAU, rng, hash, mtof, dbToGain, osc, biquad, svfLP, noise, adsr, mul, expEnv, releaseTail, I, D, Bus, reverb, delay, limiter, wav16 };
