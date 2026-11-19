// KBB Studio · look · per-media analysis the GPU effects draw from: person masks (background removal, object-cover
// transition) and face landmarks (skin touch-up). Computed ONCE per media, offline, in vision-worker.js, on a fixed time
// grid; drawing then only samples the cache, so the preview stays real time and the export draws the very same masks.
// Temporal smoothing: masks are averaged over neighbouring grid frames (1-2-1) and blended between grid points.
import { MediaStore } from '../core/media.js';
import { bus } from '../core/bus.js';
import { findMedia, srcTime, itemEnd } from '../core/project.js';
import { subjectReady, subjectMasks, SUBJECT_SIZE } from './subject.js';

export const SEG_FPS = 15, FACE_FPS = 10, SEG_SIZE = 256, FACE_SIZE = 640;
const SEG = new Map();    // mediaId -> { w, h, frames: Map(k -> Uint8Array) }
const FACE = new Map();   // mediaId -> { frames: Map(k -> [Float32Array]) }
// Background removal v2 (look.bg q >= 2, bg.js): its own mask tracks, so a project saved before it draws exactly as it
// did. 'person': the selfie segmenter on a 30 fps grid (fast motion no longer smears between 15 fps samples); 'subject':
// U²-Net-p (subject.js, optional model) on a 10 fps grid. Drawing reads them through maskAt() with the temporal filter.
export const MASK_FPS = { person: 30, subject: 10 };
const MASKS = new Map();  // mediaId + '|' + kind -> { w, h, fps, frames: Map(k -> Uint8Array) }

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

const usesFx = (type) => (it) => (it.fx || []).some((f) => f.type === type && (type !== 'look.touchup' || (f.strength ?? 0.35) > 0) && (type !== 'look.bg' || !((f.q || 1) >= 2)));
/** which v2 mask track a clip's look.bg needs: 'person' | 'subject' | null (chroma key and v1 need none here) */
export const maskKind = (f) => (f && f.type === 'look.bg' && (f.q || 1) >= 2 && f.key !== 'chroma' ? (f.key === 'subject' ? 'subject' : 'person') : null);
const usesKind = (kind) => (it) => (it.fx || []).some((f) => maskKind(f) === kind);
const usesCover = (project) => {
  const ids = new Set();
  for (const tr of project.tracks) for (const it of tr.items) {
    if (it.type === 'look.transition' && it.style?.kind === 'cover') ids.add(it.style.a);
    // the cut-out transitions (themes/cutout-trans.js) need the person on both sides of their cut
    else if (it.type === 'theme.trans' && /^cut-/.test(it.style?.kind || '')) { ids.add(it.style.a); ids.add(it.style.b); }
  }
  return (it) => ids.has(it.id);
};

/**
 * "Text behind the host" elements (themes/textbehind.js) need the person (or the main subject) of the clip they stand on,
 * only over the time they overlap it: the grid indices of media `mediaId` those elements ask for with mask `kind`.
 */
export function behindFrames(project, mediaId, fps, kind) {
  const ks = new Set(), tracks = project.tracks || [];
  tracks.forEach((tr, ti) => {
    for (const el of tr.items) {
      if (el.type !== 'theme.el' || el.style?.el !== 'tbehind' || ((el.style.key === 'subject' ? 'subject' : 'person') !== kind)) continue;
      const a = el.t0, b = itemEnd(el);
      for (let j = 0; j < ti; j++) for (const it of tracks[j].items) {
        if (it.type !== 'clip' || it.media !== mediaId) continue;
        const lo = Math.max(a, it.t0), hi = Math.min(b, itemEnd(it)); if (hi <= lo) continue;
        // sample the overlap finely enough to follow speed ramps, then pad one grid step each side
        const n = Math.max(2, Math.ceil((hi - lo) * fps * 2));
        for (let i = 0; i <= n; i++) { const s = srcTime(it, lo + (hi - lo) * Math.min(1, i / n) - (i === n ? 1e-4 : 0)), k = Math.floor(s * fps); ks.add(k - 1); ks.add(k); ks.add(k + 1); ks.add(k + 2); }
      }
    }
  });
  ks.delete(-1);
  return ks;
}

/**
 * Analyse what the project needs and is not cached yet. onProgress(label, 0..1).
 * kinds: which analyses to run ('seg', 'face'); default: whatever the project's effects use.
 */
// It also tells the editor how it went on the bus — 'vision:progress' {label, f}, 'vision:done' {ran}, 'vision:failed'
// {message} — so a status line that says "Finding the person…" moves on when the masks arrive, whoever started the
// analysis (the editor's catch-up after an edit has no progress callback of its own), and says so when it failed
// instead of waiting for ever (the owner, 19 Nov: text behind the host "not working properly").
let queue = Promise.resolve();
export function ensureAnalysis(project, opts = {}) {
  // one analysis at a time: a second caller waits, then finds the work already done
  const onProgress = (l, f) => { opts.onProgress?.(l, f); if (l) bus.emit('vision:progress', { label: l, f }); };
  const run = queue.then(() => analyse(project, { ...opts, onProgress }));
  run.then((ran) => bus.emit('vision:done', { ran: !!ran }), (e) => bus.emit('vision:failed', { message: e?.message || String(e) }));
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
    const kinds = {};
    for (const kind of ['person', 'subject']) {
      const rec = MASKS.get(m.id + '|' + kind), ks = [...new Set([...neededFrames(project, m.id, MASK_FPS[kind], usesKind(kind)), ...behindFrames(project, m.id, MASK_FPS[kind], kind)])].sort((x, y) => x - y).filter((k) => k >= 0 && !rec?.frames.has(k));
      if (!ks.length || (kind === 'subject' && !(await subjectReady()))) continue;
      if (m.kind === 'image') ks.splice(1);
      kinds[kind] = ks;
    }
    if (m.kind === 'image') { if (segK.length) segK.splice(1); if (faceK.length) faceK.splice(1); }
    if (segK.length || faceK.length || Object.keys(kinds).length) jobs.push({ m, segK, faceK, kinds });
  }
  if (!jobs.length) return false;
  const total = jobs.reduce((s, j) => s + j.segK.length + j.faceK.length + Object.values(j.kinds).reduce((a, x) => a + x.length, 0), 0);
  let done = 0;
  for (const { m, segK, faceK, kinds } of jobs) {
    const store = new MediaStore({ getProject: () => project, engine: null });
    const reader = await store.openReader(m.id);
    try {
      // one decode pass in time order; each grid time feeds the segmenter and/or the landmarker
      const times = new Map();
      for (const k of segK) times.set(+(k / SEG_FPS).toFixed(4), Object.assign(times.get(+(k / SEG_FPS).toFixed(4)) || {}, { seg: k }));
      for (const k of faceK) { const t = +(k / FACE_FPS).toFixed(4); times.set(t, Object.assign(times.get(t) || {}, { face: k })); }
      for (const [kind, ks] of Object.entries(kinds)) for (const k of ks) { const t = +(k / MASK_FPS[kind]).toFixed(4); const e = times.get(t) || {}; (e.kinds ||= []).push([kind, k]); times.set(t, e); }
      const order = [...times.keys()].sort((a, b) => a - b);
      let segBatch = [], faceBatch = [];
      const kBatch = { person: [], subject: [] };
      const flushKind = async (kind, force) => {
        const b = kBatch[kind]; if (!b.length || (!force && b.length < 8)) return;
        kBatch[kind] = [];
        const r = kind === 'subject' ? await subjectMasks(b.map((x) => x.bm)) : (await call('seg', b.map((x) => x.bm))).masks;
        const key = m.id + '|' + kind, rec = MASKS.get(key) || { w: r[0].w, h: r[0].h, fps: MASK_FPS[kind], frames: new Map() }; MASKS.set(key, rec);
        r.forEach((mk, i) => rec.frames.set(b[i].k, mk.data));
        done += b.length; onProgress?.(kind === 'subject' ? 'Finding the main subject…' : 'Finding the person…', done / total);
      };
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
        for (const [kind, k] of need.kinds || []) {
          // the person model keeps the picture's shape (long side 256); U²-Net-p takes its square 320 x 320 input
          const sz = kind === 'subject' ? [SUBJECT_SIZE, SUBJECT_SIZE] : [Math.round(sw * SEG_SIZE / Math.max(sw, sh)), Math.round(sh * SEG_SIZE / Math.max(sw, sh))];
          kBatch[kind].push({ k, bm: await createImageBitmap(src, { resizeWidth: sz[0], resizeHeight: sz[1], resizeQuality: 'high' }) });
          await flushKind(kind, false);
        }
        await flush(false);
      }
      await flush(true);
      for (const kind of Object.keys(kinds)) await flushKind(kind, true);
      // an image is one frame: copy it to every grid index asked for
      if (m.kind === 'image') {
        const s = SEG.get(m.id); if (s) { const one = s.frames.values().next().value; if (one) for (const k of neededFrames(project, m.id, SEG_FPS)) s.frames.set(k, one); }
        const f = FACE.get(m.id); if (f) { const one = f.frames.values().next().value; if (one) for (const k of neededFrames(project, m.id, FACE_FPS)) f.frames.set(k, one); }
        for (const kind of Object.keys(kinds)) { const r = MASKS.get(m.id + '|' + kind), one = r?.frames.values().next().value; if (one) for (const k of neededFrames(project, m.id, r.fps)) r.frames.set(k, one); }
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
// ── v2 masks: temporal filter ──
// Each grid frame is averaged with its neighbours (±R frames, R = Smoothness x 4) with a Gaussian in time AND a
// per-pixel range weight: a neighbour that disagrees a lot (the arm really moved) counts little, one that differs a
// little (the segmenter's frame-to-frame shimmer) counts fully. So still edges stop flickering and fast motion does
// not ghost. Offline and cached: the export reads the very same arrays.
const RW = new Float32Array(256);
for (let i = 0; i < 256; i++) { const x = i / 255 / 0.3; RW[i] = Math.exp(-x * x); }
const tCache = new Map();
function temporal(key, rec, k, smooth) {
  const R = Math.round(Math.max(0, Math.min(1, smooth)) * 4), ck = key + ':' + k + ':' + R;
  if (tCache.has(ck)) { const v = tCache.get(ck); tCache.delete(ck); tCache.set(ck, v); return v; }
  const c = nearest(rec.frames, k); if (!c) return null;
  if (!R) return c;
  const nb = [], sig = R / 1.6 + 0.4;
  for (let d = -R; d <= R; d++) { if (!d) continue; const f = rec.frames.get(k + d); if (f && f.length === c.length) nb.push([f, Math.exp(-(d * d) / (2 * sig * sig))]); }
  const out = new Uint8Array(c.length);
  for (let i = 0; i < c.length; i++) {
    const v = c[i]; let s = v, w = 1;
    for (let j = 0; j < nb.length; j++) { const u = nb[j][0][i], ww = nb[j][1] * RW[u > v ? u - v : v - u]; s += u * ww; w += ww; }
    out[i] = (s / w + 0.5) | 0;
  }
  if (tCache.size > 160) tCache.delete(tCache.keys().next().value);
  tCache.set(ck, out);
  return out;
}
const blend2 = new Map();
/** v2 mask at source time t: {w, h, data, key} (kind 'person' | 'subject', smoothness 0..1), null when not analysed */
export function maskAt(mediaId, t, kind = 'person', smooth = 0.5) {
  const key = mediaId + '|' + kind, rec = MASKS.get(key); if (!rec) return null;
  const f = Math.max(0, t) * rec.fps, k = Math.floor(f + 1e-6), u = f - k;
  const a = temporal(key, rec, k, smooth); if (!a) return null;
  const b = u > 0.02 ? temporal(key, rec, k + 1, smooth) : null;
  if (!b || b.length !== a.length) return { w: rec.w, h: rec.h, data: a, key: key + ':' + k };
  let out = blend2.get(key); if (!out || out.length !== a.length) { out = new Uint8Array(a.length); blend2.set(key, out); }
  const wb = Math.round(u * 256), wa = 256 - wb;
  for (let i = 0; i < a.length; i++) out[i] = (a[i] * wa + b[i] * wb) >> 8;
  return { w: rec.w, h: rec.h, data: out, key: key + ':' + f.toFixed(3) };
}
export const hasMask = (id, kind = 'person') => MASKS.has(id + '|' + kind);
/** share of the frame a v2 mask covers at t (0..1) */
export function maskCoverageAt(mediaId, t, kind = 'person', smooth = 0.5) { const m = maskAt(mediaId, t, kind, smooth); if (!m) return null; let s = 0; for (let i = 0; i < m.data.length; i++) s += m.data[i] > 127 ? 1 : 0; return s / m.data.length; }

export const hasSeg = (id) => SEG.has(id);
export const hasFace = (id) => FACE.has(id);
/** share of the frame the person covers at t (0..1), for the checks and the panel */
export function coverageAt(mediaId, t) { const m = segAt(mediaId, t) || maskAt(mediaId, t, 'person'); if (!m) return null; let s = 0; for (let i = 0; i < m.data.length; i++) s += m.data[i] > 127 ? 1 : 0; return s / m.data.length; }
export function forget(mediaId) {
  SEG.delete(mediaId); FACE.delete(mediaId); for (const k of [...smoothCache.keys()]) if (k.startsWith(mediaId + ':')) smoothCache.delete(k);
  for (const k of [...MASKS.keys()]) if (k.startsWith(mediaId + '|')) MASKS.delete(k);
  for (const k of [...tCache.keys()]) if (k.startsWith(mediaId + '|')) tCache.delete(k);
}
export { findMedia };
