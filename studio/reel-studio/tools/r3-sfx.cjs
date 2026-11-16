'use strict';
// KBB Studio · R3 · SFX library synthesised OFFLINE (deterministic). node tools/r3-sfx.cjs
//   -> library/sfx/<id>.m4a + library/sfx/library.json  (peak -1 dBFS, 44.1 kHz stereo, AAC 160k)
const fs = require('fs'), path = require('path'), os = require('os'), { execFileSync } = require('child_process');
const X = require('./r3-dsp.cjs');
const { SR, TAU, I, D, Bus } = X;
const OUT = path.resolve(__dirname, '..', 'library/sfx');

const N = s => Math.floor(s * SR);
const sweepNoise = (dur, f0, f1, q, r, envFn) => { // band-passed noise with a moving centre
  const n = N(dur), nz = X.noise(n, r), o = new Float32Array(n);
  let ic1 = 0, ic2 = 0;
  for (let i = 0; i < n; i++) {
    const p = i / n, fc = f0 * Math.pow(f1 / f0, p), g = Math.tan(Math.PI * Math.min(fc, SR * .45) / SR), k = 1 / q;
    const a1 = 1 / (1 + g * (g + k)), a2 = g * a1, a3 = g * a2, v3 = nz[i] - ic2, v1 = a1 * ic1 + a2 * v3, v2 = ic2 + a2 * ic1 + a3 * v3;
    ic1 = 2 * v1 - ic1; ic2 = 2 * v2 - ic2; o[i] = v1 * envFn(p, i / SR);
  }
  return o;
};
const tone = (dur, fFn, envFn, type = 'sine') => { const n = N(dur), o = new Float32Array(n); let ph = 0; for (let i = 0; i < n; i++) { const t = i / SR, p = i / n; ph += fFn(p, t) / SR; const s = Math.sin(TAU * ph); o[i] = (type === 'tri' ? Math.asin(s) * 2 / Math.PI : type === 'sq' ? Math.tanh(4 * s) : s) * envFn(p, t); } return o; };
const bell = (f, dur, a = .5) => I.bell(f, dur, a);
const hann = p => Math.sin(Math.PI * p);
const panSweep = (bus, sig, t0, from, to, g = 1) => { const s = N(t0); for (let i = 0; i < sig.length && s + i < bus.n; i++) { const pan = from + (to - from) * (i / sig.length), a = (pan + 1) * Math.PI / 4; bus.L[s + i] += sig[i] * Math.cos(a) * g * 1.4; bus.R[s + i] += sig[i] * Math.sin(a) * g * 1.4; } };

const SFX = {
  'whoosh': { cat: 'transition', dur: 1.0, make: (b, r) => panSweep(b, sweepNoise(0.9, 300, 2800, 1.6, r, p => Math.pow(hann(p), 1.5)), 0.02, -0.7, 0.7) },
  'whoosh-deep': { cat: 'transition', dur: 1.3, make: (b, r) => { panSweep(b, sweepNoise(1.2, 120, 900, 1.2, r, p => Math.pow(hann(p), 1.3)), 0, -0.5, 0.5, 1.2); b.add(tone(1.2, p => 60 + 40 * p, p => 0.3 * hann(p)), 0); } },
  'swoosh': { cat: 'transition', dur: 0.55, make: (b, r) => panSweep(b, sweepNoise(0.5, 1200, 6000, 2.5, r, p => Math.pow(hann(Math.pow(p, 0.6)), 2)), 0, 0.6, -0.6) },
  'swipe': { cat: 'transition', dur: 0.35, make: (b, r) => panSweep(b, sweepNoise(0.3, 3000, 900, 2, r, p => (p < .15 ? p / .15 : Math.exp(-(p - .15) * 9))), 0, -0.8, 0.8) },
  'pop': { cat: 'ui', dur: 0.25, make: (b, r) => b.add(tone(0.2, p => 900 * Math.exp(-p * 3) + 250, (p, t) => Math.min(1, t / .002) * Math.exp(-t * 28)), 0, .9) },
  'bubble': { cat: 'ui', dur: 0.3, make: (b, r) => b.add(tone(0.25, p => 300 + 900 * p * p, (p, t) => Math.min(1, t / .004) * Math.exp(-t * 16)), 0, .8) },
  'click': { cat: 'ui', dur: 0.08, make: (b, r) => { const c = X.biquad(X.noise(N(0.02), r), 'bp', 3500, 3); for (let i = 0; i < c.length; i++) c[i] *= Math.exp(-i / SR * 400); b.add(c, 0, 1.3); b.add(tone(0.03, () => 1800, (p, t) => Math.exp(-t * 200)), 0, .4); } },
  'sparkle': { cat: 'accent', dur: 1.4, make: (b, r) => { for (let k = 0; k < 14; k++) { const f = 2000 + r() * 4000, t = k * 0.055 + r() * 0.03; b.add(bell(f, 0.3, .35 - k * .015), t, 0.5, r() * 1.6 - .8); } } },
  'ding': { cat: 'accent', dur: 2.2, make: b => { b.add(bell(1318.5, 1.5, .8), 0, .9, 0); b.add(bell(2637, 1, .3), 0, .3, .2); } },
  'magic': { cat: 'accent', dur: 2.0, make: (b, r) => { [72, 76, 79, 84, 88, 91, 96].forEach((m, k) => b.add(bell(X.mtof(m), 0.6, .45), k * 0.07, .5, -0.6 + k * 0.2)); b.add(sweepNoise(1.2, 3000, 9000, 4, r, p => 0.25 * hann(p)), 0, 0.6); } },
  'riser': { cat: 'transition', dur: 2.6, make: (b, r) => { b.add(sweepNoise(2.5, 200, 7000, 3, r, p => Math.pow(p, 2) * (p < .97 ? 1 : (1 - p) / .03)), 0, 1); b.add(tone(2.5, p => 110 * Math.pow(4, p), p => 0.25 * p * p * (p < .97 ? 1 : (1 - p) / .03), 'tri'), 0, 1); } },
  'impact': { cat: 'transition', dur: 2.2, make: (b, r) => { b.add(D.boom(r), 0, 1.2); b.add(D.crash(r), 0, .5); b.add(D.kick(r), 0, .8); } },
  'sub-drop': { cat: 'transition', dur: 1.8, make: b => b.add(tone(1.6, p => 90 * Math.pow(0.35, p), (p, t) => Math.min(1, t / .01) * Math.exp(-t * 2.2)), 0, 1.2) },
  'camera-shutter': { cat: 'ui', dur: 0.4, make: (b, r) => { for (const [t, f, g] of [[0, 2600, 1], [0.085, 1900, .8]]) { const c = X.biquad(X.noise(N(0.05), r), 'bp', f, 2.5); for (let i = 0; i < c.length; i++) c[i] *= Math.exp(-i / SR * 120); b.add(c, t, 1.4 * g); } const m = X.biquad(X.noise(N(0.09), r), 'hp', 1500); for (let i = 0; i < m.length; i++) m[i] *= 0.25 * hann(i / m.length); b.add(m, 0.005, 1); } },
  'cash-register': { cat: 'accent', dur: 1.8, make: (b, r) => { for (const t of [0, 0.06, 0.12]) { const c = X.biquad(X.noise(N(0.03), r), 'bp', 2500, 4); for (let i = 0; i < c.length; i++) c[i] *= Math.exp(-i / SR * 150); b.add(c, t, 1); } b.add(bell(2093, 1.2, .8), 0.2, .7, -.2); b.add(bell(2637, 1.2, .8), 0.2, .6, .2); b.add(D.crash(r), 0.2, .12); } },
  'coin': { cat: 'accent', dur: 0.9, make: b => { b.add(tone(0.08, () => 988, (p, t) => Math.exp(-t * 10), 'sq'), 0, .25); b.add(tone(0.7, () => 1319, (p, t) => Math.exp(-t * 6), 'sq'), 0.08, .25); } },
  'notification': { cat: 'ui', dur: 1.0, make: b => { b.add(I.marimba(X.mtof(84), .2, .9), 0, .8); b.add(I.marimba(X.mtof(91), .3, .9), 0.12, .8); } },
  'success-chime': { cat: 'ui', dur: 1.6, make: b => { [79, 84, 88].forEach((m, k) => b.add(bell(X.mtof(m), .8, .7), k * .09, .5, k * .3 - .3)); } },
  'error-buzz': { cat: 'ui', dur: 0.5, make: b => { for (const t of [0, 0.18]) b.add(tone(0.14, () => 140, (p, tt) => (p < .1 ? p / .1 : 1) * (p > .85 ? (1 - p) / .15 : 1), 'sq'), t, .35); } },
  'boing': { cat: 'accent', dur: 0.8, make: b => b.add(tone(0.7, (p, t) => 220 + 90 * Math.sin(TAU * 12 * t) * Math.exp(-t * 4) + 180 * p, (p, t) => Math.min(1, t / .005) * Math.exp(-t * 5), 'tri'), 0, .8) },
  'typewriter': { cat: 'ui', dur: 1.2, make: (b, r) => { for (let k = 0; k < 9; k++) { const c = X.biquad(X.noise(N(0.025), r), 'bp', 2000 + r() * 1500, 3); for (let i = 0; i < c.length; i++) c[i] *= Math.exp(-i / SR * 250); b.add(c, k * 0.11 + r() * 0.03, 1.1, r() - .5); } b.add(bell(2794, .5, .4), 1.0, .3); } },
  'glitch': { cat: 'transition', dur: 0.6, make: (b, r) => { for (let k = 0; k < 10; k++) { const d = 0.02 + r() * 0.04, f = 100 + r() * 2000; b.add(tone(d, () => f, () => 0.6, 'sq'), k * 0.05, .35, r() * 2 - 1); } b.add(X.biquad(X.noise(N(0.5), r), 'hp', 3000).map((v, i) => v * (((i / 900) | 0) % 2 ? 0.25 : 0)), 0, 1); } },
  'reverse-cymbal': { cat: 'transition', dur: 2.0, make: (b, r) => { const c = D.crash(r).slice(0, N(1.9)).reverse(); b.add(c, 0, 1.6); } },
  'hit-transition': { cat: 'transition', dur: 1.2, make: (b, r) => { b.add(D.kick(r), 0, 1); b.add(D.clap(r), 0, .6); b.add(sweepNoise(0.8, 4000, 600, 1.5, r, p => Math.exp(-p * 5)), 0, .6); } },
  'heartbeat': { cat: 'accent', dur: 1.1, make: (b, r) => { b.add(D.kick(r, 1), 0, .9); b.add(D.kick(r, 1), 0.28, .6); } },
  'tick-tock': { cat: 'accent', dur: 2.1, make: (b, r) => { for (let k = 0; k < 4; k++) b.add(tone(0.04, () => (k % 2 ? 1400 : 1800), (p, t) => Math.exp(-t * 120)), k * 0.5, .7, k % 2 ? .3 : -.3); } },
  'drum-roll': { cat: 'accent', dur: 2.2, make: (b, r) => { const sn = D.snare(r, 1); for (let k = 0; k < 36; k++) b.add(sn, k * 0.045, 0.15 + k * 0.012); b.add(D.crash(r), 1.62, .6); b.add(D.kick(r), 1.62, .9); } },
  'applause-light': { cat: 'accent', dur: 2.5, make: (b, r) => { for (let k = 0; k < 160; k++) { const c = D.clap(r).slice(0, N(0.05)); b.add(c, r() * 2.2, 0.1 + r() * 0.12 * hann(k / 160), r() * 1.6 - .8); } } },
};

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  const A = await import(path.resolve(__dirname, '..', 'app/auto/lib/audio.js'));
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'r3-sfx-')), list = [];
  for (const [id, s] of Object.entries(SFX)) {
    const r = X.rng(X.hash(id)), bus = new Bus(N(s.dur));
    s.make(bus, r);
    // fade tail, normalise peak to -1 dBFS
    const n = bus.n, fl = Math.min(N(0.03), n); for (let i = 0; i < fl; i++) { const g = i / fl; bus.L[n - 1 - i] *= g; bus.R[n - 1 - i] *= g; }
    let pk = 0; for (let i = 0; i < n; i++) pk = Math.max(pk, Math.abs(bus.L[i]), Math.abs(bus.R[i]));
    const g = X.dbToGain(-1) / (pk || 1); for (let i = 0; i < n; i++) { bus.L[i] *= g; bus.R[i] *= g; }
    // the audible peak moment (for syncing the hit to a cut)
    let at = 0, mx = 0; for (let i = 0; i < n; i++) { const v = Math.abs(bus.L[i]) + Math.abs(bus.R[i]); if (v > mx) { mx = v; at = i; } }
    const wav = path.join(tmp, id + '.wav');
    fs.writeFileSync(wav, X.wav16(bus));
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', wav, '-c:a', 'aac', '-b:a', '160k', '-metadata', 'title=' + id, path.join(OUT, id + '.m4a')]);
    const lu = A.loudness([bus.L, bus.R], SR).integrated;
    list.push({ id, file: id + '.m4a', category: s.cat, duration: +(n / SR).toFixed(3), hitAt: +(at / SR).toFixed(3), lufs: +lu.toFixed(1), peakDb: -1, source: 'synth' });
    console.log(id.padEnd(16), s.cat.padEnd(11), (n / SR).toFixed(2) + 's', 'hit@' + (at / SR).toFixed(3), 'LUFS', lu.toFixed(1));
  }
  fs.writeFileSync(path.join(OUT, 'library.json'), JSON.stringify({ v: 1, generated: 'tools/r3-sfx.cjs', licence: 'Original, generated by KBB Studio; free to use', sfx: list }, null, 1));
  fs.rmSync(tmp, { recursive: true, force: true });
}
if (require.main === module) main().catch(e => { console.error(e); process.exit(1); });
module.exports = { SFX };
