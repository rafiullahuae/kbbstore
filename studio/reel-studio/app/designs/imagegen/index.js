// KBB Studio · Designs · the offline image generator, app side: picks the Mac's GPU (WebGPU) or the CPU (WASM), runs
// the worker, returns candidates, checks each picture, upscales x2 (Swin2SR) and saves into the company's library.
//   status()                          -> {installed, upscaler, device, note}
//   generate(prompt, {n, seed, quality, onProgress, onImage}) -> [{seed, canvas, ms, flags:[...]}]
//   upscale(canvas)                   -> canvas 2x
//   save(ws, canvas, meta)            -> 'workspaces/<ws>/designs/images/<file>.png' (+ .json beside it: prompt, seed, model, licence)
import { engine } from '../../core/engine.js';
import { sanitize } from './prompts.js';
import { pictureCheck } from './check.js';

export const MODEL = { id: 'image-ai', repo: 'creador/sdxs-512-dreamshaper-onnx', name: 'SDXS-512-DreamShaper (one-step)', licence: 'CreativeML Open RAIL++-M', licenceUrl: 'https://huggingface.co/spaces/CompVis/stable-diffusion-license' };
export const UPSCALER = { id: 'upscaler', repo: 'Xenova/swin2SR-classical-sr-x2-64' };

let W = null, seq = 0, loadedDevice = null;
const pend = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
  W.onmessage = (e) => {
    const d = e.data, p = pend.get(d.id); if (!p) return;
    if (d.progress) return p.onProgress?.(d.progress);
    if (d.image) return p.onImage?.(d.image);
    pend.delete(d.id); d.error ? p.rej(new Error(d.error)) : p.res(d.result);
  };
  return W;
}
const call = (msg, hooks = {}, transfer = []) => new Promise((res, rej) => { const id = ++seq; pend.set(id, { res, rej, ...hooks }); worker().postMessage({ id, ...msg }, transfer); });
const paths = () => ({ models: new URL('/models/', location.href).href, vendor: new URL('../../../vendor/transformers/', import.meta.url).href });

export async function gpuOK() {
  if (!navigator.gpu) return false;
  try { const a = await navigator.gpu.requestAdapter(); return !!a && (a.features?.has?.('shader-f16') ?? false); } catch { return false; }
}
export async function status() {
  let st = null; try { st = await engine.modelsStatus(); } catch {}
  const have = (id) => !!st?.models?.find((m) => m.id === id)?.installed;
  const gpu = await gpuOK();
  return { installed: have(MODEL.id), upscaler: have(UPSCALER.id), device: gpu ? 'webgpu' : 'wasm',
    note: gpu ? 'On this Mac’s GPU: about 1–3 s per picture.' : 'No WebGPU here: the CPU makes each picture in about 15–40 s (Chrome on a Mac uses the GPU).' };
}

/** n candidates for one prompt (different seeds, or the locked seed and its neighbours) */
export async function generate(prompt, { n = 4, seed = null, quality = 'fast', device, onProgress, onImage } = {}) {
  const s = sanitize(prompt);
  if (!s.ok) throw Object.assign(new Error(s.refused || 'Type what the picture should show'), { refused: true });
  const dev = device || ((await gpuOK()) ? 'webgpu' : 'wasm');
  const p = paths();
  if (loadedDevice !== dev) {
    await call({ op: 'load', device: dev, base: p.models + MODEL.repo + '/', repo: MODEL.repo, models: p.models, vendor: p.vendor }, { onProgress: (x) => onProgress?.(x.f * 0.3, x.stage) });
    loadedDevice = dev;
  }
  const base = seed ?? Math.floor(Math.random() * 2 ** 31);
  const seeds = Array.from({ length: n }, (_, i) => (base + i * 7919) >>> 0);
  const out = [];
  await call({ op: 'gen', prompt: s.prompt, seeds, quality }, {
    onImage: (im) => {
      const c = document.createElement('canvas'); c.width = im.w; c.height = im.h;
      c.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(im.data), im.w, im.h), 0, 0);
      const cand = { seed: im.seed, canvas: c, ms: im.ms, prompt: s.prompt, flags: pictureCheck(c) };
      out.push(cand); onImage?.(cand); onProgress?.(0.3 + 0.7 * (out.length / seeds.length), `Picture ${out.length} of ${seeds.length}`);
    },
  });
  return out;
}

/** release the picture model (frees ~1–2 GB; the next picture loads it again) */
export function unload() { if (W) { W.terminate(); W = null; } loadedDevice = null; for (const p of pend.values()) p.rej(new Error('stopped')); pend.clear(); }

/** Swin2SR x2 in its own short-lived worker; on the CPU the picture model is released first so both never sit in memory */
/** a smooth 2x enlargement (no model): the CPU fallback -- Swin2SR's fp16 graph needs the Mac's GPU (on WASM it stops
 *  with an ORT allocation error), so on a Mac without WebGPU pictures are enlarged this way and say so */
export function smoothUpscale(canvas) {
  const c = document.createElement('canvas'); c.width = canvas.width * 2; c.height = canvas.height * 2;
  const x = c.getContext('2d'); x.imageSmoothingEnabled = true; x.imageSmoothingQuality = 'high'; x.drawImage(canvas, 0, 0, c.width, c.height);
  c.dataset.method = 'smooth';
  return c;
}
export async function upscale(canvas, { device } = {}) {
  const dev = device || ((await gpuOK()) ? 'webgpu' : 'wasm');
  if (dev === 'wasm') return smoothUpscale(canvas);
  try { const c = await swin(canvas, dev); c.dataset.method = 'swin2sr'; return c; } catch (e) { console.warn('[designs] Swin2SR upscale failed, smooth enlargement instead:', e.message); return smoothUpscale(canvas); }
}
export async function swin(canvas, dev) {
  const d = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
  const p = paths();
  const w = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
  try {
    const r = await new Promise((res, rej) => {
      w.onmessage = (e) => { if (e.data.progress || e.data.image) return; e.data.error ? rej(new Error(e.data.error)) : res(e.data.result); };
      w.onerror = (e) => rej(new Error(e.message || 'upscaler failed'));
      setTimeout(() => rej(new Error('the upscaler took too long')), 120000);
      w.postMessage({ id: 1, op: 'upscale', w: d.width, h: d.height, data: d.data.buffer, models: p.models, vendor: p.vendor, device: dev }, [d.data.buffer]);
    });
    const c = document.createElement('canvas'); c.width = r.w; c.height = r.h;
    c.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(r.data), r.w, r.h), 0, 0);
    return c;
  } finally { w.terminate(); }
}

export async function save(wsId, canvas, meta = {}) {
  const name = `ai-${new Date().toISOString().slice(0, 10)}-${(meta.seed ?? 0).toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
  const dir = `workspaces/${wsId}/designs/images`;
  const blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
  await engine.writeFile(`${dir}/${name}.png`, blob);
  await engine.writeFile(`${dir}/${name}.json`, { app: 'kbb-studio', kind: 'ai-picture', model: MODEL.repo, modelName: MODEL.name, licence: MODEL.licence, licenceUrl: MODEL.licenceUrl, prompt: meta.prompt || '', seed: meta.seed ?? null, upscaled: !!meta.upscaled, width: canvas.width, height: canvas.height, flags: meta.flags || [], made: new Date().toISOString(), note: 'Made offline on this Mac. Text is never part of the picture; product photos are real cut-outs.' });
  return `${dir}/${name}.png`;
}
