// KBB Studio · Designs · offline AI pictures: SDXS-512-DreamShaper (one-step latent diffusion, CreativeML Open RAIL++-M)
// in a worker with onnxruntime-web -- WebGPU on the Mac's GPU, WASM on the CPU otherwise -- and Swin2SR x2 for the
// upscale. Loads ONLY from the studio's models/ folder (the engine serves it; nothing is fetched from the internet).
//
// in : {id, op:'load', device:'webgpu'|'wasm', base, vendor, models}        base = '/models/creador/sdxs-512-dreamshaper-onnx/'
//      {id, op:'gen', prompt, seeds:[...], quality:'fast'|'best', latent?:64} -> one {id, image:{seed, w, h, data:ArrayBuffer RGBA, ms}} per seed, then {id, result}
//      {id, op:'upscale', w, h, data, models, vendor, device}                  -> {id, result:{w, h, data}}
// out: {id, result} | {id, error} | {id, progress:{stage, f}} | {id, image}
//
// The sampler is the model's own single step (manifest.json): latent = a·noise + b·ε(noise, t=999, text). No guidance, so
// no negative prompt: what must not appear (text, logos, watermarks) is excluded by the prompt builder (prompts.js) and
// the picture is checked after (check.js).
let ort = null, T = null, tok = null, te = null, unet = null, dec = null, decL = null, man = null, loaded = '', up = null;

const f16 = (() => { // float32 <-> float16 (IEEE half), for the fp16 graphs
  const f = new Float32Array(1), u = new Uint32Array(f.buffer);
  return {
    from32(a) {
      const o = new Uint16Array(a.length);
      for (let i = 0; i < a.length; i++) {
        f[0] = a[i]; const x = u[0], s = (x >>> 16) & 0x8000; let e = ((x >>> 23) & 0xff) - 112; const m = x & 0x7fffff;
        if (e <= 0) o[i] = s | (e > -11 ? ((m | 0x800000) >> (1 - e + 13)) : 0); else if (e >= 31) o[i] = s | 0x7c00; else o[i] = s | (e << 10) | (m >> 13);
      }
      return o;
    },
    to32(h) {
      const o = new Float32Array(h.length);
      for (let i = 0; i < h.length; i++) {
        const x = h[i], s = x & 0x8000 ? -1 : 1, e = (x >> 10) & 31, m = x & 1023;
        o[i] = e === 0 ? s * m * 2 ** -24 : e === 31 ? (m ? NaN : s * Infinity) : s * (1 + m / 1024) * 2 ** (e - 15);
      }
      return o;
    },
  };
})();
const asF32 = (t) => (t.type === 'float16' ? (t.data instanceof Uint16Array ? f16.to32(t.data) : Float32Array.from(t.data)) : t.data);
const asType = (arr, type, dims) => new ort.Tensor(type, type === 'float16' ? f16.from32(arr) : arr, dims);

// a seeded Gaussian (mulberry32 + Box-Muller): the same seed gives the same picture ("seed lock")
function gaussian(seed, n) {
  let a = seed >>> 0;
  const rnd = () => { a = (a + 0x6D2B79F5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
  const out = new Float32Array(n);
  for (let i = 0; i < n; i += 2) {
    const u1 = Math.max(rnd(), 1e-12), u2 = rnd(), r = Math.sqrt(-2 * Math.log(u1));
    out[i] = r * Math.cos(2 * Math.PI * u2); if (i + 1 < n) out[i + 1] = r * Math.sin(2 * Math.PI * u2);
  }
  return out;
}

async function session(url, device) {
  return ort.InferenceSession.create(url, { executionProviders: device === 'webgpu' ? ['webgpu'] : ['wasm'], graphOptimizationLevel: 'all' });
}
const inType = (s, name) => {
  const md = s.inputMetadata; // ORT ≥1.22 lists {name, type} per input; older builds: assume fp16 (the shipped graphs are)
  const m = Array.isArray(md) ? md.find((x) => x.name === name) : null;
  return m?.type || 'float16';
};

self.onmessage = async (e) => {
  const m = e.data;
  const post = (x) => self.postMessage(Object.assign({ id: m.id }, x));
  try {
    if (m.op === 'load') {
      const key = m.base + '|' + m.device;
      if (loaded !== key) {
        if (!ort) ort = await import(m.vendor + 'ort.webgpu.min.mjs');
        ort.env.wasm.wasmPaths = m.vendor; ort.env.logLevel = 'error';
        ort.env.wasm.numThreads = self.crossOriginIsolated ? Math.min(8, navigator.hardwareConcurrency || 2) : 1;
        if (!T) T = await import(m.vendor + 'transformers.min.js');
        T.env.allowRemoteModels = false; T.env.allowLocalModels = true; T.env.localModelPath = m.models; T.env.useBrowserCache = false;
        man = await (await fetch(m.base + 'manifest.json')).json();
        post({ progress: { stage: 'Reading the words model', f: 0.05 } });
        tok = await T.AutoTokenizer.from_pretrained(m.repo);
        post({ progress: { stage: 'Loading the text encoder', f: 0.1 } });
        te = await session(m.base + man.models.textEncoder, m.device);
        post({ progress: { stage: 'Loading the picture model (UNet)', f: 0.3 } });
        unet = await session(m.base + man.models.unet, m.device);
        post({ progress: { stage: 'Loading the decoder', f: 0.9 } });
        dec = await session(m.base + man.models.decoder, m.device);
        decL = null;
        loaded = key;
      }
      post({ result: { ok: true, device: m.device, manifest: man } });
    } else if (m.op === 'gen') {
      if (!unet) throw new Error('model not loaded');
      const t0 = performance.now();
      const enc = tok(m.prompt, { padding: 'max_length', max_length: 77, truncation: true });
      const ids = Int32Array.from(Array.from(enc.input_ids.data, Number));
      const teIn = te.inputNames[0];
      const hidden = (await te.run({ [teIn]: new ort.Tensor(inType(te, teIn) === 'int64' ? 'int64' : 'int32', inType(te, teIn) === 'int64' ? BigInt64Array.from(ids, BigInt) : ids, [1, 77]) }))[te.outputNames[0]];
      const hs = asF32(hidden);
      const L = m.latent || (man.latentShape?.[2] ?? 64), n = 4 * L * L;
      const { sampleCoefficient: a, outputCoefficient: b, timestep } = man.scheduler;
      if (m.quality === 'best' && !decL && man.models.decoderLarge) decL = await session(m.base + man.models.decoderLarge, m.device === 'webgpu' ? 'webgpu' : 'wasm');
      const decoder = m.quality === 'best' && decL ? decL : dec;
      for (const seed of m.seeds) {
        const ts = performance.now();
        const noise = gaussian(seed, n);
        const un = unet.inputNames;
        const feeds = {};
        for (const name of un) {
          if (/sample/.test(name)) feeds[name] = asType(noise, inType(unet, name), [1, 4, L, L]);
          else if (/time/.test(name)) feeds[name] = asType(new Float32Array([timestep]), inType(unet, name) === 'float16' ? 'float16' : 'float32', [1]);
          else feeds[name] = asType(hs, inType(unet, name), [1, 77, 768]);
        }
        const eps = asF32((await unet.run(feeds))[unet.outputNames[0]]);
        const lat = new Float32Array(n);
        for (let i = 0; i < n; i++) lat[i] = a * noise[i] + b * eps[i];
        const dn = decoder.inputNames[0];
        const img = (await decoder.run({ [dn]: asType(lat, inType(decoder, dn), [1, 4, L, L]) }))[decoder.outputNames[0]];
        const [, , H, W] = img.dims, px = asF32(img), rgba = new Uint8ClampedArray(W * H * 4), plane = W * H;
        for (let i = 0; i < plane; i++) {
          rgba[i * 4] = px[i] * 255; rgba[i * 4 + 1] = px[plane + i] * 255; rgba[i * 4 + 2] = px[2 * plane + i] * 255; rgba[i * 4 + 3] = 255;
        }
        self.postMessage({ id: m.id, image: { seed, w: W, h: H, data: rgba.buffer, ms: Math.round(performance.now() - ts) } }, [rgba.buffer]);
      }
      post({ result: { ms: Math.round(performance.now() - t0) } });
    } else if (m.op === 'upscale') {
      if (!T) T = await import(m.vendor + 'transformers.min.js');
      T.env.allowRemoteModels = false; T.env.allowLocalModels = true; T.env.localModelPath = m.models; T.env.useBrowserCache = false;
      T.env.backends.onnx.wasm.wasmPaths = m.vendor;
      if (!up) up = await T.pipeline('image-to-image', 'Xenova/swin2SR-classical-sr-x2-64', { device: m.device, dtype: 'fp16' });
      const raw = new T.RawImage(new Uint8ClampedArray(m.data), m.w, m.h, 4);
      const out = await up(raw.rgb());
      const o = out.rgba ? out.rgba() : out;
      const data = new Uint8ClampedArray(o.data).buffer;
      self.postMessage({ id: m.id, result: { w: o.width, h: o.height, data } }, [data]);
    }
  } catch (err) { post({ error: String(err?.message || err) }); }
};
