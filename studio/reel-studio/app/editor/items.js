// KBB Studio — making items for the timeline (pure functions).

import { createItem, fitBox, itemEnd, ASPECTS, VISUAL_KINDS } from '../core/project.js';
import { runReflows } from '../core/modules.js';

export function clipFromMedia(project, m, trackKind, t0 = 0) {
  if (m.kind === 'audio') return createItem('audio', { media: m.id, t0, in: 0, out: m.dur || 1, name: m.name, fadeOut: 0 });
  const c = project.canvas;
  const dur = m.kind === 'image' ? 4 : m.dur || 1;
  let box;
  if (trackKind === 'video') box = fitBox(c, m.w || c.w, m.h || c.h, 'cover');
  else {
    const f = fitBox(c, m.w || 400, m.h || 400, 'contain');
    const k = 0.55; box = { w: Math.round(f.w * k), h: Math.round(f.h * k) }; box.x = Math.round((c.w - box.w) / 2); box.y = Math.round((c.h - box.h) / 2);
  }
  return createItem('clip', Object.assign({ media: m.id, t0, in: 0, out: dur, name: m.name }, box));
}

export const TEXT_PRESETS = {
  title: { label: 'Title', text: 'Your title here', h: 0.12, y: 0.2, style: { size: 96, weight: 800, color: '#ffffff', shadow: true, align: 'center' } },
  subtitle: { label: 'Subtitle', text: 'A short line of text', h: 0.08, y: 0.32, style: { size: 56, weight: 600, color: '#ffffff', shadow: true, align: 'center' } },
  lower: { label: 'Lower third', text: 'Name Surname\nRole · Company', h: 0.11, y: 0.62, w: 0.72, x: 0.08, style: { size: 46, weight: 700, color: '#ffffff', bg: 'rgba(0,0,0,.6)', align: 'left', radius: 18, pad: 32 } },
  price: { label: 'Price tag', text: 'AED 99', h: 0.08, y: 0.5, w: 0.42, x: 0.29, style: { size: 72, weight: 900, color: '#ffffff', bg: '#e8508b', align: 'center', radius: 999 }, rot: -6 },
  cta: { label: 'Call to action', text: 'Shop now →', h: 0.075, y: 0.7, w: 0.56, x: 0.22, style: { size: 58, weight: 800, color: '#111111', bg: '#ffffff', align: 'center', radius: 999 } },
  bold: { label: 'Bold outline', text: 'WOW', h: 0.14, y: 0.4, style: { size: 150, weight: 900, color: '#ffe14d', stroke: '#000000', strokeW: 8, upper: true, align: 'center' } },
};

export function textItem(project, key = 'title', t0 = 0, ws = null) {
  const p = TEXT_PRESETS[key] || TEXT_PRESETS.title, c = project.canvas;
  const scale = Math.min(c.w, c.h) / 1080;
  const w = Math.round((p.w ?? 0.84) * c.w), hgt = Math.round(p.h * 1920 * scale);
  const style = Object.assign({ font: ws?.fonts?.heading || 'system-ui', lineH: 1.15, pad: 24 }, structuredClone(p.style));
  style.size = Math.round(style.size * scale);
  if (ws?.colors?.primary && style.bg === '#e8508b') style.bg = ws.colors.primary;
  return createItem('text', { t0, in: 0, out: 3, text: p.text, style, w, h: Math.max(60, hgt), x: Math.round((p.x ?? (1 - (p.w ?? 0.84)) / 2) * c.w), y: Math.round(p.y * c.h), rot: p.rot || 0, name: p.label });
}

export function shapeItem(project, shape = 'rect', t0 = 0, ws = null) {
  const c = project.canvas, s = Math.round(c.w * 0.4);
  return createItem('shape', { t0, in: 0, out: 3, w: shape === 'line' ? s * 1.5 : s, h: shape === 'line' ? 40 : s, x: Math.round(c.w / 2 - (shape === 'line' ? s * 0.75 : s / 2)), y: Math.round(c.h / 2 - (shape === 'line' ? 20 : s / 2)), style: { shape, fill: ws?.colors?.primary || '#e8508b', stroke: '#ffffff', strokeW: shape === 'line' ? 10 : 0, radius: shape === 'rect' ? 24 : 0 }, name: shape });
}

export function stickerItem(project, glyph, t0 = 0) {
  const c = project.canvas, s = Math.round(c.w * 0.22);
  return createItem('sticker', { t0, in: 0, out: 3, text: glyph, w: s, h: s, x: Math.round(c.w / 2 - s / 2), y: Math.round(c.h * 0.35), name: glyph });
}

export const STICKERS = ['✨', '💖', '🔥', '⭐', '🎉', '💯', '👉', '👇', '✅', '❤️', '😍', '🛍️', '💄', '🧴', '🌸', '🎁', '💸', '⚡', '🌍', '✈️', '🎓', '📄', '🇪🇺', '🏆', '📍', '📞', '⏰', '🆕', '💬', '👏'];

/** the end of the last item on a track (where "append" puts the next one) */
export const trackEnd = (track) => track.items.reduce((e, it) => Math.max(e, itemEnd(it)), 0);

/** can an item of this type live on a track of this kind? */
export function fits(itemType, trackKind) {
  if (itemType === 'audio') return trackKind === 'music' || trackKind === 'sfx';
  if (itemType === 'caption') return trackKind === 'caption';
  if (itemType === 'clip') return trackKind === 'video' || trackKind === 'overlay';
  return VISUAL_KINDS.has(trackKind) && trackKind !== 'caption';
}

/**
 * Re-lay a project for another aspect: full-frame clips are re-covered; everything else keeps its relative position
 * and scales by the smaller ratio. Returns a function suitable for ops.mutate.
 */
export function reflowTo(aspect) {
  return (p) => {
    const A = ASPECTS[aspect]; if (!A) return;
    const W1 = p.canvas.w, H1 = p.canvas.h, W2 = A.w, H2 = A.h;
    const s = Math.min(W2 / W1, H2 / H1);
    for (const tr of p.tracks) {
      for (const it of tr.items) {
        if (tr.kind === 'music' || tr.kind === 'sfx') continue;
        const full = it.type === 'clip' && it.w >= W1 * 0.98 && it.h >= H1 * 0.98;
        if (it.type === 'caption' && it.w >= W1 * 0.98 && it.h >= H1 * 0.98) {   // a caption item spans the frame: it stays full-frame
          Object.assign(it, { x: 0, y: 0, w: W2, h: H2 });                          // (shrunk, its handles sat over the captions)
        } else if (full) {
          const m = p.media.find((x) => x.id === it.media);
          Object.assign(it, fitBox({ w: W2, h: H2 }, m?.w || it.w, m?.h || it.h, 'cover'));
        } else {
          const cx = (it.x + it.w / 2) / W1, cy = (it.y + it.h / 2) / H1;
          it.w = Math.round(it.w * s); it.h = Math.round(it.h * s);
          it.x = Math.round(cx * W2 - it.w / 2); it.y = Math.round(cy * H2 - it.h / 2);
          if (it.style?.size) it.style.size = Math.round(it.style.size * s);
        }
        it.kf = {};
      }
    }
    runReflows(p, { w: W1, h: H1, aspect: p.canvas.aspect }, { w: W2, h: H2, aspect });   // e.g. captions keep a layout per shape
    Object.assign(p.canvas, { w: W2, h: H2, aspect });
  };
}
