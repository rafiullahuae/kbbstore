// Speech models: which files, and how they get onto the Mac.
// The _timestamped exports carry the cross-attention outputs Whisper needs for word-level times;
// the plain onnx-community/whisper-* exports cannot give word timestamps.
//
// ONE source of truth: models/manifest.json (built by tools/make-model-manifest.py). The engine's installer
// downloads, resumes and verifies every file of a manifest model into models/<repo>/<path>, and the speech
// worker loads from exactly those paths (stt.js: env.localModelPath = /models/). There is no second download path:
// a missing model is installed by the same installer as the first-run Setup screen, after ONE question.

// dtype -> file suffix (transformers.js 3.x naming)
const SUFFIX = { fp32: '', fp16: '_fp16', q8: '_quantized', q4: '_q4' };

export const SPEECH_MODELS = {
  small: { key: 'small', id: 'whisper-small', label: 'Accurate (Whisper small)', repo: 'onnx-community/whisper-small_timestamped' },
  base: { key: 'base', id: 'whisper-base', label: 'Fast (Whisper base)', repo: 'onnx-community/whisper-base_timestamped' },
};
export const DEFAULT_MODEL = 'small';

/**
 * dtypes per device. WASM: int8 both halves (fast on CPU, smallest download).
 * WebGPU: int8 kernels are weak on GPU, so the encoder runs fp16 and the decoder 4-bit — the configuration
 * transformers.js recommends for Whisper on WebGPU. Both sets are in the manifest, so either runs offline.
 */
export function dtypesFor(device) {
  if (device === 'webgpu') return { encoder_model: 'fp16', decoder_model_merged: 'q4' };
  return { encoder_model: 'q8', decoder_model_merged: 'q8' };
}

/** the files one runtime needs (every one of them is in the model's manifest entry) */
export function filesFor(modelKey, dtypes) {
  const out = ['config.json', 'generation_config.json', 'preprocessor_config.json', 'tokenizer.json', 'tokenizer_config.json'];
  for (const [part, dt] of Object.entries(dtypes)) out.push(`onnx/${part}${SUFFIX[dt]}.onnx`);
  return out.map((file) => ({ file }));
}

/**
 * Pick the device: WebGPU when an adapter with shader-f16 exists (Chrome on an Apple-silicon Mac), else WASM.
 * prefer: 'auto' | 'wasm'
 */
export async function pickDevice(prefer = 'auto') {
  if (prefer === 'wasm') return { device: 'wasm', f16: false };
  try {
    if (typeof navigator !== 'undefined' && navigator.gpu) {
      const ad = await navigator.gpu.requestAdapter();
      if (ad && ad.features.has('shader-f16')) return { device: 'webgpu', f16: true };
    }
  } catch { /* no GPU */ }
  return { device: 'wasm', f16: false };
}

/** wait for the engine's installer to finish this model (progress arrives on the bus as 'engine:models') */
function waitInstalled(engine, bus, id, onProgress) {
  return new Promise((res, rej) => {
    let off = null, poll = 0;
    const end = (fn) => { off?.(); clearInterval(poll); fn(); };
    const check = (st) => {
      const m = st.models?.find((x) => x.id === id), inst = st.installer || {};
      if (inst.total) onProgress({ phase: 'download', loaded: inst.done, total: inst.total });
      if (m?.installed) end(res);
      else if (['stalled', 'nospace', 'paused'].includes(inst.state)) end(() => rej(new Error(inst.message || 'The download stopped.')));
    };
    off = bus?.on('engine:models', check) || null;
    // the event stream is the fast path; a slow poll covers a dropped stream
    poll = setInterval(() => engine.modelsStatus().then(check, () => {}), 3000);
  });
}

/**
 * Make sure a speech model is installed in models/. Installed: nothing happens. Missing: ONE question (with the
 * size) through the app's permission dialog, then the engine's installer (resume, retries, checksums).
 * Throws err.denied when he says no.
 */
export async function ensureModel(engine, modelKey, onProgress = () => {}, bus = null) {
  const m = SPEECH_MODELS[modelKey];
  const st = await engine.modelsStatus();
  const entry = st.models.find((x) => x.id === m.id);
  if (!entry) throw new Error(`The model list (models/manifest.json) has no ${m.id}`);
  if (entry.installed) return { downloaded: 0 };
  const need = Math.max(0, entry.size - entry.have);
  const ok = await engine.askPermission({
    what: `Speech-to-text model "${m.label}" — downloaded once into the models folder, then captions work offline`,
    size: need, host: 'huggingface.co', url: `https://huggingface.co/${m.repo}/`, scope: 'repo',
  });
  if (!ok) { const e = new Error('Download not allowed'); e.denied = true; throw e; }
  const busy = st.installer?.state === 'running' && (st.installer.ids || []).includes(m.id);
  if (!busy) await engine.installModels([entry]);
  await waitInstalled(engine, bus, m.id, onProgress);
  return { downloaded: need };
}
