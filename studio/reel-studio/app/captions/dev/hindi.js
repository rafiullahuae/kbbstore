// Hindi / Hinglish dev harness (not shipped in the editor): window.HI is what tools/hindi-check.cjs drives.
// Test speech comes from the MMS voices (Xenova/mms-tts-hin, -eng), voiced phrase by phrase and laid end to end, so
// the language of every stretch is known.
import { Transcriber } from '../stt.js';
import { captureWords } from '../auto.js';

const VENDOR = new URL('../../../vendor/transformers/', import.meta.url).href;
const MODELS = new URL('../../../models/', import.meta.url).href;
const SR = 16000;

let lib = null; const tts = {};
async function voiceFn(lang) {
  if (!lib) {
    lib = await import(VENDOR + 'transformers.min.js');
    Object.assign(lib.env, { allowRemoteModels: false, allowLocalModels: true, localModelPath: MODELS, useBrowserCache: false });
    lib.env.backends.onnx.wasm.wasmPaths = VENDOR;
    lib.env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? 4 : 1;
  }
  if (!tts[lang]) {
    const id = `Xenova/mms-tts-${lang}`;
    const tok = await lib.AutoTokenizer.from_pretrained(id);
    const model = await lib.VitsModel.from_pretrained(id, { dtype: 'fp32' });
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
const rng = (seed) => () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };

export const HI = {
  tr: new Transcriber(),
  /**
   * parts: [[voice ('hin'|'eng'), text]] voiced one by one and joined with short pauses (+ faint noise).
   * Returns { audio, spans:[{voice, text, t0, t1}], dur }.
   */
  async speech(parts, { gap = 0.35, seed = 5 } = {}) {
    const r = rng(seed), clips = [];
    for (const [v, text] of parts) clips.push({ v, text, x: await (await voiceFn(v))(text) });
    let n = Math.round(0.4 * SR);
    const spans = clips.map((c) => { const t0 = n / SR; n += c.x.length; const s = { voice: c.v, text: c.text, t0, t1: n / SR, at: n - c.x.length }; n += Math.round(gap * SR); return s; });
    n += Math.round(0.5 * SR);
    const audio = new Float32Array(n);
    for (let i = 0; i < n; i++) audio[i] = (r() * 2 - 1) * 0.0025;
    clips.forEach((c, k) => audio.set(c.x.map((v, i) => v + audio[spans[k].at + i]), spans[k].at));
    return { audio, spans: spans.map(({ at, ...s }) => s), dur: n / SR };
  },
  /** per-window language probabilities straight from Whisper (no decoding) */
  async detect(audio, model = 'base') {
    await HI.tr.load(model, { prefer: 'wasm' });
    return HI.tr._call({ type: 'detect', audio: audio.slice() });
  },
  /** words voiced ONE BY ONE (known onsets, like tools/s-sync.cjs): { audio, gt:[{w,t0,t1}], dur } */
  async wordClip(words, { voice = 'hin', seed = 17, gap = [0.0, 0.09], every = 7 } = {}) {
    const p = await voiceFn(voice), r = rng(seed), tr = [];
    for (const w of words) {
      const x = await p(w.replace(/[।?,.]/g, ''));
      let pk = 0; for (const v of x) pk = Math.max(pk, Math.abs(v));
      let a = 0, b = x.length - 1, on = pk * 0.0316;
      while (a < b && Math.abs(x[a]) < pk * 0.01) a++;
      while (b > a && Math.abs(x[b]) < pk * 0.01) b--;
      let o = a; while (o < b && Math.abs(x[o]) < on) o++;
      let e = b; while (e > o && Math.abs(x[e]) < on) e--;
      tr.push({ x: x.slice(a, b + 1), on: o - a, off: e - a });
    }
    let n = Math.round(0.6 * SR);
    const at = tr.map((w, i) => { const q = n; n += w.x.length + Math.round(((i + 1) % every === 0 ? 0.45 : gap[0] + r() * (gap[1] - gap[0])) * SR); return q; });
    n += Math.round(0.8 * SR);
    const audio = new Float32Array(n);
    for (let i = 0; i < n; i++) audio[i] = (r() * 2 - 1) * 0.0025;
    tr.forEach((w, i) => { for (let k = 0; k < w.x.length; k++) audio[at[i] + k] += w.x[k]; });
    return { audio, gt: tr.map((w, i) => ({ w: words[i], t0: (at[i] + w.on) / SR, t1: (at[i] + w.off) / SR })), dur: n / SR };
  },
  async align(words, audio) {
    const { alignWords, Aligner } = await import('../../sync/align.js');
    HI.aligner ||= new Aligner();
    return (await alignWords(words, audio, { aligner: HI.aligner })).words;
  },
  wav64(audio) {
    const b = new ArrayBuffer(44 + audio.length * 2), v = new DataView(b), s = (o, t) => [...t].forEach((c, i) => v.setUint8(o + i, c.charCodeAt(0)));
    s(0, 'RIFF'); v.setUint32(4, 36 + audio.length * 2, true); s(8, 'WAVEfmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
    v.setUint32(24, SR, true); v.setUint32(28, SR * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true); s(36, 'data'); v.setUint32(40, audio.length * 2, true);
    for (let i = 0; i < audio.length; i++) v.setInt16(44 + i * 2, Math.max(-1, Math.min(1, audio[i])) * 32767, true);
    let bin = ''; const u = new Uint8Array(b); for (let i = 0; i < u.length; i += 32768) bin += String.fromCharCode(...u.subarray(i, i + 32768));
    return btoa(bin);
  },
  async transcribe(audio, opts = {}) {
    const r = await captureWords(HI.tr, audio, { model: 'base', device: 'wasm', speakers: false, ...opts });
    return { words: r.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1, ...(w.lang ? { lang: w.lang } : {}) })), lang: r.lang, hinglish: r.hinglish, stats: r.raw.stats, detect: r.raw.detect || null, text: r.words.map((w) => w.w).join(' ') };
  },
};
window.HI = HI;
