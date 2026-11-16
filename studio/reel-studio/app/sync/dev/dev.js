// Lane S dev harness (not shipped in the editor): window.S is what tools/s-check.cjs drives for the measured
// word-timing numbers. Synthetic speech with KNOWN word times: each word is voiced on its own (flite in ffmpeg for
// English, MMS-TTS here for Arabic and Hindustani), then the words are laid end to end with short random gaps and a
// little background noise, so every word's true onset is known to the sample.
import { Transcriber } from '../../captions/stt.js';
import { captureWords } from '../../captions/auto.js';
import { alignWords, Aligner, ALIGN_MODEL } from '../align.js';

const VENDOR = new URL('../../../vendor/transformers/', import.meta.url).href;
const MODELS = new URL('../../../models/', import.meta.url).href;
const SR = 16000;

let ttsLib = null; const tts = {};
async function ttsPipe(lang) {
  if (!ttsLib) {
    ttsLib = await import(VENDOR + 'transformers.min.js');
    Object.assign(ttsLib.env, { allowRemoteModels: false, allowLocalModels: true, localModelPath: MODELS, useBrowserCache: false });
    ttsLib.env.backends.onnx.wasm.wasmPaths = VENDOR;
    ttsLib.env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? 4 : 1;
  }
  if (!tts[lang]) {
    const id = `Xenova/mms-tts-${lang}`;
    const tok = await ttsLib.AutoTokenizer.from_pretrained(id);
    const model = await ttsLib.VitsModel.from_pretrained(id, { dtype: 'fp32' });
    tts[lang] = async (text) => { const { waveform } = await model(tok(text)); return resample(waveform.data, model.config.sampling_rate); };
  }
  return tts[lang];
}
async function resample(data, sr, to = SR) {
  if (sr === to) return Float32Array.from(data);
  const ctx = new OfflineAudioContext(1, Math.ceil(data.length * to / sr), to);
  const b = ctx.createBuffer(1, data.length, sr); b.copyToChannel(Float32Array.from(data), 0);
  const s = ctx.createBufferSource(); s.buffer = b; s.connect(ctx.destination); s.start();
  return (await ctx.startRendering()).getChannelData(0);
}
// seeded random (the clips are the same every run)
const rng = (seed) => () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };

/** trim a voiced word to its sound (-40 dB of its peak); returns {x, on} with `on` = true onset (-30 dB) inside x */
function trimWord(x) {
  let pk = 0; for (const v of x) pk = Math.max(pk, Math.abs(v));
  const lo = pk * 0.01, on = pk * 0.0316;
  let a = 0, b = x.length - 1;
  while (a < x.length && Math.abs(x[a]) < lo) a++;
  while (b > a && Math.abs(x[b]) < lo) b--;
  let o = a; while (o < b && Math.abs(x[o]) < on) o++;
  let e = b; while (e > o && Math.abs(x[e]) < on) e--;
  return { x: x.slice(a, b + 1), on: o - a, off: e - a };
}

export const S = {
  async loadWav(url) {
    const buf = await (await fetch(url)).arrayBuffer();
    const b = await new OfflineAudioContext(1, 1, SR).decodeAudioData(buf);
    return resample(b.getChannelData(0), b.sampleRate);
  },
  /** words voiced one by one (MMS) -> [Float32Array] */
  async voice(words, lang) { const p = await ttsPipe(lang); const out = []; for (const w of words) out.push(await p(w)); return out; },
  /**
   * lay voiced words end to end. parts: [Float32Array] (16 kHz), text: [string] (the caption words, same length).
   * opts: { seed, gap:[min,max] s, sentenceEvery, sentenceGap, noiseDb }. Returns { audio, gt:[{w,t0,t1}], dur }.
   */
  build(parts, text, { seed = 7, gap = [0.0, 0.09], sentenceEvery = 9, sentenceGap = 0.45, noiseDb = -52, lead = 0.6 } = {}) {
    const r = rng(seed), tr = parts.map(trimWord);
    let n = Math.round(lead * SR);
    const at = tr.map((w, i) => { const p = n; n += w.x.length + Math.round(((i + 1) % sentenceEvery === 0 ? sentenceGap : gap[0] + r() * (gap[1] - gap[0])) * SR); return p; });
    n += Math.round(0.8 * SR);
    const audio = new Float32Array(n), nz = Math.pow(10, noiseDb / 20);
    for (let i = 0; i < n; i++) audio[i] = (r() * 2 - 1) * nz;
    tr.forEach((w, i) => { for (let k = 0; k < w.x.length; k++) audio[at[i] + k] += w.x[k]; });
    const gt = tr.map((w, i) => ({ w: text[i], t0: (at[i] + w.on) / SR, t1: (at[i] + w.off) / SR }));
    return { audio, gt, dur: n / SR };
  },
  /** Whisper's words for the clip (the captions pipeline as the editor runs it) */
  async whisper(audio, { lang, model = 'base' } = {}) {
    S.tr ||= new Transcriber();
    const r = await captureWords(S.tr, audio, { model, lang, device: 'wasm', speakers: false });
    return r.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1, tag: w.tag }));
  },
  async align(words, audio, { model = true, snap = true, spec = null } = {}) {
    if (spec) { S.alx ||= {}; S.al = S.alx[spec.repo] ||= new Aligner({ ...ALIGN_MODEL, ...spec }); } else if (model) S.al = (S.alx ||= {})[ALIGN_MODEL.repo] ||= new Aligner(ALIGN_MODEL);
    const t = performance.now();
    const r = await alignWords(words, audio, { aligner: model ? S.al : null, snap });
    return { words: r.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1 })), stats: r.stats, ms: performance.now() - t, loadMs: S.al?.loadMs };
  },
};
/** raw emissions + token ids for offline tuning (tools/out/s/tune) */
S.emissions = async (audio, words, spec = null) => {
  const al = spec ? new Aligner({ ...ALIGN_MODEL, ...spec }) : new Aligner(ALIGN_MODEL);
  await al.load();
  const ids = words.map((w) => al.tok(w.w));
  const cols = [...new Set([al.ids.blank, al.ids.delim, ...ids.flat()].filter((x) => x != null))];
  const em = await al.emissions(audio, cols);
  al.dispose();
  return { T: em.T, cols: em.cols, data: Array.from(em.data, (x) => Math.round(x * 1000) / 1000), frameSec: em.frameSec, ids, blank: al.ids.blank, delim: al.ids.delim };
};
/** 16-bit WAV bytes as base64 (the check keeps each clip for offline re-scoring) */
S.wav64 = (x) => {
  const b = new DataView(new ArrayBuffer(44 + x.length * 2)), w = (o, s) => [...s].forEach((c, i) => b.setUint8(o + i, c.charCodeAt(0)));
  w(0, 'RIFF'); b.setUint32(4, 36 + x.length * 2, true); w(8, 'WAVEfmt '); b.setUint32(16, 16, true); b.setUint16(20, 1, true); b.setUint16(22, 1, true);
  b.setUint32(24, SR, true); b.setUint32(28, SR * 2, true); b.setUint16(32, 2, true); b.setUint16(34, 16, true); w(36, 'data'); b.setUint32(40, x.length * 2, true);
  for (let i = 0; i < x.length; i++) b.setInt16(44 + i * 2, Math.round(Math.max(-1, Math.min(1, x[i])) * 32767), true);
  let s = ''; const u = new Uint8Array(b.buffer); for (let i = 0; i < u.length; i += 0x8000) s += String.fromCharCode(...u.subarray(i, i + 0x8000));
  return btoa(s);
};
window.S = S;
