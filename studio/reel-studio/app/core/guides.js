// KBB Studio — core/guides.js: snapping targets and the snap itself for anything dragged on the canvas (pure, no DOM).
//
//   frameTargets(W, H, safe)          centre lines, rule-of-thirds and the platform safe box as { x:[…], y:[…] } targets
//   boxTargets(boxes, kind)           the edges and centres of other boxes (other caption lines, a face) as targets
//   snapMove(box, dx, dy, targets, thr) -> { dx, dy, guides }   the box's left/centre/right (top/middle/bottom) snap to
//                                     the nearest target within thr canvas px on each axis; guides are what to draw
//                                     ({ axis, at, kind } — editor/preview.js showGuides draws them during the drag).
// A target is { at (canvas px), kind: 'centre' | 'third' | 'safe' | 'line' | 'face' }.

export function frameTargets(W, H, safe) {
  const x = [{ at: W / 2, kind: 'centre' }, { at: W / 3, kind: 'third' }, { at: (2 * W) / 3, kind: 'third' }];
  const y = [{ at: H / 2, kind: 'centre' }, { at: H / 3, kind: 'third' }, { at: (2 * H) / 3, kind: 'third' }];
  if (safe) {
    x.push({ at: safe.x, kind: 'safe' }, { at: safe.x + safe.w, kind: 'safe' });
    y.push({ at: safe.y, kind: 'safe' }, { at: safe.y + safe.h, kind: 'safe' });
  }
  return { x, y };
}

export function boxTargets(boxes, kind) {
  const x = [], y = [];
  for (const b of boxes) {
    x.push({ at: b.x, kind }, { at: b.x + b.w / 2, kind }, { at: b.x + b.w, kind });
    y.push({ at: b.y, kind }, { at: b.y + b.h / 2, kind }, { at: b.y + b.h, kind });
  }
  return { x, y };
}

export const mergeTargets = (...list) => ({ x: list.flatMap((t) => t.x), y: list.flatMap((t) => t.y) });

function best(vals, targets, thr) {
  let out = null;
  for (const v of vals) for (const t of targets) { const d = t.at - v; if (Math.abs(d) <= thr && (!out || Math.abs(d) < Math.abs(out.d))) out = { d, at: t.at, kind: t.kind }; }
  return out;
}

export function snapMove(box, dx, dy, targets, thr) {
  const sx = best([box.x + dx, box.x + box.w / 2 + dx, box.x + box.w + dx], targets.x, thr);
  const sy = best([box.y + dy, box.y + box.h / 2 + dy, box.y + box.h + dy], targets.y, thr);
  const guides = [];
  if (sx) { dx += sx.d; guides.push({ axis: 'x', at: sx.at, kind: sx.kind }); }
  if (sy) { dy += sy.d; guides.push({ axis: 'y', at: sy.at, kind: sy.kind }); }
  return { dx, dy, guides };
}

export const overlaps = (a, b) => a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
