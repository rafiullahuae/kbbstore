// KBB Studio · content · image tagger worker: CLIP ViT-B/32 (int8 ONNX, from the studio's models/ folder only).
// in : {id, op:'tag', blob, prompts:[...], modelsUrl, vendorUrl, repo}
// out: {id, result:{scores:[p per prompt]}}  softmax over the prompts (CLIP's logit scale 100)
let T = null, M = null, textEmb = null, textKey = '';

async function load(m) {
  if (!T) T = await import(m.vendorUrl + 'transformers.min.js');
  if (M) return M;
  const { env, AutoTokenizer, AutoProcessor, CLIPTextModelWithProjection, CLIPVisionModelWithProjection } = T;
  env.allowRemoteModels = false; env.allowLocalModels = true; env.localModelPath = m.modelsUrl; env.useBrowserCache = false;
  env.backends.onnx.wasm.wasmPaths = m.vendorUrl;
  env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? Math.min(4, navigator.hardwareConcurrency || 2) : 1;
  const o = { device: 'wasm', dtype: 'q8' };
  const [tok, proc, text, vision] = await Promise.all([AutoTokenizer.from_pretrained(m.repo), AutoProcessor.from_pretrained(m.repo),
    CLIPTextModelWithProjection.from_pretrained(m.repo, o), CLIPVisionModelWithProjection.from_pretrained(m.repo, o)]);
  return (M = { tok, proc, text, vision });
}
const norm = (v) => { let s = 0; for (const x of v) s += x * x; s = Math.sqrt(s) || 1; return v.map((x) => x / s); };
function rows(t) { const [n, d] = t.dims, a = t.data, out = []; for (let i = 0; i < n; i++) out.push(norm(Array.from(a.subarray(i * d, (i + 1) * d)))); return out; }

self.onmessage = async (e) => {
  const m = e.data;
  try {
    const { tok, proc, text, vision } = await load(m);
    const key = m.prompts.join('|');
    if (textKey !== key) {
      const inp = tok(m.prompts, { padding: true, truncation: true });
      textEmb = rows((await text(inp)).text_embeds); textKey = key;
    }
    const img = await T.RawImage.fromBlob(m.blob);
    const iv = rows((await vision(await proc(img))).image_embeds)[0];
    const logits = textEmb.map((t) => 100 * t.reduce((s, x, i) => s + x * iv[i], 0));
    const mx = Math.max(...logits), ex = logits.map((l) => Math.exp(l - mx)), sum = ex.reduce((a, b) => a + b, 0);
    self.postMessage({ id: m.id, result: { scores: ex.map((x) => x / sum) } });
  } catch (err) { self.postMessage({ id: m.id, error: String(err?.message || err) }); }
};
