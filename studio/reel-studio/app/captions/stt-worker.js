// Whisper in a Web Worker (module worker). Nothing here touches the network: models come from
// the studio's own models/ folder (env.allowRemoteModels = false) and the ONNX runtime's WASM
// from vendor/transformers/.
//
// in : {type:'load', id, repo, device, dtype, modelsUrl, vendorUrl, threads}
//      {type:'detect', id, audio}                 -> {lang}
//      {type:'run', id, audio, offset, language, task}   -> {words:[{t0,t1,w}], text}
// out: {type:'progress', ...} | {type:'done', id, ...} | {type:'error', id, message}

let T = null, asr = null;

async function lib(vendorUrl) {
  if (!T) T = await import(vendorUrl + 'transformers.min.js');
  return T;
}

const post = (m) => self.postMessage(m);

self.onmessage = async (e) => {
  const m = e.data;
  try {
    if (m.type === 'load') {
      const { pipeline, env } = await lib(m.vendorUrl);
      env.allowRemoteModels = false;
      env.allowLocalModels = true;
      env.localModelPath = m.modelsUrl;
      env.useBrowserCache = false;               // the files are already local; no second copy in Cache Storage
      env.backends.onnx.wasm.wasmPaths = m.vendorUrl;
      env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? m.threads : 1;
      const t = performance.now();
      asr = await pipeline('automatic-speech-recognition', m.repo, {
        device: m.device, dtype: m.dtype,
        progress_callback: (p) => { if (p.status === 'progress') post({ type: 'progress', phase: 'load', file: p.file, loaded: p.loaded, total: p.total }); },
      });
      post({ type: 'done', id: m.id, loadMs: performance.now() - t, threads: env.backends.onnx.wasm.numThreads });
    } else if (m.type === 'detect') {
      post({ type: 'done', id: m.id, lang: await detectLanguage(m.audio) });
    } else if (m.type === 'run') {
      const out = await asr(m.audio, {
        return_timestamps: 'word', task: m.task || 'transcribe', language: m.language || null,
        chunk_length_s: 0, // our client does the 30 s windows itself
      });
      const words = (out.chunks || []).map((c) => {
        const [a, b] = c.timestamp || [0, null];
        return { w: c.text.trim(), t0: m.offset + (a ?? 0), t1: m.offset + (b ?? (a ?? 0) + 0.3) };
      }).filter((w) => w.w);
      post({ type: 'done', id: m.id, words, text: out.text });
    }
  } catch (err) {
    post({ type: 'error', id: m.id, message: String(err && err.message || err) });
  }
};

/**
 * Whisper's own language ID: encoder + one decoder step from <|startoftranscript|>, then the most
 * likely language token. (transformers.js 3.x defaults to English when no language is given.)
 */
async function detectLanguage(audio) {
  const { Tensor } = T;
  const tok = asr.tokenizer, model = asr.model;
  const { input_features } = await asr.processor(audio);
  const sot = tok.model.tokens_to_ids.get('<|startoftranscript|>');
  const out = await model({ input_features, decoder_input_ids: new Tensor('int64', BigInt64Array.from([BigInt(sot)]), [1, 1]) });
  const logits = out.logits.data; // [1,1,vocab]
  // Language tokens sit between <|startoftranscript|> and <|translate|> in every Whisper vocabulary.
  const first = sot + 1, last = tok.model.tokens_to_ids.get('<|translate|>') - 1;
  let bestId = -1, bestV = -Infinity;
  for (let id = first; id <= last; id++) if (logits[id] > bestV) { bestV = logits[id]; bestId = id; }
  const best = bestId >= 0 ? /^<\|([a-z]+)\|>$/.exec(tok.model.vocab[bestId] || '')?.[1] : null;
  return best || 'en';
}
