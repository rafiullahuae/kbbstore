// KBB Studio · look · "Cut out the main subject" (products, objects — not only people): U²-Net-p salient-object masks.
// The model is an OPTIONAL one-time download (models/manifest.json id 'subject-cutout', 4.6 MB):
//   U²-Net-p — Qin et al., github.com/xuebinqin/U-2-Net, Apache-2.0; ONNX export from rembg (MIT), published as
//   huggingface.co/BritishWerewolf/U-2-Netp (Apache-2.0).
// It runs on the CPU (ONNX Runtime WASM, vendored in vendor/transformers) in its own module worker, 320 x 320 in, one
// 0..255 mask out per frame. vision.js calls it during the offline analysis, so drawing only reads the cache.
import { engine } from '../core/engine.js';

export const SUBJECT_MODEL = { id: 'subject-cutout', repo: 'BritishWerewolf/U-2-Netp', file: 'onnx/model.onnx', licence: 'Apache-2.0', size: 4574861 };
export const SUBJECT_SIZE = 320;

let ready = null;       // cached "installed?" answer (true once installed; re-asked after a failed look)
/** is the model on this Mac? (asks the engine once; a fresh install resets it via subjectInstalled()) */
export async function subjectReady() {
  if (ready === true) return true;
  ready = await engine.modelInstalled(SUBJECT_MODEL.id).catch(() => false);
  return ready;
}
export function subjectInstalled() { ready = true; }

/** download it: ONE question through the app's permission dialog, then the engine's checked installer (resume,
 *  retries, checksums). Resolves when installed; err.denied when he says no. */
export async function installSubjectModel(onProgress = () => {}) {
  const { bus } = await import('../core/bus.js');
  const st = await engine.modelsStatus(), entry = st.models.find((x) => x.id === SUBJECT_MODEL.id);
  if (!entry) throw new Error('The model list (models/manifest.json) has no ' + SUBJECT_MODEL.id);
  if (entry.installed) { subjectInstalled(); return true; }
  const ok = await engine.askPermission({ what: 'Cut-out model U²-Net-p (Apache-2.0) — downloaded once into the models folder, then "Cut out the main subject" works offline',
    size: Math.max(0, entry.size - (entry.have || 0)), host: 'huggingface.co', url: `https://huggingface.co/${SUBJECT_MODEL.repo}/`, scope: 'repo' });
  if (!ok) throw Object.assign(new Error('Download not allowed'), { denied: true });
  await new Promise((res, rej) => {
    let off = null, poll = 0, tm = 0;
    const end = (fn) => { off?.(); clearInterval(poll); clearTimeout(tm); fn(); };
    const check = (s) => {
      const m = s.models?.find((x) => x.id === SUBJECT_MODEL.id), inst = s.installer || {};
      if (inst.total) onProgress(Math.min(1, (inst.done || 0) / inst.total));
      if (m?.installed) end(res);
      else if (['stalled', 'nospace', 'paused'].includes(inst.state)) end(() => rej(new Error(inst.message || 'The download stopped.')));
    };
    off = bus.on('engine:models', check);
    poll = setInterval(() => engine.modelsStatus().then(check, () => {}), 3000);   // covers a dropped event stream
    tm = setTimeout(() => end(() => rej(new Error('The download took too long'))), 600000);
    engine.installModels([entry]).then(() => engine.modelsStatus().then(check, () => {}), (e) => end(() => rej(e)));
  });
  subjectInstalled();
  return true;
}

let W = null, seq = 0; const pending = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./subject-worker.js', import.meta.url), { type: 'module' });
  W.onmessage = (e) => { const p = pending.get(e.data.id); if (!p) return; pending.delete(e.data.id); e.data.error ? p.rej(new Error(e.data.error)) : p.res(e.data); };
  W.onerror = (e) => { e.preventDefault?.(); for (const p of pending.values()) p.rej(new Error(e.message || 'cut-out worker failed')); pending.clear(); W = null; };
  return W;
}
/** masks for 320 x 320 ImageBitmaps: [{w, h, data: Uint8Array}] */
export function subjectMasks(bitmaps) {
  return new Promise((res, rej) => {
    const id = ++seq; pending.set(id, { res: (d) => res(d.masks), rej });
    worker().postMessage({ id, bitmaps, model: new URL('/models/' + SUBJECT_MODEL.repo + '/' + SUBJECT_MODEL.file, location.href).href, vendor: new URL('../../vendor/transformers/', import.meta.url).href }, bitmaps);
  });
}
