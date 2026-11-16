'use strict';
// KBB Studio · R3 · starter music library, composed and rendered OFFLINE by a deterministic synth.
//   node tools/r3-music.cjs [onlyId]      -> library/music/<id>.m4a + library/music/library.json
// Every track: chords + bass + drums/percussion + pad + simple melody, intro / A / B / A2 / musical ending,
// mastered to -14 LUFS integrated with a -1.5 dBFS lookahead limiter, AAC 128k.
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const X = require('./r3-dsp.cjs');
const { SR, I, D, Bus } = X;
const ROOT = path.resolve(__dirname, '..'), OUT = path.join(ROOT, 'library/music');

const SCALES = { major: [0, 2, 4, 5, 7, 9, 11], minor: [0, 2, 3, 5, 7, 8, 10], dorian: [0, 2, 3, 5, 7, 9, 10], mixo: [0, 2, 4, 5, 7, 9, 10],
  hijaz: [0, 1, 4, 5, 7, 8, 10], nahawand: [0, 2, 3, 5, 7, 8, 11], kurd: [0, 1, 3, 5, 7, 8, 10], penta: [0, 2, 4, 7, 9] };
const Q = { maj: [0, 4, 7], min: [0, 3, 7], maj7: [0, 4, 7, 11], min7: [0, 3, 7, 10], dom7: [0, 4, 7, 10], sus2: [0, 2, 7], sus4: [0, 5, 7],
  add9: [0, 4, 7, 14], min9: [0, 3, 7, 10, 14], maj9: [0, 4, 7, 11, 14], m11: [0, 3, 7, 10, 17], six9: [0, 4, 9, 14], dim: [0, 3, 6] };
const NOTE = ['C', 'C#', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B'];
const chord = s => { const [r, q] = s.split(':'); return { root: +r, iv: Q[q], q }; };

// ---- per-mood arrangement presets ----
const MOODS = {
  luxury: { bpm: [84, 94], scale: 'major', prog: ['0:maj9', '9:min9', '5:maj9', '7:sus4'], progB: ['2:min9', '7:dom7', '0:maj9', '9:min7'],
    keys: 'epiano', pad: 'warmpad', bass: 'sub', lead: 'piano', arp: null, kit: 'luxe', swing: 0.04, energy: 'mid', moods2: ['elegant', 'beauty'] },
  'upbeat-pop': { bpm: [112, 124], scale: 'major', prog: ['0:maj', '7:maj', '9:min', '5:maj'], progB: ['5:maj', '0:maj', '7:maj', '9:min'],
    keys: 'pad', pad: 'pad', bass: 'pluck', lead: 'lead', arp: 'marimba', kit: 'pop', swing: 0, energy: 'high', duck: true, moods2: ['happy', 'energetic', 'sale'] },
  'calm-spa': { bpm: [62, 72], scale: 'major', prog: ['0:maj9', '5:maj9', '0:add9', '5:six9'], progB: ['9:min9', '5:maj9', '2:min9', '7:sus2'],
    keys: 'harp', pad: 'warmpad', bass: 'sub', lead: 'flute', arp: 'harp', kit: 'spa', swing: 0, energy: 'low', moods2: ['relaxing', 'skincare', 'soft'] },
  'lo-fi': { bpm: [74, 86], scale: 'dorian', prog: ['2:min9', '7:dom7', '0:maj9', '9:min7'], progB: ['5:maj7', '4:min7', '2:min9', '7:dom7'],
    keys: 'epiano', pad: null, bass: 'sub', lead: 'piano', arp: null, kit: 'lofi', swing: 0.16, energy: 'low', lofi: true, moods2: ['chill', 'study', 'cozy'] },
  corporate: { bpm: [100, 114], scale: 'major', prog: ['0:add9', '7:maj', '9:min7', '5:maj9'], progB: ['5:maj9', '7:sus4', '9:min7', '7:maj'],
    keys: 'piano8', pad: 'pad', bass: 'pluck', lead: 'bell', arp: 'guitar', kit: 'corp', swing: 0, energy: 'mid', duck: true, moods2: ['professional', 'consulting', 'positive'] },
  'arabic-oud': { bpm: [92, 106], scale: 'hijaz', prog: ['0:maj', '1:maj', '10:min', '0:maj'], progB: ['7:min', '5:min', '1:maj', '0:maj'],
    keys: null, pad: 'strings', bass: 'sub', lead: 'oud', lead2: 'ney', arp: 'oudarp', kit: 'darbuka', swing: 0.05, energy: 'mid', moods2: ['arabic', 'middle-east', 'oud'] },
  inspiring: { bpm: [92, 108], scale: 'major', prog: ['9:min', '5:maj', '0:maj', '7:maj'], progB: ['5:maj9', '7:sus4', '9:min7', '0:add9'],
    keys: 'piano8', pad: 'strings', bass: 'sub', lead: 'bell', arp: 'marimba', kit: 'inspire', swing: 0, energy: 'mid', moods2: ['motivational', 'success', 'story'] },
  travel: { bpm: [104, 118], scale: 'major', prog: ['0:maj', '5:maj', '9:min', '7:maj'], progB: ['5:maj', '7:maj', '0:maj', '0:sus4'],
    keys: 'strum', pad: null, bass: 'pluck', lead: 'whistle', arp: 'marimba', kit: 'travel', swing: 0.06, energy: 'high', duck: true, moods2: ['adventure', 'summer', 'vlog'] },
  cinematic: { bpm: [82, 96], scale: 'minor', prog: ['0:min', '8:maj', '3:maj', '10:maj'], progB: ['5:min', '8:maj', '10:maj', '7:sus4'],
    keys: 'ostinato', pad: 'strings', bass: 'sub', lead: 'piano', arp: null, brass: true, kit: 'cine', swing: 0, energy: 'high', moods2: ['epic', 'trailer', 'dramatic'] },
};
// ---- the 27 tracks ----
const TRACKS = [
  ['luxury', 'velvet-hour', 'Velvet Hour', 2, 62], ['luxury', 'golden-serum', 'Golden Serum', 9, 48], ['luxury', 'silk-and-pearl', 'Silk and Pearl', 5, 75],
  ['upbeat-pop', 'glow-up', 'Glow Up', 7, 45], ['upbeat-pop', 'flash-sale-fever', 'Flash Sale Fever', 0, 32], ['upbeat-pop', 'pink-confetti', 'Pink Confetti', 4, 64],
  ['calm-spa', 'morning-ritual', 'Morning Ritual', 5, 70], ['calm-spa', 'lotus-water', 'Lotus Water', 2, 88], ['calm-spa', 'soft-focus', 'Soft Focus', 7, 50],
  ['lo-fi', 'night-routine', 'Night Routine', 2, 60], ['lo-fi', 'sheet-mask-sunday', 'Sheet Mask Sunday', 9, 80], ['lo-fi', 'study-break', 'Study Break', 5, 40],
  ['corporate', 'clear-path', 'Clear Path', 7, 58], ['corporate', 'next-step', 'Next Step', 0, 36], ['corporate', 'open-doors', 'Open Doors', 2, 90],
  ['arabic-oud', 'desert-rose', 'Desert Rose', 2, 60, 'hijaz'], ['arabic-oud', 'souk-lanterns', 'Souk Lanterns', 9, 46, 'nahawand'], ['arabic-oud', 'oud-of-dubai', 'Oud of Dubai', 7, 74, 'kurd'],
  ['inspiring', 'new-horizons', 'New Horizons', 0, 66], ['inspiring', 'first-day', 'First Day', 5, 42], ['inspiring', 'dream-big', 'Dream Big', 2, 84],
  ['travel', 'boarding-pass', 'Boarding Pass', 7, 50], ['travel', 'coastline', 'Coastline', 2, 34], ['travel', 'passport-stamps', 'Passport Stamps', 9, 68],
  ['cinematic', 'the-arrival', 'The Arrival', 2, 56], ['cinematic', 'rise', 'Rise', 9, 38], ['cinematic', 'beyond-borders', 'Beyond Borders', 4, 80],
];

// ---- drum kits: 16-step patterns, [inst, pattern, gain, pan] per level ----
const KITS = {
  luxe: { 1: [['kick', 'x.........x.....', .7], ['hat', '..x...x...x...x.', .25, .3]], 2: [['kick', 'x.....x...x.....', .8], ['rim', '....x.......x...', .45, -.2], ['hat', '..x...x...x...x.', .3, .3], ['shaker', 'x.xxx.xxx.xxx.xx', .25, -.3]] },
  pop: { 1: [['kick', 'x...x...x...x...', .85], ['hat', '..x...x...x...x.', .35, .25]], 2: [['kick', 'x...x...x...x...', .95], ['clap', '....x.......x...', .6], ['hat', '..x...x...x...x.', .4, .25], ['shaker', 'xxxxxxxxxxxxxxxx', .2, -.3]] },
  spa: { 1: [], 2: [['shaker', '..x...x...x...x.', .15, .4]] },
  lofi: { 1: [['kick', 'x......x..x.....', .75], ['hat', 'x.x.x.x.x.x.x.x.', .25, .2]], 2: [['kick', 'x......x..x.....', .8], ['snare', '....x.......x...', .5], ['hat', 'x.x.x.x.x.x.x.xx', .28, .2]] },
  corp: { 1: [['kick', 'x.......x.......', .8], ['shaker', 'xxxxxxxxxxxxxxxx', .18, .3]], 2: [['kick', 'x.......x.x.....', .9], ['clap', '....x.......x...', .55], ['hat', '..x...x...x...x.', .3, -.25], ['shaker', 'xxxxxxxxxxxxxxxx', .2, .3]] },
  darbuka: { 1: [['doum', 'x.......x.......', .8], ['tek', '..x...x.....x...', .45, .2]], 2: [['doum', 'x.......x.......', .9], ['tek', '..x...x.....x...', .55, .2], ['ka', '.x.x.x.x.x.x.x.x', .25, -.3], ['kick', 'x.......x.......', .45]] },
  inspire: { 1: [['tom', 'x.......x.......', .4]], 2: [['kick', 'x...x...x...x...', .8], ['clap', '....x.......x...', .45], ['hat', '..x...x...x...x.', .3, .25], ['tom', '..........x.x.x.', .35, -.2]] },
  travel: { 1: [['shaker', 'xxxxxxxxxxxxxxxx', .25, .3], ['kick', 'x.......x.......', .7]], 2: [['kick', 'x..x..x.x.......', .85], ['clap', '....x.......x...', .55], ['shaker', 'xxxxxxxxxxxxxxxx', .28, .3], ['hat', '..x...x...x...x.', .25, -.25]] },
  cine: { 1: [['boom', 'x.......x.......', .6]], 2: [['boom', 'x.......x.......', .8], ['tom', '......x.....x.x.', .35], ['snare', '....x.......x...', .25]] },
};

function voiceChord(ch, key, prevAvg, lo = 55, hi = 74) {
  const center = prevAvg || 64, notes = [];
  for (const iv of ch.iv) {
    const pc = (key + ch.root + iv) % 12;
    let best = 0, bd = 1e9;
    for (let m = lo; m <= hi; m++) if (m % 12 === pc && Math.abs(m - center) < bd) { bd = Math.abs(m - center); best = m; }
    if (best && !notes.includes(best)) notes.push(best);
  }
  return notes.sort((a, b) => a - b);
}

function render(spec) {
  const M = MOODS[spec.mood], r = X.rng(X.hash(spec.id));
  const bpm = Math.round(M.bpm[0] + r() * (M.bpm[1] - M.bpm[0])), beat = 60 / bpm, bar = beat * 4, s16 = beat / 4;
  const scaleName = spec.scale || M.scale, scale = SCALES[scaleName];
  const key = spec.key; // pitch class
  let bars = Math.max(10, Math.round((spec.dur - 2.5) / bar));
  const introBars = bars >= 18 ? 4 : 2, endBars = 2, body = bars - introBars - endBars;
  // levels per bar
  const lv = [];
  for (let b = 0; b < introBars; b++) lv.push(0);
  const phrases = Math.ceil(body / 4), pat = phrases <= 2 ? [2, 3] : phrases === 3 ? [1, 2, 3] : [1, 2, 3, ...Array(phrases - 4).fill(0).map((_, i) => i % 3 === 1 ? 1 : (i % 2 ? 2 : 3)), 3];
  for (let p = 0, b = 0; b < body; p++) for (let k = 0; k < 4 && b < body; k++, b++) lv.push(pat[Math.min(p, pat.length - 1)]);
  lv.push(4, 5); // ending bars
  bars = lv.length;
  const total = bars * bar + 3.2, n = Math.ceil(total * SR);
  const tt = (b, step) => b * bar + step * s16 + ((step % 2) ? M.swing * s16 : 0);
  const buses = { drums: new Bus(n), keys: new Bus(n), pad: new Bus(n), bass: new Bus(n), lead: new Bus(n), arp: new Bus(n), fx: new Bus(n) };
  const progOf = b => { const sec = Math.floor((b - introBars) / 8); const useB = b >= introBars && sec % 2 === 1 && lv[b] >= 2; return (useB ? M.progB : M.prog).map(chord); };
  const chordAt = b => {
    if (lv[b] === 4) return chord(M.scale === 'minor' || scaleName === 'minor' ? '7:sus4' : scaleName === 'hijaz' || scaleName === 'kurd' || scaleName === 'nahawand' ? '1:maj' : '7:sus4');
    if (lv[b] === 5) return chord(M.prog[0].split(':')[0] + ':' + (M.prog[0].split(':')[1].startsWith('min') ? 'min' : (M.prog[0].split(':')[1] === 'maj' ? 'maj' : 'add9')));
    return progOf(b)[b % 4];
  };
  const kickTimes = [];
  // ---- drums ----
  const kitHits = {};
  const hit = (name, t, g, pan = 0) => { const k = name + ((r() * 3) | 0); if (!kitHits[k]) { const rr = X.rng(X.hash(spec.id + k)); kitHits[k] = name === 'hatOpen' ? D.hat(rr, 1) : D[name](rr, M.kit === 'lofi' || M.kit === 'spa' ? 1 : 0); } buses.drums.add(kitHits[k], t, g, pan); if (name === 'kick' || name === 'boom' || name === 'doum') kickTimes.push(t); };
  const kit = KITS[M.kit];
  for (let b = 0; b < bars; b++) {
    const L = lv[b];
    if (L === 5) { if (M.kit !== 'spa') { hit(M.kit === 'cine' ? 'boom' : M.kit === 'darbuka' ? 'doum' : 'kick', b * bar, .9); } buses.fx.add(D.crash(X.rng(9)), b * bar, M.kit === 'spa' ? .25 : .55, .2); continue; }
    if (L === 0) continue;
    const set = kit[Math.min(2, L >= 3 ? 2 : L === 4 ? 2 : L)] || [];
    for (const [inst, p, g, pan = 0] of set) for (let s = 0; s < 16; s++) if (p[s] === 'x') {
      if (L === 4 && s >= 12 && inst !== 'kick') continue;
      const vel = g * (s % 4 === 0 ? 1 : 0.8) * (0.92 + r() * 0.16) * (L >= 3 ? 1.05 : 1);
      hit(inst, tt(b, s), vel, pan);
    }
    // fill into new phrase / ending
    if ((b + 1 - introBars) % 8 === 0 && L >= 2 && M.kit !== 'spa') for (const s of [12, 13, 14, 15]) hit(M.kit === 'darbuka' ? 'tek' : M.kit === 'cine' ? 'tom' : 'snare', tt(b, s), 0.25 + (s - 12) * 0.08, (s - 13.5) * 0.2);
    if (b === introBars && M.kit !== 'spa') buses.fx.add(D.crash(X.rng(3)), b * bar, .35, -.2);
  }
  // ---- harmony ----
  let prevAvg = 62;
  for (let b = 0; b < bars; b++) {
    const ch = chordAt(b), L = lv[b], v = voiceChord(ch, key, prevAvg); prevAvg = v.reduce((a, c) => a + c, 0) / v.length;
    const t0 = b * bar, last = L === 5, dur = last ? bar * 1.6 : bar;
    // pad
    if (M.pad && (L >= 1 || M.mood === 'calm-spa' || M.pad === 'strings' || M.keys === 'strum' || !M.keys)) {
      const inst = I[M.pad] || I.pad; for (const m of v) buses.pad.add(inst(X.mtof(m), dur * 0.98, L >= 3 ? 0.75 : 0.6), t0, 0.5 / Math.sqrt(v.length), ((m % 7) - 3) * 0.12);
    }
    // keys
    const K = M.keys;
    if (K === 'epiano' || K === 'piano') {
      const hits = M.mood === 'lo-fi' ? [0, 6, 10] : L === 0 ? [0] : [0, 10];
      for (const s of hits) { const d = (s === 0 ? (hits[1] || 16) : 16 - s) * s16; for (const [i, m] of v.entries()) buses.keys.add(I[K](X.mtof(m), last ? dur : d, 0.6 + r() * 0.15), tt(b, s) + i * 0.008, 0.33, (i - 1.5) * 0.15); }
    } else if (K === 'piano8') {
      for (let s = 0; s < 16; s += 2) { if (last && s > 0) break; const m = v[(s / 2) % v.length], hi = v[v.length - 1]; buses.keys.add(I.piano(X.mtof(s % 4 ? m : hi), last ? dur : s16 * 2, (s % 8 === 0 ? .8 : .55)), tt(b, s), L === 0 ? .32 : .38, -.15); }
    } else if (K === 'strum') {
      const sv = voiceChord(ch, key, 60, 52, 71).concat([X.mtof ? 0 : 0]).filter(Boolean);
      const pattern = L === 0 ? [[0, 1]] : [[0, 1], [4, .6], [6, .7], [10, .55], [12, .8], [14, .5]];
      for (const [s, vel] of pattern) { if (last && s > 0) break; const up = s % 4 === 2; const order = up ? sv.slice().reverse() : sv; order.forEach((m, i) => buses.keys.add(I.guitar(X.mtof(m), last ? dur : s16 * 3, vel * 0.7, r), tt(b, s) + i * 0.012, 0.3, (i - 2) * 0.1)); }
    } else if (K === 'harp') {
      for (let s = 0; s < 16; s += 4) { const m = v[(s / 4) % v.length] + 12; buses.keys.add(I.harp(X.mtof(m), bar, 0.55, r), tt(b, s), 0.32, 0.3); if (last) break; }
    } else if (K === 'ostinato') {
      const root = 36 + ((key + ch.root) % 12) + (((key + ch.root) % 12) > 7 ? 0 : 12);
      for (let s = 0; s < 16; s += 2) { if (last && s > 0) break; if (L === 0 && s % 4) continue; buses.keys.add(I.bass(X.mtof(root + (s % 8 === 6 ? 12 : 0)), s16 * 1.6, .8, 'pluck'), tt(b, s), .32, 0); }
    } else if (K === 'pad' && L >= 2 && !last) { // pop: stabs
      for (const s of [0, 3, 6, 10]) for (const m of v) buses.keys.add(I.pluck(X.mtof(m + 12), s16 * 2, .5, .6, r), tt(b, s), .16, .25);
    }
    // bass
    if (L >= 1) {
      const rootPc = (key + ch.root) % 12, root = 36 + rootPc + (rootPc < 4 ? 12 : 0), kind = M.bass;
      const pattern = M.mood === 'upbeat-pop' ? [0, 3, 6, 8, 11, 14] : M.mood === 'travel' ? [0, 6, 8, 12] : M.mood === 'corporate' ? [0, 6, 8, 12, 14] : M.mood === 'arabic-oud' ? [0, 8, 10] : [0, 10];
      const pts = last ? [0] : L === 4 ? [0, 8] : pattern;
      pts.forEach((s, i) => { const next = pts[i + 1] ?? 16; const m = root + (M.mood === 'arabic-oud' && s === 10 ? 7 : 0); buses.bass.add(I.bass(X.mtof(m), last ? dur : (next - s) * s16 * 0.92, 0.9, kind), tt(b, s), kind === 'sub' ? 0.7 : 0.55, 0); });
    }
    // brass braams
    if (M.brass && L >= 3 && b % 2 === 0 && !last) { const rootPc = (key + ch.root) % 12; for (const m of [36 + rootPc, 43 + rootPc]) buses.pad.add(I.brass(X.mtof(m), bar * 1.6, .8), t0, .35, 0); }
    // arp
    if (M.arp && L >= 3 && !last) {
      const av = v.map(m => m + 12).concat(v.map(m => m + 24));
      const step = M.mood === 'upbeat-pop' || M.mood === 'corporate' ? 2 : 2;
      for (let s = 0, i = 0; s < 16; s += step, i++) {
        const m = av[i % av.length];
        if (M.arp === 'guitar') buses.arp.add(I.guitar(X.mtof(m - 12), s16 * 2, .5, r), tt(b, s), .22, .35);
        else if (M.arp === 'oudarp') { if (s % 4 === 0) buses.arp.add(I.harp(X.mtof(m), s16 * 4, .5, r), tt(b, s), .2, .4); }
        else if (M.arp === 'harp') buses.arp.add(I.harp(X.mtof(m), s16 * 2, .4, r), tt(b, s), .18, -.35);
        else buses.arp.add(I.marimba(X.mtof(m), s16 * 2, .5), tt(b, s), .2, (i % 2 ? .3 : -.3));
      }
    }
  }
  // ---- melody ----
  const SL = scale.length;
  const deg2midi = d => 60 + key + scale[((d % SL) + SL) % SL] + 12 * Math.floor(d / SL) + (key > 6 ? -12 : 0);
  const chordDegs = (ch) => { const pcs = new Set(ch.iv.map(iv => (key + ch.root + iv) % 12)); const out = []; for (let d = -SL; d <= SL * 3; d++) if (pcs.has(((deg2midi(d) % 12) + 12) % 12)) out.push(d); return out; };
  const RH = [[0, 4, 6, 8, 12], [0, 2, 4, 8, 10, 12], [0, 6, 8, 12, 14], [0, 3, 6, 8, 11, 12], [0, 4, 8, 10, 12, 14], [0, 8, 10, 12]];
  const slow = M.mood === 'calm-spa' || M.mood === 'luxury' || M.mood === 'cinematic';
  const rA = slow ? [[0, 8], [0, 6, 8, 12]][(r() * 2) | 0] : RH[(r() * RH.length) | 0], rB = slow ? [0, 4, 8, 12] : RH[(r() * RH.length) | 0], cad = [0, 4, 8];
  const phraseSeed = r() * 1e9;
  function phrase(b0, variant, instName, gain, oct = 0) {
    const pr = X.rng((phraseSeed + variant * 7919) >>> 0);
    let cur = SL + 2; const first = [];
    for (let k = 0; k < 4; k++) {
      const b = b0 + k; if (b >= bars || lv[b] < 2 || lv[b] === 4 && k < 3) {/* still play */}
      if (b >= bars) break;
      const ch = chordAt(b), cds = chordDegs(ch), rhy = k === 3 ? cad : k === 1 ? rB : rA;
      rhy.forEach((s, i) => {
        let d;
        if (k === 2 && first[i] !== undefined) d = first[i] + (ch.root - chordAt(b0).root > 0 ? 1 : 0);
        else if (s % 8 === 0) { d = cds.reduce((a, c) => Math.abs(c - cur) < Math.abs(a - cur) ? c : a, cds[0]); }
        else { const x = pr(); d = cur + (x < 0.6 ? (pr() < 0.5 ? 1 : -1) : x < 0.85 ? (pr() < 0.5 ? 2 : -2) : 0); }
        if (k === 3 && i === rhy.length - 1) d = variant % 2 ? SL : cds.reduce((a, c) => Math.abs(c - SL) < Math.abs(a - SL) ? c : a, cds[0]);
        d = Math.max(SL - 3, Math.min(2 * SL + 2, d + (d < cur - 4 ? 2 : d > cur + 4 ? -2 : 0)));
        if (k === 0) first[i] = d;
        cur = d;
        const next = rhy[i + 1] ?? 16, len = (next - s) * s16 * (k === 3 && i === rhy.length - 1 ? 2.2 : 0.9);
        const f = X.mtof(deg2midi(d) + 12 * oct), inst = I[instName];
        buses.lead.add(inst(f, len, 0.7 + pr() * 0.2, pr), tt(b, s), gain, 0.05);
      });
    }
  }
  const leadG = { piano: .42, lead: .55, flute: .5, bell: .32, oud: .5, whistle: .45, ney: .4 }[M.lead] || .4;
  for (let b = introBars, p = 0; b < bars - 2; b += 4, p++) {
    const L = lv[b];
    if (L >= 2) phrase(b, p % 2, M.lead, leadG, M.lead === 'bell' ? 1 : 0);
    if (L >= 3 && M.lead2) phrase(b, p % 2 + 2, M.lead2, .3, 0);
  }
  // ending melody: tonic long note on last bar
  const endT = (bars - 1) * bar;
  buses.lead.add(I[M.lead](X.mtof(deg2midi(SL) + (M.lead === 'bell' ? 12 : 0)), bar * 1.5, .75, r), endT, leadG * 0.9, 0);
  // ---- mix ----
  if (M.duck) {
    const env = new Float32Array(n).fill(1);
    for (const kt of kickTimes) { const s = Math.round(kt * SR); for (let i = 0; i < 0.22 * SR && s + i < n; i++) env[s + i] = Math.min(env[s + i], 0.5 + 0.5 * Math.min(1, i / (0.2 * SR))); }
    buses.pad.gainEnv(env); buses.keys.gainEnv(env); buses.arp.gainEnv(env);
  }
  const send = new Bus(n);
  const sends = { drums: .08, keys: .25, pad: .35, bass: 0, lead: .3, arp: .3, fx: .3 };
  const g = { drums: 0.7, keys: 1.0, pad: 0.6, bass: 0.4, lead: 1.15, arp: 0.75, fx: 0.6 };
  if (M.mood === 'calm-spa') { sends.lead = .55; sends.keys = .45; }
  if (M.mood === 'cinematic') { sends.pad = .5; sends.lead = .5; }
  const master = new Bus(n);
  buses.bass.map(x => X.biquad(x, 'lp', 900));
  buses.pad.map(x => X.biquad(x, 'hp', 140));
  buses.keys.map(x => X.biquad(x, 'hp', 110));
  for (const k in buses) { master.mix(buses[k], g[k]); if (sends[k]) send.mix(buses[k], sends[k]); }
  if (['lead'].length) { const dl = X.delay(buses.lead, beat * 0.75, 0.3, M.mood === 'calm-spa' ? .3 : .16); master.mix(dl, 1); }
  master.mix(X.reverb(send, M.mood === 'calm-spa' || M.mood === 'cinematic' ? 0.86 : 0.8, 0.4), 0.55);
  if (M.lofi) { master.map(x => X.biquad(X.biquad(x, 'lp', 4200, 0.6), 'lowshelf', 200, 0.7, 2)); const v = D.vinyl(n, X.rng(5)); master.add(v, 0, 1, 0); }
  master.map(x => X.biquad(X.biquad(X.biquad(X.biquad(x, 'hp', 35), 'lowshelf', 160, 0.7, -4), 'peak', 2800, 0.8, 2.5), 'highshelf', 9000, 0.7, 2));
  // gentle bus glue (soft clip after makeup)
  return { master, bpm, bar, beat, bars, lv, introBars, total, scaleName };
}

async function master(bus) {
  const A = await import(path.join(ROOT, 'app/auto/lib/audio.js'));
  let L = A.loudness([bus.L, bus.R], SR).integrated;
  for (let it = 0; it < 3; it++) {
    const g = X.dbToGain(-13.8 - L);
    for (let i = 0; i < bus.n; i++) { bus.L[i] = Math.tanh(bus.L[i] * g * 0.9) / 0.9; bus.R[i] = Math.tanh(bus.R[i] * g * 0.9) / 0.9; }
    X.limiter(bus, -1.6);
    L = A.loudness([bus.L, bus.R], SR).integrated;
    if (Math.abs(L + 13.8) < 0.15) break;
  }
  let pk = 0; for (let i = 0; i < bus.n; i++) pk = Math.max(pk, Math.abs(bus.L[i]), Math.abs(bus.R[i]));
  return { lufs: +L.toFixed(2), peakDb: +(20 * Math.log10(pk)).toFixed(2) };
}


function decode(file) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '2', '-ar', String(SR), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 });
  return new Float32Array(b.buffer, b.byteOffset, b.length / 4);
}
/** dip the source where the decoded file peaks above -1.8 dBFS; returns true when anything changed */
function tpFix(bus, dec) {
  const ceil = X.dbToGain(-2.8), n = Math.min(bus.n, dec.length / 2), need = new Float32Array(bus.n).fill(1);
  let any = false;
  for (let i = 0; i < n; i++) { const p = Math.max(Math.abs(dec[2 * i]), Math.abs(dec[2 * i + 1])); if (p > ceil) { need[i] = ceil / p * 0.97; any = true; } }
  if (!any) return false;
  const W = Math.round(0.012 * SR), g = new Float32Array(bus.n).fill(1);
  for (let i = 0; i < bus.n; i++) if (need[i] < 1) for (let k = -W; k <= W; k++) { const j = i + k; if (j < 0 || j >= bus.n) continue; const v = need[i] + (1 - need[i]) * Math.pow(Math.abs(k) / W, 2); if (v < g[j]) g[j] = v; }
  bus.gainEnv(g);
  return true;
}

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  const only = process.argv[2];
  const libPath = path.join(OUT, 'library.json');
  const lib = fs.existsSync(libPath) ? JSON.parse(fs.readFileSync(libPath, 'utf8')) : { v: 1, tracks: [] };
  const tmp = fs.mkdtempSync(path.join(require('os').tmpdir(), 'r3-music-'));
  for (const [mood, id, title, key, dur, scale] of TRACKS) {
    if (only && only !== id) continue;
    const t0 = Date.now();
    const R = render({ mood, id, key, dur, scale });
    const m = await master(R.master);
    const wav = path.join(tmp, id + '.wav'), out = path.join(OUT, id + '.m4a');
    // encode; the AAC encoder can overshoot on sharp transients, so decode, find the spots that came back hotter than
    // -2.8 dBFS and dip the source there (a few ms, inaudible), re-encode. Integrated loudness is unaffected.
    for (let pass = 0; pass < 6; pass++) {
      fs.writeFileSync(wav, X.wav16(R.master));
      execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', wav, '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', '-metadata', 'title=' + title, '-metadata', 'artist=KBB Studio starter library', out]);
      if (!tpFix(R.master, decode(out))) break;
    }
    if (process.env.R3_KEEP_WAV) fs.copyFileSync(wav, path.join(process.env.R3_KEEP_WAV, id + '.wav'));
    fs.unlinkSync(wav);
    const M = MOODS[mood], beats = [];
    for (let b = 0; b < R.bars * 4 + 1; b++) beats.push(+(b * R.beat).toFixed(4));
    const sec = [], names = { 0: 'intro', 1: 'verse', 2: 'build', 3: 'chorus', 4: 'ending', 5: 'ending' };
    R.lv.forEach((l, b) => { const nm = names[l]; const last = sec[sec.length - 1]; if (last && last.name === nm) last.t1 = +((b + 1) * R.bar).toFixed(3); else sec.push({ name: nm, t0: +(b * R.bar).toFixed(3), t1: +((b + 1) * R.bar).toFixed(3) }); });
    const entry = { id, file: id + '.m4a', title, mood, tags: M.moods2, energy: M.energy, bpm: R.bpm, key: NOTE[key] + ' ' + (scale || M.scale), duration: +R.total.toFixed(3),
      beatsPerBar: 4, firstBeat: 0, barSec: +R.bar.toFixed(4), beats, sections: sec, endingStart: +((R.bars - 2) * R.bar).toFixed(3), musicEnd: +(R.bars * R.bar).toFixed(3),
      lufs: m.lufs, samplePeakDb: m.peakDb, source: 'synth', licence: 'Original, generated by tools/r3-music.cjs (KBB Studio); free to use' };
    lib.tracks = lib.tracks.filter(t => t.id !== id).concat(entry);
    console.log(`${id.padEnd(20)} ${mood.padEnd(11)} ${R.bpm}bpm ${R.total.toFixed(1)}s  LUFS ${m.lufs} peak ${m.peakDb}  ${((Date.now() - t0) / 1000).toFixed(1)}s`);
  }
  const order = TRACKS.map(t => t[1]);
  lib.tracks.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
  lib.generated = 'tools/r3-music.cjs'; lib.moods = Object.keys(MOODS);
  fs.writeFileSync(libPath, JSON.stringify(lib, null, 1));
  fs.rmSync(tmp, { recursive: true, force: true });
}
if (require.main === module) main().catch(e => { console.error(e); process.exit(1); });
module.exports = { MOODS, TRACKS };
