// Product cut-outs.
//
// 1. A real segmentation network, run locally in the browser with ONNX Runtime
//    Web: BiRefNet-lite (MIT) on WebGPU when the Mac has it, ISNet general-use
//    (Apache-2.0) on the CPU otherwise. The engine downloads the runtime and
//    the model once (Settings -> Connections -> Generators shows the size and
//    the pinned SHA-256) and serves them same-origin; the photo never leaves
//    the Mac. Each product's mask is computed ONCE and kept in the data folder
//    (/api/brain/x/seg/mask), so a design re-renders instantly.
// 2. Without a model: an edge-aware flood fill from the border (the old method
//    leaked through white caps: now strong edges stop it, holes are filled and
//    the edge is a soft ramp, not a hard threshold).
// Then, either way: edge decontamination (the white studio fringe is unmixed
// from the edge pixels, so there is no halo), crop to the product, and a
// palette picked from the product itself.
//
// Products shot on coloured or lifestyle backgrounds: the network finds the
// product anyway; the original photo is kept as .photo for the lifestyle
// (Ken Burns) moments of motion ads.

let info = null; // { token }
async function token() {
  if (!info) info = fetch('/api/info', { cache: 'no-store' }).then((r) => r.json()).catch(() => ({}));
  return (await info).token || '';
}
let account = '';
export function setSegAccount(a) { account = a || ''; }
async function get(path, type = 'json', init = {}) {
  const r = await fetch(path, Object.assign({ cache: 'no-store' }, init, { headers: Object.assign({ 'X-KAA': await token() }, account ? { 'X-KAA-Account': account } : {}, init.headers || {}) }));
  if (!r.ok) { const e = new Error(`HTTP ${r.status}`); e.status = r.status; throw e; }
  return type === 'json' ? r.json() : type === 'buf' ? r.arrayBuffer() : type === 'blob' ? r.blob() : r;
}

export const segLog = []; // what ran, for checks and the UI: {url, method, ms}

// ---------- model ----------
let statusP = null;
export function segStatus(force) {
  if (!statusP || force) statusP = get('/api/brain/x/seg/status').catch(() => null);
  return statusP;
}
export async function segDownload(model) {
  statusP = null;
  return get('/api/brain/x/seg/download', 'json', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ model }) });
}

let gpuP = null;
function hasWebGPU() {
  if (!gpuP) gpuP = (async () => { try { return !!(navigator.gpu && (await navigator.gpu.requestAdapter())); } catch { return false; } })();
  return gpuP;
}

// The model to use now: an explicit choice (localStorage kaa.seg), else
// BiRefNet on WebGPU, else ISNet; only installed ones count.
export async function pickModel() {
  const st = await segStatus();
  if (!st || !st.have || !st.have['ort-wasm']) return null;
  let pref = '';
  try { pref = localStorage.getItem('kaa.seg') || ''; } catch {}
  if (pref === 'off') return null;
  const gpu = await hasWebGPU();
  const ok = (m) => m && st.have[m.asset] && (!m.webgpu || gpu);
  const byId = Object.fromEntries((st.models || []).map((m) => [m.id, m]));
  if (pref && ok(byId[pref])) return byId[pref];
  return [byId['birefnet-lite'], byId.isnet].find(ok) || null;
}

let ortP = null;
const sessions = new Map();
async function session(m) {
  if (!sessions.has(m.id)) {
    sessions.set(m.id, (async () => {
      if (!ortP) {
        ortP = (async () => {
          const ort = await import('../../vendor/ort/ort.webgpu.bundle.min.js');
          ort.env.wasm.numThreads = 1; // no SharedArrayBuffer without cross-origin isolation
          ort.env.wasm.proxy = false;
          ort.env.wasm.wasmBinary = await get('/api/brain/x/seg/file/ort-wasm', 'buf');
          return ort;
        })();
      }
      const ort = await ortP;
      const bytes = await get(`/api/brain/x/seg/file/${m.asset}`, 'buf');
      const s = await ort.InferenceSession.create(bytes, { executionProviders: m.webgpu ? ['webgpu'] : ['wasm'], enableCpuMemArena: false, enableMemPattern: false, graphOptimizationLevel: 'all' });
      return { ort, s };
    })().catch((e) => { sessions.delete(m.id); throw e; }));
  }
  return sessions.get(m.id);
}

// one at a time: two 1024^2 networks at once would double the memory
let queue = Promise.resolve();
function serial(fn) { const p = queue.then(fn, fn); queue = p.catch(() => {}); return p; }

async function runModel(m, im) {
  const { ort, s } = await session(m);
  const N = m.input, c = document.createElement('canvas');
  c.width = c.height = N;
  const x = c.getContext('2d', { willReadFrequently: true });
  x.drawImage(im, 0, 0, N, N);
  const d = x.getImageData(0, 0, N, N).data, f = new Float32Array(3 * N * N), P = N * N;
  for (let i = 0; i < P; i++) for (let k = 0; k < 3; k++) f[k * P + i] = (d[i * 4 + k] / 255 - m.mean[k]) / m.std[k];
  const feeds = { [s.inputNames[0]]: new ort.Tensor('float32', f, [1, 3, N, N]) };
  const out = await s.run(feeds, [s.outputNames[0]]);
  const o = out[s.outputNames[0]];
  let data = o.data;
  if (!(data instanceof Float32Array)) data = Float32Array.from(data, Number); // fp16 models answer Float16/Uint16
  const mask = new Uint8ClampedArray(P);
  let mn = Infinity, mx = -Infinity;
  for (let i = 0; i < P; i++) { const v = m.sigmoid ? 1 / (1 + Math.exp(-data[i])) : data[i]; data[i] = v; if (v < mn) mn = v; if (v > mx) mx = v; }
  const span = Math.max(1e-6, mx - mn);
  for (let i = 0; i < P; i++) mask[i] = Math.round(((data[i] - mn) / span) * 255);
  for (const t of Object.values(out)) t.dispose && t.dispose();
  return { mask, w: N, h: N };
}

// ---------- helpers ----------
async function sha(s) {
  const b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s));
  return [...new Uint8Array(b)].slice(0, 16).map((v) => v.toString(16).padStart(2, '0')).join('');
}
function maskToCanvas(mask, w, h) {
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const x = c.getContext('2d');
  const id = x.createImageData(w, h);
  for (let i = 0; i < w * h; i++) { const v = mask[i]; id.data[i * 4] = id.data[i * 4 + 1] = id.data[i * 4 + 2] = v; id.data[i * 4 + 3] = 255; }
  x.putImageData(id, 0, 0);
  return c;
}
function blobOf(c) { return new Promise((ok) => c.toBlob(ok, 'image/png')); }
function loadImg(src) { return new Promise((ok, no) => { const im = new Image(); im.decoding = 'async'; im.onload = () => ok(im); im.onerror = no; im.src = src; }); }

// Border statistics: median colour and spread. A studio shot has a calm,
// near-uniform border; a lifestyle photo does not.
function borderStats(p, w, h) {
  const rs = [], gs = [], bs = [];
  const take = (i) => { rs.push(p[i * 4]); gs.push(p[i * 4 + 1]); bs.push(p[i * 4 + 2]); };
  const step = Math.max(1, Math.floor((w + h) / 400));
  for (let i = 0; i < w; i += step) { take(i); take((h - 1) * w + i); }
  for (let j = 0; j < h; j += step) { take(j * w); take(j * w + w - 1); }
  const med = (a) => a.slice().sort((x, y) => x - y)[a.length >> 1];
  const B = [med(rs), med(gs), med(bs)];
  let far = 0;
  for (let i = 0; i < rs.length; i++) if (Math.abs(rs[i] - B[0]) + Math.abs(gs[i] - B[1]) + Math.abs(bs[i] - B[2]) > 45) far++;
  return { B, uniform: far / rs.length < 0.12 };
}

// Classic mask (no model): flood from the border through pixels close to the
// border colour, stopped by edges; unreached = product; soft 2 px ramp.
function classicMask(p, w, h, B) {
  const n = w * h, lum = new Float32Array(n), dist = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    const r = p[i * 4], g = p[i * 4 + 1], b = p[i * 4 + 2];
    lum[i] = 0.299 * r + 0.587 * g + 0.114 * b;
    dist[i] = Math.hypot(r - B[0], g - B[1], b - B[2]);
  }
  const grad = (i) => { const xx = i % w, yy = (i - xx) / w; if (xx < 1 || yy < 1 || xx > w - 2 || yy > h - 2) return 0; return Math.abs(lum[i + 1] - lum[i - 1]) + Math.abs(lum[i + w] - lum[i - w]); };
  const seen = new Uint8Array(n), q = new Int32Array(n);
  let head = 0, tail = 0;
  const push = (i) => { if (!seen[i] && dist[i] < 34 && grad(i) < 18) { seen[i] = 1; q[tail++] = i; } };
  for (let i = 0; i < w; i++) { push(i); push((h - 1) * w + i); }
  for (let j = 0; j < h; j++) { push(j * w); push(j * w + w - 1); }
  while (head < tail) {
    const i = q[head++], cx = i % w;
    if (cx > 0) push(i - 1); if (cx < w - 1) push(i + 1); if (i >= w) push(i - w); if (i < n - w) push(i + w);
  }
  const m = new Uint8ClampedArray(n);
  for (let i = 0; i < n; i++) m[i] = seen[i] ? Math.max(0, Math.min(255, (dist[i] - 8) * 6)) : 255;
  // 2 px box blur for a soft, even edge
  const t = new Uint8ClampedArray(n);
  for (let pass = 0; pass < 2; pass++) {
    for (let j = 0; j < h; j++) for (let i = 1; i < w - 1; i++) { const k = j * w + i; t[k] = (m[k - 1] + m[k] + m[k + 1]) / 3; }
    for (let j = 1; j < h - 1; j++) for (let i = 0; i < w; i++) { const k = j * w + i; m[k] = (t[k - w] + t[k] + t[k + w]) / 3; }
  }
  return { mask: m, removed: tail / n };
}

// Palette: the product's own colours (saturated, frequent), as hex.
export function paletteOf(p, w, h) {
  const bins = new Map();
  for (let i = 0; i < w * h; i += 3) {
    if (p[i * 4 + 3] < 220) continue;
    const r = p[i * 4], g = p[i * 4 + 1], b = p[i * 4 + 2];
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b), sat = mx ? (mx - mn) / mx : 0;
    if (mx < 40 || (sat < 0.18 && mx > 200)) continue;
    const k = ((r >> 4) << 8) | ((g >> 4) << 4) | (b >> 4);
    const e = bins.get(k) || { n: 0, r: 0, g: 0, b: 0, sat: 0 };
    e.n++; e.r += r; e.g += g; e.b += b; e.sat += sat;
    bins.set(k, e);
  }
  const all = [...bins.values()].map((e) => ({ r: e.r / e.n, g: e.g / e.n, b: e.b / e.n, n: e.n, sat: e.sat / e.n })).sort((a, b) => b.n * (0.35 + b.sat) - a.n * (0.35 + a.sat));
  const out = [];
  for (const c of all) {
    if (out.every((o) => Math.hypot(o.r - c.r, o.g - c.g, o.b - c.b) > 60)) out.push(c);
    if (out.length === 4) break;
  }
  return out.map((c) => '#' + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join(''));
}

// ---------- public ----------
const cache = new Map();

// cut(url, imageElement): a canvas with transparency, cropped to the product.
// Properties: .cutout (true when the background was removed), .method,
// .palette (hex), .photo (the original, for lifestyle moments), .ms.
export function cut(key, im) {
  if (!cache.has(key)) cache.set(key, serial(() => doCut(key, im)).catch((e) => { console.warn('cut-out failed', e); return fallbackCanvas(im); }));
  return cache.get(key);
}

function fallbackCanvas(im) {
  const c = document.createElement('canvas');
  c.width = im.naturalWidth; c.height = im.naturalHeight;
  c.getContext('2d').drawImage(im, 0, 0);
  c.cutout = false; c.method = 'none'; c.palette = []; c.photo = c;
  return c;
}

async function doCut(key, im) {
  const t0 = performance.now();
  const s = Math.min(1, 1100 / Math.max(im.naturalWidth, im.naturalHeight));
  const w = Math.max(1, Math.round(im.naturalWidth * s)), h = Math.max(1, Math.round(im.naturalHeight * s));
  const cv = document.createElement('canvas');
  cv.width = w; cv.height = h;
  const x = cv.getContext('2d', { willReadFrequently: true });
  x.drawImage(im, 0, 0, w, h);
  let d;
  try { d = x.getImageData(0, 0, w, h); } catch { return fallbackCanvas(im); }
  const p = d.data;
  const photo = document.createElement('canvas');
  photo.width = w; photo.height = h; photo.getContext('2d').drawImage(cv, 0, 0);
  const { B, uniform } = borderStats(p, w, h);

  // the mask: model (cached) or classic
  let alpha = null, method = 'classic';
  const m = await pickModel().catch(() => null);
  if (m) {
    const mk = await sha(`${m.id}|${key}`);
    let mc = null;
    try { const b = await get(`/api/brain/x/seg/mask?key=${mk}`, 'blob'); if (b.size) mc = await loadImg(URL.createObjectURL(b)); } catch {}
    if (!mc) {
      try {
        const r = await runModel(m, im);
        const c = maskToCanvas(r.mask, r.w, r.h);
        const b = await blobOf(c);
        get(`/api/brain/x/seg/mask?key=${mk}`, 'json', { method: 'PUT', body: b, headers: { 'content-type': 'image/png' } }).catch(() => {});
        mc = c;
        method = m.id;
      } catch (e) { console.warn('segmentation model failed, using the classic cut-out', e); }
    } else method = m.id + ' (cached)';
    if (mc) {
      const mx = document.createElement('canvas');
      mx.width = w; mx.height = h;
      const g = mx.getContext('2d', { willReadFrequently: true });
      g.imageSmoothingQuality = 'high';
      g.drawImage(mc, 0, 0, w, h);
      const md = g.getImageData(0, 0, w, h).data;
      alpha = new Uint8ClampedArray(w * h);
      // gentle contrast: lifts the faint haze to 0 and the product core to 1
      for (let i = 0; i < w * h; i++) { const v = md[i * 4] / 255, a = Math.min(1, Math.max(0, (v - 0.06) / 0.86)); alpha[i] = Math.round(a * a * (3 - 2 * a) * 255); }
    }
  }
  if (!alpha) {
    if (!uniform) { // lifestyle / coloured photo and no model: keep the photo whole
      photo.cutout = false; photo.method = 'photo'; photo.palette = paletteOf(p, w, h); photo.photo = photo; photo.ms = Math.round(performance.now() - t0);
      segLog.push({ key, method: 'photo', ms: photo.ms });
      return photo;
    }
    const r = classicMask(p, w, h, B);
    if (r.removed < 0.06) { photo.cutout = false; photo.method = 'photo'; photo.palette = paletteOf(p, w, h); photo.photo = photo; return photo; }
    alpha = r.mask;
  }

  // apply alpha + decontaminate the edge (unmix the background colour)
  for (let i = 0; i < w * h; i++) {
    const a = alpha[i] / 255;
    p[i * 4 + 3] = alpha[i];
    if (uniform && a > 0.02 && a < 0.97) {
      for (let k = 0; k < 3; k++) p[i * 4 + k] = Math.max(0, Math.min(255, (p[i * 4 + k] - (1 - a) * B[k]) / a));
    }
  }
  x.putImageData(d, 0, 0);
  // crop to the product (+2% air)
  let x0 = w, y0 = h, x1 = -1, y1 = -1;
  for (let j = 0; j < h; j++) for (let i = 0; i < w; i++) if (alpha[j * w + i] > 26) { if (i < x0) x0 = i; if (i > x1) x1 = i; if (j < y0) y0 = j; if (j > y1) y1 = j; }
  if (x1 < 0 || (x1 - x0) * (y1 - y0) < w * h * 0.01) { photo.cutout = false; photo.method = 'photo'; photo.palette = paletteOf(p, w, h); photo.photo = photo; return photo; }
  const pad = Math.round(Math.max(x1 - x0, y1 - y0) * 0.02);
  x0 = Math.max(0, x0 - pad); y0 = Math.max(0, y0 - pad); x1 = Math.min(w - 1, x1 + pad); y1 = Math.min(h - 1, y1 + pad);
  const out = document.createElement('canvas');
  out.width = x1 - x0 + 1; out.height = y1 - y0 + 1;
  out.getContext('2d').drawImage(cv, x0, y0, out.width, out.height, 0, 0, out.width, out.height);
  out.cutout = true;
  out.method = method;
  out.photo = uniform ? null : photo;
  const od = out.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, out.width, out.height).data;
  out.palette = paletteOf(od, out.width, out.height);
  out.ms = Math.round(performance.now() - t0);
  segLog.push({ key, method, ms: out.ms });
  return out;
}
