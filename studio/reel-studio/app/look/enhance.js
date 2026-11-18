// KBB Studio · look · the Adjust commands: slider edits, reset, "✨ Enhance video" (measured per clip) and its parts.
// Every command is ONE undo step and only touches the clip's `look.adjust` entry in item.fx (adjust.js has the format).
//
//   setAdjust(ctx, patch, {ids, all})      resetAdjust(ctx, keys|null, {ids, all})
//   enhanceVideo(ctx, {ids, all})          setEnhancePart(ctx, part, on, {ids, all})      enhanceOff(ctx, {ids, all})
//   adjustOf(item)                          the clip's look.adjust entry (or null)
import { ops } from '../core/history.js';
import { MediaStore } from '../core/media.js';
import { findItem, findMedia, srcTime, itemEnd } from '../core/project.js';
import { samplePixels, autoCorrect } from './analyze.js';
import { KEYS, adjustFx, enhancePlan, noiseSigma } from './adjust.js';
import { LOOK_FX } from './fx.js';

export const adjustOf = (item) => (item?.fx || []).find((f) => f.type === 'look.adjust') || null;

/** the clips a command works on: `ids`, else the selected clips, else (or with all) every clip on the video tracks */
export function adjustTargets(ctx, { ids, all } = {}) {
  const p = ctx.project, every = () => p.tracks.filter((t) => t.kind === 'video' || t.kind === 'overlay').flatMap((t) => t.items.filter((i) => i.type === 'clip' && i.media && findMedia(p, i.media)?.kind !== 'audio'));
  if (all) return every();
  const sel = (ids || ctx.selection?.() || []).map((id) => findItem(p, id)?.item).filter((i) => i && i.type === 'clip' && i.media);
  return sel.length ? sel : every();
}
function withAdjust(item, fn) {
  const list = (item.fx || []).slice(), i = list.findIndex((f) => f.type === 'look.adjust');
  const next = fn(i >= 0 ? JSON.parse(JSON.stringify(list[i])) : adjustFx());
  if (next == null) { if (i >= 0) list.splice(i, 1); return list; }
  next.type = 'look.adjust';
  if (i >= 0) list[i] = next; else { list.push(next); list.sort((a, b) => (LOOK_FX.indexOf(a.type) + 1 || 99) - (LOOK_FX.indexOf(b.type) + 1 || 99)); }
  return list;
}
function apply(ctx, items, fn, label) {
  if (!items.length) throw new Error('Add a video clip first');
  ctx.history.apply(ops.setItems(items.map((it) => ({ id: it.id, props: { fx: withAdjust(it, fn) } })), label));
  window.kbbEditor?.preview?.draw();
  return items.length;
}
const clean = (a) => { for (const k of KEYS) if (a[k] != null && Math.abs(a[k]) < 1e-4) delete a[k]; if (!a.up) delete a.up; return a; };

/** sliders: patch = {exp: 0.3, ...}; a slider drag is merged into one undo step by the history (same label) */
export function setAdjust(ctx, patch, opt = {}) {
  const keys = Object.keys(patch).filter((k) => KEYS.includes(k) || k === 'up');
  const label = keys.length === 1 ? `Adjust · ${keys[0]}` : 'Adjust';
  return apply(ctx, adjustTargets(ctx, opt), (a) => clean(Object.assign(a, Object.fromEntries(keys.map((k) => [k, k === 'up' ? !!patch[k] : +(+patch[k]).toFixed(4)])))), label);
}
export function resetAdjust(ctx, keys = null, opt = {}) {
  return apply(ctx, adjustTargets(ctx, opt), (a) => {
    if (!keys) return a.enh ? { type: 'look.adjust', enh: a.enh } : null;
    for (const k of keys) delete a[k];
    return a;
  }, keys ? 'Reset ' + keys.join(', ') : 'Reset adjustments');
}
/** the copy of one clip's adjustments onto every clip (sliders and Enhance) */
export function adjustAllLike(ctx, item) {
  const src = adjustOf(item);
  return apply(ctx, adjustTargets(ctx, { all: true }), () => (src ? JSON.parse(JSON.stringify(src)) : null), 'Adjust · all clips');
}

// ── Enhance video ──
/** noise of a clip at its own resolution: the middle 256×256 of three frames (scaled pictures would hide the grain) */
async function clipNoise(project, item) {
  const m = findMedia(project, item.media); if (!m || m.kind === 'audio') return 0;
  const r = await new MediaStore({ getProject: () => project, engine: null }).openReader(m.id);
  const out = [];
  try {
    for (let i = 0; i < (m.kind === 'image' ? 1 : 3); i++) {
      const f = await r.frame(m.kind === 'image' ? 0 : srcTime(item, item.t0 + ((itemEnd(item) - item.t0) * (i + 0.5)) / 3));
      const fw = f.displayWidth || f.videoWidth || f.naturalWidth || f.width, fh = f.displayHeight || f.videoHeight || f.naturalHeight || f.height;
      const S = Math.min(256, fw, fh), bm = await createImageBitmap(f, Math.round((fw - S) / 2), Math.round((fh - S) / 2), S, S);
      const c = new OffscreenCanvas(S, S).getContext('2d', { willReadFrequently: true }); c.drawImage(bm, 0, 0); bm.close();
      const d = c.getImageData(0, 0, S, S).data, Y = new Float32Array(S * S);
      for (let k = 0, j = 0; k < d.length; k += 4, j++) Y[j] = (0.2126 * d[k] + 0.7152 * d[k + 1] + 0.0722 * d[k + 2]) / 255;
      out.push(noiseSigma(Y, S, S));
    }
  } finally { r?.close(); }
  out.sort((a, b) => a - b);
  return out[Math.floor(out.length / 2)] || 0;
}
/** measure each clip and switch on what it needs; one undo step for all of them */
export async function enhanceVideo(ctx, opt = {}) {
  const p = ctx.project, items = adjustTargets(ctx, opt), plans = new Map();
  if (!items.length) throw new Error('Add a video clip first');
  for (const [i, it] of items.entries()) {
    ctx.progress?.(`Measuring clip ${i + 1} of ${items.length}…`, i / items.length);
    const px = await samplePixels(p, it, 6, 64); if (!px) continue;
    const m = findMedia(p, it.media), c = it.crop || { l: 0, t: 0, r: 0, b: 0 };
    const srcLong = Math.max((m.w || 0) * (1 - c.l - c.r), (m.h || 0) * (1 - c.t - c.b)), outLong = Math.max(it.w || 0, it.h || 0);
    plans.set(it.id, enhancePlan(px, autoCorrect(px), await clipNoise(p, it), Math.round(srcLong), Math.round(outLong)));
  }
  ctx.progress?.(null, null);
  applyPer(ctx, items.filter((i) => plans.has(i.id)), (it, a) => Object.assign(a, { enh: plans.get(it.id) }), 'Enhance video');
  return [...plans.entries()].map(([id, e]) => ({ id, ...e }));
}
/** like apply(), with the item handed to fn (per-clip values) */
function applyPer(ctx, items, fnFor, label) {
  if (!items.length) throw new Error('Add a video clip first');
  ctx.history.apply(ops.setItems(items.map((it) => ({ id: it.id, props: { fx: withAdjust(it, (a) => fnFor(it, a)) } })), label));
  window.kbbEditor?.preview?.draw();
  return items.length;
}

export function setEnhancePart(ctx, part, on, opt = {}) {
  const items = adjustTargets(ctx, opt).filter((i) => adjustOf(i)?.enh);
  if (!items.length) throw new Error('Run ✨ Enhance video first');
  return applyPer(ctx, items, (it, a) => { a.enh.parts = Object.assign({}, a.enh.parts, { [part]: !!on }); return a; }, `Enhance · ${part} ${on ? 'on' : 'off'}`);
}
export function enhanceOff(ctx, opt = {}) {
  const items = adjustTargets(ctx, opt).filter((i) => adjustOf(i)?.enh);
  if (!items.length) return 0;
  return applyPer(ctx, items, (it, a) => { delete a.enh; return Object.keys(a).length > 1 ? a : null; }, 'Enhance video off');
}
