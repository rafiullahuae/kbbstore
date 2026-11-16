// KBB Studio · word-perfect sync — the CTC model in a Web Worker (module worker). Offline: the model comes from
// the studio's own models/ folder (env.allowRemoteModels = false), the ONNX runtime from vendor/transformers/.
//
// in : {type:'load', id, repo, dtype, modelsUrl, vendorUrl, threads}           -> {vocab, loadMs}
//      {type:'emit', id, audio (Float32 16 kHz), cols:[ids]}                    -> {T, cols, data (log-probs), frameSec}
// out: {type:'progress', ...} | {type:'done', id, ...} | {type:'error', id, message}

let T = null, model = null;
const post = (m, tr) => self.postMessage(m, tr || []);
const SR = 16000, HOP = 320;                  // wav2vec2: one frame per 320 samples (20 ms)
const WIN = 20 * SR, CTX = 1 * SR;            // 20 s windows with 1 s of context each side

self.onmessage = async (e) => {
  const m = e.data;
  try {
    if (m.type === 'load') {
      if (!T) T = await import(m.vendorUrl + 'transformers.min.js');
      const { env, AutoModelForCTC } = T;
      env.allowRemoteModels = false; env.allowLocalModels = true; env.localModelPath = m.modelsUrl; env.useBrowserCache = false;
      env.backends.onnx.wasm.wasmPaths = m.vendorUrl;
      env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? m.threads : 1;
      const t = performance.now();
      model = await AutoModelForCTC.from_pretrained(m.repo, { device: 'wasm', dtype: m.dtype,
        progress_callback: (p) => { if (p.status === 'progress') post({ type: 'progress', phase: 'load', loaded: p.loaded, total: p.total }); } });
      const vocab = await (await fetch(m.modelsUrl + m.repo + '/vocab.json').catch(() => null))?.json().catch(() => null)
        || await tokenizerVocab(m.modelsUrl + m.repo + '/tokenizer.json');
      post({ type: 'done', id: m.id, loadMs: performance.now() - t, vocab });
    } else if (m.type === 'emit') {
      post({ type: 'done', id: m.id, ...(await emissions(m.audio, m.cols, m.id)) });
    }
  } catch (err) {
    post({ type: 'error', id: m.id, message: err?.message || String(err) });
  }
};

async function tokenizerVocab(url) { const j = await (await fetch(url)).json(); return j.model.vocab; }

/** log-softmax emissions for the whole clip, only the columns asked for (letters of the transcript + blank + |) */
async function emissions(audio, cols, id) {
  const { Tensor } = T;
  const total = Math.floor(audio.length / HOP);
  const C = cols.length, data = new Float32Array(total * C).fill(-30);
  for (let s = 0; s < audio.length; s += WIN) {
    const a = Math.max(0, s - CTX), z = Math.min(audio.length, s + WIN + CTX);
    const x = audio.slice(a, z);
    if (x.length < 800) break;
    // Wav2Vec2FeatureExtractor: zero mean, unit variance per input
    let mean = 0; for (let i = 0; i < x.length; i++) mean += x[i]; mean /= x.length;
    let v = 0; for (let i = 0; i < x.length; i++) v += (x[i] - mean) ** 2; const sd = Math.sqrt(v / x.length + 1e-7);
    for (let i = 0; i < x.length; i++) x[i] = (x[i] - mean) / sd;
    const inputs = { input_values: new Tensor('float32', x, [1, x.length]) };
    if (model.sessions?.model?.inputNames?.includes('attention_mask')) inputs.attention_mask = new Tensor('int64', new BigInt64Array(x.length).fill(1n), [1, x.length]);
    const { logits } = await model(inputs);
    const [, Tw, V] = logits.dims, L = logits.data;
    const f0 = Math.round(a / HOP), keep0 = Math.round(s / HOP), keep1 = Math.min(total, Math.round(Math.min(audio.length, s + WIN) / HOP));
    for (let t = 0; t < Tw; t++) {
      const gf = f0 + t; if (gf < keep0 || gf >= keep1) continue;
      let mx = -Infinity; const o = t * V;
      for (let k = 0; k < V; k++) if (L[o + k] > mx) mx = L[o + k];
      let se = 0; for (let k = 0; k < V; k++) se += Math.exp(L[o + k] - mx);
      const lse = mx + Math.log(se);
      for (let c = 0; c < C; c++) data[gf * C + c] = L[o + cols[c]] - lse;
    }
    logits.dispose?.();
    post({ type: 'progress', phase: 'align', id, done: Math.min(audio.length, s + WIN), total: audio.length });
  }
  return { T: total, cols, data, frameSec: HOP / SR };
}
