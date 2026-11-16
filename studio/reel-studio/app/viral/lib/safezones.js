// KBB Studio · viral · platform safe zones (pure ESM).
// Where each app draws its own buttons, captions and bars over a vertical video (fractions of the frame, measured from
// each app's 2026 layout on a 1080×1920 screen, rounded outward). Text, captions and stickers must stay out of them.

export const PLATFORMS = {
  ig: { name: 'Instagram Reels', rects: [
    { id: 'top', x: 0, y: 0, w: 1, h: 0.075, what: 'Reels title and camera' },
    { id: 'rail', x: 0.86, y: 0.43, w: 0.14, h: 0.45, what: 'like · comment · share · audio buttons' },
    { id: 'bottom', x: 0, y: 0.79, w: 1, h: 0.21, what: 'name, caption, audio and the tab bar' }] },
  tiktok: { name: 'TikTok', rects: [
    { id: 'top', x: 0, y: 0, w: 1, h: 0.085, what: 'Following · For You' },
    { id: 'rail', x: 0.85, y: 0.37, w: 0.15, h: 0.5, what: 'avatar · like · comment · save · share · sound' },
    { id: 'bottom', x: 0, y: 0.77, w: 1, h: 0.23, what: 'name, caption, sound and the tab bar' }] },
  shorts: { name: 'YouTube Shorts', rects: [
    { id: 'top', x: 0, y: 0, w: 1, h: 0.07, what: 'search and the menu' },
    { id: 'rail', x: 0.85, y: 0.42, w: 0.15, h: 0.46, what: 'like · dislike · comment · share · remix' },
    { id: 'bottom', x: 0, y: 0.8, w: 1, h: 0.2, what: 'channel, title, subscribe and the tab bar' }] },
  snap: { name: 'Snapchat Spotlight', rects: [
    { id: 'top', x: 0, y: 0, w: 1, h: 0.09, what: 'header' },
    { id: 'rail', x: 0.87, y: 0.5, w: 0.13, h: 0.34, what: 'favourite · comment · share' },
    { id: 'bottom', x: 0, y: 0.84, w: 1, h: 0.16, what: 'creator name, caption and the tab bar' }] },
};
export const PLATFORM_IDS = Object.keys(PLATFORMS);

/** UI rects in canvas px for one platform, or the union of all ('all'). Non-vertical canvases get a plain margin. */
export function uiRects(canvas, platform = 'ig') {
  const { w, h } = canvas;
  if (h / w < 1.3) return [{ id: 'edge-top', x: 0, y: 0, w, h: h * 0.05, what: 'edge', platform }, { id: 'edge-bottom', x: 0, y: h * 0.92, w, h: h * 0.08, what: 'player controls', platform }];
  const ids = platform === 'all' ? PLATFORM_IDS : [platform];
  return ids.flatMap((pid) => PLATFORMS[pid].rects.map((r) => ({ id: pid + ':' + r.id, x: r.x * w, y: r.y * h, w: r.w * w, h: r.h * h, what: r.what, platform: pid })));
}

export const area = (r) => Math.max(0, r.w) * Math.max(0, r.h);
export function inter(a, b) { const x = Math.max(a.x, b.x), y = Math.max(a.y, b.y), x2 = Math.min(a.x + a.w, b.x + b.w), y2 = Math.min(a.y + a.h, b.y + b.h); return x2 > x && y2 > y ? { x, y, w: x2 - x, h: y2 - y } : null; }
/** rects a box touches (more than `tol` px of overlap in both directions) */
export function hits(box, rects, tol = 2) { return rects.filter((r) => { const i = inter(box, r); return i && i.w > tol && i.h > tol; }); }

/**
 * The smallest move (and, when it cannot fit, a shrink) that takes `box` out of every rect, staying on the canvas.
 * -> { dx, dy, scale } (scale about the box centre, applied before the move) or null when it is already clear.
 */
export function nudge(box, rects, canvas, margin = 12) {
  if (!hits(box, rects).length) return null;
  const top = Math.max(0, ...rects.filter((r) => r.y <= 1 && r.w >= canvas.w * 0.5).map((r) => r.y + r.h));           // full-width bars at the top
  const bottom = Math.min(canvas.h, ...rects.filter((r) => r.y + r.h >= canvas.h - 1 && r.w >= canvas.w * 0.5).map((r) => r.y)); // ... and at the bottom
  const rails = rects.filter((r) => r.w < canvas.w * 0.5);
  const free = { x0: margin, x1: canvas.w - margin, y0: top + margin, y1: bottom - margin };
  // shrink when the box is taller than the free band
  let s = Math.min(1, (free.y1 - free.y0) / box.h, (free.x1 - free.x0) / box.w);
  const tryAt = (b) => !hits(b, rects).length && b.x >= free.x0 - 1 && b.x + b.w <= free.x1 + 1 && b.y >= free.y0 - 1 && b.y + b.h <= free.y1 + 1;
  for (let k = 0; k < 12; k++, s *= 0.92) {
    const w = box.w * s, h = box.h * s, cx = box.x + box.w / 2, cy = box.y + box.h / 2;
    const b0 = { x: cx - w / 2, y: cy - h / 2, w, h };
    const cands = [];
    const clampY = (y) => Math.min(free.y1 - h, Math.max(free.y0, y)), clampX = (x) => Math.min(free.x1 - w, Math.max(free.x0, x));
    cands.push({ x: clampX(b0.x), y: clampY(b0.y) });                                   // just pushed inside the free band
    for (const r of rails) {
      cands.push({ x: clampX(Math.min(b0.x, r.x - margin - w)), y: clampY(b0.y) });      // left of the rail
      cands.push({ x: clampX(b0.x), y: clampY(Math.min(b0.y, r.y - margin - h)) });      // above the rail
      cands.push({ x: clampX(b0.x), y: clampY(Math.max(b0.y, r.y + r.h + margin)) });    // below the rail
      cands.push({ x: clampX(Math.min(b0.x, r.x - margin - w)), y: clampY(Math.min(b0.y, r.y - margin - h)) });
      // centred in what is left of the width beside the rail (wide captions)
      cands.push({ x: clampX(Math.max(free.x0, (free.x0 + r.x - margin) / 2 - w / 2)), y: clampY(b0.y) });
    }
    const ok = cands.map((c) => ({ ...c, w, h })).filter(tryAt).sort((a, b) => Math.hypot(a.x - b0.x, a.y - b0.y) - Math.hypot(b.x - b0.x, b.y - b0.y));
    if (ok.length) return { dx: ok[0].x - b0.x, dy: ok[0].y - b0.y, scale: s };
  }
  return { dx: 0, dy: 0, scale: s, failed: true };
}
