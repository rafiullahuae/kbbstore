// Speech models: what to download, how big, and the one permission question before it happens.
// The _timestamped exports carry the cross-attention outputs Whisper needs for word-level times;
// the plain onnx-community/whisper-* exports cannot give word timestamps.

const MB = 1e6;
const CONFIG_FILES = [
  ['config.json', 0.003 * MB], ['generation_config.json', 0.004 * MB], ['preprocessor_config.json', 0.001 * MB],
  ['tokenizer.json', 2.48 * MB], ['tokenizer_config.json', 0.28 * MB],
];

// dtype -> file suffix (transformers.js 3.x naming)
const SUFFIX = { fp32: '', fp16: '_fp16', q8: '_quantized', q4: '_q4' };

export const SPEECH_MODELS = {
  small: {
    key: 'small', label: 'Accurate (Whisper small)', repo: 'onnx-community/whisper-small_timestamped',
    sizes: { encoder_model: { fp32: 352.8, fp16: 176.5, q8: 92.2 }, decoder_model_merged: { q8: 156.8, q4: 233.4 } },
  },
  base: {
    key: 'base', label: 'Fast (Whisper base)', repo: 'onnx-community/whisper-base_timestamped',
    sizes: { encoder_model: { fp32: 82.5, fp16: 41.3, q8: 23.2 }, decoder_model_merged: { q8: 53.7, q4: 123.7 } },
  },
};
export const DEFAULT_MODEL = 'small';

/**
 * dtypes per device. WASM: int8 both halves (fast on CPU, smallest download).
 * WebGPU: int8 kernels are weak on GPU, so the encoder runs fp16 (fp32 without shader-f16) and the
 * decoder 4-bit — the configuration transformers.js recommends for Whisper on WebGPU.
 */
export function dtypesFor(device, f16 = false) {
  if (device === 'webgpu') return { encoder_model: f16 ? 'fp16' : 'fp32', decoder_model_merged: 'q4' };
  return { encoder_model: 'q8', decoder_model_merged: 'q8' };
}

export function filesFor(modelKey, dtypes) {
  const m = SPEECH_MODELS[modelKey];
  const out = CONFIG_FILES.map(([f, s]) => ({ file: f, size: s }));
  for (const [part, dt] of Object.entries(dtypes)) out.push({ file: `onnx/${part}${SUFFIX[dt]}.onnx`, size: Math.round(m.sizes[part][dt] * MB) });
  return out;
}

/** Pick the device: WebGPU when an adapter exists (Chrome on a Mac), else WASM. */
export async function pickDevice(prefer = 'auto') {
  if (prefer === 'wasm') return { device: 'wasm', f16: false };
  try {
    if (typeof navigator !== 'undefined' && navigator.gpu) {
      const ad = await navigator.gpu.requestAdapter();
      if (ad) return { device: 'webgpu', f16: ad.features.has('shader-f16') };
    }
  } catch { /* no GPU */ }
  return { device: 'wasm', f16: false };
}

/**
 * Make sure the model files are in models/. Missing files: ONE question (with the total size) through
 * engine.askPermission, then engine.fetchModel per file (fetchModel's own question is already answered
 * for this repo). Throws err.denied when he says no.
 */
export async function ensureModel(engine, modelKey, dtypes, onProgress = () => {}) {
  const m = SPEECH_MODELS[modelKey];
  const files = filesFor(modelKey, dtypes);
  const missing = [];
  for (const f of files) if (!(await engine.exists(`models/${m.repo}/${f.file}`))) missing.push(f);
  if (!missing.length) return { downloaded: 0 };
  const total = missing.reduce((s, f) => s + f.size, 0);
  const ok = await engine.askPermission({
    what: `Speech-to-text model "${m.label}" — downloaded once, then captions work offline`,
    size: total, host: 'huggingface.co', url: `https://huggingface.co/${m.repo}/`, scope: 'repo',
  });
  if (!ok) { const e = new Error('Download not allowed'); e.denied = true; throw e; }
  let done = 0;
  for (const f of missing) {
    await engine.fetchModel(m.repo, f.file, {
      size: f.size, what: m.label,
      onProgress: (d) => onProgress({ phase: 'download', file: f.file, loaded: done + (d.done || 0), total }),
    });
    done += f.size;
    onProgress({ phase: 'download', file: f.file, loaded: done, total });
  }
  return { downloaded: total };
}
