// KBB Studio · themes · TEXT BEHIND THE HOST (the owner, 19 Nov 2026: "EUROPE big bold text just behind the host … the
// system which sense the video, and talking person and separate it and overlayed the text", and the same day: "we can
// put carousel by selecting or writing the countries, and we can make two lines if required. Jobs in (one line) and
// another line will be Countries name or carousel").
//
// One theme element (item.type 'theme.el', style.el 'tbehind'), drawn like every other element inside its box, with
// the PERSON of the clip under it laid back on top of the words: the frame under the element is copied (device px,
// only the rectangle the words cover), the words are drawn, then that copy is put back through the person's mask
// placed exactly like its clip (core/render.js drawItem with the mask as the source: crop, cover fit, Ken Burns, box,
// rotation). The real background stays; only the words go between it and the host.
//
//   style.code / lang     the place the default word names ('europe', 'au' …) and its language ('en' | 'ar' | 'ur' | 'hi')
//   style.text.word/top   the owner's words (themes/text.js tx(): inspector field + double-click on the video)
//   style.tb              look: font, track, fill {type, colors, dir, hard}, outline, outlineCol, shadow, animIn, animOut,
//                         inDur, outDur · the cut-out: key 'person' | 'subject', feather, grow, smooth · the lines:
//                         top {on, font, size, track, color, gap}, main (false hides it) · mode 'word' | 'carousel',
//                         car {items:[{id, en, ar, flag, text}], style, per, order, loop, flags, lang}
//
// The masks come from look/vision.js (analysed once, offline, on a fixed grid; behindFrames() asks for exactly the
// stretch of each clip an element covers), so the preview samples a cache and the export draws the very same pixels.
// While the mask is not there yet the words still draw (in front of everybody) and the preview shows a small status.
// Pure function of the project and t (a carousel's shuffle is seeded by the item id): preview = export.
// Leaf module: no editor code, nothing that imports elements.js.
import { drawItem, registerPrepare } from '../core/render.js';
import { findMedia, srcTime, isActive } from '../core/project.js';
import { maskAt, hasMask } from '../look/vision.js';
import { subjectReady } from '../look/subject.js';
import { personOf } from './cutout-trans.js';
import { tx, noteSlot, registerTextSlots } from './text.js';
import { placeName } from './country-words.js';
import { loadFlags, flagCanvas } from './capsules.js';
import { loadCaptionFonts, fontsReady } from '../captions/fonts.js';
import * as A from './art.js';

const { clamp, easeOut, easeIn, back } = A;

// ── the catalogue of choices (the inspector reads these; a stored value outside them falls back to the default) ──
export const TB_FONTS = [
  ['Anton', 'Anton', 400], ['Bebas Neue', 'Bebas Neue', 400], ['Montserrat', 'Montserrat Black', 900], ['Bangers', 'Bangers', 400],
  ['Playfair Display', 'Playfair Display', 900], ['Cairo', 'Cairo · عربي', 900], ['Noto Sans Arabic', 'Noto Sans Arabic · عربي', 900],
  ['Tajawal', 'Tajawal · عربي', 700], ['Amiri', 'Amiri · عربي', 700],
];
export const TB_IN = [['rise', 'Rise'], ['fade', 'Fade'], ['scale', 'Scale in'], ['type', 'Letters typing'], ['slide', 'Slide'], ['none', 'None']];
export const TB_OUT = [['fade', 'Fade'], ['sink', 'Sink'], ['scale', 'Scale out'], ['slide', 'Slide'], ['none', 'None']];
export const TB_CAR = [['up', 'Slide up'], ['side', 'Slide sideways'], ['flip', 'Flip'], ['fade', 'Fade'], ['type', 'Typewriter'], ['ticker', 'Ticker']];
export const TB_DEFAULTS = {
  font: 'Anton', track: 0.02, fill: { type: 'solid', colors: ['#ffffff'] }, outline: 0, outlineCol: '#000000', shadow: 0.35,
  animIn: 'rise', animOut: 'fade', inDur: 0.6, outDur: 0.4, key: 'person', feather: 0.15, grow: 0, smooth: 0.5,
  top: { on: false, font: 'Montserrat', size: 0.3, track: 0.18, color: null, gap: 0.06 }, main: true,
  mode: 'word', car: { items: [], style: 'up', per: 1.4, order: 'list', loop: true, flags: false, lang: 'en' },
};
const one = (v, list, d) => (list.some((x) => x[0] === v) ? v : d);
const num = (v, a, b, d) => (Number.isFinite(+v) && v !== null && v !== '' ? clamp(+v, a, b) : d);
const HEX = /^#[0-9a-f]{6}$/i;
/** the element's settings with every value checked (a damaged or old style draws with the defaults) */
export function tbOf(style) {
  const t = style?.tb || {}, D = TB_DEFAULTS, top = t.top || {}, car = t.car || {};
  const fill = t.fill && Array.isArray(t.fill.colors) && t.fill.colors.length ? { type: t.fill.type === 'grad' ? 'grad' : 'solid', colors: t.fill.colors.filter((c) => HEX.test(c)).slice(0, 6), dir: t.fill.dir === 'h' ? 'h' : 'v', hard: !!t.fill.hard } : D.fill;
  if (!fill.colors.length) fill.colors = ['#ffffff'];
  return {
    font: one(t.font, TB_FONTS, D.font), track: num(t.track, -0.05, 0.5, D.track), fill, outline: num(t.outline, 0, 0.15, 0), outlineCol: HEX.test(t.outlineCol || '') ? t.outlineCol : D.outlineCol,
    shadow: num(t.shadow, 0, 1, D.shadow), animIn: one(t.animIn, TB_IN, D.animIn), animOut: one(t.animOut, TB_OUT, D.animOut), inDur: num(t.inDur, 0.1, 2, D.inDur), outDur: num(t.outDur, 0.1, 2, D.outDur),
    key: style?.key === 'subject' || t.key === 'subject' ? 'subject' : 'person', feather: num(t.feather, 0, 1, D.feather), grow: num(t.grow, -1, 1, D.grow), smooth: num(t.smooth, 0, 1, D.smooth),
    top: { on: !!top.on, font: one(top.font, TB_FONTS, D.top.font), size: num(top.size, 0.12, 1, D.top.size), track: num(top.track, -0.05, 0.6, D.top.track), color: HEX.test(top.color || '') ? top.color : null, gap: num(top.gap, -0.2, 0.6, D.top.gap) },
    main: t.main !== false, mode: t.mode === 'carousel' ? 'carousel' : 'word',
    car: { items: Array.isArray(car.items) ? car.items.filter((x) => x && (x.en || x.ar || x.text)).slice(0, 80) : [], style: one(car.style, TB_CAR, D.car.style), per: num(car.per, 0.4, 8, D.car.per), order: car.order === 'shuffle' ? 'shuffle' : 'list', loop: car.loop !== false, flags: !!car.flags, lang: car.lang === 'ar' ? 'ar' : 'en' },
  };
}
const isAr = (s) => /[\u0600-\u06FF]/.test(s || '');
/** the default main word: the place's name, in capitals for Latin scripts */
export function defaultWord(style) {
  const n = placeName(style?.code || 'europe', style?.lang || 'en');
  return /^(en)$/.test(style?.lang || 'en') ? n.toUpperCase() : n;
}
/** a carousel entry's words: its own override, else its name in the carousel's language */
export const carWord = (it, lang) => String(it.text || (lang === 'ar' ? it.ar || it.en : it.en || it.ar) || '').trim();
const hashOf = (s) => { let h = 2166136261; for (let i = 0; i < String(s).length; i++) h = Math.imul(h ^ String(s).charCodeAt(i), 16777619); return h >>> 0; };
/** the carousel's order (shuffle seeded by the item id: the same every frame, preview and export) */
export function carOrder(itemId, car) {
  const list = car.items.slice();
  if (car.order === 'shuffle' && list.length > 2) { let s = hashOf(itemId) || 1; for (let i = list.length - 1; i > 0; i--) { s = (Math.imul(s, 1664525) + 1013904223) >>> 0; const j = s % (i + 1); [list[i], list[j]] = [list[j], list[i]]; } }
  return list;
}
/** which carousel entry shows at local time tl: { i (index in order), u (0..1 into the switch), prev } */
export function carAt(n, car, tl) {
  if (!n) return null;
  const k = Math.floor(Math.max(0, tl) / car.per), i = car.loop ? k % n : Math.min(k, n - 1);
  const into = Math.max(0, tl) - k * car.per, sw = Math.min(0.45, car.per * 0.35);
  const switching = k > 0 && (car.loop || k < n) && into < sw;
  return { i, prev: switching ? (car.loop ? (k - 1) % n : k - 1) : -1, u: switching ? into / sw : 1, k };
}

// ── fonts and measuring ──
const weightOf = (font) => TB_FONTS.find((f) => f[0] === font)?.[2] || 900;
const famOf = (font) => `"${font}", "Cairo", "Noto Sans Arabic", "Anton", system-ui, sans-serif`;
export const fontStr = (font, px) => `${weightOf(font)} ${Math.round(px * 10) / 10}px ${famOf(font)}`;
let MC = null; const MEAS = new Map();
/** width (at 100 px, with spacing), ink ascent and descent of a string in a font: measured once per font state */
function measure(text, font, track) {
  const k = text + '|' + font + '|' + track + '|' + (fontsReady() ? 1 : 0);
  let v = MEAS.get(k);
  if (!v) {
    MC ||= (typeof document !== 'undefined' ? document.createElement('canvas') : new OffscreenCanvas(8, 8)).getContext('2d');
    MC.font = fontStr(font, 100); if ('letterSpacing' in MC) MC.letterSpacing = (track * 100).toFixed(1) + 'px';
    const m = MC.measureText(text);
    const asc = m.actualBoundingBoxAscent || 72, desc = m.actualBoundingBoxDescent || 0;
    v = { w: Math.max(1, m.width - (track > 0 ? track * 100 : 0)), asc: Math.max(20, asc), desc: Math.max(0, desc) };
    if (MEAS.size > 2000) MEAS.clear(); MEAS.set(k, v);
  }
  return v;
}
/** the size at which `text` fits a w x h line (ink height = h, width ≤ w) */
function fitSize(text, font, track, w, h) {
  const m = measure(text, font, track), ink = (m.asc + m.desc) / 100;
  return Math.max(4, Math.min(h / Math.max(0.35, ink), w * 0.98 / (m.w / 100)));
}

// ── the person under the element ──
/** the clip the element stands on at t: the topmost visible clip on a track below the element's own */
export function hostClip(project, item, t) {
  const tracks = project.tracks || [], ti = tracks.findIndex((tr) => tr.items.some((i) => i.id === item.id));
  for (let j = (ti < 0 ? tracks.length : ti) - 1; j >= 0; j--) {
    const tr = tracks[j]; if (tr.hidden || (tr.kind !== 'video' && tr.kind !== 'overlay')) continue;
    for (let k = tr.items.length - 1; k >= 0; k--) { const it = tr.items[k]; if (it.type === 'clip' && (it.media || /^hp-clip-/.test(it.id || '')) && isActive(it, t)) return { clip: it, track: tr }; }
  }
  return null;
}
// mask canvases, reused (one per mask key; the evicted canvas is recycled, so playback makes no garbage)
const MCV = new Map(); let SUBJ = false;
subjectReady().then((r) => { SUBJ = !!r; }).catch(() => {});
export const noteSubjectReady = () => { SUBJ = true; };
/**
 * The mask as a white-on-transparent canvas in the clip's own shape, its edge grown / shrunk (gm) and feathered (bm)
 * in MASK px: the edge work runs once per mask frame on a 256-px picture, not on every device-size frame.
 */
function maskCanvas(m, aspect, gm = 0, bm = 0) {
  const key = m.key + '|' + aspect.toFixed(4) + '|' + gm.toFixed(1) + '|' + bm.toFixed(1);
  let cv = MCV.get(key); if (cv) return cv;
  if (MCV.size >= 8) { const k0 = MCV.keys().next().value; cv = MCV.get(k0); MCV.delete(k0); } else cv = document.createElement('canvas');
  // the mask stretched to the clip's own shape (the subject model's mask is square), long side its own size
  const L = Math.max(m.w, m.h), w = aspect >= 1 ? L : Math.round(L * aspect), h = aspect >= 1 ? Math.round(L / aspect) : L;
  const raw = rawCanvas('raw', m.w, m.h), rg = raw.getContext('2d'), id = rg.createImageData(m.w, m.h), d = id.data;
  for (let i = 0, n = m.w * m.h; i < n; i++) { const o = i * 4; d[o] = d[o + 1] = d[o + 2] = 255; d[o + 3] = m.data[i]; }
  rg.putImageData(id, 0, 0);
  let src = raw;
  if (Math.abs(gm) >= 0.25) {
    // grow: the union of copies shifted round a circle; shrink: their intersection
    const gr = rawCanvas('grow', m.w, m.h), gg = gr.getContext('2d'), r = Math.abs(gm);
    gg.globalCompositeOperation = 'source-over'; gg.clearRect(0, 0, m.w, m.h); gg.drawImage(raw, 0, 0);
    gg.globalCompositeOperation = gm > 0 ? 'source-over' : 'destination-in';
    for (let i = 0; i < 8; i++) { const a = (i / 8) * Math.PI * 2; gg.drawImage(raw, Math.cos(a) * r, Math.sin(a) * r); }
    gg.globalCompositeOperation = 'source-over'; src = gr;
  }
  cv.width = w; cv.height = h; const g = cv.getContext('2d'); g.clearRect(0, 0, w, h); g.imageSmoothingQuality = 'high';
  if (bm >= 0.2) g.filter = `blur(${bm.toFixed(2)}px)`;
  g.drawImage(src, 0, 0, w, h); g.filter = 'none';
  MCV.set(key, cv);
  return cv;
}
const RAWS = new Map();
const rawCanvas = (name, w, h) => { let c = RAWS.get(name); if (!c) { c = document.createElement('canvas'); RAWS.set(name, c); } if (c.width !== w || c.height !== h) { c.width = w; c.height = h; } return c; };
/**
 * The person (or the main subject) of the host clip at t: { cv, state } — state 'ok' | 'none' (nobody in the frame) |
 * 'pending' (not analysed yet) | 'model' (the main-subject model is not installed) | 'noclip' (nothing under it)
 */
export function personAt(project, item, t, tb) {
  const H = hostClip(project, item, t);
  if (!H) return { state: 'noclip' };
  const clip = H.clip;
  if (!clip.media) { const r = personOf(project, clip, t); return r.state === 'ok' ? { cv: r.m.cv, state: 'ok', host: H } : { state: r.state, host: H }; }
  if (tb.key === 'subject' && !SUBJ && !hasMask(clip.media, 'subject')) return { state: 'model', host: H };
  const m = maskAt(clip.media, srcTime(clip, t), tb.key, tb.smooth);
  if (!m) return { state: 'pending', host: H };
  let s = 0; for (let i = 0; i < m.data.length; i += 7) s += m.data[i] > 127 ? 1 : 0;
  if (s / (m.data.length / 7) < 0.002) return { state: 'none', host: H };
  const md = findMedia(project, clip.media), aspect = (md?.w && md?.h) ? md.w / md.h : m.w / m.h;
  // the edge controls are shares of the frame: in mask px they scale with how big the clip is on the frame
  const L = Math.max(m.w, m.h), r = Math.min(project.canvas.w, project.canvas.h) / Math.max(1, clip.w || project.canvas.w, clip.h || project.canvas.h);
  return { cv: maskCanvas(m, aspect, tb.grow * L * 0.012 * r, tb.feather * L * 0.02 * r), state: 'ok', host: H };
}

// ── device-px scratch canvases, reused ──
const POOL = new Map();
function buf(name, w, h) {
  let c = POOL.get(name); if (!c) { c = document.createElement('canvas'); POOL.set(name, c); }
  if (c.width !== w || c.height !== h) { c.width = w; c.height = h; }
  const g = c.getContext('2d'); g.setTransform(1, 0, 0, 1, 0, 0); g.globalAlpha = 1; g.globalCompositeOperation = 'source-over'; g.filter = 'none'; g.shadowBlur = 0; g.shadowColor = 'rgba(0,0,0,0)'; g.clearRect(0, 0, w, h);
  return [c, g];
}
/** the host's mask placed like its clip into the device rectangle R */
function placedMask(P, project, t, env, R) {
  const [mc, mg] = buf('tbMask', R.w, R.h), s = env.scale || 1;
  mg.setTransform(s, 0, 0, s, -R.x, -R.y);
  drawItem(mg, { ...P.host.clip, fx: [], look: null, opacity: 1, fadeIn: 0, fadeOut: 0 }, t, project, Object.assign({}, env, { track: P.host.track, preview: false, source: () => P.cv }));
  return mc;
}

// ── drawing ──
/** the fill of a line: a colour, or a gradient across its ink box (soft, or hard stripes like a flag) */
function fillOf(c, f, x0, y0, x1, y1) {
  if (f.type !== 'grad' || f.colors.length < 2) return f.colors[0];
  const g = f.dir === 'h' ? c.createLinearGradient(x0, 0, x1, 0) : c.createLinearGradient(0, y0, 0, y1), n = f.colors.length;
  f.colors.forEach((col, i) => {
    if (f.hard) { g.addColorStop(i / n, col); g.addColorStop(Math.min(1, (i + 1) / n - 1e-4), col); } else g.addColorStop(i / (n - 1), col);
  });
  return g;
}
/** one string at (x, baseline y), left aligned at x (ink box known), with outline, shadow, spacing */
function paint(c, text, x, y, size, font, track, fill, tb, rtl) {
  c.font = fontStr(font, size); c.textBaseline = 'alphabetic'; c.textAlign = 'left';
  c.direction = rtl ? 'rtl' : 'ltr';
  if ('letterSpacing' in c) c.letterSpacing = rtl ? '0px' : (track * size).toFixed(2) + 'px';
  const ax = rtl ? x + measure(text, font, rtl ? 0 : track).w * size / 100 : x;
  if (rtl) c.textAlign = 'right';
  if (tb.shadow > 0) { c.shadowColor = `rgba(0,0,0,${(0.25 + 0.5 * tb.shadow).toFixed(2)})`; c.shadowBlur = size * 0.22 * tb.shadow; c.shadowOffsetY = size * 0.05 * tb.shadow; }
  if (tb.outline > 0) { c.lineJoin = 'round'; c.strokeStyle = tb.outlineCol; c.lineWidth = Math.max(1, size * tb.outline); c.strokeText(text, ax, y); c.shadowColor = 'rgba(0,0,0,0)'; }
  c.fillStyle = fill; c.fillText(text, ax, y);
  c.shadowColor = 'rgba(0,0,0,0)'; c.shadowBlur = 0; c.shadowOffsetY = 0;
  if ('letterSpacing' in c) c.letterSpacing = '0px';
}
/** graphemes (typing shows whole letters, never half a surrogate pair) */
const SEG = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter() : null;
const letters = (s) => (SEG ? [...SEG.segment(s)].map((x) => x.segment) : [...s]);

/**
 * Lay a line out: [{text, x, y (baseline), size, font, track, rtl, ink:[x0,y0,x1,y1], flag?}] in item-local px, centred
 * in the line's rectangle (lx, ly, lw, lh). `size` forces a font size (the carousel keeps one).
 */
function layLine(text, font, track, lx, ly, lw, lh, size = null, flagCode = null) {
  const rtl = isAr(text), tr = rtl ? 0 : track, m = measure(text, font, tr);
  let sz = size || fitSize(text, font, tr, lw, lh);
  const fl = flagCode && flagCanvas(flagCode) ? 1 : 0;
  let tw = m.w * sz / 100;
  const fw = fl ? (m.asc * sz / 100) * 0.62 * 4 / 3 : 0, gap = fl ? sz * 0.12 : 0;
  if (tw + fw + gap > lw * 0.98) { const k = lw * 0.98 / (tw + fw + gap); sz *= k; tw *= k; }
  const fw2 = fl ? (m.asc * sz / 100) * 0.62 * 4 / 3 : 0, gap2 = fl ? sz * 0.12 : 0;
  const inkH = (m.asc + m.desc) * sz / 100, base = ly + (lh - inkH) / 2 + m.asc * sz / 100;
  const total = tw + fw2 + gap2, x = lx + (lw - total) / 2 + fw2 + gap2;
  return { text, x, y: base, size: sz, font, track: tr, rtl, w: tw, ink: [x - fw2 - gap2, base - m.asc * sz / 100, x + tw, base + m.desc * sz / 100], flag: fl ? { code: flagCode, x: x - fw2 - gap2, y: base - m.asc * sz / 100 * 0.81, w: fw2, h: fw2 * 0.75 } : null };
}
function drawLaid(c, L, fill, tb, reveal = 1) {
  if (L.flag) { const f = flagCanvas(L.flag.code); if (f) { c.save(); c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = L.size * 0.08; c.drawImage(f, L.flag.x, L.flag.y, L.flag.w, L.flag.h); c.restore(); } }
  if (reveal >= 1) { paint(c, L.text, L.x, L.y, L.size, L.font, L.track, fill, tb, L.rtl); return; }
  if (reveal <= 0) return;
  if (L.rtl) {
    // Arabic joins its letters: typing reveals it from the right, whole
    c.save(); c.beginPath(); c.rect(L.x + L.w * (1 - reveal) - L.size, L.ink[1] - L.size, L.w * reveal + L.size * 2, L.ink[3] - L.ink[1] + L.size * 2); c.clip();
    paint(c, L.text, L.x, L.y, L.size, L.font, L.track, fill, tb, true); c.restore(); return;
  }
  const ls = letters(L.text), n = Math.ceil(ls.length * reveal);
  paint(c, ls.slice(0, n).join(''), L.x, L.y, L.size, L.font, L.track, fill, tb, false);
}

/** in / out animation of the whole text layer: { a (alpha), dx, dy, s (scale), reveal (typing 0..1) } */
function animAt(tb, tl, d, w, h) {
  const i = clamp(tl / tb.inDur), o = clamp((d - tl) / tb.outDur), r = { a: 1, dx: 0, dy: 0, s: 1, reveal: 1 };
  const ei = easeOut(i);
  switch (tb.animIn) {
    case 'fade': r.a *= ei; break;
    case 'rise': r.a *= clamp(i * 1.6); r.dy += (1 - ei) * h * 0.35; break;
    case 'scale': r.a *= clamp(i * 2); r.s *= 0.55 + 0.45 * back(i); break;
    case 'slide': r.a *= clamp(i * 1.6); r.dx -= (1 - ei) * w * 0.45; break;
    case 'type': r.reveal = clamp(tl / Math.max(0.3, tb.inDur * 1.4)); break;
    default: break;
  }
  const eo = easeIn(1 - o);
  switch (tb.animOut) {
    case 'fade': r.a *= o; break;
    case 'sink': r.a *= clamp(o * 1.6); r.dy += eo * h * 0.3; break;
    case 'scale': r.a *= o; r.s *= 1 + 0.25 * eo; break;
    case 'slide': r.a *= clamp(o * 1.6); r.dx += eo * w * 0.45; break;
    default: break;
  }
  return r;
}

/** the element's lines in its box: { top: rect|null, main: rect|null } (item-local) */
function linesOf(tb, w, h) {
  if (!tb.top.on) return { top: null, main: tb.main ? [0, 0, w, h] : null };
  if (!tb.main) return { top: [0, 0, w, h], main: null };
  const k = tb.top.size, g = tb.top.gap, mh = h / (1 + k + Math.max(0, g)), th = mh * k, gy = mh * g;
  return { top: [0, 0, w, th], main: [0, th + gy, w, Math.max(4, h - th - Math.max(0, gy))] };
}

/** the carousel's frame at tl: the lines to draw (each with its own offset / alpha / reveal / flip) */
function carouselLines(S, tb, rect, itemId) {
  const car = tb.car, list = carOrder(itemId, car), n = list.length, [lx, ly, lw, lh] = rect;
  if (!n) return [];
  // a name from the list in capitals (Latin), the owner's own override as typed
  const word = (k) => { const s = carWord(list[k], car.lang); return list[k].text || isAr(s) ? s : s.toUpperCase(); };
  // one size for every word: the line's height, shrunk only for a word too wide for it
  const flagOf = (k) => (car.flags ? list[k].flag || null : null);
  if (car.style === 'ticker') {
    const sz = Math.min(...list.map((x, k) => fitSize(word(k) || 'A', tb.font, tb.track, 1e9, lh)));
    const parts = list.map((x, k) => layLine(word(k), tb.font, tb.track, 0, ly, 1e9, lh, sz, flagOf(k)));
    const sp = sz * 0.9, widths = parts.map((L) => L.ink[2] - L.ink[0]), total = widths.reduce((a, b) => a + b + sp, 0);
    const speed = total / Math.max(0.4, car.per * n), off = (S.tl * speed) % total, out = [];
    for (let x0 = -off, k = 0; x0 < lw + total && k < n * 4; k++) {
      const L = parts[k % n], wd = widths[k % n];
      if (x0 + wd > -sp && x0 < lw + sp) out.push({ L: { ...L, x: L.x - L.ink[0] + x0 + lx, ink: [x0 + lx, L.ink[1], x0 + lx + wd, L.ink[3]], flag: L.flag ? { ...L.flag, x: L.flag.x - L.ink[0] + x0 + lx } : null }, a: 1, dx: 0, dy: 0, sy: 1, reveal: 1, k: k % n });
      x0 += wd + sp;
    }
    return out;
  }
  const at = carAt(n, car, S.tl);
  const lay = (k) => layLine(word(k), tb.font, tb.track, lx, ly, lw, lh, null, flagOf(k));
  const cur = lay(at.i), u = easeOut(at.u), out = [];
  if (at.prev < 0) { out.push({ L: cur, a: 1, dx: 0, dy: 0, sy: 1, reveal: 1, k: at.i }); return out; }
  const prev = lay(at.prev);
  switch (car.style) {
    case 'up': out.push({ L: prev, a: 1 - u, dx: 0, dy: -u * lh * 0.9, sy: 1, reveal: 1, k: at.prev }, { L: cur, a: u, dx: 0, dy: (1 - u) * lh * 0.9, sy: 1, reveal: 1, k: at.i }); break;
    case 'side': out.push({ L: prev, a: 1 - u, dx: -u * lw * 0.7, dy: 0, sy: 1, reveal: 1, k: at.prev }, { L: cur, a: u, dx: (1 - u) * lw * 0.7, dy: 0, sy: 1, reveal: 1, k: at.i }); break;
    case 'flip': if (at.u < 0.5) out.push({ L: prev, a: 1, dx: 0, dy: 0, sy: Math.cos(at.u * Math.PI), reveal: 1, k: at.prev }); else out.push({ L: cur, a: 1, dx: 0, dy: 0, sy: -Math.cos(at.u * Math.PI), reveal: 1, k: at.i }); break;
    case 'type': out.push({ L: cur, a: 1, dx: 0, dy: 0, sy: 1, reveal: at.u, k: at.i }); break;
    default: out.push({ L: prev, a: 1 - u, dx: 0, dy: 0, sy: 1, reveal: 1, k: at.prev }, { L: cur, a: u, dx: 0, dy: 0, sy: 1, reveal: 1, k: at.i });
  }
  return out;
}

/** the element: draw function for themes/elements.js (S carries the item, the project and the render env) */
export function drawTextBehind(c, K, S) {
  const style = S.style || {}, tb = tbOf(style), w = S.w, h = S.h, item = S.item, project = S.project, env = S.env || {};
  const rects = linesOf(tb, w, h), draws = [];
  // the top line ("JOBS IN") and the main line (one word, or the carousel)
  if (rects.top) {
    const t = tx(S, 'top', 'JOBS IN');
    if (t) { const L = layLine(t, tb.top.font, tb.top.track, ...rects.top); draws.push({ L, fill: tb.top.color ? { type: 'solid', colors: [tb.top.color] } : tb.fill, key: 'top', a: 1, dx: 0, dy: 0, sy: 1, reveal: 1 }); }
  }
  if (rects.main) {
    if (tb.mode === 'carousel' && tb.car.items.length) {
      const list = carOrder(item?.id || '', tb.car);
      for (const d of carouselLines(S, tb, rects.main, item?.id || '')) draws.push({ ...d, fill: tb.fill, key: 'tbcar:' + list[d.k].id, clip: rects.main });
    } else {
      const t = tx(S, 'word', defaultWord(style));
      if (t) draws.push({ L: layLine(t, tb.font, tb.track, ...rects.main), fill: tb.fill, key: 'word', a: 1, dx: 0, dy: 0, sy: 1, reveal: 1 });
    }
  }
  if (!draws.length) return;
  const an = animAt(tb, S.tl, S.d, w, h);
  if (an.a <= 0.002) return;
  // the device rectangle the words cover (with their outline, shadow and motion), clamped to the canvas
  const real = !!(env.source && project && item && c.canvas && typeof document !== 'undefined');
  let P = null, R = null, snap = null;
  if (real) {
    P = personAt(project, item, S.t, tb);
    if (P.state === 'ok') {
      let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
      for (const d of draws) { if (d.a <= 0.002) continue; const p = d.L.size * (0.3 + tb.outline + tb.shadow * 0.3); x0 = Math.min(x0, d.L.ink[0] + d.dx - p); y0 = Math.min(y0, d.L.ink[1] + d.dy - p); x1 = Math.max(x1, d.L.ink[2] + d.dx + p); y1 = Math.max(y1, d.L.ink[3] + d.dy + p); }
      const cx = w / 2, cy = h / 2, M = c.getTransform(), CW = c.canvas.width, CH = c.canvas.height;
      let X0 = Infinity, Y0 = Infinity, X1 = -Infinity, Y1 = -Infinity;
      for (const [px0, py0] of [[x0, y0], [x1, y0], [x0, y1], [x1, y1]]) {
        const px = cx + (px0 - cx) * an.s + an.dx, py = cy + (py0 - cy) * an.s + an.dy, X = M.a * px + M.c * py + M.e, Y = M.b * px + M.d * py + M.f;
        X0 = Math.min(X0, X); Y0 = Math.min(Y0, Y); X1 = Math.max(X1, X); Y1 = Math.max(Y1, Y);
      }
      const rx = Math.max(0, Math.floor(X0)), ry = Math.max(0, Math.floor(Y0)), rx1 = Math.min(CW, Math.ceil(X1)), ry1 = Math.min(CH, Math.ceil(Y1));
      if (rx1 - rx >= 2 && ry1 - ry >= 2) {
        R = { x: rx, y: ry, w: rx1 - rx, h: ry1 - ry, CW, CH };
        const [sc, sg] = buf('tbSnap', R.w, R.h); sg.drawImage(c.canvas, R.x, R.y, R.w, R.h, 0, 0, R.w, R.h); snap = sc;
      }
    }
  }
  // the words
  c.save();
  c.globalAlpha *= an.a;
  if (an.dx || an.dy || an.s !== 1) { c.translate(w / 2 + an.dx, h / 2 + an.dy); c.scale(an.s, an.s); c.translate(-w / 2, -h / 2); }
  for (const d of draws) {
    if (d.a <= 0.002 || Math.abs(d.sy) < 0.01) continue;
    c.save();
    if (d.clip) { c.beginPath(); c.rect(d.clip[0] - w, d.clip[1], d.clip[2] + 2 * w, d.clip[3]); c.clip(); }
    c.globalAlpha *= d.a;
    const L = d.L, my = (L.ink[1] + L.ink[3]) / 2;
    c.translate(d.dx, d.dy);
    if (d.sy !== 1) { c.translate(0, my); c.scale(1, d.sy); c.translate(0, -my); }
    drawLaid(c, L, fillOf(c, d.fill, L.ink[0], L.ink[1], L.ink[2], L.ink[3]), tb, Math.min(d.reveal, an.reveal));
    c.restore();
    // where it landed (the preview only): double-click edits it on the video
    noteSlot({ key: d.key, text: L.text, x: L.ink[0] + an.dx, y: L.ink[1] + an.dy, w: L.ink[2] - L.ink[0], h: L.ink[3] - L.ink[1], size: L.size, ar: L.rtl, align: 'center' });
  }
  c.restore();
  // the host laid back on top: the frame as it was under the words, through the person's mask
  if (snap && R) {
    // the placed mask takes the copied frame's colours where it is opaque (one buffer, two draws)
    const oc = placedMask(P, project, S.t, env, R), og = oc.getContext('2d');
    og.setTransform(1, 0, 0, 1, 0, 0); og.globalAlpha = 1; og.globalCompositeOperation = 'source-in'; og.drawImage(snap, 0, 0); og.globalCompositeOperation = 'source-over';
    c.save(); c.setTransform(1, 0, 0, 1, 0, 0); c.globalAlpha = 1; c.globalCompositeOperation = 'source-over'; c.filter = 'none'; c.shadowColor = 'rgba(0,0,0,0)';
    c.drawImage(oc, R.x, R.y);
    c.restore();
  }
  // the preview says why the host is not in front yet (never drawn in an export)
  if (env.preview && P && (P.state === 'pending' || P.state === 'model')) {
    const msg = P.state === 'model' ? 'Main-subject model not installed (Inspector → Text behind the host)' : 'Finding the person… (the words go behind you when it is done)', fs = Math.max(12, Math.min(w, h) * 0.07);
    c.save(); c.font = `600 ${fs}px system-ui, sans-serif`; c.textBaseline = 'middle'; c.textAlign = 'center';
    const tw = Math.min(w, c.measureText(msg).width + fs * 1.2);
    c.globalAlpha = 0.85; c.fillStyle = 'rgba(14,16,28,0.8)'; c.fillRect((w - tw) / 2, h - fs * 1.6, tw, fs * 1.4);
    c.globalAlpha = 1; c.fillStyle = '#ffffff'; c.fillText(msg, w / 2, h - fs * 0.9, w * 0.96); c.restore();
  }
}

/** the state of an element now (the inspector's line): 'ok' | 'none' | 'pending' | 'model' | 'noclip' */
export function tbStatus(project, item, t) {
  const tc = isActive(item, t) ? t : item.t0 + Math.min(0.3, (item.out - item.in) / 2);
  return personAt(project, item, tc, tbOf(item.style)).state;
}

/** the starting style of a new element (merged by el2.js into elements.js elementStyle) */
export function tbStyle({ code = 'europe', lang = 'en', text } = {}) {
  return { code, lang, key: 'person', tb: JSON.parse(JSON.stringify(TB_DEFAULTS)), ...(text ? { text } : {}) };
}
/** where it starts: a wide band in the upper half (the word's lower part behind the head) */
export function tbBox(W, H) {
  const land = W > H * 1.05, w = Math.round(land ? W * 0.9 : W * 0.96), h = Math.round(land ? H * 0.34 : Math.min(W * 0.4, H * 0.3));
  return { x: Math.round((W - w) / 2), y: Math.round(land ? H * 0.08 : H * 0.11), w, h };
}

// ── before an export (and when a project opens): the fonts and the carousel's flags ──
registerPrepare('textbehind', async (project) => {
  const items = (project.tracks || []).flatMap((t) => t.items).filter((i) => i.type === 'theme.el' && i.style?.el === 'tbehind');
  if (!items.length) return;
  await loadCaptionFonts();
  const flags = items.flatMap((i) => (i.style.tb?.car?.flags ? (i.style.tb.car.items || []).map((x) => x.flag).filter(Boolean) : []));
  if (flags.length) await loadFlags(flags);
});
export const loadTbAssets = (style) => Promise.all([loadCaptionFonts(), loadFlags((style?.tb?.car?.items || []).map((x) => x.flag).filter(Boolean))]);

// a carousel word is a text slot: double-click it on the video to type over it (its override)
registerTextSlots({
  prefix: 'tbcar:', label: 'Carousel word',
  read: (item, key) => { const car = item.style?.tb?.car, it = (car?.items || []).find((x) => x.id === key.slice(6)); return it ? carWord(it, car.lang) : null; },
  write: (style, key, v) => { const it = (style.tb?.car?.items || []).find((x) => x.id === key.slice(6)); if (it) { const s = String(v).trim(); if (s) it.text = s; else delete it.text; } },
  focus: (key) => `[data-tb-car-word="${key.slice(6).replace(/["\\]/g, '')}"]`,
});
