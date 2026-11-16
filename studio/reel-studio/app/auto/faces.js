// KBB Studio · face detection with MediaPipe Tasks Vision (vendored, offline: vendor/mediapipe/).
// Runs in faces-worker.js (classic worker) so the 9 MB WASM start-up and every detection stay off the UI thread.
// If a browser cannot start that worker, it falls back to the page, one frame per idle slice.
const BASE = new URL('../../vendor/mediapipe/', import.meta.url).href;

let W = null, seq = 0; const pending = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./faces-worker.js', import.meta.url));          // classic on purpose (see faces-worker.js)
  W.onmessage = (e) => { const p = pending.get(e.data.id); if (!p) return; pending.delete(e.data.id); e.data.error ? p.rej(new Error(e.data.error)) : p.res(e.data.faces); };
  W.onerror = (e) => { e.preventDefault?.(); for (const p of pending.values()) p.rej(new Error(e.message || 'face worker failed')); pending.clear(); W = null; };
  return W;
}
const viaWorker = (bitmaps, ts) => new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); worker().postMessage({ id, bitmaps, ts }, bitmaps); });

// ── page fallback ──
const NOISE = /^(INFO:|W\d{4} |I\d{4} |Graph successfully started running|.*XNNPACK delegate)/;
async function quiet(fn) {
  const orig = console.error, origW = console.warn;
  console.error = (...a) => { if (!NOISE.test(String(a[0] ?? ''))) orig(...a); };
  console.warn = (...a) => { if (!NOISE.test(String(a[0] ?? ''))) origW(...a); };
  try { return await fn(); } finally { console.error = orig; console.warn = origW; }
}
let detP = null;
function pageDetector() {
  return (detP ||= quiet(async () => {
    const { FilesetResolver, FaceDetector } = await import(BASE + 'tasks-vision-0.10.21/vision_bundle.mjs');
    const fileset = await FilesetResolver.forVisionTasks(BASE + 'tasks-vision-0.10.21/wasm');
    return FaceDetector.createFromOptions(fileset, { baseOptions: { modelAssetPath: BASE + 'models/blaze_face_short_range.tflite', delegate: 'CPU' }, runningMode: 'IMAGE', minDetectionConfidence: 0.5 });
  }).catch(e => { detP = null; throw e; }));
}
const idle = () => new Promise(r => (typeof requestIdleCallback === 'function' ? requestIdleCallback(() => r(), { timeout: 50 }) : setTimeout(r, 0)));
async function inPage(frames, onProgress) {
  const det = await pageDetector(), out = [];
  for (let i = 0; i < frames.length; i++) {
    const bm = frames[i].bitmap, r = await quiet(() => det.detect(bm)); let best = null;
    for (const d of r.detections || []) { const b = d.boundingBox, a = b.width * b.height; if (!best || a > best.a) best = { a, x: (b.originX + b.width / 2) / bm.width, y: (b.originY + b.height / 2) / bm.height, w: b.width / bm.width, h: b.height / bm.height, conf: d.categories?.[0]?.score ?? 0 }; }
    if (best) delete best.a;
    out.push({ t: frames[i].t, ...(best || { x: null, y: null, conf: 0 }) });
    onProgress?.(i / frames.length); await idle();
  }
  return out;
}

/** faces over [{t, bitmap}] -> [{t, x, y, w, h, conf}] (x null when nobody). Bitmaps are consumed (transferred). */
export async function trackFaces(frames, onProgress) {
  const out = [];
  try {
    for (let i = 0; i < frames.length; i += 8) {
      const batch = frames.slice(i, i + 8);
      out.push(...await viaWorker(batch.map(f => f.bitmap), batch.map(f => f.t)));
      onProgress?.(Math.min(1, (i + 8) / frames.length));
    }
    return out;
  } catch (e) {
    if (out.length) throw e;
    console.warn('[auto] face worker unavailable, detecting in the page:', e.message);
    return inPage(frames, onProgress);
  }
}
/** one image -> biggest face or null (copies the source into a bitmap first) */
export async function detectFace(source) {
  const bm = await createImageBitmap(source);
  const [f] = await trackFaces([{ t: 0, bitmap: bm }]);
  return f && f.x != null ? f : null;
}
