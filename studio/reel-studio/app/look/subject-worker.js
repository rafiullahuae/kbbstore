// KBB Studio · look · U²-Net-p in a module worker (ONNX Runtime WASM, CPU — vendored, offline). See subject.js.
// Protocol: {id, bitmaps:[ImageBitmap 320x320], model:url, vendor:url} -> {id, masks:[{w:320, h:320, data:Uint8Array}]}
// Pre-processing as rembg does for this export: the picture divided by its brightest value, then ImageNet mean/std.
// The first output (the fused side output, sigmoid 0..1) is the mask; it is NOT re-stretched per frame (rembg's min/max
// normalisation), which would make a frame with no clear subject flicker into noise.
let ort = null, sess = null, loaded = '';
const MEAN = [0.485, 0.456, 0.406], STD = [0.229, 0.224, 0.225];

async function load(model, vendor) {
  if (sess && loaded === model) return sess;
  if (!ort) {
    ort = await import(vendor + 'ort.webgpu.min.mjs');
    ort.env.wasm.wasmPaths = vendor; ort.env.logLevel = 'error';
    ort.env.wasm.numThreads = self.crossOriginIsolated ? Math.min(4, navigator.hardwareConcurrency || 2) : 1;
  }
  sess = await ort.InferenceSession.create(model, { executionProviders: ['wasm'], graphOptimizationLevel: 'all', logSeverityLevel: 3 });
  loaded = model;
  return sess;
}

self.onmessage = async (e) => {
  const { id, bitmaps = [], model, vendor } = e.data || {};
  try {
    const s = await load(model, vendor), masks = [], transfer = [];
    for (const bm of bitmaps) {
      const W = bm.width, H = bm.height, c = new OffscreenCanvas(W, H).getContext('2d', { willReadFrequently: true });
      c.drawImage(bm, 0, 0); bm.close?.();
      const px = c.getImageData(0, 0, W, H).data, n = W * H, x = new Float32Array(3 * n);
      let mx = 1; for (let i = 0; i < px.length; i += 4) mx = Math.max(mx, px[i], px[i + 1], px[i + 2]);
      for (let i = 0, j = 0; j < n; i += 4, j++) for (let ch = 0; ch < 3; ch++) x[ch * n + j] = (px[i + ch] / mx - MEAN[ch]) / STD[ch];
      const out = await s.run({ [s.inputNames[0]]: new ort.Tensor('float32', x, [1, 3, H, W]) });
      const f = out[s.outputNames[0]].data, data = new Uint8Array(n);
      for (let i = 0; i < n; i++) data[i] = Math.round(Math.max(0, Math.min(1, f[i])) * 255);
      masks.push({ w: W, h: H, data }); transfer.push(data.buffer);
    }
    self.postMessage({ id, masks }, transfer);
  } catch (err) { self.postMessage({ id, error: String(err && err.message || err) }); }
};
