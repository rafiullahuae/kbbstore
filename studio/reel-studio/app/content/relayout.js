// KBB Studio · content · one edit → every shape. 9:16 / 4:5 / 1:1 / 16:9 RE-LAID OUT, not just cropped:
//   video        cover-fitted again and re-aimed at the face (or the product) with the same smooth follow Auto reel
//                uses (auto/lib/video.js reframe) — a 16:9 talk becomes a 9:16 that keeps the speaker in frame
//   text, stickers, pops   kept on the same ANCHOR of the title-safe area (top-left stays top-left …), scaled by the
//                smaller ratio, wrapped to the new width and clamped inside the safe area
//   captions     cues rebuilt for the new width (more words fit on 16:9), drawn inside the platform safe area
// Batch export writes one MP4 per shape. The open project is never changed by an export.
import { ASPECTS, clone, safeAreas, findMedia, fitBox, projectDuration } from '../core/project.js';
import { exportMp4 } from '../core/export.js';
import { exportsDir } from '../core/config.js';
import { buildLines } from '../captions/model.js';
import { reframe, cropToItemKf } from '../auto/lib/video.js';
import { facesFor } from './looks.js';
import { r1 } from './lib/items.js';

export const SHAPES = ['9:16', '4:5', '1:1', '16:9'];

/** anchor of a box inside an area: [ax, ay] in 0 / 0.5 / 1 (left|centre|right, top|middle|bottom) */
function anchor(b, A) {
  const cx = (b.x + b.w / 2 - A.x) / A.w, cy = (b.y + b.h / 2 - A.y) / A.h;
  return [cx < 0.34 ? 0 : cx > 0.66 ? 1 : 0.5, cy < 0.34 ? 0 : cy > 0.66 ? 1 : 0.5, cx, cy];
}

/** a copy of project p laid out for `aspect`. faces: {mediaId:[{t,x,y,w,h}]} for the reframe (optional) */
export function layoutFor(p, aspect, faces = {}) {
  const A2 = ASPECTS[aspect]; if (!A2) throw new Error('Unknown shape ' + aspect);
  const q = clone(p);
  if (p.canvas.aspect === aspect) return q;
  const c1 = p.canvas, c2 = { ...c1, w: A2.w, h: A2.h, aspect };
  const S1 = safeAreas(c1).title, S2 = safeAreas(c2).title;
  const s = Math.min(c2.w / c1.w, c2.h / c1.h), sText = s;
  q.canvas = c2;
  for (const tr of q.tracks) {
    if (tr.kind === 'music' || tr.kind === 'sfx') continue;
    for (const it of tr.items) {
      const full = it.w >= c1.w * 0.95 && it.h >= c1.h * 0.95 && it.x <= c1.w * 0.03 && it.y <= c1.h * 0.03;
      if (it.type === 'caption') { Object.assign(it, { x: 0, y: 0, w: c2.w, h: c2.h }); continue; }
      if (full || (it.slot && it.slot.region === 'full')) {
        const m = it.media ? findMedia(q, it.media) : null;
        delete it.kf.x; delete it.kf.y; delete it.kf.w; delete it.kf.h;
        if (it.type === 'clip' && m?.w && tr.kind === 'video' && faces[m.id]?.length && Math.abs(m.w / m.h - c2.w / c2.h) > 0.05) {
          // re-aim at the face: crop keyframes in the source, turned into box keyframes for this item
          const crop = reframe(faces[m.id].map((f) => ({ t: f.t, x: f.x, y: f.y, conf: f.conf })), { w: m.w, h: m.h }, { w: c2.w, h: c2.h }, { duration: m.dur });
          const kf = cropToItemKf(crop.filter((k) => k.t >= it.in - 0.5 && k.t <= it.out + 0.5), { w: m.w, h: m.h }, c2, it.t0, it.in);
          if (kf.x.length) { it.kf = { ...it.kf, ...kf }; Object.assign(it, { x: kf.x[0][1], y: kf.y[0][1], w: kf.w[0][1], h: kf.h[0][1] }); it.crop = null; continue; }
        }
        Object.assign(it, m?.w ? fitBox(c2, m.w, m.h, 'cover') : { x: 0, y: 0, w: c2.w, h: c2.h });
        continue;
      }
      if (it.slot) { // half-screen slots (top/bottom/left/right): the same half of the new canvas
        const R = { top: [0, 0, 1, 0.5], bottom: [0, 0.5, 1, 0.5], left: [0, 0, 0.5, 1], right: [0.5, 0, 0.5, 1] }[it.slot.region] || [0, 0, 1, 1];
        Object.assign(it, { x: R[0] * c2.w, y: R[1] * c2.h, w: R[2] * c2.w, h: R[3] * c2.h }); continue;
      }
      // everything else: same anchor in the title-safe area, scaled, clamped inside it
      const [ax, ay, cx, cy] = anchor(it, S1);
      const k = it.type === 'text' ? sText : s;
      let w = it.w * k, h = it.h * k;
      if (it.type === 'text') { w = Math.min(S2.w, Math.max(w, Math.min(S2.w, it.w / c1.w * c2.w * 0.9))); h = it.h * k; }
      if (w > S2.w) { h *= S2.w / w; w = S2.w; } if (h > S2.h) { w *= S2.h / h; h = S2.h; }
      // left/top anchors keep their margin, right/bottom keep theirs, centred ones keep their centre (all as fractions)
      const place = (a, c, pos, size, A1, a1, A2, a2, n) => a === 0 ? A2 + (pos - A1) / a1 * a2 : a === 1 ? A2 + a2 - (A1 + a1 - pos - size) / a1 * a2 - n : A2 + c * a2 - n / 2;
      let x = place(ax, cx, it.x, it.w, S1.x, S1.w, S2.x, S2.w, w), y = place(ay, cy, it.y, it.h, S1.y, S1.h, S2.y, S2.h, h);
      x = Math.max(S2.x, Math.min(S2.x + S2.w - w, x)); y = Math.max(S2.y, Math.min(S2.y + S2.h - h, y));
      const dx = x - it.x, dy = y - it.y, kw = w / Math.max(1, it.w), kh = h / Math.max(1, it.h);
      // keyframed boxes (slides, pops) move with the item
      for (const [prop, f] of [['x', (v) => S2.x + (v - S1.x) / S1.w * S2.w], ['y', (v) => S2.y + (v - S1.y) / S1.h * S2.h], ['w', (v) => v * kw], ['h', (v) => v * kh]]) if (it.kf?.[prop]) it.kf[prop] = it.kf[prop].map(([t, v, e]) => [t, r1(prop === 'x' || prop === 'y' ? v + (prop === 'x' ? dx : dy) : f(v)), e]);
      Object.assign(it, { x: r1(x), y: r1(y), w: r1(w), h: r1(h) });
      if (it.style?.size) it.style = { ...it.style, size: Math.round(it.style.size * k), ...(it.style.strokeW ? { strokeW: +(it.style.strokeW * k).toFixed(1) } : {}), ...(it.style.pad ? { pad: Math.round(it.style.pad * k) } : {}) };
    }
  }
  if (q.captions?.words?.length) {
    q.captions = { ...q.captions, lines: buildLines(q.captions.words, q.captions.style, c2) };
    for (const tr of q.tracks) for (const it of tr.items) if (it.type === 'caption' && it.style) it.style = { ...it.style }; // per-segment looks keep their pos (fractions of the safe area)
  }
  return q;
}

/**
 * Export the open edit in several shapes. opts: { shapes=SHAPES, size=1080, fps, quality, faces, onProgress, signal }.
 * Returns [{aspect, path, w, h, bytes, frames, note}].
 */
export async function exportShapes(ctx, opts = {}) {
  const p = ctx.project, shapes = opts.shapes || SHAPES, out = [];
  if (projectDuration(p) <= 0) throw new Error('The timeline is empty');
  const faces = opts.faces || await facesFor(ctx);
  const base = String(p.name || 'reel').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 40) || 'reel';
  for (let i = 0; i < shapes.length; i++) {
    const aspect = shapes[i], q = layoutFor(p, aspect, faces), a = ASPECTS[aspect], sc = (opts.size || 1080) / Math.min(a.w, a.h);
    const w = Math.round(a.w * sc / 2) * 2, h = Math.round(a.h * sc / 2) * 2;
    const r = await exportMp4(q, ctx.media, { width: w, height: h, fps: opts.fps, quality: opts.quality || 'standard', signal: opts.signal,
      onProgress: (f, txt) => opts.onProgress?.(`${aspect} (${i + 1}/${shapes.length}) · ${txt}`, (i + f) / shapes.length) });
    const path = `${exportsDir(p.workspace)}/${base}-${aspect.replace(':', 'x')}-${w}x${h}.mp4`;
    if (ctx.engine?.available) await ctx.engine.export(path, r.blob);
    out.push({ aspect, path, w, h, bytes: r.blob.size, frames: r.frames, note: r.note, url: URL.createObjectURL(r.blob) });
  }
  return out;
}
