// KBB Studio · audio worker: every heavy sound job runs here, off the UI thread.
//   voice   {L, R?, opt}                -> {L, R, stats}       noise suppression (RNNoise) + de-hum + EQ + de-ess + level + compress
//                                                              (opt.v 2: ✨ Enhance voice, voice.js — + room echo, loudness)
//   master  {channels:[L,R], target}    -> {channels, report}  platform loudness (BS.1770) + true-peak limiter
//   measure {channels:[L,R]}            -> {lufs, tp}
//   stretch {channels, segs:[{a,b,rate,len}]} -> {wav}         pitch-kept speed pieces (WSOLA), joined, 16-bit WAV
//   retime  {channels, from, item, len} -> {channels}          a retimed clip's sound (speed, ramp, reverse; pitch kept or tape)
import * as D from './dsp.js';
import { createRnnoise } from './rnnoise.js';
import { enhanceVoice } from './voice.js';
import { srcTime, speedAt, speedGain } from '../core/retime.js';

let rnP = null;
const rnnoise = () => (rnP ||= createRnnoise(new URL('../../vendor/rnnoise-0.2.1/rnnoise.wasm', import.meta.url).href).catch((e) => { rnP = null; throw e; }));

export async function voiceChain(x, opt = {}, rn = null) {
  // ✨ Enhance voice (settings v2): the switchable chain in voice.js
  if (opt.v === 2) return enhanceVoice(x, opt, opt.strength > 0 && opt.stages?.noise !== false ? rn || await rnnoise() : null);
  const sr = 48000, stats = { floorBefore: D.noiseFloorDb(x, sr) };
  let y = x;
  if (opt.dehum !== false) { const r = D.dehum(y, sr); y = r.y; stats.hum = r.base; }
  if ((opt.denoise ?? 1) > 0) { const n = rn || await rnnoise(); y = n.denoise(y, Math.min(1, opt.denoise ?? 1)).y; }
  y = D.eq(y, opt.eq || 'clear', sr);
  if ((opt.deess ?? 0.5) > 0) y = D.deess(y, sr, opt.deess ?? 0.5);
  if (opt.level !== false) y = D.level(y, sr, { target: -20 });
  if (opt.compress !== false) y = D.compress(y, sr, { threshold: -24, ratio: 3, makeup: 2 });
  // keep headroom: never above -1 dBFS sample peak (the export's loudness pass does the true-peak work)
  let pk = 0; for (let i = 0; i < y.length; i++) { const a = Math.abs(y[i]); if (a > pk) pk = a; }
  if (pk > 0.89) { const g = 0.89 / pk; for (let i = 0; i < y.length; i++) y[i] *= g; }
  stats.floorAfter = D.noiseFloorDb(y, sr);
  stats.lufs = D.loudness([y], sr).integrated;
  return { y, stats };
}

self.onmessage = async (e) => {
  const { id, op, args } = e.data || {};
  try {
    let result, transfer = [];
    if (op === 'voice') {
      const { L, R, opt } = args;
      const mono = R && R !== L ? L.map((v, i) => (v + R[i]) / 2) : L;
      const { y, stats } = await voiceChain(mono, opt);
      result = { L: y, stats }; transfer = [y.buffer];
    } else if (op === 'master') {
      const ch = args.channels;
      const report = D.normalizeLoudness(ch, 48000, args.target);
      result = { channels: ch, report }; transfer = ch.map((c) => c.buffer);
    } else if (op === 'measure') {
      result = { lufs: D.loudness(args.channels, 48000).integrated, tp: D.truePeakDb(args.channels) };
    } else if (op === 'stretch') {
      const outs = args.channels.map(() => []);
      for (const s of args.segs) args.channels.forEach((c, k) => {
        let y = D.stretch(c.subarray(s.a, s.b), s.rate);
        if (y.length !== s.len) { const z = new Float32Array(s.len); z.set(y.subarray(0, s.len)); y = z; }
        outs[k].push(y);
      });
      const join = (parts) => { const n = parts.reduce((a, p) => a + p.length, 0), o = new Float32Array(n); let off = 0; for (const p of parts) { o.set(p, off); off += p.length; } return o; };
      const wav = D.wav(outs.map(join), 48000);
      result = { wav }; transfer = [wav];
    } else if (op === 'retime') {
      // a retimed clip's sound: item = {in, out, speed, curve, reverse, keepPitch, rampMute}, channels = the source from
      // sample `from` (the item's source range plus a little margin), len = its timeline length in samples
      const { channels, from, item, len } = args, sr = 48000, it = Object.assign({}, item, { t0: 0 });
      const n = channels[0].length;
      if (it.reverse) for (const c of channels) c.reverse();
      const pos = it.reverse ? (i) => (n - 1) - (srcTime(it, i / sr) * sr - from) : (i) => srcTime(it, i / sr) * sr - from;
      const curved = !!(it.curve && it.curve.pts && it.curve.pts.length >= 2);
      const gain = (i) => speedGain(curved ? speedAt(it, i / sr) : (it.speed || 1), curved && it.rampMute);
      const out = D.retime(channels, pos, len, it.keepPitch !== false, gain, sr);
      result = { channels: out }; transfer = out.map((c) => c.buffer);
    } else throw new Error('unknown op ' + op);
    self.postMessage({ id, result }, transfer);
  } catch (err) { self.postMessage({ id, error: String(err && err.message || err) }); }
};
