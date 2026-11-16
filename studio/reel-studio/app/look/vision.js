// KBB Studio · look · per-media analysis the GPU effects draw from: person masks (background removal, object-cover
// transition) and face landmarks (skin touch-up). Computed ONCE per media, offline, in vision-worker.js, on a fixed time
// grid; drawing then only samples the cache, so the preview stays real time and the export draws the very same masks.
// Temporal smoothing: masks are averaged over neighbouring grid frames (1-2-1) and blended between grid points.
import { MediaStore } from '../core/media.js';
import { findMedia } from '../core/project.js';

export const SEG_FPS = 15, FACE_FPS = 10, SEG_SIZE = 256, FACE_SIZE = 640;
const SEG = new Map();    // mediaId -> { w, h, frames: Map(k -> Uint8Array) }
const FACE = new Map();   // mediaId -> { frames: Map(k -> [Float32Array]) }

let W = null, seq = 0; const pending = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./vision-worker.js', import.meta.url));
  W.onmessage = (e) => { const p = pending.get(e.data.id); if (!p) return; pending.delete(e.data.id); e.data.error ? p.rej(new Error(e.data.error)) : p.res(e.data); };
  W.onerror = (e) => { e.preventDefault?.(); for (const p of pending.values()) p.rej(new Error(e.message || 'vision worker failed')); pending.clear(); W = null; };
  return W;
}
const call = (op, bitmaps) => new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); worker().postMessage({ id, op, bitmaps }, bitmaps); });

/** grid indices (at fps) a media needs: the union of the source ranges its items use */
export function neededFrames(project, mediaId, fps, pred = () => true) {
  const ks = new Set();
  for (const tr of project.tracks) for (const it of tr.items) {
    if (it.media !== mediaId || !pred(it)) continue;
    const a = Math.max(0, Math.floor((it.in - 0.2) * fps)), b = Math.ceil((it.out + 0.2) * fps);
    for (let k = a; k <= b; k++) ks.add(k);
  }
  return [...ks].sort((x, y) => x - y);
}

const usesFx = (type) => (it) => (it.fx || []).some((f) => f.type === type && (type !== 'look.touchup' || (f.strength ?? 0.35) > 0));
const usesCover = (project) => {
  const ids = new Set();
  for (const tr of project.tracks) for (const it of tr.items) if (it.type === 'look.transition' && it.style?.kind === 'cover') ids.add(it.style.a);
  return (it) => ids.has(it.id);
};

/**
 * Analyse what the project needs and is not cached yet. onProgress(label, 0..1).
 * kinds: which analyses to run ('seg', 'face'); default: whatever the project's effects use.
 */
let queue = Promise.resolve();
export function ensureAnalysis(project, opts = {}) {
  // one analysis at a time: a second caller waits, then finds the work already done
  const run = queue.then(() => analyse(project, opts));
  queue = run.catch(() => {});
  return run;
}
async function analyse(project, { onProgress, only } = {}) {
  const jobs = [];
  for (const m of project.media) {
    if (m.kind !== 'video' && m.kind !== 'image') continue;
    if (only && !only.includes(m.id)) continue;
    const cover = usesCover(project);
    const segK = neededFrames(project, m.id, SEG_FPS, (it) => usesFx('look.bg')(it) || cover(it)).filter((k) => !SEG.get(m.id)?.frames.has(k));
    const faceK = neededFrames(project, m.id, FACE_FPS, usesFx('look.touchup')).filter((k) => !FACE.get(m.id)?.frames.has(k));
    if (m.kind === 'image') { if (segK.length) segK.splice(1); if (faceK.length) faceK.splice(1); }
    if (segK.length || faceK.length) jobs.push({ m, segK, faceK });
  }
  if (!jobs.length) return false;
  const total = jobs.reduce((s, j) => s + j.segK.length + j.faceK.length, 0);
  let done = 0;
  for (const { m, segK, faceK } of jobs) {
    const store = new MediaStore({ getProject: () => project, engine: null });
    const reader = await store.openReader(m.id);
    try {
      // one decode pass in time order; each grid time feeds the segmenter and/or the landmarker
      const times = new Map();
      for (const k of segK) times.set(+(k / SEG_FPS).toFixed(4), Object.assign(times.get(+(k / SEG_FPS).toFixed(4)) || {}, { seg: k }));
      for (const k of faceK) { const t = +(k / FACE_FPS).toFixed(4); times.set(t, Object.assign(times.get(t) || {}, { face: k })); }
      const order = [...times.keys()].sort((a, b) => a - b);
      let segBatch = [], faceBatch = [];
      const flush = async (force) => {
        if (segBatch.length && (force || segBatch.length >= 8)) {
          const b = segBatch; segBatch = [];
          const r = await call('seg', b.map((x) => x.bm));
          const rec = SEG.get(m.id) || { w: r.masks[0].w, h: r.masks[0].h, frames: new Map() }; SEG.set(m.id, rec);
          r.masks.forEach((mk, i) => rec.frames.set(b[i].k, mk.data));
          done += b.length; onProgress?.('Finding the person…', done / total);
        }
        if (faceBatch.length && (force || faceBatch.length >= 8)) {
          const b = faceBatch; faceBatch = [];
          const r = await call('face', b.map((x) => x.bm));
          const rec = FACE.get(m.id) || { frames: new Map() }; FACE.set(m.id, rec);
          r.faces.forEach((f, i) => rec.frames.set(b[i].k, f));
          done += b.length; onProgress?.('Mapping the face…', done / total);
        }
      };
      for (const t of order) {
        const need = times.get(t);
        const src = await reader.frame(m.kind === 'image' ? 0 : Math.min(t, Math.max(0, (m.dur || t) - 0.02)));
        const sw = src.displayWidth || src.videoWidth || src.naturalWidth || src.width, sh = src.displayHeight || src.videoHeight || src.naturalHeight || src.height;
        if (need.seg != null) { const k = SEG_SIZE / Math.max(sw, sh); segBatch.push({ k: need.seg, bm: await createImageBitmap(src, { resizeWidth: Math.round(sw * k), resizeHeight: Math.round(sh * k), resizeQuality: 'medium' }) }); }
        if (need.face != null) { const k = Math.min(1, FACE_SIZE / Math.max(sw, sh)); faceBatch.push({ k: need.face, bm: await createImageBitmap(src, { resizeWidth: Math.round(sw * k), resizeHeight: Math.round(sh * k), resizeQuality: 'medium' }) }); }
        await flush(false);
      }
      await flush(true);
      // an image is one frame: copy it to every grid index asked for
      if (m.kind === 'image') {
        const s = SEG.get(m.id); if (s) { const one = s.frames.values().next().value; if (one) for (const k of neededFrames(project, m.id, SEG_FPS)) s.frames.set(k, one); }
        const f = FACE.get(m.id); if (f) { const one = f.frames.values().next().value; if (one) for (const k of neededFrames(project, m.id, FACE_FPS)) f.frames.set(k, one); }
      }
    } finally { reader?.close(); }
  }
  onProgress?.(null, null);
  return true;
}

const nearest = (frames, k) => { if (frames.has(k)) return frames.get(k); for (let d = 1; d < 6; d++) { if (frames.has(k - d)) return frames.get(k - d); if (frames.has(k + d)) return frames.get(k + d); } return null; };

const smoothCache = new Map();
function smoothed(id, rec, k) {
  const key = id + ':' + k;
  if (smoothCache.has(key)) return smoothCache.get(key);
  const a = nearest(rec.frames, k - 1), b = nearest(rec.frames, k), c = nearest(rec.frames, k + 1);
  if (!b) return null;
  const out = new Uint8Array(b.length);
  for (let i = 0; i < b.length; i++) out[i] = ((a ? a[i] : b[i]) + 2 * b[i] + (c ? c[i] : b[i]) + 2) >> 2;
  if (smoothCache.size > 120) smoothCache.delete(smoothCache.keys().next().value);
  smoothCache.set(key, out);
  return out;
}
const blend = new Map();
/** person mask at source time t: {w, h, data} (temporally smoothed) or null when not analysed */
export function segAt(mediaId, t) {
  const rec = SEG.get(mediaId); if (!rec) return null;
  const f = Math.max(0, t) * SEG_FPS, k = Math.floor(f), u = f - k;
  const a = smoothed(mediaId, rec, k), b = u > 0.01 ? smoothed(mediaId, rec, k + 1) : null;
  if (!a) return null;
  if (!b) return { w: rec.w, h: rec.h, data: a, key: mediaId + ':' + k };
  let out = blend.get(mediaId); if (!out || out.length !== a.length) { out = new Uint8Array(a.length); blend.set(mediaId, out); }
  const wb = Math.round(u * 256), wa = 256 - wb;
  for (let i = 0; i < a.length; i++) out[i] = (a[i] * wa + b[i] * wb) >> 8;
  return { w: rec.w, h: rec.h, data: out, key: mediaId + ':' + f.toFixed(3) };
}
/** face landmarks at source time t: [Float32Array(478*2)] interpolated, [] when none, null when not analysed */
export function facesAt(mediaId, t) {
  const rec = FACE.get(mediaId); if (!rec) return null;
  const f = Math.max(0, t) * FACE_FPS, k = Math.floor(f), u = f - k;
  const A = nearest(rec.frames, k), B = nearest(rec.frames, k + 1) || A;
  if (!A) return [];
  return A.map((a, i) => { const b = B[i]; if (!b || b.length !== a.length) return a; const o = new Float32Array(a.length); for (let j = 0; j < a.length; j++) o[j] = a[j] + (b[j] - a[j]) * u; return o; });
}
export const hasSeg = (id) => SEG.has(id);
export const hasFace = (id) => FACE.has(id);
/** share of the frame the person covers at t (0..1), for the checks and the panel */
export function coverageAt(mediaId, t) { const m = segAt(mediaId, t); if (!m) return null; let s = 0; for (let i = 0; i < m.data.length; i++) s += m.data[i] > 127 ? 1 : 0; return s / m.data.length; }
export function forget(mediaId) { SEG.delete(mediaId); FACE.delete(mediaId); for (const k of [...smoothCache.keys()]) if (k.startsWith(mediaId + ':')) smoothCache.delete(k); }
export { findMedia };
