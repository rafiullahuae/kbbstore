// Drag a caption on the canvas: grabs the caption block under the pointer (the exact box the renderer draws) and
// moves THAT line (line.ov.pos) — or every caption (style.pos) with Shift held or when the panel's "Drag moves"
// says "All lines". One undo step per drag (commands coalesce on a key; the editor seals on pointer-up).

import { registerCanvasGrab } from '../core/modules.js';
import { captionBlocks, safeArea } from './render.js';
import { resolveStyle } from './styles.js';
import { setLineOverride, moveAll } from './commands.js';

export const dragPrefs = { scope: 'line' };   // 'line' | 'all' (the panel's toggle)

let mctx = null;
const measureCtx = () => (mctx ||= (typeof OffscreenCanvas === 'function' ? new OffscreenCanvas(8, 8) : document.createElement('canvas')).getContext('2d'));

function captionItem(project, t) {
  for (const tr of project.tracks || []) {
    if (tr.kind !== 'caption' || tr.hidden || tr.locked) continue;
    for (const it of tr.items) if (it.type === 'caption' && t >= it.t0 && t < it.t0 + (it.out - it.in) / (it.speed || 1)) return it;
  }
  return null;
}

/** The caption block at canvas point p (12 px of slack), or null. */
export function captionAt(project, t, p, { needItem = true } = {}) {
  const item = captionItem(project, t);
  if (needItem && !item) return null;
  const blocks = captionBlocks(measureCtx(), project, t, item);
  const s = 12;
  for (const b of blocks) if (p.x >= b.box.x - s && p.x <= b.box.x + b.box.w + s && p.y >= b.box.y - s && p.y <= b.box.y + b.box.h + s) return { ...b, item, blocks };
  return null;
}

/** Start a drag at p: returns { move(dx,dy,ev), end() } or null when no caption is there. */
export function grabCaption(project, t, p, ev, api, opts = {}) {
  const hit = captionAt(project, t, p, opts);
  if (!hit) return null;
  const cap = project.captions, W = project.canvas?.w || 1080, H = project.canvas?.h || 1920;
  const safe = safeArea(W, H);
  const L = hit.blocks.find((b) => b.main)?.L || hit.L;
  // where the block's centre is now, as safe-area fractions (the renderer's pos.x / pos.y)
  const first = hit.blocks[0], lastB = hit.blocks[hit.blocks.length - 1];
  const cy = (first.top + lastB.top + lastB.lay.height) / 2;
  const cx = hit.box.x + hit.box.w / 2;
  const x0 = (cx - safe.x) / safe.w, y0 = (cy - safe.y) / safe.h;
  const all = ev?.shiftKey || dragPrefs.scope === 'all';
  const st = resolveStyle(cap.style);
  const key = all ? 'captions.moveAll' : 'captions.move:' + L.id;
  let moved = false;
  return {
    line: L.id, all,
    move(dx, dy) {
      if (!moved && Math.hypot(dx, dy) < 3) return;
      moved = true;
      let x = x0 + dx / safe.w, y = y0 + dy / safe.h;
      if (Math.abs(x - 0.5) < 0.02) x = 0.5;                 // snap to the centre line
      x = +Math.max(0, Math.min(1, x)).toFixed(4); y = +Math.max(0, Math.min(1, y)).toFixed(4);
      api.apply(all ? moveAll({ x, y }, key) : setLineOverride(L.id, { pos: { x, y } }, key));
    },
    end() { return moved; },
    st,
  };
}

registerCanvasGrab({ id: 'captions', grab: (project, t, p, ev, api) => grabCaption(project, t, p, ev, api) });
