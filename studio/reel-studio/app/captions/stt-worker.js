// Whisper in a Web Worker (module worker). Nothing here touches the network: models come from
// the studio's own models/ folder (env.allowRemoteModels = false) and the ONNX runtime's WASM
// from vendor/transformers/.
//
// in : {type:'load', id, repo, device, dtype, modelsUrl, vendorUrl, threads}
//      {type:'detect', id, audio}                 -> {lang, probs:{code: p}} (ten likeliest + en/hi/ur/ar)
//      {type:'run', id, audio, offset, language, task, prompt?}   -> {words:[{t0,t1,w}], text, prompted}
//      {type:'analyse', id, audio}                -> classifyAudio() (speech / music / silence picture)
//      {type:'speakers', id, audio, words}        -> detectSpeakers() (best-effort two-voice split)
// out: {type:'progress', ...} | {type:'done', id, ...} | {type:'error', id, message}

import { classifyAudio } from './nonspeech.js';
import { detectSpeakers } from './script.js';

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
      post({ type: 'done', id: m.id, ...(await detectLanguage(m.audio)) });
    } else if (m.type === 'run') {
      const opts = {
        return_timestamps: 'word', task: m.task || 'transcribe', language: m.language || null,
        chunk_length_s: 0, // our client does the 30 s windows itself
      };
      const pre = m.prompt && m.language ? promptIds(m.prompt, m.language, m.task || 'transcribe') : null;
      if (pre) opts.decoder_input_ids = pre.ids;
      let out = await asr(m.audio, opts);
      let chunks = out.chunks || [];
      if (pre) {
        chunks = dropPrompt(chunks, pre.text);
        // the prompt did not come back cleanly (merged with the first word): decode again without it
        if (!chunks) { delete opts.decoder_input_ids; out = await asr(m.audio, opts); chunks = out.chunks || []; }
      }
      const words = chunks.map((c) => {
        const [a, b] = c.timestamp || [0, null];
        return { w: c.text.trim(), t0: m.offset + (a ?? 0), t1: m.offset + (b ?? (a ?? 0) + 0.3) };
      }).filter((w) => w.w);
      post({ type: 'done', id: m.id, words, text: out.text, prompted: !!opts.decoder_input_ids });
    } else if (m.type === 'analyse') {
      const a = classifyAudio(m.audio);
      post({ type: 'done', id: m.id, analysis: a });
    } else if (m.type === 'speakers') {
      const r = detectSpeakers(m.words, m.audio);
      post({ type: 'done', id: m.id, words: r.words, n: r.n, f0: r.f0 });
    }
  } catch (err) {
    post({ type: 'error', id: m.id, message: String(err && err.message || err), stack: String(err?.stack || ''), op: m.type });
  }
};

/**
 * Whisper's "previous text" prompt: <|startofprev|> prompt tokens <|startoftranscript|> <|lang|> <|task|>
 * <|notimestamps|>. transformers.js 3.8 has no prompt_ids support in generate(), but it accepts decoder_input_ids;
 * the prompt's own tokens then come back at the start of the output and are dropped (dropPrompt).
 * Whisper reads at most the last 223 prompt tokens; we keep ≤ 120.
 */
function promptIds(prompt, language, task) {
  const tok = asr.tokenizer, ids = tok.model.tokens_to_ids;
  const sop = ids.get('<|startofprev|>'), sot = ids.get('<|startoftranscript|>'), lang = ids.get(`<|${language}|>`);
  const tk = ids.get(`<|${task}|>`), nts = ids.get('<|notimestamps|>');
  if ([sop, sot, lang, tk, nts].some((x) => x == null)) return null;
  let p = tok.encode(' ' + prompt.trim(), { add_special_tokens: false });
  if (p.length > 120) p = p.slice(p.length - 120);
  return { ids: [sop, ...p, sot, lang, tk, nts], text: tok.decode(p, { skip_special_tokens: true }) };
}

const letters = (s) => String(s).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
/** Remove the leading chunks that spell the prompt back (they precede <|startoftranscript|>). */
function dropPrompt(chunks, text) {
  const want = letters(text);
  let acc = '', k = 0;
  while (k < chunks.length && acc.length < want.length) { acc += letters(chunks[k].text); k++; }
  return acc === want ? chunks.slice(k) : null;
}

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
  // A softmax over the language tokens only = Whisper's own language probabilities (what its detect_language gives).
  const first = sot + 1, last = tok.model.tokens_to_ids.get('<|translate|>') - 1;
  let mx = -Infinity;
  for (let id = first; id <= last; id++) if (logits[id] > mx) mx = logits[id];
  const all = [];
  let z = 0;
  for (let id = first; id <= last; id++) {
    const code = /^<\|([a-z]+)\|>$/.exec(tok.model.vocab[id] || '')?.[1];
    if (!code) continue;
    const e = Math.exp(logits[id] - mx); z += e; all.push([code, e]);
  }
  all.sort((a, b) => b[1] - a[1]);
  const probs = {};
  all.forEach(([code, e], k) => { if (k < 10 || ['en', 'hi', 'ur', 'ar'].includes(code)) probs[code] = +(e / z).toFixed(4); });
  return { lang: all[0]?.[0] || 'en', probs };
}
