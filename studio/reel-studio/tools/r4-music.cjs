'use strict';
// KBB Studio · R4 · "viral reel" music for the library (the owner, 20 Nov: "i want more music like viral reels use in
// the backgrounds of ads"). The STYLES of what short-form ads use — phonk-lite cowbell bounce, trap bounce, pop-house
// with claps, afro-house, future-bass drops, lo-fi hop, Arabic pop with darbuka, cinematic rise + drop, stomp-clap sale
// hype, glossy beauty R&B, deep-house luxury, amapiano log drums, baile-style funk, emotional piano, cute pizzicato,
// corporate motivational — composed here, note by note, by a deterministic synth. No sample, no melody and no
// progression is taken from any real song: chords are generic progressions, hooks are generated from the seed.
//
//   node tools/r4-music.cjs [onlyId]     -> library/music/<id>.m4a + entries in library/music/library.json
//   node tools/r4-music.cjs --waves      -> only (re)writes the `wave` overview of EVERY track in library.json
//
// Every track: drums with real envelopes (kick, snare/clap, hats with velocity and rolls), bass (808 with glides, log
// drum, offbeat house bass …), chords, a hook, and an arrangement intro → build (riser, roll) → drop → break → drop 2.
// Its BODY LOOPS SEAMLESSLY (library.json `loop`, 0 → endingStart): the tails (reverb, delays, long notes) past the last
// bar are folded onto the first bars and the master chain runs in from the loop's end, so the loop's last sample flows
// into its first. After the loop: a WRITTEN ENDING (a final hit on the tonic + a bar of tail, endingStart → musicEnd),
// which Auto music (auto/lib/music.js fitTrack) ends a reel on and "Play once" ends with. Mastered to −14 LUFS
// integrated (K-weighted, the app's own meter; ffmpeg ebur128 reported by tools/music-check.cjs), −1.5 dBFS ceiling,
// AAC 128 kb/s.
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const X = require('./r3-dsp.cjs');
const { SR, TAU, I, D, Bus } = X;
const ROOT = path.resolve(__dirname, '..'), OUT = path.join(ROOT, 'library/music');

const SCALES = { major: [0, 2, 4, 5, 7, 9, 11], minor: [0, 2, 3, 5, 7, 8, 10], dorian: [0, 2, 3, 5, 7, 9, 10], phryg: [0, 1, 3, 5, 7, 8, 10], hijaz: [0, 1, 4, 5, 7, 8, 10], harm: [0, 2, 3, 5, 7, 8, 11] };
const Q = { maj: [0, 4, 7], min: [0, 3, 7], maj7: [0, 4, 7, 11], min7: [0, 3, 7, 10], dom7: [0, 4, 7, 10], sus2: [0, 2, 7], sus4: [0, 5, 7], add9: [0, 4, 7, 14], min9: [0, 3, 7, 10, 14], maj9: [0, 4, 7, 11, 14], min11: [0, 3, 7, 10, 17] };
const NOTE = ['C', 'C#', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B'];
const chord = (s) => { const [r, q] = s.split(':'); return { root: +r, iv: Q[q] }; };
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

// ───────────── extra instruments (mono Float32Array, like r3-dsp's) ─────────────
const N = (s) => Math.max(1, Math.floor(s * SR));
/** 808: punchy pitch drop into a long sine, soft saturation; glideTo (Hz) bends into the next note */
function i808(f, dur, vel = 1, glideTo = 0, drive = 1.8) {
  const n = N(dur + 0.25), o = new Float32Array(n); let ph = 0;
  for (let i = 0; i < n; i++) {
    const t = i / SR; let ff = f * (1 + 1.4 * Math.exp(-t * 55));
    if (glideTo && t > dur - 0.09) ff *= Math.pow(glideTo / f, clamp((t - (dur - 0.09)) / 0.09, 0, 1));
    ph += ff / SR; ph -= Math.floor(ph);
    const env = Math.min(1, t / 0.003) * Math.exp(-t * 0.9) * (t > dur ? Math.max(0, 1 - (t - dur) / 0.25) : 1);
    o[i] = Math.tanh(drive * Math.sin(TAU * ph) + 0.15 * Math.sin(2 * TAU * ph)) * env * 0.8 * vel;
  }
  return o;
}
/** amapiano log drum: woody pitched thump, fast downward bend, short body */
function iLog(f, dur, vel = 1) {
  const n = N(Math.min(dur, 0.6) + 0.15), o = new Float32Array(n); let ph = 0;
  for (let i = 0; i < n; i++) {
    const t = i / SR, ff = f * (1 + 0.9 * Math.exp(-t * 38)); ph += ff / SR; ph -= Math.floor(ph);
    const env = Math.min(1, t / 0.002) * Math.exp(-t * 5.5);
    o[i] = Math.tanh(2.4 * (Math.sin(TAU * ph) + 0.35 * Math.sin(2 * TAU * ph) + 0.12 * Math.sin(3 * TAU * ph))) * env * 0.75 * vel;
  }
  return X.biquad(o, 'lp', 1600, 0.8);
}
/** phonk-style cowbell: two detuned square partials through a band-pass, quick decay */
function iCowbell(f, dur, vel = 1) {
  const n = N(Math.min(0.45, dur + 0.25)), a = X.osc('square', f, n), b = X.osc('square', f * 1.48, n), o = new Float32Array(n);
  for (let i = 0; i < n; i++) { const t = i / SR; o[i] = (a[i] + 0.7 * b[i]) * Math.min(1, t / 0.0015) * (0.6 * Math.exp(-t * 28) + 0.4 * Math.exp(-t * 7)); }
  return X.mul(X.biquad(X.biquad(o, 'bp', f * 1.25, 1.4), 'hp', 300), new Float32Array(n).fill(0.55 * vel));
}
/** synthetic vocal chop ("ah"/"oh"/"ee"): a saw voice through three formants, a little scoop up into the note */
const VOWEL = { a: [800, 1150, 2900], o: [450, 800, 2830], e: [400, 1700, 2600], u: [350, 600, 2700] };
function iVox(f, dur, vel = 0.8, vowel = 'a') {
  const n = N(dur + 0.12), src = new Float32Array(n); let ph = 0;
  for (let i = 0; i < n; i++) { const t = i / SR, ff = f * Math.pow(2, (-0.6 * Math.exp(-t * 30) + 0.08 * Math.sin(TAU * 5.5 * t) * Math.min(1, t / 0.2)) / 12); ph += ff / SR; ph -= Math.floor(ph); src[i] = 2 * ph - 1; }
  const [f1, f2, f3] = VOWEL[vowel] || VOWEL.a, a = X.biquad(src, 'bp', f1, 6), b = X.biquad(src, 'bp', f2, 8), c = X.biquad(src, 'bp', f3, 10), o = new Float32Array(n);
  for (let i = 0; i < n; i++) o[i] = a[i] * 1.0 + b[i] * 0.6 + c[i] * 0.3;
  return X.mul(o, X.mul(X.adsr(n, 0.012, 0.08, 0.75, 0.1, dur), new Float32Array(n).fill(0.9 * vel)));
}
/** supersaw chord stab / future-bass chord: wide saws, filter opening then closing */
function iSaw(f, dur, vel = 0.8, bright = 1) {
  const n = N(dur + 0.25), a = X.osc('saw', f * 1.006, n), b = X.osc('saw', f * 0.994, n, { phase: 0.4 }), c = X.osc('saw', f * 1.0015, n, { phase: 0.77 });
  let o = new Float32Array(n); for (let i = 0; i < n; i++) o[i] = (a[i] + b[i] + c[i]) / 3;
  o = X.svfLP(o, (i) => 500 + bright * 4200 * Math.exp(-i / SR * 5) + 900, 0.9);
  return X.mul(o, X.mul(X.adsr(n, 0.004, 0.25, 0.65, 0.2, dur), new Float32Array(n).fill(0.32 * vel)));
}
/** deep-house organ / chord stab (sine + 2nd + 3rd, short) */
function iStab(f, dur, vel = 0.8) {
  const n = N(dur + 0.2), o = new Float32Array(n);
  for (let i = 0; i < n; i++) { const t = i / SR; o[i] = (Math.sin(TAU * f * t) + 0.5 * Math.sin(TAU * 2 * f * t) + 0.3 * Math.sin(TAU * 3 * f * t) + 0.12 * Math.sin(TAU * 4.01 * f * t)) * Math.min(1, t / 0.003) * Math.exp(-t * 7); }
  return X.mul(X.biquad(o, 'lp', 2400, 0.7), new Float32Array(n).fill(0.32 * vel));
}
/** bright pluck lead (saw, fast filter env) */
function iPluck(f, dur, vel = 0.8) {
  const n = N(dur + 0.3), a = X.osc('saw', f, n), b = X.osc('square', f * 2.002, n, { phase: 0.2 }), o = new Float32Array(n);
  for (let i = 0; i < n; i++) o[i] = a[i] * 0.7 + b[i] * 0.25;
  return X.mul(X.svfLP(o, (i) => 300 + 5200 * Math.exp(-i / SR * 14), 1.1), X.mul(X.adsr(n, 0.002, 0.18, 0.25, 0.2, dur), new Float32Array(n).fill(0.4 * vel)));
}
/** pizzicato string: very short Karplus-Strong, a touch of body */
const iPizz = (f, dur, vel, r) => X.biquad(I.pluck(f, Math.min(dur, 0.18), vel, 0.55, r, 0.985), 'peak', 500, 1, 3);
const iGlock = (f, dur, vel) => { const o = I.bell(f, 0.3, vel); return X.biquad(o, 'hp', 900); };
// drums
function kickPunch(r, tune = 52, decay = 7, click = 0.5) {
  const n = N(0.42), o = new Float32Array(n); let ph = 0;
  for (let i = 0; i < n; i++) { const t = i / SR; ph += (tune + 190 * Math.exp(-t * 34)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * decay) * Math.min(1, t / 0.0008); }
  const c = X.biquad(X.noise(N(0.006), r), 'hp', 3000); for (let i = 0; i < c.length; i++) o[i] += c[i] * (1 - i / c.length) * click;
  for (let i = 0; i < n; i++) o[i] = Math.tanh(o[i] * 1.8) * 0.85;
  return o;
}
function stomp(r) {
  const n = N(0.35), o = new Float32Array(n), nz = X.biquad(X.noise(n, r), 'lp', 700); let ph = 0;
  for (let i = 0; i < n; i++) { const t = i / SR; ph += (70 + 60 * Math.exp(-t * 30)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * 11) * 0.9 + nz[i] * Math.exp(-t * 18) * 1.4; }
  return o;
}
function snap(r) { const n = N(0.07), nz = X.biquad(X.noise(n, r), 'bp', 2600, 2.5), o = new Float32Array(n); for (let i = 0; i < n; i++) { const t = i / SR; o[i] = nz[i] * Math.exp(-t * 75) * 2.2; } return o; }
function conga(r, f = 220) { const n = N(0.3), o = new Float32Array(n); let ph = 0; for (let i = 0; i < n; i++) { const t = i / SR; ph += f * (1 + 0.25 * Math.exp(-t * 40)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * 14) * 0.8; } return o; }
function snareTight(r) { const n = N(0.22), nz = X.biquad(X.biquad(X.noise(n, r), 'hp', 1500), 'lp', 9000), o = new Float32Array(n); for (let i = 0; i < n; i++) { const t = i / SR; o[i] = nz[i] * Math.exp(-t * 26) * 0.8 + Math.sin(TAU * 205 * t) * Math.exp(-t * 35) * 0.5; } return o; }
function riser(len, r) {
  const n = N(len), nz = X.noise(n, r), o = new Float32Array(n); let ph = 0, lp = 0;
  for (let i = 0; i < n; i++) { const x = i / n, fc = 300 + 9000 * x * x, a = Math.exp(-TAU * fc / SR); lp = (1 - a) * nz[i] + a * lp; ph += (180 + 900 * x * x) / SR; o[i] = (lp * 0.9 + 0.12 * Math.sin(TAU * ph)) * x * x * 0.7; }
  return X.biquad(o, 'hp', 200);
}
function impact(r) {
  const n = N(2.2), o = new Float32Array(n), nz = X.biquad(X.noise(n, r), 'lp', 300); let ph = 0;
  for (let i = 0; i < n; i++) { const t = i / SR; ph += (42 + 70 * Math.exp(-t * 9)) / SR; o[i] = Math.sin(TAU * ph) * Math.exp(-t * 2.2) * 0.9 + nz[i] * Math.exp(-t * 5) * 0.8; }
  return o;
}
const reverse = (x) => Float32Array.from(x).reverse();

// ───────────── the styles ─────────────
// drum lines: [inst, 16-step pattern ('x' accent, 'o' normal, 'g' ghost), gain, pan]; lv: which sections play it
// (i intro · b build · d drop · k break · e drop 2). bass: pattern ('R' root, 'O' octave, '5' fifth, 'b' a step below
// the next root; '-' holds), kind. hook: instrument, rhythm style, octave.
const STYLES = {
  phonk: { label: 'Phonk-lite bounce', cat: ['viral', 'hype'], mood: 'viral-phonk', tags: ['phonk', 'cowbell', 'bounce', 'night', 'cars', 'fashion'], scale: 'phryg', swing: 0, half: true,
    prog: ['0:min', '0:min', '8:maj', '7:min'], drums: [['kick', 'x.....x...x.....', 1, 0, 'bde'], ['clap', '........x.......', .8, 0, 'de'], ['snap', '........x.......', .45, .1, 'k'], ['hat', 'o.o.o.o.o.oooo.o', .32, .25, 'ibdke'], ['hatroll', '..............x.', .3, .25, 'de'], ['ohat', '......x.........', .2, -.3, 'e']],
    bass: { pat: 'R-----R---R-----', kind: '808', glide: true }, keys: 'pad', hook: { inst: 'cowbell', rhythm: 'phonk', oct: 1 }, extra: 'vox', energy: 'high' },
  trap: { label: 'Trap bounce', cat: ['viral', 'beauty'], mood: 'viral-trap', tags: ['trap', 'bounce', 'swag', 'fashion', 'confident'], scale: 'minor', swing: 0, half: true,
    prog: ['0:min', '8:maj', '3:maj', '10:maj'], drums: [['kick', 'x......x..x.....', 1, 0, 'bde'], ['snare', '........x.......', .8, 0, 'de'], ['snap', '........x.......', .5, 0, 'dek'], ['hat', 'ooooxoooooooxooo', .28, .25, 'ibdke'], ['hatroll', '......x.......x.', .28, .2, 'de']],
    bass: { pat: 'R------R--R-----', kind: '808', glide: true }, keys: 'bell', hook: { inst: 'pluck', rhythm: 'trap', oct: 1 }, extra: 'vox', energy: 'high' },
  pophouse: { label: 'Pop house', cat: ['upbeat', 'viral', 'hype'], mood: 'pop-house', tags: ['house', 'claps', 'happy', 'sale', 'summer', 'dance'], scale: 'major', swing: 0.04,
    prog: ['9:min', '5:maj', '0:maj', '7:maj'], drums: [['kick', 'x...x...x...x...', 1, 0, 'bde'], ['clap', '....x.......x...', .7, 0, 'bde'], ['ohat', '..o...o...o...o.', .32, .2, 'ide'], ['hat', 'g.g.g.g.g.g.g.g.', .2, -.2, 'dke'], ['shaker', 'gogogogogogogogo', .16, .35, 'kde']],
    bass: { pat: '..R...R...R..RO.', kind: 'pluck' }, keys: 'piano', hook: { inst: 'vox', rhythm: 'pop', oct: 1 }, extra: 'pluck', energy: 'high', pump: 0.55 },
  afro: { label: 'Afro house', cat: ['upbeat', 'viral', 'chill'], mood: 'afro-house', tags: ['afro', 'house', 'groove', 'summer', 'travel', 'warm'], scale: 'dorian', swing: 0.08,
    prog: ['0:min9', '5:min9', '3:maj7', '10:sus2'], drums: [['kick', 'x...x...x...x...', .95, 0, 'bde'], ['rim', '...o..o....o..o.', .45, -.25, 'ide'], ['shaker', 'gogxgogxgogxgogx', .2, .35, 'ibdke'], ['conga', 'o..o..o.o.o..o..', .5, .3, 'dke'], ['congahi', '..o.....o..o..o.', .35, -.35, 'de'], ['ohat', '..o...o...o...o.', .22, .15, 'e']],
    bass: { pat: 'R.....R...R..O..', kind: 'sub' }, keys: 'stab', hook: { inst: 'marimba', rhythm: 'afro', oct: 1 }, extra: 'vox', energy: 'mid', pump: 0.4 },
  futurebass: { label: 'Future bass', cat: ['viral', 'hype', 'upbeat'], mood: 'future-bass', tags: ['future bass', 'drop', 'supersaw', 'reveal', 'glow'], scale: 'major', swing: 0, half: true,
    prog: ['5:maj9', '7:add9', '4:min7', '9:min9'], drums: [['kick', 'x.........x.....', 1, 0, 'bde'], ['snare', '........x.......', .85, 0, 'de'], ['clap', '........x.......', .5, 0, 'de'], ['hat', '..o...o...o...o.', .28, .25, 'ibdke'], ['hatroll', '..............x.', .25, .25, 'e']],
    bass: { pat: 'R-------R---R---', kind: 'saw' }, keys: 'saw', hook: { inst: 'vox', rhythm: 'fb', oct: 1 }, extra: 'pluck', energy: 'high', pump: 0.75 },
  lofi: { label: 'Lo-fi hip hop', cat: ['lofi', 'chill'], mood: 'lo-fi-hop', tags: ['lo-fi', 'chill', 'study', 'cozy', 'skincare'], scale: 'dorian', swing: 0.17, lofi: true,
    prog: ['2:min9', '7:dom7', '0:maj7', '9:min7'], drums: [['kick', 'x......x..x.....', .9, 0, 'bdke'], ['snare', '....o.......o...', .6, 0, 'bde'], ['hat', 'o.o.o.o.o.o.o.oo', .22, .2, 'ibdke'], ['rim', '..............g.', .25, -.2, 'e']],
    bass: { pat: 'R-------R--5----', kind: 'sub' }, keys: 'epiano', hook: { inst: 'epianoLead', rhythm: 'lofi', oct: 1 }, energy: 'low' },
  arabicpop: { label: 'Arabic pop · darbuka', cat: ['arabic', 'upbeat'], mood: 'arabic-pop', tags: ['arabic', 'darbuka', 'oud', 'dubai', 'khaleeji', 'modern'], scale: 'hijaz', swing: 0.03,
    prog: ['0:maj', '1:maj', '10:min', '0:maj'], drums: [['doum', 'x..x...x........', .9, 0, 'bdke'], ['tek', '....o.o.....o.o.', .5, .2, 'ibdke'], ['ka', '.g.g.g.g.g.g.g.g', .22, -.3, 'de'], ['kick', 'x.......x.......', .7, 0, 'de'], ['clap', '....x.......x...', .55, 0, 'de'], ['hat', '..o...o...o...o.', .18, .3, 'e']],
    bass: { pat: 'R..R...R..5.R...', kind: 'sub' }, keys: 'strings', hook: { inst: 'oud', rhythm: 'arab', oct: 0 }, extra: 'ney', energy: 'mid' },
  cinematic: { label: 'Cinematic rise + drop', cat: ['emotional', 'hype'], mood: 'cinematic-reveal', tags: ['cinematic', 'reveal', 'before after', 'trailer', 'epic', 'launch'], scale: 'minor', swing: 0,
    prog: ['0:min', '8:maj', '3:maj', '10:maj'], drums: [['boom', 'x.......x.......', .9, 0, 'de'], ['tom', '......o.....o.o.', .4, .2, 'de'], ['snare', '....o.......o...', .35, 0, 'e'], ['hat', 'g.g.g.g.g.g.g.g.', .14, .3, 'bde']],
    bass: { pat: 'R---------------', kind: 'sub' }, keys: 'ostinato', hook: { inst: 'piano', rhythm: 'slow', oct: 0 }, extra: 'brass', energy: 'high', cine: true },
  stomp: { label: 'Stomp & clap', cat: ['hype', 'upbeat'], mood: 'stomp-clap', tags: ['stomp', 'clap', 'sale', 'hype', 'countdown', 'folk pop'], scale: 'major', swing: 0.02,
    prog: ['0:maj', '7:maj', '9:min', '5:maj'], drums: [['stomp', 'x.......x.......', 1, 0, 'ibdke'], ['clap', '....x.......x...', .8, 0, 'bde'], ['clap2', '....x.......x.x.', .35, .3, 'e'], ['kick', 'x.......x.......', .6, 0, 'de'], ['shaker', 'o.o.o.o.o.o.o.o.', .2, .3, 'de'], ['tamb', '..x...x...x...x.', .2, -.3, 'e']],
    bass: { pat: 'R...R...R...R...', kind: 'pluck' }, keys: 'strum', hook: { inst: 'whistle', rhythm: 'stomp', oct: 1 }, extra: 'vox', energy: 'high' },
  rnb: { label: 'Glossy R&B', cat: ['beauty', 'chill', 'luxury'], mood: 'glossy-rnb', tags: ['r&b', 'beauty', 'glossy', 'skincare', 'makeup', 'smooth'], scale: 'dorian', swing: 0.1, half: true,
    prog: ['5:maj7', '4:min7', '2:min9', '0:maj9'], drums: [['kick', 'x.........x..x..', .9, 0, 'bdke'], ['snap', '........x.......', .7, 0, 'bdke'], ['hat', 'o.o.o.o.o.oooo.o', .2, .25, 'ibdke'], ['rim', '...g.......g....', .25, -.25, 'de']],
    bass: { pat: 'R---------R--O--', kind: '808', glide: true }, keys: 'epiano', hook: { inst: 'bell', rhythm: 'rnb', oct: 1 }, extra: 'vox', energy: 'mid' },
  deephouse: { label: 'Deep house', cat: ['luxury', 'chill', 'beauty'], mood: 'deep-house', tags: ['deep house', 'luxury', 'night', 'rooftop', 'elegant', 'fashion'], scale: 'minor', swing: 0.06,
    prog: ['0:min9', '0:min9', '5:min9', '8:maj7'], drums: [['kick', 'x...x...x...x...', .95, 0, 'ibde'], ['rim', '..o.....o..o....', .4, -.2, 'dke'], ['ohat', '..o...o...o...o.', .26, .2, 'bde'], ['shaker', 'gogogogogogogogo', .15, .35, 'ide'], ['clap', '....o.......o...', .45, 0, 'e']],
    bass: { pat: '..R...R...R..R..', kind: 'sub' }, keys: 'stab', hook: { inst: 'bell', rhythm: 'deep', oct: 1 }, extra: 'pad', energy: 'mid', pump: 0.5 },
  amapiano: { label: 'Amapiano', cat: ['viral', 'upbeat', 'chill'], mood: 'amapiano', tags: ['amapiano', 'log drum', 'piano', 'groove', 'summer', 'dance'], scale: 'dorian', swing: 0.12,
    prog: ['0:min9', '5:min9', '10:dom7', '3:maj9'], drums: [['kick', 'x.......x.......', .8, 0, 'bde'], ['shaker', 'gogogogogogogogo', .22, .35, 'ibdke'], ['rim', '...o..o...o..o..', .35, -.25, 'dke'], ['clap', '........x.......', .45, 0, 'de'], ['ohat', '..o...o...o...o.', .14, .2, 'e']],
    bass: { pat: 'R..R..O...R.R.5.', kind: 'log' }, keys: 'piano', hook: { inst: 'piano', rhythm: 'ama', oct: 1 }, extra: 'pad', energy: 'mid' },
  baile: { label: 'Baile funk', cat: ['hype', 'viral'], mood: 'baile-funk', tags: ['baile', 'funk', 'carnival', 'energy', 'party', 'sale'], scale: 'minor', swing: 0,
    prog: ['0:min', '0:min', '8:maj', '7:maj'], drums: [['kick', 'x..x..x...x..x..', 1, 0, 'bde'], ['clap', '...x..x....x..x.', .6, 0, 'de'], ['tom', '..o.......o.....', .4, .25, 'de'], ['hat', 'oooooooooooooooo', .16, .3, 'ibdke'], ['snare', '........x.......', .4, 0, 'e']],
    bass: { pat: 'R..R..R...R..R..', kind: '808' }, keys: 'none', hook: { inst: 'vox', rhythm: 'baile', oct: 1 }, extra: 'cowbell', energy: 'high' },
  piano: { label: 'Emotional piano', cat: ['emotional', 'corporate'], mood: 'emotional-piano', tags: ['emotional', 'piano', 'story', 'thank you', 'testimonial', 'hope'], scale: 'major', swing: 0,
    prog: ['0:maj', '7:maj', '9:min', '5:maj'], drums: [['kick', 'x.......x.......', .5, 0, 'de'], ['clap', '....o.......o...', .3, 0, 'e'], ['shaker', 'o.o.o.o.o.o.o.o.', .1, .3, 'e']],
    bass: { pat: 'R---------------', kind: 'sub' }, keys: 'arp', hook: { inst: 'piano', rhythm: 'slow', oct: 1 }, extra: 'strings', energy: 'low' },
  pizz: { label: 'Cute pizzicato', cat: ['upbeat', 'beauty'], mood: 'cute-pizzicato', tags: ['cute', 'pizzicato', 'playful', 'kids', 'unboxing', 'tips'], scale: 'major', swing: 0.05,
    prog: ['0:maj', '9:min', '5:maj', '7:maj'], drums: [['kick', 'x.......x.......', .6, 0, 'de'], ['snap', '....o.......o...', .55, 0, 'bdke'], ['shaker', 'g.o.g.o.g.o.g.o.', .14, .3, 'de'], ['tamb', '..o...o...o...o.', .14, -.3, 'e']],
    bass: { pat: 'R...5...R...5...', kind: 'pizz' }, keys: 'pizz', hook: { inst: 'glock', rhythm: 'pizz', oct: 2 }, extra: 'whistle', energy: 'mid' },
  corporate: { label: 'Corporate motivational', cat: ['corporate', 'upbeat'], mood: 'corporate-motivation', tags: ['corporate', 'careers', 'motivational', 'hiring', 'success', 'positive'], scale: 'major', swing: 0,
    prog: ['0:add9', '7:maj', '9:min7', '5:maj9'], drums: [['kick', 'x...x...x...x...', .8, 0, 'bde'], ['clap', '....x.......x...', .55, 0, 'de'], ['hat', '..o...o...o...o.', .22, .25, 'bdke'], ['shaker', 'gogogogogogogogo', .14, -.3, 'ide']],
    bass: { pat: 'R.R.R.R.R.R.R.R.', kind: 'pluck' }, keys: 'piano8', hook: { inst: 'bell', rhythm: 'corp', oct: 1 }, extra: 'guitar', energy: 'mid' },
};
// hook rhythms: [step, length in steps] over 2 bars (32 steps)
const RHY = {
  phonk: [[0, 2], [3, 1], [6, 2], [8, 2], [11, 1], [14, 2], [16, 2], [19, 1], [22, 2], [24, 1], [26, 2], [28, 3]],
  trap: [[0, 3], [3, 3], [6, 2], [10, 2], [12, 4], [16, 3], [19, 3], [22, 2], [24, 6]],
  pop: [[0, 2], [2, 1], [4, 2], [7, 3], [10, 2], [12, 4], [16, 2], [18, 1], [20, 2], [23, 3], [26, 6]],
  afro: [[0, 1], [3, 1], [6, 1], [8, 2], [11, 1], [14, 2], [16, 1], [19, 1], [22, 2], [24, 1], [26, 1], [28, 4]],
  fb: [[0, 2], [3, 2], [6, 2], [8, 4], [12, 2], [14, 2], [16, 2], [19, 2], [22, 2], [24, 8]],
  lofi: [[0, 3], [4, 2], [6, 4], [12, 4], [16, 2], [19, 3], [24, 8]],
  arab: [[0, 1], [1, 1], [2, 2], [4, 2], [6, 1], [7, 1], [8, 4], [12, 4], [16, 1], [17, 1], [18, 2], [20, 2], [22, 2], [24, 8]],
  slow: [[0, 6], [6, 2], [8, 8], [16, 6], [22, 2], [24, 8]],
  stomp: [[0, 2], [2, 2], [4, 4], [8, 2], [10, 2], [12, 4], [16, 2], [18, 2], [20, 4], [24, 8]],
  rnb: [[0, 3], [3, 1], [4, 4], [10, 2], [12, 4], [16, 3], [19, 1], [20, 4], [26, 6]],
  deep: [[2, 2], [6, 2], [10, 4], [18, 2], [22, 2], [26, 6]],
  ama: [[0, 2], [3, 2], [6, 2], [10, 2], [12, 2], [16, 2], [19, 2], [22, 2], [26, 4]],
  baile: [[0, 1], [3, 1], [6, 1], [10, 1], [12, 2], [16, 1], [19, 1], [22, 1], [26, 1], [28, 2]],
  pizz: [[0, 1], [2, 1], [4, 1], [6, 1], [8, 2], [12, 2], [16, 1], [18, 1], [20, 1], [22, 1], [24, 4]],
  corp: [[0, 2], [4, 2], [6, 2], [8, 4], [12, 4], [16, 2], [20, 2], [22, 2], [24, 8]],
};
// the 31 tracks: [style, id, title, key (pitch class), bpm, target seconds]
const TRACKS = [
  ['phonk', 'midnight-bounce', 'Midnight Bounce', 5, 140, 44], ['phonk', 'cherry-cowbell', 'Cherry Cowbell', 1, 144, 40],
  ['trap', 'bounce-back', 'Bounce Back', 9, 142, 44], ['trap', 'gloss-mode', 'Gloss Mode', 4, 136, 46],
  ['pophouse', 'sunlit-swipe', 'Sunlit Swipe', 7, 124, 46], ['pophouse', 'add-to-cart', 'Add to Cart', 2, 126, 38], ['pophouse', 'golden-hour-club', 'Golden Hour Club', 9, 122, 52],
  ['afro', 'lagoon-steps', 'Lagoon Steps', 9, 122, 50], ['afro', 'savanna-glow', 'Savanna Glow', 2, 118, 44],
  ['futurebass', 'sugar-rush-drop', 'Sugar Rush Drop', 0, 150, 42], ['futurebass', 'neon-petals', 'Neon Petals', 5, 146, 48],
  ['lofi', 'matcha-morning', 'Matcha Morning', 5, 82, 50], ['lofi', 'rainy-window', 'Rainy Window', 10, 76, 54],
  ['arabicpop', 'saffron-steps', 'Saffron Steps', 2, 100, 46], ['arabicpop', 'marina-nights', 'Marina Nights', 9, 96, 52], ['arabicpop', 'bahar-groove', 'Bahar Groove', 7, 104, 40],
  ['cinematic', 'the-reveal', 'The Reveal', 2, 92, 42], ['cinematic', 'before-and-after', 'Before and After', 9, 88, 48],
  ['stomp', 'clap-for-the-deal', 'Clap for the Deal', 7, 120, 40], ['stomp', 'last-day-sale', 'Last Day Sale', 0, 126, 34],
  ['rnb', 'lip-gloss', 'Lip Gloss', 3, 92, 48], ['rnb', 'dewy-skin', 'Dewy Skin', 8, 88, 52],
  ['deephouse', 'champagne-tiles', 'Champagne Tiles', 0, 120, 52], ['deephouse', 'velvet-rooftop', 'Velvet Rooftop', 7, 118, 48],
  ['amapiano', 'sunset-log-drum', 'Sunset Log Drum', 5, 112, 50], ['amapiano', 'piano-sway', 'Piano Sway', 10, 110, 46],
  ['baile', 'carnival-cart', 'Carnival Cart', 4, 130, 38],
  ['piano', 'thank-you-note', 'Thank You Note', 0, 76, 50], ['piano', 'her-story', 'Her Story', 7, 72, 56],
  ['pizz', 'tiny-steps', 'Tiny Steps', 0, 112, 40], ['pizz', 'pink-bubbles', 'Pink Bubbles', 5, 116, 36],
  ['corporate', 'career-climb', 'Career Climb', 7, 110, 46], ['corporate', 'offer-letter', 'Offer Letter', 2, 106, 42],
];

/** sections over N bars: intro · build · drop · break · drop 2 */
function arrange(n) {
  const intro = n >= 24 ? 4 : 2, build = 2, brk = n >= 20 ? 4 : 2, rest = n - intro - build - brk, drop = Math.max(4, Math.floor(rest / 2 / 2) * 2), drop2 = rest - drop;
  const out = []; const add = (k, b) => { for (let i = 0; i < b; i++) out.push({ k, i, n: b }); };
  add('i', intro); add('b', build); add('d', drop); add('k', brk); add('e', drop2);
  return out;
}
function voice(ch, key, center, lo = 55, hi = 76) {
  const out = [];
  for (const iv of ch.iv) { const pc = (key + ch.root + iv) % 12; let best = 0, bd = 1e9; for (let m = lo; m <= hi; m++) if (m % 12 === pc && Math.abs(m - center) < bd) { bd = Math.abs(m - center); best = m; } if (best && !out.includes(best)) out.push(best); }
  return out.sort((a, b) => a - b);
}

function render(spec) {
  const S = STYLES[spec.style], r = X.rng(X.hash('r4:' + spec.id)), key = spec.key, bpm = spec.bpm;
  const beat = 60 / bpm, bar = beat * 4, s16 = beat / 4, scale = SCALES[S.scale];
  let nb = Math.round(spec.dur / bar); nb = clamp(nb + (nb % 2), 14, 40);
  const A = arrange(nb), bars = A.length, total = bars * bar, TAIL = 4, n = Math.ceil((total + TAIL) * SR);
  const tt = (b, s) => b * bar + s * s16 + ((s % 2) ? S.swing * s16 : 0);
  const B = { drums: new Bus(n), bass: new Bus(n), keys: new Bus(n), lead: new Bus(n), lead2: new Bus(n), fx: new Bus(n) };
  const kicks = [];
  const lvl = (k) => ({ i: 0, b: 1, d: 2, k: 1, e: 3 }[k]);
  // ── drums ──
  const cache = {};
  const drum = (name, rr) => {
    switch (name) {
      case 'kick': return S.half || S.mood === 'baile-funk' ? kickPunch(rr, 50, 9, 0.7) : kickPunch(rr, 54, 8, 0.5);
      case 'snare': return S.lofi ? D.snare(rr, 1) : snareTight(rr);
      case 'clap': case 'clap2': return D.clap(rr);
      case 'hat': case 'hatroll': return D.hat(rr, 0);
      case 'ohat': return D.hat(rr, 1);
      case 'shaker': return D.shaker(rr);
      case 'tamb': { const o = D.shaker(rr); const t = D.hat(rr, 0); for (let i = 0; i < Math.min(o.length, t.length); i++) o[i] = o[i] * 1.3 + t[i] * 0.8; return o; }
      case 'rim': return D.rim(rr);
      case 'snap': return snap(rr);
      case 'conga': return conga(rr, 196 + ((key * 7) % 5) * 6);
      case 'congahi': return conga(rr, 300);
      case 'stomp': return stomp(rr);
      case 'tom': return D.tom(rr, 120);
      case 'boom': return D.boom(rr);
      default: return D[name](rr);
    }
  };
  const hit = (name, t, g, pan = 0) => { if (/hat|shaker|tamb/.test(name)) g *= 1.35; const k = name + ((r() * 3) | 0); if (!cache[k]) cache[k] = drum(name, X.rng(X.hash(spec.id + k))); B.drums.add(cache[k], t, g, pan); if (/kick|stomp|boom|doum/.test(name)) kicks.push(t); };
  for (let b = 0; b < bars; b++) {
    const sec = A[b], L = lvl(sec.k), lastOfBuild = sec.k === 'b' && sec.i === sec.n - 1;
    for (const [inst, pat, g, pan = 0, where] of S.drums) {
      if (!where.includes(sec.k)) continue;
      for (let s = 0; s < 16; s++) {
        const c = pat[s]; if (c === '.') continue;
        if (lastOfBuild && s >= 8 && /kick|stomp|boom|doum/.test(inst)) continue;   // the gap before the drop
        if (inst === 'hatroll') { const sub = S.half ? 3 : 2; for (let k = 0; k < sub; k++) hit('hat', tt(b, s) + k * s16 / sub, g * (0.6 + 0.4 * k / sub), pan); continue; }
        const v = (c === 'x' ? 1 : c === 'o' ? 0.75 : 0.42) * (0.9 + r() * 0.2) * (sec.k === 'i' ? 0.8 : 1);
        hit(inst, tt(b, s), g * v, pan);
      }
    }
    // build: a snare roll that speeds up, a riser, the drop's impact
    if (sec.k === 'b') {
      const step = sec.i === sec.n - 1 ? 1 : 2;
      for (let s = 0; s < 16; s += step) hit(S.mood === 'arabic-pop' ? 'tek' : S.mood === 'stomp-clap' ? 'clap' : 'snare', tt(b, s), 0.12 + 0.5 * ((sec.i * 16 + s) / (sec.n * 16)), 0);
      if (sec.i === 0) B.fx.add(riser(sec.n * bar, X.rng(X.hash(spec.id + 'rise'))), b * bar, 0.55, 0);
    }
    if ((sec.k === 'd' || sec.k === 'e') && sec.i === 0) { B.fx.add(D.crash(X.rng(7 + b)), b * bar, 0.5, 0.2); if (S.cine || S.energy === 'high') B.fx.add(impact(X.rng(11 + b)), b * bar, S.cine ? 0.8 : 0.45, 0); }
    if (sec.k === 'k' && sec.i === sec.n - 1) B.fx.add(reverse(D.crash(X.rng(5 + b))), (b + 1) * bar - 2.5, 0.4, -0.2);
  }
  // ── harmony + bass ──
  const prog = S.prog.map(chord);
  let center = 64;
  for (let b = 0; b < bars; b++) {
    const sec = A[b], L = lvl(sec.k), ch = prog[b % prog.length], next = prog[(b + 1) % prog.length], v = voice(ch, key, center); center = v.reduce((a, c) => a + c, 0) / v.length;
    const t0 = b * bar, filt = sec.k === 'i' ? 0.45 : sec.k === 'b' ? 0.45 + 0.55 * (sec.i + 1) / sec.n : 1;
    const K = S.keys;
    const keyG = 0.33 * (0.7 + 0.3 * filt);
    if (K === 'piano' || K === 'epiano') {
      const hits = K === 'epiano' ? (sec.k === 'i' ? [0] : [0, 6, 10]) : S.mood === 'amapiano' ? [0, 3, 6, 10, 12] : sec.k === 'i' || sec.k === 'k' ? [0, 8] : [0, 3, 6, 10];
      for (const [j, s] of hits.entries()) { const len = ((hits[j + 1] ?? 16) - s) * s16 * 0.95; v.forEach((m, i) => B.keys.add(I[K === 'epiano' ? 'epiano' : 'piano'](X.mtof(m), len, 0.55 + r() * 0.2), tt(b, s) + i * 0.006, keyG, (i - 1.5) * 0.18)); }
    } else if (K === 'stab') {
      const hits = sec.k === 'i' ? [0] : [0, 3, 6, 10];
      for (const s of hits) v.forEach((m, i) => B.keys.add(iStab(X.mtof(m), s16 * 1.5, 0.8), tt(b, s), keyG * 1.2, (i - 1.5) * 0.2));
    } else if (K === 'saw') {
      const hits = sec.k === 'd' || sec.k === 'e' ? [0, 3, 6, 8, 11, 14] : [0];
      for (const [j, s] of hits.entries()) { const len = sec.k === 'd' || sec.k === 'e' ? ((hits[j + 1] ?? 16) - s) * s16 * 0.8 : bar * 0.95; v.forEach((m, i) => B.keys.add(iSaw(X.mtof(m), len, 0.8, filt), tt(b, s), 0.3, (i - 1.5) * 0.35)); }
    } else if (K === 'pad' || K === 'strings') {
      const inst = K === 'strings' ? I.strings : I.warmpad; v.forEach((m, i) => B.keys.add(inst(X.mtof(m), bar * 0.98, 0.6), t0, 0.42 / Math.sqrt(v.length) * 1.6, (i - 1.5) * 0.25));
    } else if (K === 'bell') {
      for (const s of [0, 6, 10]) v.forEach((m, i) => B.keys.add(I.bell(X.mtof(m + 12), s16 * 3, 0.4), tt(b, s), 0.12, (i - 1.5) * 0.3));
      v.forEach((m, i) => B.keys.add(I.warmpad(X.mtof(m), bar, 0.5), t0, 0.22, (i - 1.5) * 0.3));
    } else if (K === 'ostinato') {
      const root = 48 + ((key + ch.root) % 12);
      for (let s = 0; s < 16; s++) { if (sec.k === 'i' && s % 4) continue; B.keys.add(I.bass(X.mtof(root + (s % 4 === 3 ? 12 : 0)), s16 * 0.9, 0.8, 'pluck'), tt(b, s), 0.26, 0); }
      v.forEach((m, i) => B.keys.add(I.strings(X.mtof(m), bar, 0.7), t0, 0.32, (i - 1.5) * 0.3));
    } else if (K === 'strum') {
      const pat = sec.k === 'i' ? [[0, 1]] : [[0, 1], [3, .55], [6, .8], [10, .6], [12, .9], [14, .5]];
      const sv = voice(ch, key, 60, 52, 72);
      for (const [s, vel] of pat) { const up = s % 4 === 2; (up ? sv.slice().reverse() : sv).forEach((m, i) => B.keys.add(I.guitar(X.mtof(m), s16 * 3, vel * 0.7, r), tt(b, s) + i * 0.011, 0.26, (i - 2) * 0.12)); }
    } else if (K === 'arp') {
      const av = v.concat(v.map((m) => m + 12));
      for (let s = 0; s < 16; s += 2) B.keys.add(I.piano(X.mtof(av[(s / 2) % av.length]), s16 * 4, s % 8 === 0 ? 0.8 : 0.55), tt(b, s), 0.34, ((s / 2) % 2 ? 0.2 : -0.2));
      if (L >= 1) v.forEach((m, i) => B.keys.add(I.strings(X.mtof(m - 12), bar, 0.5 + 0.2 * L), t0, 0.16 * L, (i - 1.5) * 0.3));
    } else if (K === 'pizz') {
      const av = v.concat(v.map((m) => m + 12));
      for (let s = 0; s < 16; s += 2) B.keys.add(iPizz(X.mtof(av[(s / 2) % av.length]), s16 * 2, 0.8, r), tt(b, s), 0.32, ((s / 2) % 2 ? 0.25 : -0.25));
    } else if (K === 'piano8') {
      for (let s = 0; s < 16; s += 2) B.keys.add(I.piano(X.mtof(s % 4 ? v[(s / 2) % v.length] : v[v.length - 1]), s16 * 2, s % 8 === 0 ? 0.8 : 0.55), tt(b, s), 0.32, -0.15);
      if (L >= 2) v.forEach((m, i) => B.keys.add(I.pad(X.mtof(m), bar, 0.5), t0, 0.14, (i - 1.5) * 0.3));
    }
    // a soft pad bed under the intro and the break when the keys are short hits (no gaps, a warmer opening)
    if ((sec.k === 'i' || sec.k === 'k') && !['pad', 'strings', 'bell', 'ostinato', 'arp'].includes(K)) v.forEach((m, i) => B.keys.add(I.warmpad(X.mtof(m), bar * 0.98, 0.5), t0, 0.2, (i - 1.5) * 0.3));
    // bass
    const bassOn = sec.k !== 'i' || S.mood === 'deep-house' || S.mood === 'emotional-piano';
    if (bassOn) {
      const rootPc = (key + ch.root) % 12, nextPc = (key + next.root) % 12, root = 36 + rootPc - (rootPc > 7 ? 12 : 0), nroot = 36 + nextPc - (nextPc > 7 ? 12 : 0);
      const pat = S.bass.pat, kind = S.bass.kind;
      const pts = []; for (let s = 0; s < 16; s++) if ('RO5b'.includes(pat[s])) pts.push(s);
      pts.forEach((s, j) => {
        const c = pat[s], m = root + (c === 'O' ? 12 : c === '5' ? 7 : c === 'b' ? -1 : 0);
        let len = ((pts[j + 1] ?? 16) - s) * s16; if (kind !== '808' && kind !== 'sub') len *= 0.7;
        if (sec.k === 'b' && sec.i === sec.n - 1 && s >= 8) return;
        const lastNote = j === pts.length - 1, g808 = S.bass.glide && lastNote && r() < 0.6 ? X.mtof(nroot) : 0;
        const f = X.mtof(m), vel = sec.k === 'k' ? 0.75 : 1;
        if (kind === '808') B.bass.add(i808(f, len, vel, g808), tt(b, s), 0.62, 0);
        else if (kind === 'log') B.bass.add(iLog(X.mtof(m + 12), len, vel), tt(b, s), 0.7, 0);
        else if (kind === 'saw') { B.bass.add(I.bass(f, len, vel, 'sub'), tt(b, s), 0.55, 0); B.bass.add(iSaw(f * 2, len, 0.6, 0.3), tt(b, s), 0.18, 0); }
        else if (kind === 'pizz') B.bass.add(iPizz(f * 2, len, vel, r), tt(b, s), 0.5, 0);
        else B.bass.add(I.bass(f, len, vel, kind === 'pluck' ? 'pluck' : 'sub'), tt(b, s), kind === 'sub' ? 0.6 : 0.5, 0);
      });
    }
  }
  // ── the hook: a 2-bar motif on the chord tones, answered (bars 3–4 vary the end), seeded per track ──
  const SL = scale.length, base = 60 + key - (key > 6 ? 12 : 0);
  const deg2m = (d) => base + scale[((d % SL) + SL) % SL] + 12 * Math.floor(d / SL);
  const tones = (ch) => { const pcs = new Set(ch.iv.map((iv) => (key + ch.root + iv) % 12)); const out = []; for (let d = -SL; d <= 3 * SL; d++) if (pcs.has(((deg2m(d) % 12) + 12) % 12)) out.push(d); return out; };
  const rhy = RHY[S.hook.rhythm], hr = X.rng(X.hash('hook:' + spec.id));
  const motif = []; let cur = SL + 2 + ((hr() * 3) | 0);
  for (const [s] of rhy) {
    const b = s < 16 ? 0 : 1, ch = prog[b], td = tones(ch);
    let d;
    if (s % 8 === 0 || hr() < 0.45) d = td.reduce((a, c) => (Math.abs(c - cur) < Math.abs(a - cur) ? c : a), td[0]);
    else { const x = hr(); d = cur + (x < 0.45 ? 1 : x < 0.8 ? -1 : x < 0.9 ? 2 : -2); }
    d = clamp(d, SL - 1, 2 * SL + 3); cur = d; motif.push(d);
  }
  const variant = motif.map((d, i) => (i >= motif.length - 3 ? d + (i === motif.length - 1 ? -2 : -1) : d));
  const leadOf = (name) => ({ cowbell: iCowbell, pluck: iPluck, vox: iVox, marimba: I.marimba, epianoLead: I.epiano, oud: I.oud, piano: I.piano, whistle: I.whistle, bell: I.bell, glock: iGlock })[name];
  const leadGain = { cowbell: 0.5, pluck: 0.5, vox: 0.55, marimba: 0.55, epianoLead: 0.4, oud: 0.6, piano: 0.5, whistle: 0.6, bell: 0.4, glock: 0.45 }[S.hook.inst];
  const vowels = ['a', 'o', 'a', 'e'];
  const playHook = (b0, inst, gain, oct, bus, pan = 0.05, notes = motif) => {
    const fn = leadOf(inst);
    rhy.forEach(([s, len], i) => {
      const b = b0 + Math.floor(s / 16), ss = s % 16; if (b >= bars) return;
      const f = X.mtof(deg2m(notes[i]) + 12 * oct - 12), d = len * s16 * 0.92;
      const sig = inst === 'vox' ? iVox(f, d, 0.8, vowels[i % vowels.length]) : fn(f, d, 0.75 + hr() * 0.15, hr);
      bus.add(sig, tt(b, ss), gain, pan);
    });
  };
  for (let b = 0; b < bars; b += 2) {
    const sec = A[b], phase = Math.floor(b / 2) % 2, notes = phase ? variant : motif;
    if (sec.k === 'd' || sec.k === 'e') playHook(b, S.hook.inst, leadGain, S.hook.oct, B.lead, 0.05, notes);
    if (sec.k === 'k' && sec.i === 0) playHook(b, S.hook.inst === 'vox' ? 'epianoLead' : S.hook.inst, leadGain * 0.55, S.hook.oct, B.lead, -0.1, notes);
    if (sec.k === 'i' && sec.i === sec.n - 2 && S.energy !== 'low') playHook(b, S.hook.inst, leadGain * 0.35, S.hook.oct, B.lead, 0, notes);
    // drop 2: the extra layer answers (an octave up, or the style's second voice)
    if (sec.k === 'e' && S.extra) {
      const ex = S.extra;
      if (ex === 'pad') { const v = voice(prog[b % prog.length], key, 64); v.forEach((m, i) => B.lead2.add(I.warmpad(X.mtof(m + 12), bar * 2, 0.5), b * bar, 0.16, (i - 1.5) * 0.4)); }
      else if (ex === 'strings') { const v = voice(prog[b % prog.length], key, 70); v.forEach((m, i) => B.lead2.add(I.strings(X.mtof(m), bar * 2, 0.7), b * bar, 0.2, (i - 1.5) * 0.4)); }
      else if (ex === 'brass') { const rp = (key + prog[b % prog.length].root) % 12; for (const m of [36 + rp, 43 + rp]) B.lead2.add(I.brass(X.mtof(m), bar * 1.8, 0.8), b * bar, 0.3, 0); }
      else if (ex === 'ney') rhy.forEach(([s, len], i) => { const bb = b + Math.floor(s / 16); if (bb < bars && i % 2 === 0) B.lead2.add(I.ney(X.mtof(deg2m(notes[i] + 2)), len * s16 * 1.5, 0.7, hr), tt(bb, s % 16), 0.28, -0.3); });
      else playHook(b, ex === 'guitar' ? 'pluck' : ex, (ex === 'vox' ? 0.32 : 0.3), S.hook.oct + (ex === 'vox' ? 0 : 1), B.lead2, -0.3, notes.map((d) => d + 2));
    }
  }
  // ── mix ──
  if (S.pump) {
    const env = new Float32Array(n).fill(1), att = 0.012 * SR, rel = (S.half ? 0.32 : 0.22) * SR;
    for (const kt of kicks) { const s0 = Math.round(kt * SR); for (let i = 0; i < rel && s0 + i < n; i++) { const x = i < att ? i / att : 1; const g = 1 - S.pump * (i < att ? x : Math.pow(1 - (i - att) / (rel - att), 2)); if (g < env[s0 + i]) env[s0 + i] = g; } }
    B.keys.gainEnv(env); B.lead2.gainEnv(env); if (S.mood !== 'future-bass') { const e2 = env.map((x) => 1 - (1 - x) * 0.6); B.bass.gainEnv(e2); }
  }
  B.bass.map((x) => X.biquad(x, 'lp', S.bass.kind === 'log' ? 1500 : 1100));
  B.keys.map((x) => X.biquad(x, 'hp', 160));
  B.lead.map((x) => X.biquad(x, 'hp', 180));
  B.lead2.map((x) => X.biquad(x, 'hp', 220));
  const g = { drums: 0.78, bass: S.bass.kind === '808' ? 0.42 : 0.62, keys: 0.95, lead: 1.0, lead2: 0.8, fx: 0.55 };
  const sends = { drums: 0.05, bass: 0, keys: 0.22, lead: 0.24, lead2: 0.3, fx: 0.25 };
  const master = new Bus(n), send = new Bus(n);
  for (const k in B) { master.mix(B[k], g[k]); if (sends[k]) send.mix(B[k], sends[k]); }
  master.mix(X.delay(B.lead, beat * 0.75, 0.32, S.lofi ? 0.12 : 0.16), 1);
  master.mix(X.reverb(send, S.cine || S.mood === 'emotional-piano' ? 0.86 : 0.8, 0.4), 0.5);
  if (S.lofi) { master.map((x) => X.biquad(X.biquad(x, 'lp', 5200, 0.6), 'lowshelf', 200, 0.7, 2)); master.add(D.vinyl(n, X.rng(5)), 0, 1, 0); }
  // ── the written ending (Auto music ends a reel on it; "Loop" never plays it): one hit on the tonic, a bar of tail ──
  const endLen = Math.round((2 * bar + 1.0) * SR), EB = new Bus(endLen), ES = new Bus(endLen);
  {
    const er = X.rng(X.hash('end:' + spec.id)), tonic = prog[0], v = voice(tonic, key, 64), rootPc = (key + tonic.root) % 12, root = 36 + rootPc - (rootPc > 7 ? 12 : 0);
    const hitInst = (S.drums.find(([n]) => /kick|stomp|boom|doum/.test(n)) || ['kick'])[0];
    // the hit lands 30 ms after endingStart: the limiter's look-ahead and the true-peak dips (±12 ms) around it never
    // reach back into the loop's last samples, so the loop's seam stays exactly as rendered
    const HIT = 0.03, put = (sig, gain, pan = 0, send = 0.2) => { EB.add(sig, HIT, gain, pan); if (send) ES.add(sig, HIT, gain * send, pan); };
    put(drum(hitInst, X.rng(X.hash(spec.id + 'endk'))), 0.9 * 0.78, 0, 0.05);
    put(D.crash(X.rng(13)), 0.5 * 0.55, 0.2, 0.25);
    if (S.energy === 'high' || S.cine) put(impact(X.rng(17)), 0.4 * 0.55, 0, 0.2);
    v.forEach((m, i) => { put(I.warmpad(X.mtof(m), bar * 1.4, 0.6), 0.2, (i - 1.5) * 0.3, 0.35); put((S.keys === 'stab' ? iStab : S.keys === 'saw' ? (f, d, vel) => iSaw(f, d, vel, 0.6) : S.keys === 'pizz' ? (f, d, vel) => iPizz(f, d, vel, er) : S.keys === 'epiano' ? I.epiano : I.piano)(X.mtof(m), bar * 1.2, 0.75), 0.28, (i - 1.5) * 0.2, 0.25); });
    const bk = S.bass.kind, bf = X.mtof(root);
    put(bk === '808' ? i808(bf, bar * 1.2, 1) : bk === 'log' ? iLog(X.mtof(root + 12), 0.5, 1) : bk === 'pizz' ? iPizz(bf * 2, 0.4, 1, er) : I.bass(bf, bar * 1.1, 0.9, bk === 'pluck' ? 'pluck' : 'sub'), (bk === '808' ? 0.62 * 0.42 : 0.6 * 0.62), 0, 0);
    const fn = leadOf(S.hook.inst), lf = X.mtof(deg2m(SL) + 12 * S.hook.oct - 12);
    put(S.hook.inst === 'vox' ? iVox(lf, bar * 0.9, 0.8, 'o') : fn(lf, bar * 0.9, 0.8, er), leadGain, 0.05, 0.3);
    EB.mix(X.reverb(ES, 0.8, 0.4), 0.5);
    for (let i = endLen - Math.round(0.35 * SR); i < endLen; i++) { const f = (endLen - i) / (0.35 * SR); EB.L[i] *= f; EB.R[i] *= f; }
  }
  // fold everything past the last bar of the body onto its first bars: the body (0 → endingStart) loops seamlessly;
  // the ending carries on from the body's own tails
  const len = Math.round(total * SR), file = new Bus(len + endLen);
  for (let i = 0; i < n; i++) { const j = i % len; file.L[j] += master.L[i]; file.R[j] += master.R[i]; }
  for (let j = 0; j < endLen; j++) { const i = len + j; file.L[i] = (i < n ? master.L[i] : 0) + EB.L[j]; file.R[i] = (i < n ? master.R[i] : 0) + EB.R[j]; }
  return { bus: file, loopLen: len, bpm, bar, beat, bars, A, total: (len + endLen) / SR, endingStart: len / SR, scaleName: S.scale };
}

/** the master chain on a CIRCULAR buffer (the last second runs in before the first): EQ, glue, −14 LUFS, limiter */
async function master(bus, loopLen = bus.n) {
  const A = await import(path.join(ROOT, 'app/auto/lib/audio.js'));
  // the loop's last 2 s run in before the first sample (so the chain's state at 0 = its state at the loop's end)
  const n = bus.n, W = Math.min(loopLen, SR * 2), ext = new Bus(n + 2 * W);
  for (let i = 0; i < W; i++) { ext.L[i] = bus.L[loopLen - W + i]; ext.R[i] = bus.R[loopLen - W + i]; }
  for (let i = 0; i < n; i++) { ext.L[W + i] = bus.L[i]; ext.R[W + i] = bus.R[i]; }
  ext.map((x) => X.biquad(X.biquad(X.biquad(X.biquad(x, 'hp', 32), 'lowshelf', 140, 0.7, -2), 'peak', 3000, 0.8, 2), 'highshelf', 8000, 0.7, 3.5));
  // DC: the high-pass already removes it; also take the mean out exactly
  for (const c of ['L', 'R']) { let m = 0; for (let i = W; i < W + n; i++) m += ext[c][i]; m /= n; for (let i = 0; i < ext.n; i++) ext[c][i] -= m; }
  const mid = (b) => { const o = new Bus(n); o.L = b.L.slice(W, W + n); o.R = b.R.slice(W, W + n); return o; };
  let L = A.loudness([mid(ext).L, mid(ext).R], SR).integrated;
  for (let it = 0; it < 4; it++) {
    const gg = X.dbToGain(-13.75 - L);
    for (let i = 0; i < ext.n; i++) { ext.L[i] = Math.tanh(ext.L[i] * gg * 0.85) / 0.85; ext.R[i] = Math.tanh(ext.R[i] * gg * 0.85) / 0.85; }
    X.limiter(ext, -1.6);
    const m = mid(ext); L = A.loudness([m.L, m.R], SR).integrated;
    if (Math.abs(L + 13.75) < 0.1) break;
  }
  const out = mid(ext);
  let pk = 0; for (let i = 0; i < n; i++) pk = Math.max(pk, Math.abs(out.L[i]), Math.abs(out.R[i]));
  Object.assign(bus, out);
  return { lufs: +L.toFixed(2), peakDb: +(20 * Math.log10(pk)).toFixed(2) };
}
function decode(file, sr = SR) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '2', '-ar', String(sr), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 });
  return new Float32Array(b.buffer, b.byteOffset, b.length / 4);
}
function tpFix(bus, dec) {
  // dec is decoded at 4× the rate: its peaks are the true (inter-sample) peaks of the encoded file
  const ceil = X.dbToGain(-1.6), n = Math.min(bus.n, Math.floor(dec.length / 8)), need = new Float32Array(bus.n).fill(1); let any = false;
  for (let i = 0; i < n; i++) { let p = 0; for (let k = 0; k < 4; k++) p = Math.max(p, Math.abs(dec[2 * (4 * i + k)]), Math.abs(dec[2 * (4 * i + k) + 1])); if (p > ceil) { need[i] = ceil / p * 0.97; any = true; } }
  if (!any) return false;
  const Wn = Math.round(0.012 * SR), gn = new Float32Array(bus.n).fill(1);
  for (let i = 0; i < bus.n; i++) if (need[i] < 1) for (let k = -Wn; k <= Wn; k++) { const j = (i + k + bus.n) % bus.n; const v = need[i] + (1 - need[i]) * Math.pow(Math.abs(k) / Wn, 2); if (v < gn[j]) gn[j] = v; }
  bus.gainEnv(gn); return true;
}
/** 64-bar overview of a file, as a 64-char string ('0'..'9','a'..'z' ~ 0..35 of the loudest bar) */
function waveOf(file) {
  const x = decode(file, 8000), n = x.length / 2, bins = 64, out = new Float32Array(bins);
  for (let b = 0; b < bins; b++) { const a = Math.floor(b * n / bins), e = Math.floor((b + 1) * n / bins); let s = 0; for (let i = a; i < e; i++) s += x[2 * i] * x[2 * i] + x[2 * i + 1] * x[2 * i + 1]; out[b] = Math.sqrt(s / Math.max(1, 2 * (e - a))); }
  const mx = Math.max(...out) || 1;
  return Array.from(out, (v) => Math.round(35 * Math.sqrt(v / mx)).toString(36)).join('');
}

async function main() {
  const libPath = path.join(OUT, 'library.json'), lib = JSON.parse(fs.readFileSync(libPath, 'utf8'));
  if (process.argv[2] === '--waves') {
    for (const t of lib.tracks) t.wave = waveOf(path.join(OUT, t.file));
    fs.writeFileSync(libPath, JSON.stringify(lib, null, 1)); console.log('waves for', lib.tracks.length, 'tracks'); return;
  }
  const only = process.argv[2], tmp = fs.mkdtempSync(path.join(require('os').tmpdir(), 'r4-music-'));
  for (const [style, id, title, key, bpm, dur] of TRACKS) {
    if (only && only !== id) continue;
    const t0 = Date.now(), S = STYLES[style];
    const R = render({ style, id, key, bpm, dur });
    const m = await master(R.bus, R.loopLen);
    const wav = path.join(tmp, id + '.wav'), out = path.join(OUT, id + '.m4a');
    for (let pass = 0; pass < 6; pass++) {
      fs.writeFileSync(wav, X.wav16(R.bus));
      execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', wav, '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', '-metadata', 'title=' + title, '-metadata', 'artist=KBB Studio library', out]);
      if (!tpFix(R.bus, decode(out, SR * 4))) break;
    }
    fs.unlinkSync(wav);
    const beats = []; for (let b = 0; b < (R.bars + 2) * 4; b++) beats.push(+(b * R.beat).toFixed(4));
    const names = { i: 'intro', b: 'build', d: 'drop', k: 'break', e: 'drop' }, sec = [];
    R.A.forEach((a, b) => { const nm = names[a.k], l = sec[sec.length - 1]; if (l && l.name === nm && l.t1 === +(b * R.bar).toFixed(3)) l.t1 = +((b + 1) * R.bar).toFixed(3); else sec.push({ name: nm, t0: +(b * R.bar).toFixed(3), t1: +((b + 1) * R.bar).toFixed(3) }); });
    const E = +R.endingStart.toFixed(3); sec.push({ name: 'ending', t0: E, t1: +(E + 2 * R.bar).toFixed(3) });
    const entry = { id, file: id + '.m4a', title, mood: S.mood, cats: S.cat, genre: style, style: S.label, tags: S.tags, energy: S.energy, bpm, key: NOTE[key] + ' ' + R.scaleName, duration: +R.total.toFixed(3),
      beatsPerBar: 4, firstBeat: 0, barSec: +R.bar.toFixed(4), beats, sections: sec, endingStart: E, musicEnd: +(E + 2 * R.bar).toFixed(3), loop: { a: 0, b: E }, seamless: true,
      lufs: m.lufs, samplePeakDb: m.peakDb, wave: waveOf(out), source: 'synth', licence: 'Original, generated by tools/r4-music.cjs (KBB Studio); free to use' };
    lib.tracks = lib.tracks.filter((t) => t.id !== id).concat(entry);
    console.log(`${id.padEnd(20)} ${style.padEnd(11)} ${bpm}bpm ${R.total.toFixed(1)}s  LUFS ${m.lufs} peak ${m.peakDb}  ${(fs.statSync(out).size / 1024).toFixed(0)} KB  ${((Date.now() - t0) / 1000).toFixed(1)}s`);
  }
  lib.generated = 'tools/r3-music.cjs + tools/r4-music.cjs';
  fs.writeFileSync(libPath, JSON.stringify(lib, null, 1));
  fs.rmSync(tmp, { recursive: true, force: true });
}
if (require.main === module) main().catch((e) => { console.error(e); process.exit(1); });
module.exports = { STYLES, TRACKS };
