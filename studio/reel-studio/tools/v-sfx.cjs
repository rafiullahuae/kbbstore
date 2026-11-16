'use strict';
// KBB Studio · Lane V · SOUND HOOKS synthesised OFFLINE (deterministic, original, free to use).
//   node tools/v-sfx.cjs  ->  library/sfx/hooks/<id>.m4a + library/sfx/hooks/library.json
// Every sound carries its role (hit / riser / transition / texture / ambience / accent), the moods and industries it
// suits and `hitAt` (the audible peak) so app/viral can land the hit exactly on the hook's first frame or first beat.
const fs = require('fs'), path = require('path'), os = require('os'), { execFileSync } = require('child_process');
const X = require('./r3-dsp.cjs');
const { SR, TAU, I, D, Bus } = X;
const OUT = path.resolve(__dirname, '..', 'library/sfx/hooks');

const N = (s) => Math.floor(s * SR);
const hann = (p) => Math.sin(Math.PI * Math.min(1, Math.max(0, p)));
const env = (n, fn) => { const o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = fn(i / n, i / SR); return o; };
const mulEnv = (x, fn) => { for (let i = 0; i < x.length; i++) x[i] *= fn(i / x.length, i / SR); return x; };
/** band-passed noise whose centre moves f0 -> f1 (state-variable filter) */
function sweep(dur, f0, f1, q, r, envFn) {
  const n = N(dur), nz = X.noise(n, r), o = new Float32Array(n); let ic1 = 0, ic2 = 0;
  for (let i = 0; i < n; i++) {
    const p = i / n, fc = f0 * Math.pow(f1 / f0, p), g = Math.tan(Math.PI * Math.min(fc, SR * 0.45) / SR), k = 1 / q;
    const a1 = 1 / (1 + g * (g + k)), a2 = g * a1, a3 = g * a2, v3 = nz[i] - ic2, v1 = a1 * ic1 + a2 * v3, v2 = ic2 + a2 * ic1 + a3 * v3;
    ic1 = 2 * v1 - ic1; ic2 = 2 * v2 - ic2; o[i] = v1 * envFn(p, i / SR);
  }
  return o;
}
function tone(dur, fFn, envFn, type = 'sine') {
  const n = N(dur), o = new Float32Array(n); let ph = 0;
  for (let i = 0; i < n; i++) { const t = i / SR, p = i / n; ph += fFn(p, t) / SR; const s = Math.sin(TAU * ph); o[i] = (type === 'tri' ? Math.asin(s) * 2 / Math.PI : type === 'saw' ? 2 * (ph % 1) - 1 : type === 'sq' ? Math.tanh(4 * s) : s) * envFn(p, t); }
  return o;
}
const burst = (dur, r, f, q, decay) => mulEnv(X.biquad(X.noise(N(dur), r), 'bp', f, q), (p, t) => Math.min(1, t / 0.0015) * Math.exp(-t * decay));
/** a vowel-ish voice: saw through two formant band-passes (for the crowd "ooh") */
function voice(dur, f0Fn, formants, r, envFn) {
  const src = tone(dur, (p, t) => f0Fn(p, t) * (1 + 0.006 * Math.sin(TAU * (5 + r() * 2) * t)), () => 1, 'saw');
  let o = new Float32Array(src.length);
  for (const [f, g] of formants) { const b = X.biquad(src, 'bp', f, 5); for (let i = 0; i < o.length; i++) o[i] += b[i] * g; }
  return mulEnv(o, envFn);
}

const SFX = {
  // ── hits & transitions (attention grabbers) ──
  'hook-hit': { title: 'Cinematic hit', role: 'hit', moods: ['dramatic', 'energetic'], industries: ['any'], dur: 2.0,
    make: (b, r) => { b.add(D.boom(r), 0, 1.3); b.add(D.kick(r), 0, 0.9); b.add(burst(0.08, r, 2400, 1.2, 40), 0, 0.8); b.add(X.biquad(D.crash(r), 'lp', 9000), 0, 0.35); } },
  'hook-bass-drop': { title: 'Bass drop', role: 'hit', moods: ['energetic', 'dramatic'], industries: ['any', 'retail'], dur: 2.2,
    make: (b, r) => { b.add(tone(2.0, (p) => 120 * Math.pow(0.25, p), (p, t) => Math.min(1, t / 0.008) * Math.exp(-t * 1.4), 'tri'), 0, 1.2); b.add(D.kick(r), 0, 1); b.add(sweep(0.25, 3000, 400, 1.5, r, (p) => Math.exp(-p * 6)), 0, 0.4); } },
  'hook-riser': { title: 'Tension riser (ends on the hook)', role: 'riser', moods: ['dramatic', 'energetic'], industries: ['any'], dur: 1.8, hitAtEnd: true,
    make: (b, r) => { const e = (p) => Math.pow(p, 2.2) * (p < 0.985 ? 1 : (1 - p) / 0.015); b.add(sweep(1.8, 250, 8000, 3, r, e), 0, 1); b.add(tone(1.8, (p) => 140 * Math.pow(5, p), (p) => 0.3 * e(p), 'saw'), 0, 0.7); b.add(tone(1.8, (p) => 70 * Math.pow(5, p), (p) => 0.2 * e(p)), 0, 0.8); } },
  'hook-swoosh': { title: 'Fast swoosh', role: 'transition', moods: ['energetic', 'playful'], industries: ['any'], dur: 0.6,
    make: (b, r) => { const s = sweep(0.5, 700, 7000, 2.2, r, (p) => Math.pow(hann(Math.pow(p, 0.55)), 2)); for (let i = 0; i < s.length; i++) { const pan = -0.8 + 1.6 * i / s.length, a = (pan + 1) * Math.PI / 4; b.L[i] += s[i] * Math.cos(a) * 1.6; b.R[i] += s[i] * Math.sin(a) * 1.6; } } },
  'hook-whip': { title: 'Whip-in', role: 'transition', moods: ['energetic'], industries: ['any'], dur: 0.35,
    make: (b, r) => { b.add(sweep(0.3, 4500, 900, 2.5, r, (p) => (p < 0.2 ? p / 0.2 : Math.exp(-(p - 0.2) * 10))), 0, 1.4, -0.3); b.add(burst(0.04, r, 1800, 2, 60), 0.06, 0.4); } },
  'hook-record-scratch': { title: 'Record scratch', role: 'accent', moods: ['playful', 'dramatic'], industries: ['any'], dur: 0.7,
    make: (b, r) => {
      // a vinyl stop: noise + a tone whose playback speed rocks back and forth, then drops to a halt
      const n = N(0.65), o = new Float32Array(n), nz = X.biquad(X.noise(n, r), 'bp', 2200, 1.2); let ph = 0;
      for (let i = 0; i < n; i++) { const t = i / SR, sp = t < 0.32 ? Math.sin(TAU * 4.2 * t) * 1.6 : Math.max(0, 1 - (t - 0.32) / 0.3) * 0.6; ph += (420 * sp) / SR; o[i] = (Math.sin(TAU * ph) * 0.6 + nz[i] * Math.abs(sp) * 1.1) * Math.min(1, t / 0.004) * (t > 0.6 ? (0.65 - t) / 0.05 : 1); }
      b.add(o, 0, 1.2);
    } },
  'hook-ding': { title: 'Notification ding', role: 'accent', moods: ['playful', 'calm', 'corporate'], industries: ['any'], dur: 1.4,
    make: (b) => { b.add(I.bell(1568, 1.1, 0.9), 0, 0.9, -0.1); b.add(I.bell(2349, 0.8, 0.5), 0.09, 0.6, 0.15); } },
  'hook-shutter': { title: 'Camera shutter + flash', role: 'accent', moods: ['playful', 'luxury'], industries: ['beauty', 'retail', 'any'], dur: 0.6,
    make: (b, r) => { b.add(burst(0.05, r, 2900, 2.5, 120), 0, 1.4); b.add(burst(0.05, r, 1900, 2.5, 120), 0.07, 1.1); b.add(tone(0.4, (p) => 3200 + 3000 * p, (p, t) => 0.12 * Math.exp(-t * 9)), 0.1, 0.4); } },
  'hook-rewind': { title: 'Tape rewind (flash-forward)', role: 'transition', moods: ['playful', 'dramatic'], industries: ['any'], dur: 0.9,
    make: (b, r) => { for (let k = 0; k < 14; k++) b.add(tone(0.05, (p) => 900 + k * 120 + 600 * p, (p) => hann(p) * 0.5, 'sq'), k * 0.055, 0.35, (k % 2 ? 0.4 : -0.4)); b.add(sweep(0.8, 3000, 600, 1.2, r, (p) => hann(p) * 0.6), 0, 0.8); } },
  // ── reactions ──
  'hook-crowd-ooh': { title: 'Crowd "ooh"', role: 'accent', moods: ['playful', 'dramatic'], industries: ['any'], dur: 1.9,
    make: (b, r) => { for (let k = 0; k < 9; k++) { const f0 = 150 + r() * 120, d = 1.5 + r() * 0.3; b.add(voice(d, (p) => f0 * (1 + 0.18 * Math.sin(Math.PI * p)), [[320, 1], [820, 0.35]], r, (p) => hann(p) * (p < 0.15 ? p / 0.15 : 1)), r() * 0.12, 0.28, r() * 1.6 - 0.8); } } },
  'hook-applause': { title: 'Applause', role: 'accent', moods: ['energetic', 'corporate'], industries: ['any', 'immigration'], dur: 3.0,
    make: (b, r) => { for (let k = 0; k < 340; k++) { const c = D.clap(r).slice(0, N(0.05)); const t = r() * 2.6; b.add(c, t, (0.08 + r() * 0.1) * hann(t / 2.8 * 0.9 + 0.05), r() * 1.8 - 0.9); } } },
  // ── beauty ASMR textures ──
  'hook-asmr-taps': { title: 'ASMR nail taps', role: 'texture', moods: ['asmr', 'luxury', 'calm'], industries: ['beauty', 'retail'], dur: 1.6,
    make: (b, r) => { [0, 0.16, 0.3, 0.42, 0.7, 0.82, 0.94, 1.2].forEach((t, k) => { b.add(burst(0.03, r, 3600 + r() * 1400, 9, 160), t, 1.1, (k % 2 ? 0.25 : -0.25)); b.add(tone(0.06, () => 2100 + r() * 300, (p, tt) => Math.exp(-tt * 70) * 0.3), t, 0.6); }); } },
  'hook-pour': { title: 'Liquid pour', role: 'texture', moods: ['asmr', 'calm', 'luxury'], industries: ['beauty', 'any'], dur: 2.2,
    make: (b, r) => { b.add(mulEnv(X.biquad(X.biquad(X.noise(N(2.0), r), 'bp', 900, 0.9), 'lp', 3000), (p) => hann(Math.min(1, p * 1.4)) * 0.5), 0, 1); for (let k = 0; k < 40; k++) { const f = 500 + r() * 1100, t = 0.1 + r() * 1.8; b.add(tone(0.07, (p) => f * (1 + 0.8 * p), (p, tt) => Math.min(1, tt / 0.003) * Math.exp(-tt * 45) * 0.35), t, 0.6, r() - 0.5); } } },
  'hook-spray': { title: 'Mist spray', role: 'texture', moods: ['asmr', 'calm', 'luxury'], industries: ['beauty'], dur: 1.3,
    make: (b, r) => { for (const t of [0, 0.55]) { b.add(burst(0.012, r, 1500, 2, 300), t, 0.6); b.add(mulEnv(X.biquad(X.noise(N(0.42), r), 'hp', 4500), (p, tt) => Math.min(1, tt / 0.01) * (p > 0.75 ? (1 - p) / 0.25 : 1) * 0.6), t + 0.01, 1, (t ? 0.2 : -0.2)); } } },
  'hook-cream-squish': { title: 'Cream squish', role: 'texture', moods: ['asmr', 'playful'], industries: ['beauty'], dur: 1.2,
    make: (b, r) => { b.add(mulEnv(X.biquad(X.noise(N(0.7), r), 'bp', 420, 1.4), (p, t) => hann(p) * (0.6 + 0.4 * Math.sin(TAU * 11 * t))), 0, 1.4); for (let k = 0; k < 7; k++) { const f = 260 + r() * 500, t = 0.1 + k * 0.08 + r() * 0.03; b.add(tone(0.09, (p) => f * (1 + 1.4 * p), (p, tt) => Math.min(1, tt / 0.004) * Math.exp(-tt * 30) * 0.5), t, 0.7, r() - 0.5); } } },
  // ── travel / visa ──
  'hook-boarding-chime': { title: 'Plane boarding chime', role: 'accent', moods: ['calm', 'corporate'], industries: ['immigration', 'travel'], dur: 2.4,
    make: (b) => { b.add(I.bell(1046.5, 1.3, 0.8), 0, 0.7, -0.15); b.add(I.bell(784, 1.6, 0.8), 0.55, 0.75, 0.15); } },
  'hook-airport-ambience': { title: 'Airport ambience', role: 'ambience', moods: ['calm', 'corporate'], industries: ['immigration', 'travel'], dur: 4.0,
    make: (b, r) => {
      const n = N(4.0), hum = X.biquad(X.noise(n, r), 'lp', 380), hall = X.biquad(X.noise(n, r), 'bp', 900, 0.7);
      b.add(mulEnv(hum, (p) => 0.55 * Math.min(1, p * 10, (1 - p) * 10)), 0, 1); b.add(mulEnv(hall, (p, t) => 0.18 * (0.7 + 0.3 * Math.sin(TAU * 0.6 * t)) * Math.min(1, p * 10, (1 - p) * 10)), 0, 1);
      for (let k = 0; k < 6; k++) b.add(voice(0.5 + r() * 0.4, () => 110 + r() * 90, [[500, 1], [1500, 0.3]], r, (p) => hann(p) * 0.25), 0.3 + r() * 3.0, 0.4, r() * 1.6 - 0.8);   // distant murmur
      for (let k = 0; k < 5; k++) b.add(burst(0.03, r, 2500, 4, 90), 0.4 + r() * 3.2, 0.12, r() - 0.5);                                      // trolley clicks
      b.add(I.bell(1046.5, 0.9, 0.4), 2.6, 0.18); b.add(I.bell(784, 1.0, 0.4), 3.0, 0.18);
    } },
  'hook-page-flip': { title: 'Page flip', role: 'transition', moods: ['calm', 'corporate'], industries: ['immigration', 'any'], dur: 0.6,
    make: (b, r) => { b.add(mulEnv(sweep(0.35, 1200, 5000, 1.1, r, (p) => hann(p)), (p, t) => 0.7 + 0.3 * Math.sin(TAU * 38 * t)), 0, 1.2, 0.2); b.add(burst(0.04, r, 1800, 1.5, 70), 0.33, 0.8, -0.1); } },
  'hook-stamp-thud': { title: 'Passport stamp (APPROVED)', role: 'hit', moods: ['dramatic', 'corporate'], industries: ['immigration'], dur: 1.0,
    make: (b, r) => { b.add(D.kick(r, 1), 0, 0.9); b.add(D.tom(r, 95), 0, 0.6); b.add(burst(0.06, r, 1200, 1.1, 55), 0.002, 1.2); b.add(burst(0.03, r, 3000, 2, 120), 0.004, 0.5); } },
  'hook-typing': { title: 'Typing', role: 'texture', moods: ['corporate', 'calm'], industries: ['immigration', 'any'], dur: 1.6,
    make: (b, r) => { let t = 0; for (let k = 0; k < 13; k++) { b.add(burst(0.025, r, 2200 + r() * 1800, 3, 240), t, 1.0, r() - 0.5); b.add(burst(0.02, r, 600, 2, 200), t + 0.006, 0.4); t += 0.07 + r() * 0.08; } } },
  'hook-cash-till': { title: 'Cash till', role: 'accent', moods: ['playful', 'energetic'], industries: ['retail', 'beauty', 'any'], dur: 1.8,
    make: (b, r) => { b.add(sweep(0.22, 300, 1400, 1.3, r, (p) => hann(p)), 0, 0.8); b.add(burst(0.05, r, 900, 1.5, 60), 0.2, 1.0); b.add(I.bell(2093, 1.2, 0.8), 0.24, 0.7, -0.2); b.add(I.bell(2637, 1.2, 0.8), 0.24, 0.6, 0.2); } },
};

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  const A = await import(path.resolve(__dirname, '..', 'app/auto/lib/audio.js'));
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'v-sfx-')), list = [];
  for (const [id, s] of Object.entries(SFX)) {
    const r = X.rng(X.hash(id)), bus = new Bus(N(s.dur));
    s.make(bus, r);
    const n = bus.n, fl = Math.min(N(0.03), n);
    for (let i = 0; i < fl; i++) { const g = i / fl; bus.L[n - 1 - i] *= g; bus.R[n - 1 - i] *= g; }
    let pk = 0; for (let i = 0; i < n; i++) pk = Math.max(pk, Math.abs(bus.L[i]), Math.abs(bus.R[i]));
    const g = X.dbToGain(-1) / (pk || 1); for (let i = 0; i < n; i++) { bus.L[i] *= g; bus.R[i] *= g; }
    let at = 0, mx = 0; for (let i = 0; i < n; i++) { const v = Math.abs(bus.L[i]) + Math.abs(bus.R[i]); if (v > mx) { mx = v; at = i; } }
    if (s.hitAtEnd) at = Math.round(n * 0.985);
    if (s.role === 'ambience') at = 0;   // a bed, not a hit: it starts with the hook
    const wav = path.join(tmp, id + '.wav');
    fs.writeFileSync(wav, X.wav16(bus));
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', wav, '-c:a', 'aac', '-b:a', '160k', '-metadata', 'title=' + s.title, path.join(OUT, id + '.m4a')]);
    const lu = A.loudness([bus.L, bus.R], SR).integrated;
    list.push({ id, file: id + '.m4a', title: s.title, category: 'hook', role: s.role, moods: s.moods, industries: s.industries, duration: +(n / SR).toFixed(3), hitAt: +(at / SR).toFixed(3), lufs: +lu.toFixed(1), peakDb: -1, source: 'synth' });
    console.log(id.padEnd(24), s.role.padEnd(10), (n / SR).toFixed(2) + 's', 'hit@' + (at / SR).toFixed(3), 'LUFS', lu.toFixed(1));
  }
  fs.writeFileSync(path.join(OUT, 'library.json'), JSON.stringify({ v: 1, generated: 'tools/v-sfx.cjs', licence: 'Original, generated by KBB Studio; free to use', sfx: list }, null, 1));
  fs.rmSync(tmp, { recursive: true, force: true });
}
if (require.main === module) main().catch((e) => { console.error(e); process.exit(1); });
module.exports = { SFX };
