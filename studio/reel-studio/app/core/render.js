// KBB Studio — core/render.js (CONTRACT, owned by R1)
//
// ONE render path for the preview monitor and the MP4 export: both call renderFrame(ctx, project, t, opts).
// Modules add drawing with:
//
//   registerLayer(kind, drawFn)     kind = an item.type ('caption', 'text', 'my-effect' ...) or, as a fallback,
//                                   a track kind. drawFn(ctx, item, t, project, env) draws ONE item at time t.
//                                   Before the call the ctx is translated/rotated so (0,0) is the item box's top-left
//                                   corner, box size env.box.w × env.box.h, globalAlpha = opacity incl. fades.
//                                   Coordinates are canvas px (the ctx is already scaled for preview size).
//   registerFx(type, fn)            per-item effect in item.fx: fn(ctx, item, t, project, fx, env) runs after the
//                                   transform and before the layer draws (e.g. punch-in zoom: scale about centre).
//                                   Return a CSS filter string to add to ctx.filter, or nothing.
//   registerOverlay(id, fn, z=0)    a whole-frame pass after all tracks: fn(ctx, t, project, env) (end cards, flashes).
//
// env = { w, h, scale, preview, source(item, srcT) -> CanvasImageSource|null, box:{x,y,w,h}, track, local (s into item) }

import { isActive, itemDur, srcTime, findMedia } from './project.js';

const layers = new Map();
const fxs = new Map();
const overlays = [];

export function registerLayer(kind, drawFn) { layers.set(kind, drawFn); }
export function registerFx(type, fn) { fxs.set(type, fn); }
export function registerOverlay(id, fn, z = 0) {
  const i = overlays.findIndex((o) => o.id === id); if (i >= 0) overlays.splice(i, 1);
  overlays.push({ id, fn, z }); overlays.sort((a, b) => a.z - b.z);
}
export const hasLayer = (kind) => layers.has(kind);

// ── keyframes ──
const EASE = {
  linear: (x) => x,
  in: (x) => x * x,
  out: (x) => 1 - (1 - x) * (1 - x),
  inout: (x) => (x < 0.5 ? 2 * x * x : 1 - Math.pow(-2 * x + 2, 2) / 2),
  hold: () => 0,
};

/** value of item[prop] at timeline time t, honouring item.kf[prop] = [[t, v, ease]] (t relative to item start) */
export function evalProp(item, prop, t) {
  const k = item.kf && item.kf[prop];
  if (!k || !k.length) return item[prop];
  const lt = t - item.t0;
  if (lt <= k[0][0]) return k[0][1];
  for (let i = 0; i < k.length - 1; i++) {
    const a = k[i], b = k[i + 1];
    if (lt < b[0]) {
      const f = (EASE[a[2]] || EASE.linear)((lt - a[0]) / Math.max(1e-6, b[0] - a[0]));
      return typeof a[1] === 'number' ? a[1] + (b[1] - a[1]) * f : (f < 1 ? a[1] : b[1]);
    }
  }
  return k[k.length - 1][1];
}

/** opacity including fade in/out (visual fades for visual items; audio uses the same numbers in the mixer) */
export function fadeAt(item, t) {
  const lt = t - item.t0, d = itemDur(item);
  let f = 1;
  if (item.fadeIn > 0 && lt < item.fadeIn) f *= Math.max(0, lt / item.fadeIn);
  if (item.fadeOut > 0 && lt > d - item.fadeOut) f *= Math.max(0, (d - lt) / item.fadeOut);
  return f;
}

export function boxAt(item, t) {
  return { x: evalProp(item, 'x', t), y: evalProp(item, 'y', t), w: evalProp(item, 'w', t), h: evalProp(item, 'h', t) };
}

/** visible items at t in draw order: [{track, item}] */
export function activeItems(project, t) {
  const out = [];
  for (const track of project.tracks) {
    if (track.hidden || track.kind === 'music' || track.kind === 'sfx') continue;
    for (const item of track.items) if (isActive(item, t)) out.push({ track, item });
  }
  return out;
}

// built-in colour filters for item.fx entries {type, v}
const FILTERS = {
  brightness: (v) => `brightness(${v})`,
  contrast: (v) => `contrast(${v})`,
  saturate: (v) => `saturate(${v})`,
  hue: (v) => `hue-rotate(${v}deg)`,
  blur: (v) => (v > 0 ? `blur(${v}px)` : ''),
  grayscale: (v) => `grayscale(${v})`,
  sepia: (v) => `sepia(${v})`,
  warmth: (v) => (v ? `sepia(${Math.abs(v) * 0.35}) hue-rotate(${v < 0 ? 180 : 0}deg)`.replace(' hue-rotate(0deg)', '') : ''),
};
/** filter presets the inspector offers (name -> fx list) */
export const FILTER_PRESETS = {
  none: [],
  glow: [{ type: 'brightness', v: 1.08 }, { type: 'contrast', v: 0.95 }, { type: 'saturate', v: 1.15 }],
  vivid: [{ type: 'contrast', v: 1.15 }, { type: 'saturate', v: 1.4 }],
  warm: [{ type: 'warmth', v: 0.5 }, { type: 'saturate', v: 1.1 }],
  cool: [{ type: 'hue', v: -12 }, { type: 'saturate', v: 0.9 }, { type: 'brightness', v: 1.04 }],
  mono: [{ type: 'grayscale', v: 1 }, { type: 'contrast', v: 1.1 }],
  vintage: [{ type: 'sepia', v: 0.45 }, { type: 'contrast', v: 0.9 }, { type: 'brightness', v: 1.05 }],
  soft: [{ type: 'brightness', v: 1.06 }, { type: 'contrast', v: 0.88 }, { type: 'saturate', v: 0.9 }],
};

/**
 * Draw the frame at timeline time t.
 * @param ctx      CanvasRenderingContext2D (or Offscreen) sized project.canvas.w*scale × h*scale
 * @param opts     { scale=1, preview=false, source(item, srcT) -> drawable|null, skip?:Set(itemId) }
 */
export function renderFrame(ctx, project, t, opts = {}) {
  const scale = opts.scale || 1;
  const { w, h } = project.canvas;
  ctx.save();
  ctx.setTransform(scale, 0, 0, scale, 0, 0);
  ctx.globalAlpha = 1; ctx.filter = 'none';
  ctx.fillStyle = project.canvas.bg || '#000';
  ctx.fillRect(0, 0, w, h);
  const env = { w, h, scale, preview: !!opts.preview, source: opts.source || (() => null), project };
  for (const { track, item } of activeItems(project, t)) {
    if (opts.skip && opts.skip.has(item.id)) continue;
    drawItem(ctx, item, t, project, Object.assign({}, env, { track }));
  }
  for (const o of overlays) {
    ctx.save();
    try { o.fn(ctx, t, project, env); } catch (e) { warnOnce('overlay:' + o.id, e); }
    ctx.restore();
  }
  ctx.restore();
}

export function drawItem(ctx, item, t, project, env) {
  const draw = layers.get(item.type) || layers.get(env.track?.kind);
  if (!draw) return;
  const box = boxAt(item, t);
  const op = evalProp(item, 'opacity', t) * fadeAt(item, t);
  if (op <= 0.001) return;
  const rot = evalProp(item, 'rot', t) || 0;
  ctx.save();
  ctx.globalAlpha = Math.min(1, op);
  ctx.translate(box.x + box.w / 2, box.y + box.h / 2);
  if (rot) ctx.rotate((rot * Math.PI) / 180);
  ctx.translate(-box.w / 2, -box.h / 2);
  const e = Object.assign({}, env, { box, local: t - item.t0 });
  const filters = [];
  for (const fx of item.fx || []) {
    if (FILTERS[fx.type]) { const f = FILTERS[fx.type](fx.v); if (f) filters.push(f); continue; }
    const fn = fxs.get(fx.type);
    if (fn) { try { const f = fn(ctx, item, t, project, fx, e); if (typeof f === 'string' && f) filters.push(f); } catch (err) { warnOnce('fx:' + fx.type, err); } }
  }
  if (filters.length) ctx.filter = filters.join(' ');
  try { draw(ctx, item, t, project, e); } catch (err) { warnOnce('layer:' + item.type, err); }
  ctx.restore();
}

const warned = new Set();
function warnOnce(key, err) { if (warned.has(key)) return; warned.add(key); console.warn('[render]', key, err); }

// ── built-in layers ──

/** clip: a video or image source, cover-fitted into its box, with crop and Ken Burns zoom */
registerLayer('clip', (ctx, item, t, project, env) => {
  const src = env.source(item, srcTime(item, t));
  const { w, h } = env.box;
  if (!src) { if (env.preview) { ctx.fillStyle = '#1b1b22'; ctx.fillRect(0, 0, w, h); } return; }
  const sw = src.videoWidth || src.displayWidth || src.naturalWidth || src.width;
  const sh = src.videoHeight || src.displayHeight || src.naturalHeight || src.height;
  if (!sw || !sh) return;
  const c = item.crop || { l: 0, t: 0, r: 0, b: 0 };
  let cx = sw * c.l, cy = sh * c.t, cw = sw * (1 - c.l - c.r), ch = sh * (1 - c.t - c.b);
  if (cw <= 1 || ch <= 1) return;
  // cover the box with the cropped source
  const s = Math.max(w / cw, h / ch);
  let vw = w / s, vh = h / s;
  let vx = cx + (cw - vw) / 2, vy = cy + (ch - vh) / 2;
  // Ken Burns: zoom from→to across the item, centred on (cx, cy) of the visible area
  const z = item.zoom;
  if (z && (z.from !== 1 || z.to !== 1)) {
    const f = Math.min(1, Math.max(0, (t - item.t0) / Math.max(0.001, itemDur(item))));
    const k = Math.max(1, (z.from || 1) + ((z.to || 1) - (z.from || 1)) * EASE.inout(f));
    const nw = vw / k, nh = vh / k;
    vx += (vw - nw) * (z.cx ?? 0.5); vy += (vh - nh) * (z.cy ?? 0.5); vw = nw; vh = nh;
  }
  ctx.drawImage(src, vx, vy, vw, vh, 0, 0, w, h);
});

/** text: wrapped to the box width, vertically centred; style = {font,size,weight,color,bg,align,stroke,strokeW,shadow,italic,upper,lineH,pad,radius} */
registerLayer('text', (ctx, item, t, project, env) => drawText(ctx, item.text || '', item.style || {}, env.box));

/** shape: style = {shape:'rect'|'ellipse'|'line', fill, stroke, strokeW, radius} */
registerLayer('shape', (ctx, item, t, project, env) => {
  const s = item.style || {}, { w, h } = env.box;
  ctx.beginPath();
  if (s.shape === 'ellipse') ctx.ellipse(w / 2, h / 2, w / 2, h / 2, 0, 0, Math.PI * 2);
  else if (s.shape === 'line') { ctx.moveTo(0, h / 2); ctx.lineTo(w, h / 2); }
  else roundRect(ctx, 0, 0, w, h, s.radius || 0);
  if (s.fill && s.shape !== 'line') { ctx.fillStyle = s.fill; ctx.fill(); }
  if (s.stroke && s.strokeW > 0 || s.shape === 'line') { ctx.strokeStyle = s.stroke || s.fill || '#fff'; ctx.lineWidth = s.strokeW || 6; ctx.stroke(); }
});

/** sticker: an emoji / glyph (item.text) or an image (item.media) filling the box */
registerLayer('sticker', (ctx, item, t, project, env) => {
  const { w, h } = env.box;
  if (item.media) { const src = env.source(item, 0); if (src) ctx.drawImage(src, 0, 0, w, h); return; }
  ctx.font = `${Math.round(h * 0.86)}px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif`;
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText(item.text || '⭐', w / 2, h / 2 + h * 0.04);
});

export function roundRect(ctx, x, y, w, h, r) {
  r = Math.max(0, Math.min(r, w / 2, h / 2));
  ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
}

/** wrap `text` into lines no wider than maxW with the ctx's current font */
export function wrapText(ctx, text, maxW) {
  const out = [];
  for (const para of String(text).split('\n')) {
    const words = para.split(/\s+/).filter(Boolean);
    let line = '';
    for (const wd of words) {
      const tryL = line ? line + ' ' + wd : wd;
      if (ctx.measureText(tryL).width <= maxW || !line) line = tryL; else { out.push(line); line = wd; }
    }
    out.push(line);
  }
  return out;
}

export function fontString(s) {
  const fam = s.font || 'system-ui';
  const quoted = /[\s]/.test(fam) && !/["',]/.test(fam) ? `"${fam}"` : fam;
  return `${s.italic ? 'italic ' : ''}${s.weight || 700} ${s.size || 72}px ${quoted}, -apple-system, "Segoe UI", Roboto, sans-serif`;
}

export function drawText(ctx, text, s, box) {
  const { w, h } = box;
  const pad = s.pad ?? 24, size = s.size || 72, lh = (s.lineH || 1.15) * size;
  ctx.font = fontString(s);
  const txt = s.upper ? String(text).toUpperCase() : String(text);
  const lines = wrapText(ctx, txt, Math.max(10, w - pad * 2));
  const total = lines.length * lh;
  if (s.bg) {
    ctx.fillStyle = s.bg; ctx.beginPath(); roundRect(ctx, 0, 0, w, h, s.radius ?? 20); ctx.fill();
  }
  ctx.textBaseline = 'middle';
  const align = s.align || 'center';
  ctx.textAlign = align;
  const x = align === 'left' ? pad : align === 'right' ? w - pad : w / 2;
  let y = h / 2 - total / 2 + lh / 2;
  if (s.shadow) { ctx.shadowColor = 'rgba(0,0,0,.55)'; ctx.shadowBlur = size * 0.18; ctx.shadowOffsetY = size * 0.05; }
  for (const ln of lines) {
    if (s.stroke && s.strokeW > 0) { ctx.lineJoin = 'round'; ctx.strokeStyle = s.stroke; ctx.lineWidth = s.strokeW * 2; ctx.strokeText(ln, x, y); }
    ctx.fillStyle = s.color || '#fff'; ctx.fillText(ln, x, y);
    y += lh;
  }
  ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0; ctx.shadowOffsetY = 0;
}

/** media lookup helper for layers that need the media entry of an item */
export const mediaOf = (project, item) => (item.media ? findMedia(project, item.media) : null);
