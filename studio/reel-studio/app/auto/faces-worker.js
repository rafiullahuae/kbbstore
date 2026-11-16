// KBB Studio · face detection worker. A CLASSIC worker on purpose: the MediaPipe loader calls importScripts(),
// which module workers lack; the ESM bundle itself comes in through dynamic import(), which classic workers allow.
// Protocol: {id, bitmaps:[ImageBitmap], ts:[t]} -> {id, faces:[{t,x,y,w,h,conf}|{t,x:null}]} | {id, error}
const BASE = new URL('../../vendor/mediapipe/', self.location.href).href;
let detP = null;
const NOISE = /^(INFO:|W\d{4} |I\d{4} |Graph successfully started running|.*XNNPACK delegate)/;
const ce = console.error.bind(console), cw = console.warn.bind(console);
console.error = (...a) => { if (!NOISE.test(String(a[0] ?? ''))) ce(...a); };
console.warn = (...a) => { if (!NOISE.test(String(a[0] ?? ''))) cw(...a); };

function detector() {
  return (detP ||= (async () => {
    const { FilesetResolver, FaceDetector } = await import(BASE + 'tasks-vision-0.10.21/vision_bundle.mjs');
    const fileset = await FilesetResolver.forVisionTasks(BASE + 'tasks-vision-0.10.21/wasm');
    return FaceDetector.createFromOptions(fileset, { baseOptions: { modelAssetPath: BASE + 'models/blaze_face_short_range.tflite', delegate: 'CPU' }, runningMode: 'IMAGE', minDetectionConfidence: 0.5 });
  })().catch(e => { detP = null; throw e; }));
}

self.onmessage = async (e) => {
  const { id, bitmaps = [], ts = [] } = e.data || {};
  try {
    const det = await detector(), faces = [];
    bitmaps.forEach((bm, i) => {
      const r = det.detect(bm), W = bm.width, H = bm.height; let best = null;
      for (const d of r.detections || []) { const b = d.boundingBox, a = b.width * b.height; if (!best || a > best.a) best = { a, x: (b.originX + b.width / 2) / W, y: (b.originY + b.height / 2) / H, w: b.width / W, h: b.height / H, conf: d.categories?.[0]?.score ?? 0 }; }
      if (best) delete best.a;
      faces.push({ t: ts[i], ...(best || { x: null, y: null, conf: 0 }) });
      bm.close?.();
    });
    self.postMessage({ id, faces });
  } catch (err) { self.postMessage({ id, error: String(err && err.message || err) }); }
};
