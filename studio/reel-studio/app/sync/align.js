// KBB Studio · word-perfect sync — the precise second pass over Whisper's words.
//
//   const r = await alignWords(words, audio16k, { aligner, lang, onProgress });
//   r.words: the SAME words (text, order, every other field kept) with t0/t1 moved to where each is said;
//   r.stats: { aligned, snapped, interpolated, model, ms }
//
// 1. forced alignment (CTC, app/sync/ctc.js) with a wav2vec2 model in a worker — when the model is installed;
// 2. every start/end snapped to the sound's own onset/offset within 60 ms (app/sync/onset.js);
// 3. words stay in order with no overlaps. Sound tags ([Music], ♪) keep their times.
// Without the model (not downloaded yet) step 2 alone runs on Whisper's times.

import { makeTokenizer, specialIds, alignStretch, planStretches } from './ctc.js';
import { envelope, quietestBetween, snapWords } from './onset.js';

const SR = 16000;
const VENDOR = new URL('../../vendor/transformers/', import.meta.url).href;
const MODELS = new URL('../../models/', import.meta.url).href;

/**
 * The alignment model (models/manifest.json id 'word-sync'). Apache-2.0, 56 languages incl. English, Arabic, Persian
 * letters (Urdu folds onto them) and Hindi, 4-bit weights.
 */
export const ALIGN_MODEL = { id: 'word-sync', repo: 'NewComer00/wav2vec2-xlsr-multilingual-56-ONNX', dtype: 'q4', label: 'Word-perfect sync', offset: 0 };

export class Aligner {
  constructor(spec = ALIGN_MODEL, { modelsUrl = MODELS, vendorUrl = VENDOR } = {}) {
    this.spec = spec; this.modelsUrl = modelsUrl; this.vendorUrl = vendorUrl;
    this.worker = null; this.seq = 0; this.pending = new Map(); this.ready = null; this.onProgress = null;
  }
  _call(msg, transfer) {
    const id = ++this.seq;
    return new Promise((res, rej) => { this.pending.set(id, { res, rej }); this.worker.postMessage({ ...msg, id }, transfer || []); });
  }
  load() {
    if (this.ready) return this.ready;
    this.worker = new Worker(new URL('./align-worker.js', import.meta.url), { type: 'module' });
    this.worker.onmessage = (e) => {
      const m = e.data;
      if (m.type === 'progress') { this.onProgress?.(m); return; }
      const p = this.pending.get(m.id); if (!p) return;
      this.pending.delete(m.id);
      m.type === 'error' ? p.rej(new Error(m.message)) : p.res(m);
    };
    this.worker.onerror = (e) => { for (const p of this.pending.values()) p.rej(new Error(e.message || 'sync worker crashed')); this.pending.clear(); };
    const threads = Math.max(1, Math.min(8, navigator.hardwareConcurrency || 4));
    this.ready = this._call({ type: 'load', repo: this.spec.repo, dtype: this.spec.dtype, modelsUrl: this.modelsUrl, vendorUrl: this.vendorUrl, threads })
      .then((r) => { this.vocab = r.vocab; this.tok = makeTokenizer(r.vocab); this.ids = specialIds(r.vocab); this.loadMs = r.loadMs; return this; })
      .catch((e) => { this.dispose(); throw e; });
    return this.ready;
  }
  /** log-probs for the clip (the last answer is kept: re-aligning the same audio after a text edit costs nothing) */
  async emissions(audio, cols) {
    await this.load();
    const key = cols.join(',');
    if (this.last && this.last.audio === audio && this.last.key === key) return this.last.em;
    const em = await this._call({ type: 'emit', audio, cols });
    this.last = { audio, key, em };
    return em;
  }
  dispose() { this.worker?.terminate(); this.worker = null; this.ready = null; for (const p of this.pending.values()) p.rej(new Error('stopped')); this.pending.clear(); }
}

let shared = null;
export const sharedAligner = () => (shared ||= new Aligner());

/**
 * words: [{t0,t1,w,...}] on the clock of `audio` + base (timeline s of audio[0]). opts: { aligner (loaded or not; null =
 * onset snapping only), onProgress(f), signal }.
 */
export async function alignWords(words, audio, opts = {}) {
  const t = performance.now(), base = opts.base || 0, dur = audio.length / SR;
  const env = envelope(audio, SR);
  const list = words.map((w) => ({ t0: w.t0 - base, t1: w.t1 - base }));
  const speech = words.map((w, i) => (w.tag ? -1 : i)).filter((i) => i >= 0);
  let aligned = 0, interpolated = 0, model = null;
  if (opts.aligner && speech.length) {
    const al = await opts.aligner.load();
    model = al.spec.id;
    const sw = speech.map((i) => ({ ...list[i], ids: al.tok(words[i].w) }));
    const cols = [...new Set([al.ids.blank, ...(al.ids.delim != null ? [al.ids.delim] : []), ...sw.flatMap((w) => w.ids)])];
    if (opts.signal?.aborted) throw new DOMException('aborted', 'AbortError');
    al.onProgress = (p) => { if (p.phase === 'align') opts.onProgress?.(p.done / p.total); };
    const em = await al.emissions(audio, cols);
    if (opts.signal?.aborted) throw new DOMException('aborted', 'AbortError');
    const C = em.cols.length, fs = em.frameSec;
    for (const st of planStretches(sw, dur, (lo, hi) => quietestBetween(env, lo, hi))) {
      const f0 = Math.max(0, Math.floor(st.t0 / fs)), f1 = Math.min(em.T, Math.ceil(st.t1 / fs));
      const part = { T: f1 - f0, cols: em.cols, data: em.data.subarray(f0 * C, f1 * C), frameSec: fs, t0: f0 * fs };
      const r = alignStretch(part, sw.slice(st.a, st.b + 1), { blankId: al.ids.blank, delimId: al.ids.delim, offset: al.spec.offset || 0 });
      r.forEach((x, k) => { if (x.ok) { const i = speech[st.a + k]; list[i].t0 = x.t0; list[i].t1 = x.t1; list[i].ok = true; aligned++; } });
    }
    // words the model could not spell (numbers on an alphabet without digits, emoji): placed between their neighbours
    for (let k = 0; k < speech.length; k++) {
      const i = speech[k]; if (list[i].ok) continue;
      const prev = speech.slice(0, k).reverse().map((j) => list[j]).find((w) => w.ok), next = speech.slice(k + 1).map((j) => list[j]).find((w) => w.ok);
      const d = Math.max(0.08, words[i].t1 - words[i].t0);
      if (prev && next && next.t0 > prev.t1) { const g = next.t0 - prev.t1; list[i].t0 = prev.t1 + Math.min(0.02, g / 4); list[i].t1 = Math.min(next.t0, list[i].t0 + d); }
      else if (prev) { list[i].t0 = Math.max(list[i].t0, prev.t1); list[i].t1 = list[i].t0 + d; }
      interpolated++;
    }
  }
  // snap to the sound (speech words only: a tag's times are the music's, not a word's)
  const sp = speech.map((i) => list[i]);
  const snapped = opts.snap === false ? sp.map((w) => ({ ...w, snapped: false })) : snapWords(sp, env);
  speech.forEach((i, k) => { list[i].t0 = snapped[k].t0; list[i].t1 = snapped[k].t1; list[i].snapped = snapped[k].snapped; });
  const out = words.map((w, i) => (w.tag ? { ...w } : { ...w, t0: r3(list[i].t0 + base), t1: r3(list[i].t1 + base) }));
  // keep the whole list monotonic (tags included) and inside the audio
  for (let i = 0; i < out.length; i++) {
    if (i && out[i].t0 < out[i - 1].t0) out[i].t0 = out[i - 1].t0;
    if (out[i].t1 < out[i].t0) out[i].t1 = out[i].t0;
  }
  return { words: out, stats: { aligned, snapped: snapped.filter((w) => w.snapped).length, interpolated, model, words: words.length, ms: Math.round(performance.now() - t) } };
}

const r3 = (x) => Math.round(x * 1000) / 1000;
