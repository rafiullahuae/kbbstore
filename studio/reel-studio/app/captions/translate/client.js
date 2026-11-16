// Translation client: drives translate/worker.js (one model loaded at a time, re-used while the pair stays the same),
// cancellable, with load progress. The engines themselves are described in models.js.

const DEFAULT_VENDOR = new URL('../../../vendor/transformers/', import.meta.url).href;
const DEFAULT_MODELS = new URL('../../../models/', import.meta.url).href;

export class Translator {
  constructor({ modelsUrl = DEFAULT_MODELS, vendorUrl = DEFAULT_VENDOR } = {}) {
    this.modelsUrl = modelsUrl; this.vendorUrl = vendorUrl;
    this.worker = null; this.loadedKey = ''; this.seq = 0; this.pending = new Map(); this.onProgress = null;
  }
  _spawn() {
    this.worker = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
    this.worker.onmessage = (e) => {
      const m = e.data;
      if (m.progress) { this.onProgress?.(m.progress); return; }
      const p = this.pending.get(m.id); if (!p) return;
      this.pending.delete(m.id);
      m.error ? p.rej(new Error(m.error)) : p.res(m.result);
    };
    this.worker.onerror = (e) => { for (const p of this.pending.values()) p.rej(new Error(e.message || 'translation worker crashed')); this.pending.clear(); this.loadedKey = ''; };
  }
  _call(msg) {
    if (!this.worker) this._spawn();
    const id = ++this.seq;
    return new Promise((res, rej) => { this.pending.set(id, { res, rej }); this.worker.postMessage({ ...msg, id }); });
  }
  /** Load an engine (a models.js ENGINES entry). Re-uses the worker when it is already loaded. */
  async load(engine, { device = 'wasm' } = {}) {
    const key = engine.id + '|' + device;
    if (this.loadedKey === key) return { ms: 0 };
    const threads = Math.max(1, Math.min(8, (typeof navigator !== 'undefined' && navigator.hardwareConcurrency) || 4));
    const r = await this._call({ op: 'load', kind: engine.kind, repo: engine.repo, device, dtype: engine.dtype || 'q8', modelsUrl: this.modelsUrl, vendorUrl: this.vendorUrl, threads });
    this.loadedKey = key;
    return r;
  }
  /** Translate sentences with the loaded seq2seq engine. opts: { src, tgt, langTag, max, beams } */
  tr(texts, opts = {}) { return this._call({ op: 'tr', texts, ...opts }); }
  /** Chat with the loaded instruct model: prompts = [[{role, content}]] */
  chat(prompts, opts = {}) { return this._call({ op: 'chat', prompts, ...opts }); }
  cancel() { this.dispose(); }
  dispose() {
    for (const p of this.pending.values()) { const e = new Error('Cancelled'); e.name = 'AbortError'; p.rej(e); }
    this.pending.clear();
    this.worker?.terminate(); this.worker = null; this.loadedKey = '';
  }
}
