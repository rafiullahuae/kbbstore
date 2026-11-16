// KBB Studio · content · auto-tagging media (his library) so b-roll can match spoken words to what is IN a picture:
//   1. file-name words ("serum-closeup-2.mp4" → serum, closeup)
//   2. the offline image tagger (CLIP ViT-B/32, int8, models/manifest.json 'media-tagger') scoring one frame against the
//      studio's own tag list below — zero-shot, no training, no internet after the one-time download.
// Tags are kept per company in workspaces/<ws>/library/tags.json (keyed by the file's path + size), so a clip is
// tagged once however many reels use it. Nothing in the project changes, so tagging never adds an undo step.
import { engine } from '../core/engine.js';
import { STOP, stem, normWord } from './lib/keywords.js';

// tag → [the prompt CLIP scores, the spoken words it should match]
export const VOCAB = [
  ['serum', 'a photo of a skincare serum bottle with a dropper', 'serum ampoule dropper essence'],
  ['cream', 'a photo of a jar of face cream', 'cream moisturiser moisturizer jar'],
  ['sunscreen', 'a photo of a sunscreen tube', 'sunscreen spf sun uv'],
  ['sheet-mask', 'a photo of a face sheet mask', 'mask sheet masks'],
  ['eye-patches', 'a photo of under-eye patches', 'eye patches patch'],
  ['cleanser', 'a photo of a foam face cleanser', 'cleanser wash foam cleansing'],
  ['lotion', 'a photo of a body lotion pump bottle', 'lotion body moisturiser'],
  ['mist', 'a photo of a face mist spray', 'mist spray toner'],
  ['lipstick', 'a photo of lipstick or lip balm', 'lipstick lip lips balm'],
  ['makeup', 'a photo of makeup products and brushes', 'makeup foundation brush concealer'],
  ['hair-oil', 'a photo of hair oil', 'hair oil scalp'],
  ['texture', 'a close-up macro photo of cream texture', 'texture swatch creamy'],
  ['skin-closeup', 'a close-up photo of glowing facial skin', 'skin glow glowing dewy radiant face pores'],
  ['applying', 'a photo of hands applying skincare to a face', 'apply applying routine step'],
  ['package', 'a photo of an unboxing of a parcel box', 'unboxing box parcel package order delivery arrived'],
  ['shelf', 'a photo of skincare products on a shelf', 'collection shelf products'],
  ['bathroom', 'a photo of a bathroom vanity mirror', 'bathroom mirror morning night'],
  ['woman-face', 'a portrait photo of a woman', 'me selfie face woman'],
  ['man-face', 'a portrait photo of a man', 'me selfie face man'],
  ['passport', 'a photo of a passport', 'passport passports'],
  ['visa', 'a photo of a visa stamp in a passport', 'visa approved stamp approval'],
  ['documents', 'a photo of paper documents and forms on a desk', 'documents document papers forms application statement letter certificate'],
  ['bank-statement', 'a photo of a bank statement', 'bank statement funds money proof'],
  ['airport', 'a photo of an airport terminal', 'airport flight fly departure travel'],
  ['airplane', 'a photo of an airplane in the sky', 'plane airplane flight fly'],
  ['university', 'a photo of a university campus building', 'university campus college school admission'],
  ['classroom', 'a photo of students in a lecture hall', 'class lecture classroom students study studying'],
  ['graduation', 'a photo of a graduation ceremony with caps', 'graduation graduate degree'],
  ['student', 'a photo of a student with a backpack', 'student students backpack'],
  ['laptop', 'a photo of a person working on a laptop', 'laptop online apply application computer'],
  ['office', 'a photo of a consultant in an office meeting', 'office consultant consultation meeting advice'],
  ['handshake', 'a photo of a handshake', 'deal agreement handshake welcome'],
  ['city', 'a photo of a european city street', 'city street europe european life'],
  ['skyline', 'a photo of a city skyline', 'skyline city downtown'],
  ['landmark', 'a photo of a famous landmark', 'landmark tower monument'],
  ['flag', 'a photo of a national flag', 'flag country'],
  ['map', 'a photo of a map', 'map country route'],
  ['money', 'a photo of money and coins', 'money cost costs price fees tuition budget salary cheap'],
  ['calendar', 'a photo of a calendar', 'deadline intake date calendar month'],
  ['job', 'a photo of people working in a workplace', 'job work working permit career employer'],
  ['family', 'a photo of a happy family', 'family spouse kids children'],
  ['celebration', 'a photo of people celebrating with confetti', 'celebrate celebration party happy congratulations'],
  ['food', 'a photo of food on a plate', 'food eat cooking recipe meal'],
  ['coffee', 'a photo of a cup of coffee', 'coffee cafe morning'],
  ['beach', 'a photo of a beach', 'beach summer holiday sea'],
  ['nature', 'a photo of green nature and trees', 'nature park green'],
  ['gift', 'a photo of a wrapped gift box', 'gift present surprise free'],
  ['shopping', 'a photo of shopping bags', 'shop shopping buy order sale offer discount'],
  ['phone', 'a photo of a smartphone screen', 'phone app whatsapp call message dm'],
].map(([tag, prompt, words]) => ({ tag, prompt, words: words.split(' ') }));

export const TAGGER_ID = 'media-tagger';
const MODEL = { repo: 'Xenova/clip-vit-base-patch32' };

/** words in a file name, minus our own id prefix and noise */
export function nameTags(name) {
  const base = String(name || '').replace(/\.[^.]+$/, '').replace(/^m[a-z0-9]{6,}-/i, '');
  return [...new Set(base.split(/[^\p{L}\p{N}]+/u).map(normWord).filter((w) => w.length > 2 && !STOP.has(w) && !/^\d+$/.test(w) && !/^(img|vid|mov|dsc|pxl|copy|final|edit)$/.test(w)))];
}

/** the words a tag answers to (for matching transcripts) */
export const wordsForTags = (tags) => [...new Set(tags.flatMap((t) => [t, ...t.split('-'), ...(VOCAB.find((v) => v.tag === t)?.words || [])]).map(stem))];

// ── the worker (CLIP) ──
let W = null, seq = 0; const pending = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./tagger-worker.js', import.meta.url), { type: 'module' });
  W.onmessage = (e) => { const p = pending.get(e.data.id); if (!p) return; pending.delete(e.data.id); e.data.error ? p.rej(new Error(e.data.error)) : p.res(e.data.result); };
  W.onerror = (e) => { e.preventDefault?.(); for (const p of pending.values()) p.rej(new Error(e.message || 'tagger failed')); pending.clear(); W = null; };
  return W;
}
const call = (op, args) => new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); worker().postMessage({ id, op, ...args }); });

let installed = null;
export async function taggerReady() {
  if (installed === null) installed = await engine.modelInstalled(TAGGER_ID).catch(() => false);
  return installed;
}
export const resetTagger = () => { installed = null; };

/** a JPEG of one frame of a studio file (middle of a video) */
async function frameBlob(src, kind) {
  const url = /^(blob:|data:)/.test(src) ? src : '/' + src.split('/').map(encodeURIComponent).join('/');
  let el;
  if (kind === 'image') { el = new Image(); el.src = url; await el.decode(); }
  else {
    el = document.createElement('video'); el.muted = true; el.preload = 'auto'; el.src = url;
    await new Promise((r, j) => { el.onloadeddata = r; el.onerror = () => j(new Error('cannot open ' + src)); });
    await new Promise((r) => { el.onseeked = r; el.currentTime = Math.min(el.duration * 0.5, Math.max(0, el.duration - 0.1)); });
  }
  const w = el.naturalWidth || el.videoWidth, h = el.naturalHeight || el.videoHeight, k = 336 / Math.max(w, h);
  const c = new OffscreenCanvas(Math.max(1, Math.round(w * k)), Math.max(1, Math.round(h * k)));
  const g = c.getContext('2d'); g.fillStyle = '#ffffff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(el, 0, 0, c.width, c.height);
  if (kind !== 'image') { el.removeAttribute('src'); el.load(); }
  return c.convertToBlob({ type: 'image/jpeg', quality: 0.9 });
}

/** CLIP tags for one file: [{tag, p}] (top 3 above a floor), or null when the model is not installed */
export async function modelTags(src, kind) {
  if (!(await taggerReady())) return null;
  const blob = await frameBlob(src, kind);
  const r = await call('tag', { blob, prompts: VOCAB.map((v) => v.prompt), modelsUrl: new URL('/models/', location.href).href, vendorUrl: new URL('../../vendor/transformers/', import.meta.url).href, repo: MODEL.repo });
  return r.scores.map((p, i) => ({ tag: VOCAB[i].tag, p })).sort((a, b) => b.p - a.p).filter((x, i) => i < 3 && x.p >= 0.08).map((x) => ({ tag: x.tag, p: +x.p.toFixed(3) }));
}

// ── the per-company tag index ──
const indexPath = (ws) => `workspaces/${ws}/library/tags.json`;
const indexes = new Map();
export async function tagIndex(ws) {
  if (indexes.has(ws)) return indexes.get(ws);
  let idx = { v: 1, items: {} };
  try { if (await engine.exists(indexPath(ws))) idx = await engine.readFile(indexPath(ws), 'json'); } catch {}
  indexes.set(ws, idx);
  return idx;
}
let saveT = null;
function saveIndex(ws) {
  clearTimeout(saveT);
  saveT = setTimeout(() => { const idx = indexes.get(ws); if (idx && engine.available) engine.writeFile(indexPath(ws), idx).catch((e) => console.warn('[content] tags not saved:', e.message)); }, 300);
}

/**
 * Tag one file (once): media = {src, name, kind, size}. Returns {tags:[...], model:[{tag,p}]|null, words:[stems]}.
 * force re-runs the model (e.g. after the tagger was installed).
 */
export async function tagMedia(ws, media, { force = false } = {}) {
  if (!media || (media.kind !== 'image' && media.kind !== 'video')) return null;
  const idx = await tagIndex(ws), key = media.src;
  let e = idx.items[key];
  if (!e || force || e.size !== (media.size || 0) || (!e.model && (await taggerReady()))) {
    let model = null;
    try { model = await modelTags(media.src, media.kind); } catch (err) { console.warn('[content] tagger:', err.message); }
    e = { name: media.name, kind: media.kind, size: media.size || 0, names: nameTags(media.name || key.split('/').pop()), model, at: new Date().toISOString() };
    idx.items[key] = e; saveIndex(ws);
  }
  const tags = [...new Set([...(e.model || []).map((x) => x.tag), ...e.names])];
  return { tags, model: e.model, words: wordsForTags(tags) };
}

/** close the tagger (frees its memory) */
export function closeTagger() { W?.terminate(); W = null; }
