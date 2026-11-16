// KBB Studio · look · person segmentation + face landmarks (MediaPipe Tasks Vision, vendored, offline, Apache-2.0).
// A CLASSIC worker on purpose (MediaPipe's loader calls importScripts()), like app/auto/faces-worker.js.
// Models (vendor/mediapipe/models/): selfie_segmenter.tflite (244 KB), face_landmarker.task (3.6 MB).
// Protocol: {id, op:'seg'|'face', bitmaps:[ImageBitmap]} ->
//   seg:  {id, masks:[{w,h,data:Uint8Array}]}           person confidence 0..255
//   face: {id, faces:[[Float32Array(478*2) x,y 0..1]]}   up to two faces per frame
const BASE = new URL('../../vendor/mediapipe/', self.location.href).href;
const NOISE = /^(INFO:|W\d{4} |I\d{4} |Graph successfully started running|.*XNNPACK delegate|.*Feedback manager requires)/;
const ce = console.error.bind(console), cw = console.warn.bind(console), cl = console.log.bind(console);
console.error = (...a) => { if (!NOISE.test(String(a[0] ?? ''))) ce(...a); };
console.warn = (...a) => { if (!NOISE.test(String(a[0] ?? ''))) cw(...a); };
console.log = (...a) => { if (!NOISE.test(String(a[0] ?? ''))) cl(...a); };

let visionP = null, segP = null, faceP = null;
const vision = () => (visionP ||= (async () => {
  const V = await import(BASE + 'tasks-vision-0.10.21/vision_bundle.mjs');
  return { V, fileset: await V.FilesetResolver.forVisionTasks(BASE + 'tasks-vision-0.10.21/wasm') };
})().catch((e) => { visionP = null; throw e; }));
const segmenter = () => (segP ||= vision().then(({ V, fileset }) => V.ImageSegmenter.createFromOptions(fileset, {
  baseOptions: { modelAssetPath: BASE + 'models/selfie_segmenter.tflite', delegate: 'CPU' }, runningMode: 'IMAGE', outputConfidenceMasks: true, outputCategoryMask: false,
})).catch((e) => { segP = null; throw e; }));
const landmarker = () => (faceP ||= vision().then(({ V, fileset }) => V.FaceLandmarker.createFromOptions(fileset, {
  baseOptions: { modelAssetPath: BASE + 'models/face_landmarker.task', delegate: 'CPU' }, runningMode: 'IMAGE', numFaces: 2,
  minFaceDetectionConfidence: 0.4, minFacePresenceConfidence: 0.4, outputFaceBlendshapes: false,
})).catch((e) => { faceP = null; throw e; }));

self.onmessage = async (e) => {
  const { id, op, bitmaps = [] } = e.data || {};
  try {
    if (op === 'seg') {
      const s = await segmenter(), masks = [], transfer = [];
      for (const bm of bitmaps) {
        const r = s.segment(bm), m = r.confidenceMasks?.[r.confidenceMasks.length - 1];
        const f = m.getAsFloat32Array(), data = new Uint8Array(f.length);
        for (let i = 0; i < f.length; i++) data[i] = Math.round(Math.max(0, Math.min(1, f[i])) * 255);
        masks.push({ w: m.width, h: m.height, data }); transfer.push(data.buffer);
        r.close?.(); bm.close?.();
      }
      self.postMessage({ id, masks }, transfer);
    } else if (op === 'face') {
      const f = await landmarker(), faces = [];
      for (const bm of bitmaps) {
        const r = f.detect(bm);
        faces.push((r.faceLandmarks || []).map((pts) => { const a = new Float32Array(pts.length * 2); pts.forEach((p, i) => { a[i * 2] = p.x; a[i * 2 + 1] = p.y; }); return a; }));
        bm.close?.();
      }
      self.postMessage({ id, faces });
    } else throw new Error('unknown op ' + op);
  } catch (err) { self.postMessage({ id, error: String(err && err.message || err) }); }
};
