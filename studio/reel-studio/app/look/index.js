// KBB Studio · Look (Lane L): colour looks, auto colour, shot matching, skin touch-up, background removal, GPU
// transitions, speed ramps and animated text — each one click, one undo step, editable, drawn through core/render.js
// so the preview and the export are the same pictures. The editor shows it as the "🎨 Look" tab of the right column.
//
// API (also used by tools/l-check.cjs):
//   applyLook(ctx, lookId, {ids, amount})        autoColour(ctx, {ids})          matchTo(ctx, refId)
//   touchUp(ctx, {ids, strength, eyes, under})   removeBackground(ctx, {ids, mode, color, colors, src, country})
//   addTransitions(ctx, kind, {at, dur})         matchCut(ctx, {at})             speedRamp(ctx, id, {curve, min, max, ease, audio})
//   addText(ctx, presetId, {t0, text, ...})      clearFx(ctx, type, {ids})
import { registerModule } from '../core/modules.js';
import { registerPrepare } from '../core/render.js';
import { ops } from '../core/history.js';
import { bus } from '../core/bus.js';
import { findItem, itemEnd } from '../core/project.js';
import { LOOKS, gradeFx, lookById } from './looks.js';
import { LOOK_FX, loadBackground, processLook, bgReady } from './fx.js';
import { ensureAnalysis, coverageAt, facesAt, hasFace } from './vision.js';
import { ensureFreezeFrames, transitionsCommand, matchCutCommand, cutsOf, KINDS } from './transitions.js';
import { rampCommand, CURVES, EASES } from './ramp.js';
import { PRESETS, textItem } from './text.js';
import { samplePixels, autoCorrect, stats, matchTransfer } from './analyze.js';
import { gl } from './gl.js';
import { lookPanel } from './panel.js';

export { LOOKS, KINDS, CURVES, EASES, PRESETS, processLook, coverageAt, facesAt, cutsOf, gl };

// ── targets and fx edits ──
export function clipTargets(ctx, ids) {
  const p = ctx.project, sel = ids || ctx.selection?.() || [];
  const picked = sel.map((id) => findItem(p, id)).filter((x) => x && x.item.type === 'clip' && x.item.media);
  if (picked.length) return picked.map((x) => x.item);
  return p.tracks.filter((t) => t.kind === 'video').flatMap((t) => t.items.filter((i) => i.type === 'clip' && i.media));
}
const withFx = (item, type, patch) => {
  const list = (item.fx || []).slice(), i = list.findIndex((f) => f.type === type);
  if (patch == null) { if (i >= 0) list.splice(i, 1); return list; }
  const next = Object.assign({}, i >= 0 ? list[i] : type === 'look.grade' ? gradeFx() : { type }, patch, { type });
  if (i >= 0) list[i] = next; else {
    // keep one order in item.fx: grade, touch-up, background (the pass does all three anyway)
    list.push(next); list.sort((a, b) => (LOOK_FX.indexOf(a.type) + 1 || 99) - (LOOK_FX.indexOf(b.type) + 1 || 99));
  }
  return list;
};
function applyFx(ctx, items, type, patchFor, label) {
  if (!items.length) throw new Error('Add a video clip first');
  ctx.history.apply(ops.setItems(items.map((it) => ({ id: it.id, props: { fx: withFx(it, type, typeof patchFor === 'function' ? patchFor(it) : patchFor) } })), label));
  redraw();
  return items.length;
}
const redraw = () => window.kbbEditor?.preview?.draw();
const progress = (ctx) => (label, f) => ctx.progress?.(label, f);

// ── colour ──
export function applyLook(ctx, lookId, { ids, amount } = {}) {
  const L = lookById(lookId), items = clipTargets(ctx, ids);
  const n = applyFx(ctx, items, 'look.grade', (it) => ({ look: L.id, amount: amount ?? (it.fx || []).find((f) => f.type === 'look.grade')?.amount ?? 1 }), `Look · ${L.label}`);
  return `${L.label} on ${n} clip${n > 1 ? 's' : ''}`;
}
export async function autoColour(ctx, { ids } = {}) {
  const items = clipTargets(ctx, ids), res = new Map();
  for (const it of items) { const px = await samplePixels(ctx.project, it); if (px) res.set(it.id, autoCorrect(px)); }
  applyFx(ctx, items.filter((i) => res.has(i.id)), 'look.grade', (it) => res.get(it.id), 'Auto colour');
  return [...res.values()];
}
export async function matchTo(ctx, refId) {
  const p = ctx.project, ref = findItem(p, refId)?.item || clipTargets(ctx)[0];
  if (!ref) throw new Error('Select the clip to match to');
  const others = p.tracks.filter((t) => t.kind === 'video').flatMap((t) => t.items).filter((i) => i.type === 'clip' && i.media && i.id !== ref.id);
  if (!others.length) throw new Error('There is only one clip');
  const refFx = (ref.fx || []).find((f) => f.type === 'look.grade') || gradeFx();
  const target = stats(await samplePixels(p, ref), refFx), res = new Map();
  for (const it of others) { const px = await samplePixels(p, it); if (px) res.set(it.id, matchTransfer(px, target)); }
  applyFx(ctx, others.filter((i) => res.has(i.id)), 'look.grade', (it) => Object.assign(res.get(it.id), { wb: [1, 1, 1], ev: 0, look: refFx.look, amount: refFx.amount }), 'Match clips');
  return { ref: ref.id, matched: res.size, target };
}

// ── touch-up and background ──
export async function touchUp(ctx, { ids, strength = 0.35, eyes = 0.5, under = 0.5 } = {}) {
  const items = clipTargets(ctx, ids);
  applyFx(ctx, items, 'look.touchup', { strength, eyes, under }, 'Skin touch-up');
  await ensureAnalysis(ctx.project, { onProgress: progress(ctx) });
  redraw();
  const found = items.filter((it) => (facesAt(it.media, it.in + 0.05) || []).length).length;
  return { clips: items.length, withFaces: found };
}
export async function removeBackground(ctx, { ids, mode = 'blur', color, colors, src, country } = {}) {
  const items = clipTargets(ctx, ids), ws = ctx.workspace || {};
  const patch = { mode, color: color || ws.colors?.secondary || '#16161c', colors: colors || [ws.colors?.primary || '#C6395F', ws.colors?.secondary || '#2A2228'], src, country: country || 'gb' };
  await loadBackground(patch);
  applyFx(ctx, items, 'look.bg', patch, 'Remove background');
  await ensureAnalysis(ctx.project, { onProgress: progress(ctx) });
  redraw();
  return { clips: items.length, coverage: items.map((it) => coverageAt(it.media, it.in + 0.05)) };
}
export function clearFx(ctx, type, { ids } = {}) { return applyFx(ctx, clipTargets(ctx, ids).filter((i) => (i.fx || []).some((f) => f.type === type)), type, null, 'Remove effect'); }

// ── transitions, ramps, text ──
export async function addTransitions(ctx, kind, { at = null, dur = null } = {}) {
  const cmd = transitionsCommand(ctx.project, kind, { at, dur, color: kind === 'flash' ? '#ffffff' : ctx.workspace?.colors?.primary });
  if (!cmd) throw new Error('There is no cut yet: split a clip (S) or add a second clip');
  ctx.history.apply(cmd);
  if (kind === 'cover') await ensureAnalysis(ctx.project, { onProgress: progress(ctx) });
  await ensureFreezeFrames(ctx.project);
  redraw();
  return ctx.project.tracks.flatMap((t) => t.items).filter((i) => i.type === 'look.transition').length;
}
export async function matchCut(ctx, { at = ctx.playhead?.() ?? 0 } = {}) {
  const cuts = cutsOf(ctx.project); if (!cuts.length) throw new Error('There is no cut yet');
  const c = cuts.reduce((b, x) => (Math.abs(x.t - at) < Math.abs(b.t - at) ? x : b));
  const r = await matchCutCommand(ctx.project, c);
  if (r?.cmd) { ctx.history.apply(r.cmd); await ensureFreezeFrames(ctx.project); redraw(); }
  return r?.shift || 0;
}
export async function speedRamp(ctx, id, opts = {}) {
  const r = await rampCommand(ctx, id, opts);
  ctx.history.apply(r.cmd);
  await ensureFreezeFrames(ctx.project);
  redraw();
  return r;
}
export function addText(ctx, preset, over = {}) {
  const p = ctx.project, it = textItem(preset, p.canvas, ctx.workspace || {}, over.t0 ?? ctx.playhead?.() ?? 0, over);
  const tr = p.tracks.find((t) => t.kind === 'text') || p.tracks.find((t) => t.kind === 'overlay');
  ctx.history.apply(ops.addItems([{ trackId: tr.id, item: it }], 'Text · ' + (PRESETS[preset]?.label || preset)));
  window.kbbEditor?.select?.([it.id]);
  return it;
}

// ── keep the analysis and freeze frames in step with edits (undo, trims, moves) ──
registerPrepare('look', async (project) => {
  for (const tr of project.tracks) for (const it of tr.items) for (const f of it.fx || []) if (f.type === 'look.bg' && !bgReady(f)) await loadBackground(f);
  await ensureAnalysis(project);
  await ensureFreezeFrames(project);
});
let catchUp = null;
bus.on('project:change', () => {
  clearTimeout(catchUp);
  catchUp = setTimeout(async () => {
    const p = window.kbbEditor?.project; if (!p) return;
    try {
      let changed = false;
      for (const tr of p.tracks) for (const it of tr.items) for (const f of it.fx || []) if (f.type === 'look.bg' && !bgReady(f)) { await loadBackground(f); changed = true; }
      changed = (await ensureAnalysis(p)) || changed;
      changed = (await ensureFreezeFrames(p)) || changed;
      if (changed) redraw();
    } catch (e) { console.warn('[look] catch-up:', e.message); }
  }, 400);
});

registerModule({
  id: 'look', label: 'Look', icon: '🎨',
  tab: { label: '🎨 Look', order: 10 },
  panel: (el, ctx) => lookPanel(el, ctx, { applyLook, autoColour, matchTo, touchUp, removeBackground, clearFx, addTransitions, matchCut, speedRamp, addText, clipTargets }),
});

export { ensureAnalysis, ensureFreezeFrames, hasFace, itemEnd };
