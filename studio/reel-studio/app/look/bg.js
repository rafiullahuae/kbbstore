// KBB Studio · look · background removal v2: one click for portrait ads and product videos.
//
//   removeBackground(ctx, {ids, mode, key, color, colors, src, country, under, feather, grow, smooth, wrap, keyColor, tol, soft, spill})
//     mode  'blur' | 'color' | 'gradient' | 'image' | 'flag' | 'landmark'   the background drawn inside the clip
//           'none'  transparent: the person floats over whatever is on the tracks below (b-roll, a photo, a theme
//                   scene); with nothing below, a "Background" track is made under the clip to drop one on
//           'clip'  another clip from the media bin, laid (muted, looped) on the track below the cut-out clip
//     key   'person' (MediaPipe selfie segmenter) | 'subject' (U²-Net-p, optional 4.6 MB model: products, objects)
//           | 'chroma' (green / blue screen, with spill suppression; the key colour is measured from the clip)
//     edges feather 0..1, grow -1..1 (shrink / grow), smooth 0..1 (frame-to-frame steadiness), wrap 0..1 (light wrap)
//   setBg(ctx, patch, {ids})             change the settings of clips that already have it (sliders; one undo per drag)
//   placeCutout(ctx, where, {ids, size}) 'full' | 'left' | 'centre' | 'right', size 0.3..1 of the frame, standing on the bottom
//   exportCutout(ctx, {what: 'clip'|'reel', format: 'webm'|'png'})   exportFrame(ctx)   (transparent, core/export-alpha.js)
//
// Every call is ONE undo step. The fx entry carries q: 2, so a project saved before v2 keeps drawing exactly as it did.
import { ops } from '../core/history.js';
import { findItem, findMedia, itemEnd, createTrack, createItem, fitBox, uid } from '../core/project.js';
import { loadBackground } from './fx.js';
import { ensureAnalysis, maskCoverageAt, coverageAt } from './vision.js';
import { samplePixels } from './analyze.js';
import { installSubjectModel, subjectReady } from './subject.js';

export const BG_DEFAULTS = { feather: 0.1, grow: 0, smooth: 0.5, wrap: 0.3, tol: 0.1, soft: 0.08, spill: 0.6 };
export const BG_MODES = [['blur', 'Blur'], ['color', 'Colour'], ['gradient', 'Brand gradient'], ['image', 'Picture'], ['clip', 'Another clip'], ['flag', 'Flag'], ['landmark', 'Landmark'], ['none', 'None (transparent)']];
export const BG_KEYS = [['person', '🧍 Person'], ['subject', '📦 Main subject'], ['chroma', '🟩 Green screen']];
const TRANSPARENT = new Set(['none', 'clip']);
export const isTransparent = (f) => !!f && (f.q || 1) >= 2 && TRANSPARENT.has(f.mode);

const redraw = () => window.kbbEditor?.preview?.draw();
const clean = (o) => { for (const k of Object.keys(o)) if (o[k] === undefined) delete o[k]; return o; };
export const bgOf = (item) => (item?.fx || []).find((f) => f.type === 'look.bg') || null;

export function clipTargets(ctx, ids) {
  const p = ctx.project, sel = ids || ctx.selection?.() || [];
  const picked = sel.map((id) => findItem(p, id)).filter((x) => x && x.item.type === 'clip' && x.item.media);
  if (picked.length) return picked.map((x) => x.item);
  return p.tracks.filter((t) => t.kind === 'video').flatMap((t) => t.items.filter((i) => i.type === 'clip' && i.media));
}
/** item.fx with the look.bg entry replaced (null removes it), the look.* order kept (grade, touch-up, bg, adjust) */
function withBg(item, next) {
  const ORDER = ['look.grade', 'look.touchup', 'look.bg', 'look.adjust'];
  const list = (item.fx || []).filter((f) => f.type !== 'look.bg');
  if (next) { list.push(next); list.sort((a, b) => (ORDER.indexOf(a.type) + 1 || 99) - (ORDER.indexOf(b.type) + 1 || 99)); }
  return list;
}

/** the key colour of a green / blue screen: the saturated colour that dominates the frame's border */
export function autoKeyColor(px, size = 64) {
  const acc = [[0, 0, 0, 0], [0, 0, 0, 0]];          // green, blue
  const per = size * size * 3, frames = Math.max(1, Math.floor(px.length / per));
  for (let f = 0; f < frames; f++) for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
    if (x > 5 && x < size - 6 && y > 5 && y < size - 6) continue;
    const i = f * per + (y * size + x) * 3, r = px[i], g = px[i + 1], b = px[i + 2];
    const k = g > r * 1.15 && g > b * 1.1 ? 0 : b > r * 1.15 && b > g * 1.05 ? 1 : -1;
    if (k < 0) continue;
    acc[k][0] += r; acc[k][1] += g; acc[k][2] += b; acc[k][3]++;
  }
  const best = acc[0][3] >= acc[1][3] ? acc[0] : acc[1];
  if (best[3] < 20) return '#20c040';
  const hx = (v) => Math.round(Math.max(0, Math.min(1, v / best[3])) * 255).toString(16).padStart(2, '0');
  return '#' + hx(best[0]) + hx(best[1]) + hx(best[2]);
}

/** where a transparent clip's background goes: an existing visual track below it, or a new one made under it */
function layerBelow(p, item) {
  const ti = p.tracks.findIndex((t) => t.items.some((i) => i.id === item.id)), end = itemEnd(item);
  let any = null;
  for (let j = ti - 1; j >= 0; j--) {
    const tr = p.tracks[j]; if (tr.kind !== 'video' && tr.kind !== 'overlay') continue;
    any = any || tr;
    if (tr.items.every((i) => itemEnd(i) <= item.t0 + 1e-3 || i.t0 >= end - 1e-3)) return { track: tr, free: true, index: j };
  }
  return { track: any, free: false, index: ti };
}

/** the commands that put media `mid` (muted, looped to fill) under each item; makes the track when there is none */
function underCommands(p, items, mid) {
  const cmds = [], made = new Map(), m = mid ? findMedia(p, mid) : null;
  for (const it of items) {
    const L = layerBelow(p, it);
    let trackId = L.free ? L.track.id : null;
    if (!trackId && !m && L.track) continue;           // transparent only: something is already below it
    if (!trackId) {
      const key = L.index;
      if (!made.has(key)) { const tr = createTrack('overlay', 'Background'); made.set(key, tr); cmds.push(ops.addTrack(tr, key, 'Background track')); }
      trackId = made.get(key).id;
    }
    if (!m) continue;
    const box = fitBox(p.canvas, m.w || p.canvas.w, m.h || p.canvas.h, 'cover'), add = [];
    const span = itemEnd(it) - it.t0, len = m.kind === 'image' ? span : Math.max(0.2, m.dur || span);
    for (let t = 0; t < span - 0.01; t += len) {
      const out = Math.min(len, span - t);
      add.push({ trackId, item: createItem('clip', Object.assign({ media: m.id, t0: it.t0 + t, in: 0, out: m.kind === 'image' ? out : out, name: m.name, volume: 0, muted: true }, box)) });
    }
    cmds.push(ops.addItems(add, 'Background clip'));
  }
  return cmds;
}

export async function removeBackground(ctx, o = {}) {
  const items = clipTargets(ctx, o.ids), ws = ctx.workspace || {}, p = ctx.project;
  if (!items.length) throw new Error('Add a video clip first');
  const mode = o.mode || 'blur', key = o.key || 'person';
  if (mode === 'clip' && !o.under) throw new Error('Pick the clip to put behind (import it into the media bin first)');
  if (mode === 'image' && !o.src) throw new Error('Import a picture first (it is used as the background)');
  if (key === 'subject' && !(await subjectReady())) {
    ctx.progress?.('Getting the cut-out model…', 0);
    await installSubjectModel((f) => ctx.progress?.('Getting the cut-out model…', f));
  }
  let keyColor = o.keyColor;
  if (key === 'chroma' && !keyColor) { const px = await samplePixels(p, items[0], 2, 64); keyColor = px ? autoKeyColor(px) : '#20c040'; }
  const prev = bgOf(items[0]);
  const keep = (k) => o[k] ?? (prev && (prev.q || 1) >= 2 ? prev[k] : undefined) ?? BG_DEFAULTS[k];
  const patch = clean({
    type: 'look.bg', q: 2, mode, key,
    color: o.color || ws.colors?.secondary || '#16161c', colors: o.colors || [ws.colors?.primary || '#C6395F', ws.colors?.secondary || '#2A2228'],
    src: o.src, country: o.country || 'gb', under: mode === 'clip' ? o.under : undefined,
    feather: keep('feather'), grow: keep('grow'), smooth: keep('smooth'), wrap: keep('wrap'),
    keyColor: key === 'chroma' ? keyColor : undefined, tol: key === 'chroma' ? keep('tol') : undefined, soft: key === 'chroma' ? keep('soft') : undefined, spill: key === 'chroma' ? keep('spill') : undefined,
  });
  await loadBackground(patch);
  const cmds = [ops.setItems(items.map((it) => ({ id: it.id, props: { fx: withBg(it, Object.assign({}, patch)) } })), 'Remove background')];
  if (TRANSPARENT.has(mode)) cmds.push(...underCommands(p, items, mode === 'clip' ? o.under : null));
  ctx.history.apply(cmds.length > 1 ? ops.batch('Remove background', cmds) : cmds[0]);
  redraw();
  if (key !== 'chroma') await ensureAnalysis(p, { onProgress: (l, f) => ctx.progress?.(l, f) });
  redraw();
  const kind = key === 'subject' ? 'subject' : 'person';
  return { clips: items.length, mode, key, keyColor, coverage: items.map((it) => (key === 'chroma' ? null : maskCoverageAt(it.media, it.in + 0.05, kind, patch.smooth))) };
}

/** live settings on the clips that already have the effect (sliders, colour pickers); returns how many changed */
export function setBg(ctx, patch, { ids, label = 'Background settings' } = {}) {
  const items = clipTargets(ctx, ids).filter((i) => bgOf(i));
  if (!items.length) return 0;
  ctx.history.apply(ops.setItems(items.map((it) => ({ id: it.id, props: { fx: withBg(it, Object.assign({}, bgOf(it), patch, { q: 2 })) } })), label));
  redraw();
  return items.length;
}
export function clearBg(ctx, { ids } = {}) {
  const items = clipTargets(ctx, ids).filter((i) => bgOf(i));
  if (!items.length) return 0;
  ctx.history.apply(ops.setItems(items.map((it) => ({ id: it.id, props: { fx: withBg(it, null) } })), 'Remove effect'));
  redraw();
  return items.length;
}

/** move / scale the cut-out like any item: a box of `size` x the frame, standing on the bottom edge */
export function placeCutout(ctx, where = 'centre', { ids, size = 0.75 } = {}) {
  const items = clipTargets(ctx, ids), c = ctx.project.canvas;
  if (!items.length) throw new Error('Select the cut-out clip');
  const list = items.map((it) => {
    const m = findMedia(ctx.project, it.media), f = fitBox(c, m?.w || c.w, m?.h || c.h, 'cover');
    if (where === 'full') return { id: it.id, props: { x: f.x, y: f.y, w: f.w, h: f.h } };
    const s = Math.max(0.2, Math.min(1, size)), w = Math.round(c.w * s), h = Math.round(c.h * s);
    const x = where === 'left' ? 0 : where === 'right' ? c.w - w : Math.round((c.w - w) / 2);
    return { id: it.id, props: { x, y: c.h - h, w, h } };
  });
  ctx.history.apply(ops.setItems(list, 'Place cut-out'));
  redraw();
  return items.length;
}

// ── transparent exports ──
async function save(ctx, name, blob) {
  const p = ctx.project, file = `workspaces/${p.workspace}/exports/${name}`;
  await ctx.engine.writeFile(file, blob);
  return file;
}
const stamp = () => new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
const slug = (s) => String(s || 'reel').replace(/\.[a-z0-9]+$/i, '').replace(/[^\w-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'reel';

export async function exportCutout(ctx, { what = 'clip', format = 'webm', ids, signal } = {}) {
  const E = await import('../core/export-alpha.js'), p = ctx.project;
  let only = null, name = slug(p.name);
  if (what === 'clip') {
    const its = clipTargets(ctx, ids).filter((i) => bgOf(i));
    if (!its.length) throw new Error('Select a clip with its background removed');
    only = new Set(its.map((i) => i.id)); name = slug(findMedia(p, its[0].media)?.name) + '-cutout';
  }
  const r = await E.exportAlpha(p, ctx.media, { format, only, name, signal, onProgress: (f, label) => ctx.progress?.(label || 'Exporting…', f) });
  const file = await save(ctx, `${name}-${stamp()}.${r.ext}`, r.blob);
  return Object.assign(r, { file });
}
export async function exportFrame(ctx, { ids, t } = {}) {
  const E = await import('../core/export-alpha.js'), p = ctx.project, at = t ?? ctx.playhead?.() ?? 0;
  const its = clipTargets(ctx, ids).filter((i) => bgOf(i) && at >= i.t0 && at < itemEnd(i));
  const blob = await E.framePng(p, ctx.media, at, { only: its.length ? new Set(its.map((i) => i.id)) : null });
  const file = await save(ctx, `${slug(p.name)}-frame-${at.toFixed(2).replace('.', '_')}s-${stamp()}.png`, blob);
  return { file, blob };
}
export { coverageAt, uid };
