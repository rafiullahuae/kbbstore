// Speech-to-text client: drives stt-worker.js, cuts long audio into 29 s windows with 5 s overlap,
// stitches the words back together, reports progress and can be cancelled at any moment.

import { SPEECH_MODELS, dtypesFor, pickDevice } from './models.js';

// 29 s, not 30: a window of exactly 480 000 samples makes the word-timestamp decode collapse
// (measured: 2 words at t=30.0 instead of 70), while 28-29.5 s windows transcribe normally.
const SR = 16000, WIN = 29, HOP = 24;            // seconds; 5 s overlap
const DEFAULT_VENDOR = new URL('../../vendor/transformers/', import.meta.url).href;
const DEFAULT_MODELS = new URL('../../models/', import.meta.url).href;

export const LANGS = [
  { id: 'auto', label: 'Auto-detect' }, { id: 'en', label: 'English' }, { id: 'ar', label: 'العربية Arabic' },
  { id: 'hi', label: 'हिन्दी Hindi' }, { id: 'ur', label: 'اردو Urdu' },
];

export class Transcriber {
  constructor({ modelsUrl = DEFAULT_MODELS, vendorUrl = DEFAULT_VENDOR } = {}) {
    this.modelsUrl = modelsUrl; this.vendorUrl = vendorUrl;
    this.worker = null; this.loaded = null; this.seq = 0; this.pending = new Map(); this.onProgress = null;
  }

  _spawn() {
    this.worker = new Worker(new URL('./stt-worker.js', import.meta.url), { type: 'module' });
    this.worker.onmessage = (e) => {
      const m = e.data;
      if (m.type === 'progress') { this.onProgress?.(m); return; }
      const p = this.pending.get(m.id); if (!p) return;
      this.pending.delete(m.id);
      m.type === 'error' ? p.rej(new Error(m.message)) : p.res(m);
    };
    this.worker.onerror = (e) => { for (const p of this.pending.values()) p.rej(new Error(e.message || 'speech worker crashed')); this.pending.clear(); };
  }

  _call(msg, transfer) {
    const id = ++this.seq;
    return new Promise((res, rej) => { this.pending.set(id, { res, rej }); this.worker.postMessage({ ...msg, id }, transfer || []); });
  }

  /** Load a model ('small' | 'base') on the best device. Re-uses the worker when nothing changed. */
  async load(modelKey = 'small', { prefer = 'auto' } = {}) {
    const { device, f16 } = await pickDevice(prefer);
    const sig = modelKey + '|' + device;
    if (this.loaded && this.loaded.sig === sig) return this.loaded;
    this.dispose();
    this._spawn();
    const dtype = dtypesFor(device, f16);
    const threads = Math.max(1, Math.min(8, (navigator.hardwareConcurrency || 4)));
    let r;
    try {
      r = await this._call({ type: 'load', repo: SPEECH_MODELS[modelKey].repo, device, dtype, modelsUrl: this.modelsUrl, vendorUrl: this.vendorUrl, threads });
    } catch (e) {
      if (device !== 'webgpu') throw e;
      // A GPU that cannot run the graph: fall back to WASM rather than failing.
      return this.load(modelKey, { prefer: 'wasm' });
    }
    this.loaded = { sig, modelKey, device, dtype, loadMs: r.loadMs, threads: r.threads };
    return this.loaded;
  }

  /**
   * Transcribe mono 16 kHz audio. Returns { words:[{t0,t1,w,conf}], lang, ms, rtf, device }.
   * opts: { model, lang:'auto'|'en'|'ar'|'hi'|'ur', task:'transcribe'|'translate', onProgress, signal, offset }
   */
  async transcribe(audio, opts = {}) {
    const { model = 'small', task = 'transcribe', onProgress = () => {}, signal, offset = 0 } = opts;
    let lang = opts.lang && opts.lang !== 'auto' ? opts.lang : null;
    const onAbort = () => this.cancel();
    signal?.addEventListener('abort', onAbort);
    try {
      this.onProgress = (p) => onProgress({ ...p, phase: 'load' });
      const info = await this.load(model);
      this.repaired = 0;
      const t0 = performance.now();
      const dur = audio.length / SR;
      const windows = [];
      for (let s = 0; s < Math.max(dur, 0.01); s += HOP) { windows.push(s); if (s + WIN >= dur) break; }
      if (!lang) {
        const w = loudestWindow(audio, windows);
        lang = (await this._call({ type: 'detect', audio: audio.slice(w * SR, (w + WIN) * SR) })).lang;
      }
      let words = [];
      for (let k = 0; k < windows.length; k++) {
        if (signal?.aborted) throw abortError();
        const s = windows[k], e = Math.min(dur, s + WIN);
        onProgress({ phase: 'transcribe', done: k, total: windows.length, t: s, dur });
        const chunk = audio.slice(Math.floor(s * SR), Math.floor(e * SR));
        if (rms(chunk) < 0.003) continue;          // silence: Whisper would only hallucinate here
        const r = await this._call({ type: 'run', audio: chunk, offset: s, language: lang, task }, [chunk.buffer]);
        // keep the words whose middle falls in this window's own part of the overlap
        const lo = k === 0 ? -Infinity : s + (WIN - HOP) / 2;
        const hi = k === windows.length - 1 ? Infinity : s + HOP + (WIN - HOP) / 2;
        words.push(...r.words.filter((w) => { const mid = (w.t0 + w.t1) / 2; return mid >= lo && mid < hi; }));
      }
      // Hole repair: Whisper sometimes skips a stretch of a window (measured: whisper-small dropped 16 s
      // of clear speech in one 29 s window). Any gap > 2.5 s that still holds speech is transcribed again
      // on its own and the words that fall inside it are put back.
      words.sort((a, b) => a.t0 - b.t0);
      const edges = [0, ...words.flatMap((w) => [w.t0, w.t1]), dur];
      for (let g = 0; g < edges.length; g += 2) {
        const a = edges[g], b = edges[g + 1];
        if (b - a < 2.5 || voiced(audio.subarray(a * SR, b * SR)) < 0.3) continue;
        if (signal?.aborted) throw abortError();
        const s0 = Math.max(0, a - 0.2), s1 = Math.min(dur, b + 0.2, s0 + WIN);
        const chunk = audio.slice(Math.floor(s0 * SR), Math.floor(s1 * SR));
        const r = await this._call({ type: 'run', audio: chunk, offset: s0, language: lang, task }, [chunk.buffer]);
        words.push(...r.words.filter((w) => { const mid = (w.t0 + w.t1) / 2; return mid > a && mid < b; }));
        this.repaired = (this.repaired || 0) + 1;
      }
      words.sort((a, b) => a.t0 - b.t0);
      words = clean(words, dur).map((w) => ({ ...w, t0: +(w.t0 + offset).toFixed(3), t1: +(w.t1 + offset).toFixed(3) }));
      const ms = performance.now() - t0;
      onProgress({ phase: 'transcribe', done: windows.length, total: windows.length, t: dur, dur });
      return { words, lang, ms, rtf: ms / 1000 / Math.max(0.01, dur), device: info.device, repaired: this.repaired, model, dtype: info.dtype, loadMs: info.loadMs, threads: info.threads };
    } finally {
      signal?.removeEventListener('abort', onAbort);
      this.onProgress = null;
    }
  }

  /** Stop now: the worker is terminated (the next run reloads the model from disk). */
  cancel() {
    if (!this.worker) return;
    for (const p of this.pending.values()) p.rej(abortError());
    this.pending.clear();
    this.dispose();
  }

  dispose() { this.worker?.terminate(); this.worker = null; this.loaded = null; }
}

const abortError = () => { const e = new Error('Cancelled'); e.name = 'AbortError'; return e; };

function rms(a) { let s = 0; for (let i = 0; i < a.length; i += 4) s += a[i] * a[i]; return Math.sqrt(s / Math.max(1, a.length / 4)); }

/** Share of 50 ms frames that carry sound (a crude VAD, enough to tell a pause from skipped speech). */
function voiced(a) {
  const f = 800; let on = 0, n = 0;
  for (let i = 0; i + f <= a.length; i += f, n++) if (rms(a.subarray(i, i + f)) > 0.02) on++;
  return n ? on / n : 0;
}

function loudestWindow(audio, windows) {
  let best = 0, bv = -1;
  for (const s of windows.slice(0, 6)) { const v = rms(audio.subarray(s * SR, (s + WIN) * SR)); if (v > bv) { bv = v; best = s; } }
  return best;
}

/**
 * Tidy the stitched words: monotonic times, a minimum length, no stuttered repeats across a window
 * seam, and a heuristic confidence (Whisper's word timing is unreliable on very short / very long
 * words and on repeats — those are what the editor highlights for checking).
 */
export function clean(words, dur = Infinity) {
  const out = [];
  for (const w0 of words) {
    const w = { ...w0 };
    const prev = out[out.length - 1];
    if (prev && w.w.toLowerCase() === prev.w.toLowerCase() && w.t0 - prev.t0 < 0.3) continue; // seam duplicate
    if (prev && w.t0 < prev.t1) w.t0 = Math.max(prev.t0 + 0.01, Math.min(w.t0, prev.t1));
    if (prev && w.t0 < prev.t1) prev.t1 = w.t0;
    if (!(w.t1 > w.t0)) w.t1 = w.t0 + 0.12;
    w.t1 = Math.min(w.t1, dur);
    if (w.t0 >= dur) continue;
    const d = w.t1 - w.t0, letters = w.w.replace(/[^\p{L}\p{N}]/gu, '').length;
    let conf = 1;
    if (d < 0.06 && letters > 2) conf -= 0.4;
    if (d > 1.8) conf -= 0.35;
    if (!letters) conf -= 0.3;
    if (prev && prev.w.toLowerCase() === w.w.toLowerCase()) conf -= 0.25;
    w.conf = +Math.max(0.05, conf).toFixed(2);
    out.push(w);
  }
  return out;
}
