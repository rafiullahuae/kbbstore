// KBB Studio · captions · translation worker. Runs one translation model at a time, OFFLINE: it loads only from the
// studio's models/ folder (env.allowRemoteModels = false), so a missing model is an error here, never a download.
//
// in : {id, op:'load', kind, repo, device, dtype, modelsUrl, vendorUrl, threads}
//      {id, op:'tr', texts:[...], src, tgt, prefix?, langTag?, max?, beams?}       (kind marian | m2m | madlad)
//      {id, op:'chat', prompts:[[messages]], max?, prefix?}                          (kind llm)
// out: {id, result} | {id, error} | {progress:{file, loaded, total}}
//
// kind:
//   marian — Helsinki-NLP OPUS-MT pairs (Xenova/opus-mt-*): one direction per model; the multi-target en-mul model
//            needs the target tag ">>urd<< " in front of every sentence (msg.langTag).
//   m2m    — Xenova/m2m100_418M: any of 100 languages to any, src_lang / tgt_lang.
//   madlad — MADLAD-400 (T5): "<2ar> " in front of the sentence (msg.langTag), text2text-generation.
//   llm    — an instruct model (Qwen2.5) asked to translate with a strict prompt; greedy, the reply is pre-started.
let T = null, pipe = null, loaded = '', kind = '';

self.onmessage = async (e) => {
  const m = e.data;
  try {
    if (m.op === 'load') {
      const key = [m.kind, m.repo, m.device, JSON.stringify(m.dtype)].join('|');
      if (loaded !== key) {
        if (!T) T = await import(m.vendorUrl + 'transformers.min.js');
        const { pipeline, env } = T;
        env.allowRemoteModels = false; env.allowLocalModels = true; env.localModelPath = m.modelsUrl; env.useBrowserCache = false;
        env.backends.onnx.wasm.wasmPaths = m.vendorUrl;
        env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? Math.max(1, Math.min(8, m.threads || 4)) : 1;
        if (pipe?.dispose) { try { await pipe.dispose(); } catch { /* already gone */ } }
        pipe = null; loaded = '';
        const task = m.kind === 'llm' ? 'text-generation' : m.kind === 'madlad' ? 'text2text-generation' : 'translation';
        const t0 = performance.now();
        pipe = await pipeline(task, m.repo, { device: m.device || 'wasm', dtype: m.dtype,
          progress_callback: (p) => { if (p.status === 'progress') self.postMessage({ id: m.id, progress: { file: p.file, loaded: p.loaded, total: p.total } }); } });
        loaded = key; kind = m.kind;
        self.postMessage({ id: m.id, result: { ok: true, ms: Math.round(performance.now() - t0) } });
      } else self.postMessage({ id: m.id, result: { ok: true, ms: 0 } });
    } else if (m.op === 'tr') {
      if (!pipe) throw new Error('no translation model loaded');
      const t0 = performance.now(), out = [];
      const gen = { max_new_tokens: m.max || 256, num_beams: m.beams || 1 };
      if (m.rep) gen.repetition_penalty = m.rep;
      for (const text of m.texts) {           // one at a time: caption sentences are short and batching pads them
        const src = (m.langTag || '') + text;
        let r;
        if (kind === 'm2m') r = await pipe(src, { ...gen, src_lang: m.src, tgt_lang: m.tgt });
        else r = await pipe(src, gen);
        const o = Array.isArray(r) ? r[0] : r;
        out.push(String(o.translation_text ?? o.generated_text ?? '').trim());
      }
      self.postMessage({ id: m.id, result: { texts: out, ms: Math.round(performance.now() - t0) } });
    } else if (m.op === 'chat') {
      if (!pipe || kind !== 'llm') throw new Error('no language model loaded');
      const t0 = performance.now(), out = [];
      for (const messages of m.prompts) {
        const prompt = pipe.tokenizer.apply_chat_template(messages, { tokenize: false, add_generation_prompt: true }) + (m.prefix || '');
        const r = await pipe(prompt, { max_new_tokens: m.max || 200, do_sample: false, repetition_penalty: 1.05, return_full_text: false });
        out.push((m.prefix || '') + String(r[0].generated_text));
      }
      self.postMessage({ id: m.id, result: { texts: out, ms: Math.round(performance.now() - t0) } });
    } else if (m.op === 'dispose') {
      if (pipe?.dispose) await pipe.dispose();
      pipe = null; loaded = ''; kind = '';
      self.postMessage({ id: m.id, result: { ok: true } });
    }
  } catch (err) { self.postMessage({ id: m.id, error: String(err?.message || err) }); }
};
