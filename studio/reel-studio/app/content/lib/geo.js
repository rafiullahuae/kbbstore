// KBB Studio · content · geometry: where things sit ON THE CANVAS over time (faces seen in the source, products and
// stickers on the overlay track), so captions, pops and titles can stay off them. Pure apart from reading the project.
import { boxAt } from '../../core/render.js';
import { isActive, findMedia, srcTime } from '../../core/project.js';

export const area = (r) => Math.max(0, r.w) * Math.max(0, r.h);
export function inter(a, b) {
  const x = Math.max(a.x, b.x), y = Math.max(a.y, b.y), x2 = Math.min(a.x + a.w, b.x + b.w), y2 = Math.min(a.y + a.h, b.y + b.h);
  return x2 > x && y2 > y ? (x2 - x) * (y2 - y) : 0;
}
export const inside = (r, box, tol = 0.5) => r.x >= box.x - tol && r.y >= box.y - tol && r.x + r.w <= box.x + box.w + tol && r.y + r.h <= box.y + box.h + tol;
export const grow = (r, k) => ({ x: r.x - r.w * k / 2, y: r.y - r.h * k / 2, w: r.w * (1 + k), h: r.h * (1 + k) });

/** a source point (0..1 of the media frame) → canvas px for a clip item at timeline time t (same cover maths as the clip layer) */
export function sourceToCanvas(item, media, t, nx, ny) {
  const box = boxAt(item, t), mw = media.w || 1920, mh = media.h || 1080, c = item.crop || { l: 0, t: 0, r: 0, b: 0 };
  const cx = mw * c.l, cy = mh * c.t, cw = mw * (1 - c.l - c.r), ch = mh * (1 - c.t - c.b);
  const s = Math.max(box.w / cw, box.h / ch), vw = box.w / s, vh = box.h / s;
  const vx = cx + (cw - vw) / 2, vy = cy + (ch - vh) / 2;
  return { x: box.x + (nx * mw - vx) * s, y: box.y + (ny * mh - vy) * s, s };
}

/** the face nearest t in a faces list [{t,x,y,w,h}] (x null = nobody) */
export function faceAt(faces, t) {
  if (!faces || !faces.length) return null;
  let best = null, bd = Infinity;
  for (const f of faces) { const d = Math.abs(f.t - t); if (d < bd) { bd = d; best = f; } }
  return best && best.x != null && best.w ? (bd < 2.5 ? best : null) : null;
}

/**
 * Canvas rects to keep clear at timeline time t:
 *   faces:  { [mediaId]: [{t (source s), x, y, w, h}] }  (from auto.analyse subjects)
 * Returns [{x,y,w,h,kind:'face'|'product'|'sticker'}]; faces grown by 35 % (hair, chin), products as drawn.
 */
export function obstaclesAt(project, t, faces = {}, { skip } = {}) {
  const out = [];
  for (const tr of project.tracks) {
    if (tr.hidden || tr.kind === 'music' || tr.kind === 'sfx' || tr.kind === 'caption') continue;
    for (const it of tr.items) {
      if (!isActive(it, t) || skip?.has(it.id)) continue;
      const m = it.media ? findMedia(project, it.media) : null;
      if (tr.kind === 'video' && m && faces[m.id]) {
        const f = faceAt(faces[m.id], srcTime(it, t));
        if (f) {
          const a = sourceToCanvas(it, m, t, f.x - f.w / 2, f.y - f.h / 2), b = sourceToCanvas(it, m, t, f.x + f.w / 2, f.y + f.h / 2);
          out.push({ ...grow({ x: a.x, y: a.y, w: b.x - a.x, h: b.y - a.y }, 0.35), kind: 'face' });
        }
        continue;
      }
      if (tr.kind === 'video') continue;
      const full = it.w >= project.canvas.w * 0.95 && it.h >= project.canvas.h * 0.95;
      if (full) continue;   // a full-frame b-roll is not an obstacle (nothing sits "beside" it)
      if (it.type === 'clip' || it.type === 'sticker' || (it.type === 'text' && it.auto === 'content.pop')) out.push({ ...boxAt(it, t), kind: it.content?.product ? 'product' : 'sticker' });
    }
  }
  return out;
}
