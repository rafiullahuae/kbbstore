// KBB Studio · content · the local writing model (Qwen2.5 instruct, ONNX) in a worker: loads ONLY from the studio's
// models/ folder, WebGPU when the Mac has it (1.5B, 4-bit), otherwise WASM on the CPU (0.5B, int8).
// in : {id, op:'load', repo, device, dtype, modelsUrl, vendorUrl} | {id, op:'gen', messages, max}
// out: {id, result} | {id, error} | {id, progress:{file, loaded, total}}
let T = null, gen = null, loaded = '';

self.onmessage = async (e) => {
  const m = e.data;
  try {
    if (m.op === 'load') {
      const key = m.repo + '|' + m.device + '|' + m.dtype;
      if (loaded !== key) {
        if (!T) T = await import(m.vendorUrl + 'transformers.min.js');
        const { pipeline, env } = T;
        env.allowRemoteModels = false; env.allowLocalModels = true; env.localModelPath = m.modelsUrl; env.useBrowserCache = false;
        env.backends.onnx.wasm.wasmPaths = m.vendorUrl;
        env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? Math.min(4, navigator.hardwareConcurrency || 2) : 1;
        gen = await pipeline('text-generation', m.repo, { device: m.device, dtype: m.dtype,
          progress_callback: (p) => { if (p.status === 'progress') self.postMessage({ id: m.id, progress: { file: p.file, loaded: p.loaded, total: p.total } }); } });
        loaded = key;
      }
      self.postMessage({ id: m.id, result: { ok: true } });
    } else if (m.op === 'gen') {
      const t = performance.now();
      // the reply is STARTED for the model (m.prefix, e.g. '{"caption": "') so even a small model stays in the JSON shape
      const prompt = gen.tokenizer.apply_chat_template(m.messages, { tokenize: false, add_generation_prompt: true }) + (m.prefix || '');
      const out = await gen(prompt, { max_new_tokens: m.max || 320, do_sample: false, repetition_penalty: 1.1, return_full_text: false });
      const text = (m.prefix || '') + String(out[0].generated_text);
      self.postMessage({ id: m.id, result: { text, ms: Math.round(performance.now() - t) } });
    }
  } catch (err) { self.postMessage({ id: m.id, error: String(err?.message || err) }); }
};
