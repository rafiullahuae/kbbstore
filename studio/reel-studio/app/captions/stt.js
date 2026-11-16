// Speech-to-text client: drives stt-worker.js, cuts long audio into 29 s windows with 5 s overlap,
// stitches the words back together, reports progress and can be cancelled at any moment.

import { SPEECH_MODELS, dtypesFor, pickDevice } from './models.js';
import { uncoveredSpeech } from './nonspeech.js';

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
    const dtype = dtypesFor(device);
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
   * Transcribe mono 16 kHz audio. Returns { words:[{t0,t1,w,conf}], lang, ms, rtf, device, analysis, stats }.
   * opts: { model, lang:'auto'|'en'|'ar'|'hi'|'ur', task:'transcribe'|'translate', onProgress, signal, offset,
   *         prompt (vocabulary sentence for Whisper's initial prompt; {en,ar} or a string), analyse (default true),
   *         debugDrop:[[t0,t1]] (test hook: pretend the first pass skipped those stretches, as Whisper does) }
   *
   * Capture pipeline (captions round):
   *  1. the soundtrack is classified once in the worker (speech / music / silence + frame-level pauses);
   *  2. VAD-gated windows: long silences are skipped, a window that holds nothing loud is never decoded, and each
   *     window ENDS IN A PAUSE when there is one in its last 8 s (so no word is cut at a seam); without a pause the
   *     old 29 s / 5 s-overlap windows split at the middle of the overlap;
   *  3. second listen: every stretch the VAD calls speech that no word covers (and every > 2.5 s hole that still
   *     holds sound) is decoded again on its own, with ≥ 3 s of context, and the words inside it are put back.
   */
  async transcribe(audio, opts = {}) {
    const { model = 'small', task = 'transcribe', onProgress = () => {}, signal, offset = 0, device = 'auto' } = opts;
    let lang = opts.lang && opts.lang !== 'auto' ? opts.lang : null;
    const onAbort = () => this.cancel();
    signal?.addEventListener('abort', onAbort);
    try {
      this.onProgress = (p) => onProgress({ ...p, phase: 'load' });
      const info = await this.load(model, { prefer: device });
      const t0 = performance.now();
      const dur = audio.length / SR;
      const stats = { windows: 0, skipped: 0, reread: 0, recovered: 0, prompted: 0, cuts: 0 };
      const an = opts.analyse === false ? null : (await this._call({ type: 'analyse', audio: audio.slice() })).analysis;
      if (signal?.aborted) throw abortError();
      const plan = planWindows(dur, an);
      if (!lang) {
        const w = loudestWindow(audio, plan.map((p) => p.s));
        lang = (await this._call({ type: 'detect', audio: audio.slice(w * SR, (w + WIN) * SR) })).lang;
      }
      const promptText = typeof opts.prompt === 'string' ? opts.prompt : opts.prompt ? (opts.prompt[lang] || opts.prompt.en || '') : '';
      const run = async (s, e) => {
        const chunk = audio.slice(Math.floor(s * SR), Math.floor(e * SR));
        const r = await this._call({ type: 'run', audio: chunk, offset: s, language: lang, task, prompt: promptText || null }, [chunk.buffer]);
        if (r.prompted) stats.prompted++;
        return r.words;
      };
      let words = [];
      for (let k = 0; k < plan.length; k++) {
        if (signal?.aborted) throw abortError();
        const { s, e, lo, hi } = plan[k];
        onProgress({ phase: 'transcribe', done: k, total: plan.length, t: s, dur });
        // VAD gate: nothing loud in this window -> Whisper would only hallucinate here
        if (an ? !loudIn(an, s, e) : rms(audio.subarray(Math.floor(s * SR), Math.floor(e * SR))) < 0.003) { stats.skipped++; continue; }
        stats.windows++;
        if (plan[k].cut) stats.cuts++;
        const ws = await run(s, e);
        words.push(...ws.filter((w) => { const mid = (w.t0 + w.t1) / 2; return mid >= lo && mid < hi; }));
      }
      if (opts.debugDrop) words = words.filter((w) => !opts.debugDrop.some(([a, b]) => (w.t0 + w.t1) / 2 > a && (w.t0 + w.t1) / 2 < b));
      words.sort((a, b) => a.t0 - b.t0);
      // Second listen. Measured before this existed: whisper-small dropped 16 s of clear speech in one 29 s window.
      const holes = [];
      const edges = [0, ...words.flatMap((w) => [w.t0, w.t1]), dur];
      for (let g = 0; g < edges.length; g += 2) {
        const a = edges[g], b = edges[g + 1];
        if (b - a >= 2.5 && voiced(audio.subarray(Math.floor(a * SR), Math.floor(b * SR))) >= 0.3) holes.push([a, b]);
      }
      if (an) for (const h of uncoveredSpeech(words, an)) holes.push(h);
      holes.sort((x, y) => x[0] - y[0]);
      const merged = [];
      for (const h of holes) { const l = merged[merged.length - 1]; if (l && h[0] <= l[1]) l[1] = Math.max(l[1], h[1]); else merged.push(h.slice()); }
      for (const [a, b] of merged.slice(0, 40)) {
        if (signal?.aborted) throw abortError();
        // at least 3 s of audio around a short hole: Whisper is unreliable on a one-second clip
        const pad = Math.max(0.2, (3 - (b - a)) / 2);
        const s0 = Math.max(0, a - pad), s1 = Math.min(dur, b + pad, s0 + WIN);
        const got = (await run(s0, s1)).filter((w) => { const mid = (w.t0 + w.t1) / 2; return mid > a && mid < b; });
        // never put back a word the first pass already has at the same moment
        const fresh = got.filter((w) => !words.some((x) => Math.abs(x.t0 - w.t0) < 0.25 && x.w.toLowerCase() === w.w.toLowerCase()));
        words.push(...fresh);
        stats.reread++; stats.recovered += fresh.length;
      }
      this.repaired = stats.reread;
      words.sort((a, b) => a.t0 - b.t0);
      words = clean(words, dur).map((w) => ({ ...w, t0: +(w.t0 + offset).toFixed(3), t1: +(w.t1 + offset).toFixed(3) }));
      const ms = performance.now() - t0;
      onProgress({ phase: 'transcribe', done: plan.length, total: plan.length, t: dur, dur });
      return { words, lang, ms, rtf: ms / 1000 / Math.max(0.01, dur), device: info.device, repaired: stats.reread, stats, analysis: an, prompt: promptText,
        model, dtype: info.dtype, loadMs: info.loadMs, threads: info.threads };
    } finally {
      signal?.removeEventListener('abort', onAbort);
      this.onProgress = null;
    }
  }

  /** Best-effort speaker turns (worker): words with spk 0/1 when two voices are told apart. */
  async speakers(audio, words) {
    if (!this.worker) this._spawn();
    return this._call({ type: 'speakers', audio: audio.slice(), words });
  }

  /** The speech / music / silence picture of a soundtrack (worker). */
  async analyse(audio) {
    if (!this.worker) this._spawn();
    return (await this._call({ type: 'analyse', audio: audio.slice() })).analysis;
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

/**
 * Decoding windows: [{s, e, lo, hi, cut}] — decode [s,e], keep words whose middle is in [lo,hi).
 * A window ends in the latest pause (≥ 0.2 s) found in its last 8 s; the next one starts just before that pause and
 * the seam is the pause's middle (no word lives there, so nothing is cut or doubled). With no pause (music under
 * speech, non-stop talk) it falls back to 29 s windows with 5 s overlap, split at the middle of the overlap.
 * Silences of 1.5 s or more are stepped over.
 */
export function planWindows(dur, an) {
  const out = [];
  const pauses = an?.pauses || [];
  let s = 0, lo = -Infinity;
  for (let guard = 0; guard < 10000; guard++) {
    const long = pauses.find((p) => p[0] <= s + 0.05 && p[1] > s && p[1] - Math.max(p[0], s) >= 1.5);
    if (long) { s = Math.max(s, long[1] - 0.3); if (lo !== -Infinity) lo = Math.max(lo, s); }
    if (s >= dur - 0.05) break;
    if (s + WIN >= dur) { out.push({ s, e: dur, lo, hi: Infinity, cut: false }); break; }
    const cands = pauses.filter((p) => { const m = (p[0] + p[1]) / 2; return m > s + WIN - 8 && m < s + WIN - 0.3; });
    if (cands.length) {
      const p = cands[cands.length - 1], m = +((p[0] + p[1]) / 2).toFixed(2);
      out.push({ s, e: Math.min(dur, p[1], s + WIN), lo, hi: m, cut: true });
      lo = m; s = Math.max(s + 1, p[0]);
    } else {
      const next = s + HOP, seam = s + HOP + (WIN - HOP) / 2;
      out.push({ s, e: s + WIN, lo, hi: seam, cut: false });
      lo = seam; s = next;
    }
  }
  if (!out.length) out.push({ s: 0, e: Math.max(dur, 0.01), lo: -Infinity, hi: Infinity, cut: false });
  out[out.length - 1].hi = Infinity;
  return out;
}

function loudIn(an, s, e) {
  const k0 = Math.max(0, Math.floor((s - an.off) / an.hop)), k1 = Math.min(an.loud.length - 1, Math.ceil((e - an.off) / an.hop));
  for (let k = k0; k <= k1; k++) if (an.loud[k]) return true;
  return false;
}
