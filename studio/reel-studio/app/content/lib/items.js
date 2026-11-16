// KBB Studio · content · item builders shared by the content features. Everything here returns plain items / commands;
// the caller applies them as ONE undoable step.
import { createItem, createTrack, tracksOfKind, findMedia, fitBox, uid, projectDuration } from '../../core/project.js';
import { ops } from '../../core/history.js';

export const r1 = (x) => Math.round(x * 1000) / 1000;
export const trackOf = (p, kind) => tracksOfKind(p, kind).find((t) => !t.locked) || tracksOfKind(p, kind)[0] || null;

/** a track of `kind`, created (as a command) when the project has none */
export function ensureTrack(p, kind, cmds) {
  let tr = trackOf(p, kind);
  if (!tr) { tr = createTrack(kind); cmds.push(ops.addTrack(tr, kind === 'overlay' ? 1 : undefined)); }
  return tr;
}

/** media entry for a file inside the studio folder (catalogue product, flag, library clip), probed once per project */
export async function studioMedia(ctx, p, src, name, cmds, pending = new Map()) {
  const have = p.media.find((m) => m.src === src) || pending.get(src);
  if (have) return have;
  const m = await ctx.media.importPath(src, name);
  pending.set(src, m);
  cmds.push(ops.addMedia(m, 'Library'));
  return m;
}

/** pop-in entrance: the 'content.pop' fx (registered in content/layers.js) scales the item about its centre
 *  0.55 → 1.12 → 1 over `dur` s — works for text (whose font size the box does not scale) and pictures alike */
export const POP = (dur = 0.28) => ({ type: 'content.pop', dur });

/** a text item (core 'text' layer) with a pop entrance and a quick fade out */
export function popText(text, box, t0, dur, style, extra = {}) {
  return createItem('text', { t0: r1(t0), in: 0, out: r1(dur), ...box, text, style, fx: [POP()], fadeOut: Math.min(0.2, dur / 4), ...extra });
}
/** an image (product cut-out, flag, country sticker) as a 'clip' on an overlay track, contain-fitted into box */
export function popImage(m, box, t0, dur, extra = {}) {
  const k = m.w && m.h ? m.w / m.h : 1;
  let w = box.w, h = box.w / k; if (h > box.h) { h = box.h; w = h * k; }
  const b = { x: r1(box.x + (box.w - w) / 2), y: r1(box.y + (box.h - h) / 2), w: r1(w), h: r1(h) };
  return createItem('clip', { media: m.id, t0: r1(t0), in: 0, out: r1(dur), ...b, fx: [POP()], fadeOut: Math.min(0.2, dur / 4), name: m.name, ...extra });
}
/** a full-frame b-roll clip (cover) with a slow push-in */
export function fullClip(p, m, t0, dur, srcIn = 0, extra = {}) {
  const box = fitBox(p.canvas, m.w || p.canvas.w, m.h || p.canvas.h, 'cover');
  return createItem('clip', { media: m.id, t0: r1(t0), in: r1(srcIn), out: r1(srcIn + dur), ...box, zoom: { from: 1, to: 1.08, cx: 0.5, cy: 0.5 }, fadeIn: 0.12, fadeOut: 0.12, name: m.name, ...extra });
}

/** the main talking clip: the first video-track item with media (its media id) */
export function mainVideo(p) {
  for (const tr of p.tracks) if (tr.kind === 'video') for (const it of [...tr.items].sort((a, b) => a.t0 - b.t0)) if (it.media && findMedia(p, it.media)?.kind === 'video') return { item: it, media: findMedia(p, it.media) };
  return null;
}
export const projectEnd = (p) => projectDuration(p);
export { uid, ops, createItem, createTrack };

/** items this feature placed earlier (item.auto === tag) — a re-run replaces them, never the owner's own work */
export const placedBy = (p, tag) => p.tracks.flatMap((t) => t.items.filter((i) => i.auto === tag).map((i) => i.id));

/** brand text style for titles / pops (caption fonts are loaded globally by the captions module) */
export function brandText(ws, { size = 86, color = '#ffffff', bg = null, font = 'Montserrat', weight = 900, upper = true, stroke = null, strokeW = 0, radius = 999, shadow = !bg } = {}) {
  return { font, size, weight, color, bg, align: 'center', upper, stroke, strokeW, shadow, radius, pad: Math.round(size * 0.35), lineH: 1.1 };
}
