// KBB Studio · themes · CAPSULES CAROUSEL: two rows of rounded "capsule" pills that scroll for ever -- jobs on top (a line
// icon + a label), countries below (a flag + a name) -- the strip on the owner's website (Planet Careers, 18 Nov 2026).
//
// It is a theme element (item.type 'theme.el', style.el 'capsules', style.caps = the model below), so it is placed,
// dragged, trimmed, run for the whole video, undone and saved like every other element. The drawing is a pure function of
// the model and the time:
//
//   x(t) = speed × t (or an eased step / a step on each music beat), wrapped modulo the row's own length -> no accumulated
//   drift, no jump at the wrap, and the preview frame at t is the export frame at t.
//
// Each capsule is pre-rendered ONCE into a bitmap (keyed by everything that changes its pixels) and only drawn per frame.
//
// caps = { v:1, preset, lang:'en'|'ar', brand:{primary,accent}|null, scale, motion:'smooth'|'ease'|'beat', fade,
//          size:{h, font, icon, gap, radius, rowGap}, tabs:{jobs, countries, jobsAr, countriesAr},
//          rows:[{ kind:'jobs'|'countries', on, dir:'left'|'right', speed, col:{fill,text,icon,border,shadow}, items:[cap] }] }
// cap  = { id, en, ar, icon? | flag? | cont?, col?:{fill,text,icon,border} }
import { registerPrepare } from '../core/render.js';
import { FEATURED_JOBS, DEFAULT_COUNTRIES, LINE_ICONS, jobCap, countryCap, continentCap } from './capsules-data.js';

export const SITE = { primary: '#0B2A66', accent: '#D7263D' };   // the screenshot's navy and red
export const COLOR_KEYS = ['fill', 'text', 'icon', 'border', 'shadow'];
export const CAP_COLOR_KEYS = ['fill', 'text', 'icon', 'border'];

export function defaultCaps() {
  return {
    v: 1, preset: 'site', lang: 'en', brand: null, scale: 1, motion: 'smooth', fade: 0.08,
    size: { h: 76, font: 29, icon: 30, gap: 16, radius: 1, rowGap: 20 },
    tabs: { jobs: 'NOW HIRING', countries: 'WE PLACE IN', jobsAr: 'نوظّف الآن', countriesAr: 'نوفّر فرصاً في' },
    rows: [
      { kind: 'jobs', on: true, dir: 'left', speed: 60, col: {}, items: FEATURED_JOBS.map(([en, ar, ic]) => jobCap(en, ar, ic)) },
      { kind: 'countries', on: true, dir: 'right', speed: 60, col: {}, items: DEFAULT_COUNTRIES.map((c) => (c[0] === '@' ? continentCap(c.slice(1)) : countryCap(c))) },
    ],
  };
}

// ── the presets: each one a look, never the content (capsules, sizes, position stay the owner's) ──
const W = '#FFFFFF';
const mixHex = (a, b, k) => { const p = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16)); const A = p(hex(a)), B = p(hex(b)); return '#' + A.map((v, i) => Math.round(v + (B[i] - v) * k).toString(16).padStart(2, '0')).join(''); };
export function hex(c) { c = String(c || ''); if (/^#[0-9a-f]{6}$/i.test(c)) return c; if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + [...c.slice(1)].map((x) => x + x).join(''); return '#000000'; }
const PASTELS = ['#FFD6E0', '#D7F2E3', '#DCE8FF', '#FFF1C9', '#EADCFF', '#FFE0CC', '#D3F4F7'];

/**
 * look(kind, P) -> the row's base colours and shape flags. P = { primary, accent } (the screenshot, or the company).
 * flags: glass (frosted, the video blurred behind), glow (neon), grad (a gradient fill), flagFill (the flag fills the pill),
 * tilt (sticker angles), merge (one big row), dots (no pill, dot separators), tab (a band + label tab), arc, iso, badge,
 * pastel (a soft colour per capsule), serif, upper.
 */
export const PRESETS = [
  { id: 'site', name: 'Website strip', note: 'The screenshot: white jobs, navy countries', look: (k, P) => (k === 'jobs' ? { fill: W, text: P.primary, icon: P.accent, border: mixHex(P.primary, W, 0.85), shadow: 'rgba(11,42,102,0.18)', blur: 10, oy: 3, bw: 1 } : { fill: P.primary, text: W, icon: W, border: P.primary, shadow: 'rgba(11,42,102,0.28)', blur: 10, oy: 3, bw: 0 }) },
  { id: 'glass', name: 'Glass on blur', note: 'Frosted pills, the video blurred behind', look: (k, P) => ({ fill: k === 'jobs' ? 'rgba(255,255,255,0.22)' : 'rgba(11,42,102,0.30)', text: W, icon: k === 'jobs' ? '#FFD1D7' : W, border: 'rgba(255,255,255,0.55)', shadow: 'rgba(0,0,0,0.25)', blur: 18, oy: 6, bw: 1.5, glass: true }) },
  { id: 'neon', name: 'Neon outline', note: 'Dark pills, glowing edges', look: (k, P) => { const n = k === 'jobs' ? '#FF2E88' : '#22E4FF'; return { fill: 'rgba(8,8,24,0.55)', text: W, icon: n, border: n, shadow: n, blur: 22, oy: 0, bw: 2.5, glow: true }; } },
  { id: 'gradient', name: 'Brand gradient', note: 'Solid gradient pills, white type', look: (k, P) => ({ fill: k === 'jobs' ? P.primary : P.accent, fill2: k === 'jobs' ? P.accent : P.primary, text: W, icon: W, border: 'rgba(255,255,255,0)', shadow: 'rgba(0,0,0,0.3)', blur: 14, oy: 5, bw: 0, grad: true }) },
  { id: 'flagfill', name: 'Flag-filled', note: 'The flag is the whole pill', look: (k, P) => (k === 'jobs' ? { fill: P.accent, text: W, icon: W, border: W, shadow: 'rgba(0,0,0,0.3)', blur: 12, oy: 4, bw: 2 } : { fill: P.primary, text: W, icon: W, border: W, shadow: 'rgba(0,0,0,0.35)', blur: 12, oy: 4, bw: 2.5, flagFill: true }) },
  { id: 'sticker', name: 'Stickers', note: 'Tilted stickers, thick white edge', look: (k, P) => ({ fill: k === 'jobs' ? '#FFD23F' : P.accent, text: k === 'jobs' ? P.primary : W, icon: k === 'jobs' ? P.accent : W, border: W, shadow: 'rgba(0,0,0,0.35)', blur: 6, oy: 5, bw: 5, tilt: true, upper: true }) },
  { id: 'bold', name: 'Big bold row', note: 'One large row, jobs and countries mixed', look: (k, P) => ({ fill: k === 'jobs' ? P.accent : P.primary, text: W, icon: W, border: 'rgba(255,255,255,0)', shadow: 'rgba(0,0,0,0.35)', blur: 16, oy: 6, bw: 0, merge: true, upper: true }) },
  { id: 'minimal', name: 'Minimal text', note: 'No pills, dot separators', look: (k, P) => ({ fill: 'rgba(0,0,0,0)', text: W, icon: k === 'jobs' ? '#FF5A6E' : W, border: 'rgba(0,0,0,0)', shadow: 'rgba(0,0,0,0.55)', blur: 8, oy: 2, bw: 0, dots: true }) },
  { id: 'luxury', name: 'Dark luxury', note: 'Black and gold, serif type', look: (k, P) => ({ fill: '#0E0E10', fill2: '#1C1A16', text: '#E9C46A', icon: '#D4AF37', border: '#C9A227', shadow: 'rgba(0,0,0,0.45)', blur: 14, oy: 5, bw: 1.5, grad: true, serif: true }) },
  { id: 'pastel', name: 'Pastel soft', note: 'A soft colour each, a deep soft shadow', look: (k, P) => ({ fill: null, text: '#2B2D42', icon: P.accent, border: 'rgba(255,255,255,0.9)', shadow: 'rgba(43,45,66,0.22)', blur: 24, oy: 10, bw: 1, pastel: true }) },
  { id: 'ticker', name: 'Ticker + tab', note: 'A band with a NOW HIRING / WE PLACE IN tab', look: (k, P) => ({ fill: k === 'jobs' ? mixHex(P.primary, W, 0.12) : mixHex(P.primary, '#000000', 0.25), text: W, icon: k === 'jobs' ? '#FF8A98' : W, border: 'rgba(255,255,255,0.18)', shadow: 'rgba(0,0,0,0)', blur: 0, oy: 0, bw: 1, tab: true, band: k === 'jobs' ? P.primary : mixHex(P.primary, '#000000', 0.4), tabFill: P.accent, upper: true }) },
  { id: 'arc', name: 'Curved arc', note: 'The rows bend on a smile', look: (k, P) => ({ fill: k === 'jobs' ? W : P.accent, text: k === 'jobs' ? P.primary : W, icon: k === 'jobs' ? P.accent : W, border: 'rgba(0,0,0,0)', shadow: 'rgba(0,0,0,0.3)', blur: 12, oy: 4, bw: 0, arc: true }) },
  { id: 'iso', name: '3D isometric', note: 'Tilted plane, pills with depth', look: (k, P) => ({ fill: k === 'jobs' ? W : P.primary, text: k === 'jobs' ? P.primary : W, icon: k === 'jobs' ? P.accent : '#FFD23F', border: 'rgba(0,0,0,0)', shadow: 'rgba(0,0,0,0.35)', blur: 10, oy: 8, bw: 0, iso: true, depth: k === 'jobs' ? mixHex(P.primary, W, 0.55) : mixHex(P.primary, '#000000', 0.45) }) },
  { id: 'badge', name: 'Icon badges', note: 'The icon or flag in its own circle', look: (k, P) => ({ fill: W, text: P.primary, icon: W, border: 'rgba(0,0,0,0)', shadow: 'rgba(0,0,0,0.22)', blur: 12, oy: 4, bw: 0, badge: k === 'jobs' ? P.accent : P.primary }) },
];
export const presetById = (id) => PRESETS.find((p) => p.id === id) || PRESETS[0];

/** the colours a row draws with: the preset's, then the row's own (company colours flow in through P) */
export function rowLook(caps, row) {
  const P = caps.brand && caps.brand.primary ? { primary: hex(caps.brand.primary), accent: hex(caps.brand.accent || caps.brand.primary) } : SITE;
  const L = Object.assign({}, presetById(caps.preset).look(row.kind, P));
  for (const k of COLOR_KEYS) if (row.col?.[k]) { L[k] = row.col[k]; if (k === 'fill') { L.grad = false; L.pastel = false; } }
  return L;
}
/** one capsule's colours: the row's, then its own overrides */
function capLook(L, cap, i) {
  const o = cap.col || {};
  const fill = o.fill || (L.pastel ? PASTELS[hashOf(cap.id) % PASTELS.length] : L.fill);
  const r = { ...L, fill, fill2: o.fill ? null : L.fill2, grad: o.fill ? false : L.grad, text: o.text || L.text, icon: o.icon || L.icon, border: o.border || L.border };
  r.key = (L.key ||= JSON.stringify(L)) + '|' + [fill, r.fill2, r.grad, r.text, r.icon, r.border].join(',');
  return r;
}
const hashOf = (s) => { let h = 2166136261; for (let i = 0; i < String(s).length; i++) h = Math.imul(h ^ String(s).charCodeAt(i), 16777619); return h >>> 0; };

// ── the flags: one bundled file (flag-icons 4x3, MIT), each flag rasterised once when first wanted ──
let SPRITE = null;
const FLAGS = new Map();
const loadSprite = () => (SPRITE ||= fetch(new URL('./capsules/flags-4x3.json', import.meta.url)).then((r) => r.json()).then((d) => d.flags).catch(() => ({})));
let onReady = () => { try { globalThis.window?.kbbEditor?.preview?.draw?.(); } catch {} };
/** the page's redraw after a flag arrives (the editor's preview; a hover tile sets its own) */
export function onFlagsReady(fn) { onReady = fn; }
export function loadFlags(codes) {
  const jobs = [];
  for (const code of new Set(codes)) {
    if (!code || FLAGS.has(code)) { if (FLAGS.get(code)?.ready) jobs.push(FLAGS.get(code).ready); continue; }
    const rec = { cv: null };
    rec.ready = loadSprite().then((all) => new Promise((res) => {
      const svg = all[code]; if (!svg) return res();
      const im = new Image();
      im.onload = () => { try { const c = mkCanvas(320, 240); c.getContext('2d').drawImage(im, 0, 0, 320, 240); rec.cv = c; } catch {} res(); };
      im.onerror = () => res();
      im.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    }));
    FLAGS.set(code, rec); jobs.push(rec.ready);
  }
  return Promise.all(jobs);
}
export const flagCanvas = (code) => FLAGS.get(code)?.cv || null;
export const flagSvgs = () => loadSprite();
export const capsFlagCodes = (caps) => (caps?.rows || []).flatMap((r) => r.items.map((c) => c.flag).filter(Boolean));

function mkCanvas(w, h) {
  if (typeof document !== 'undefined') { const c = document.createElement('canvas'); c.width = w; c.height = h; return c; }
  return new OffscreenCanvas(w, h);
}

// ── geometry ──
const FONT_SANS = '"Helvetica Neue", "SF Pro Display", system-ui, -apple-system, "Segoe UI", Roboto, "Geeza Pro", "Noto Sans Arabic", Tahoma, sans-serif';
const FONT_SERIF = 'Didot, "Bodoni 72", Georgia, "Times New Roman", "Geeza Pro", serif';
const fontOf = (L, px) => `${L.serif ? 600 : 700} ${px.toFixed(1)}px ${L.serif ? FONT_SERIF : FONT_SANS}`;
let MEASURE = null;
const MW = new Map();
function textW(txt, font) {
  const k = font + '|' + txt; let v = MW.get(k);
  if (v == null) { MEASURE ||= mkCanvas(8, 8).getContext('2d'); MEASURE.font = font; v = MEASURE.measureText(txt).width; if (MW.size > 4000) MW.clear(); MW.set(k, v); }
  return v;
}
const labelOf = (cap, caps, L) => { const s = caps.lang === 'ar' ? cap.ar || cap.en : cap.en || cap.ar || ''; return L.upper && caps.lang !== 'ar' ? s.toUpperCase() : s; };
const hasMark = (cap) => !!(cap.icon || cap.flag || cap.cont);

/** the size numbers at the carousel's scale (the preset's own multiplier for the big row) */
function geom(caps, L) {
  const s = (caps.scale || 1) * (L.merge ? 1.45 : 1), z = caps.size || {};
  const h = (z.h ?? 64) * s;
  return { h, font: (z.font ?? 25) * s, icon: (z.icon ?? 26) * s, gap: (z.gap ?? 14) * s, rad: (h / 2) * Math.max(0, Math.min(1, z.radius ?? 1)), rowGap: (z.rowGap ?? 18) * (caps.scale || 1), padX: h * 0.36, igap: h * 0.16 };
}
/** a capsule's width (canvas px) */
function capW(cap, caps, L, G) {
  const tw = textW(labelOf(cap, caps, L), fontOf(L, G.font));
  if (L.flagFill && cap.flag) return Math.max(G.h * 1.6, tw + G.padX * 2.2);
  const mark = hasMark(cap) ? (L.badge ? G.h * 0.78 : cap.flag ? G.icon * 1.38 : G.icon) + G.igap : 0;
  return Math.ceil((L.badge && hasMark(cap) ? G.h * 0.11 + G.padX * 0.9 : G.padX * 2) + mark + tw);
}

function pill(c, x, y, w, h, r) { r = Math.max(0, Math.min(r, w / 2, h / 2)); c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r); c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath(); }
const PATHS = new Map();
const pathOf = (id) => { if (!PATHS.has(id)) { const d = LINE_ICONS[id]?.[1]; PATHS.set(id, d && typeof Path2D !== 'undefined' ? new Path2D(d) : null); } return PATHS.get(id); };
/** a line icon (24-unit grid) stroked into a size×size square at x, y */
export function drawLineIcon(c, id, x, y, size, color, lw = 1.9) {
  const p = pathOf(id) || pathOf('star'); if (!p) return;
  c.save(); c.translate(x, y); c.scale(size / 24, size / 24);
  c.lineWidth = lw; c.lineCap = 'round'; c.lineJoin = 'round'; c.strokeStyle = color; c.stroke(p); c.restore();
}

// ── one capsule, pre-rendered once ──
const BMP = new Map();
const BMP_MAX = 600, BMP_PX = 16e6;   // at most 600 bitmaps and 16 Mpx (64 MB) in all, least recently used out first
let bmpPx = 0;
function capBitmap(cap, caps, L, G, dens) {
  const w = capW(cap, caps, L, G), label = labelOf(cap, caps, L);
  const m = Math.ceil(L.blur * 1.6 + Math.abs(L.oy) + L.bw + 4 + (L.depth ? G.h * 0.16 : 0) + (L.tilt ? G.h * 0.08 : 0));
  const key = [label, cap.icon, cap.flag, cap.cont, L.key || JSON.stringify(L), w, G.h, G.font, G.icon, G.rad, dens, caps.lang].join('|');
  let b = BMP.get(key);
  if (b) { BMP.delete(key); BMP.set(key, b); return b; }   // most recently used last
  const pw = Math.ceil((w + m * 2) * dens), ph = Math.ceil((G.h + m * 2) * dens);
  const cv = mkCanvas(Math.max(1, pw), Math.max(1, ph)), c = cv.getContext('2d');
  c.scale(dens, dens); c.translate(m, m);
  const missing = paintCapsule(c, cap, caps, L, G, w, label);
  b = { cv, w, h: G.h, m };
  if (!missing) { BMP.set(key, b); bmpPx += pw * ph; while (BMP.size > BMP_MAX || bmpPx > BMP_PX) { const [k1, v1] = BMP.entries().next().value; BMP.delete(k1); bmpPx -= v1.cv.width * v1.cv.height; if (!BMP.size) break; } }
  return b;
}
/** draws the capsule at the origin (w × G.h). Returns true when its flag is still loading (then it is not cached). */
function paintCapsule(c, cap, caps, L, G, w, label) {
  const h = G.h, ar = caps.lang === 'ar';
  let missing = false;
  const flag = cap.flag ? flagCanvas(cap.flag) : null;
  if (cap.flag && !flag) { missing = true; loadFlags([cap.flag]).then(() => onReady?.()); }
  if (!L.dots) {
    // depth (3D) under the face
    if (L.depth) { c.save(); pill(c, 0, h * 0.14, w, h, G.rad); c.fillStyle = L.depth; c.shadowColor = L.shadow; c.shadowBlur = L.blur; c.shadowOffsetY = L.oy; c.fill(); c.restore(); }
    // shadow / glow + fill
    c.save(); pill(c, 0, 0, w, h, G.rad);
    if (!L.depth) { c.shadowColor = L.shadow; c.shadowBlur = L.blur; c.shadowOffsetY = L.oy; }
    if (L.flagFill && flag) {
      c.fillStyle = '#000'; c.fill(); c.shadowColor = 'transparent'; c.clip();
      const k = Math.max(w / 320, h / 240); c.drawImage(flag, (w - 320 * k) / 2, (h - 240 * k) / 2, 320 * k, 240 * k);
      const g = c.createLinearGradient(0, 0, 0, h); g.addColorStop(0, 'rgba(0,0,0,0.18)'); g.addColorStop(1, 'rgba(0,0,0,0.5)'); c.fillStyle = g; c.fillRect(0, 0, w, h);
    } else if (L.glow) {
      c.fillStyle = L.fill || 'rgba(0,0,0,0)'; c.shadowColor = 'transparent'; c.fill();
    } else {
      let f = L.fill || '#FFFFFF';
      if (L.grad && L.fill2) { const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, L.fill); g.addColorStop(1, L.fill2); f = g; }
      c.fillStyle = f; c.fill();
    }
    c.restore();
    if (L.glass) { c.save(); pill(c, 0, 0, w, h, G.rad); c.clip(); const g = c.createLinearGradient(0, 0, 0, h); g.addColorStop(0, 'rgba(255,255,255,0.35)'); g.addColorStop(0.5, 'rgba(255,255,255,0.06)'); g.addColorStop(1, 'rgba(255,255,255,0)'); c.fillStyle = g; c.fillRect(0, 0, w, h * 0.55); c.restore(); }
    if (L.bw > 0) {
      c.save(); pill(c, L.bw / 2, L.bw / 2, w - L.bw, h - L.bw, G.rad - L.bw / 2); c.lineWidth = L.bw; c.strokeStyle = L.border;
      if (L.glow) { c.shadowColor = L.shadow; c.shadowBlur = L.blur; c.stroke(); c.shadowBlur = L.blur * 0.4; }
      c.stroke(); c.restore();
    }
  }
  // the mark (icon / flag / continent globe) and the label, left to right (right to left in Arabic)
  const fnt = fontOf(L, G.font), tw = textW(label, fnt);
  const fillMark = L.flagFill && flag;
  const mw = !hasMark(cap) || fillMark ? 0 : L.badge ? h * 0.78 : cap.flag ? G.icon * 1.38 : G.icon;
  const startPad = L.badge && mw ? h * 0.11 : G.padX;
  const mx = ar ? w - startPad - mw : startPad, my = (h - (L.badge ? h * 0.78 : G.icon)) / 2;
  if (mw) {
    c.save();
    if (L.glow) { c.shadowColor = L.icon; c.shadowBlur = G.icon * 0.5; }
    if (L.badge) {
      const r = h * 0.39, cx = mx + r, cy = h / 2;
      c.beginPath(); c.arc(cx, cy, r, 0, Math.PI * 2); c.fillStyle = L.badge; c.fill();
      if (cap.flag && flag) { c.save(); c.beginPath(); c.arc(cx, cy, r * 0.94, 0, Math.PI * 2); c.clip(); const k = (r * 2) / 240; c.drawImage(flag, cx - 160 * k, cy - r, 320 * k, 240 * k); c.restore(); }
      else if (cap.flag) { c.fillStyle = 'rgba(255,255,255,0.3)'; c.fill(); }
      else drawLineIcon(c, cap.icon || 'globe', cx - r * 0.62, cy - r * 0.62, r * 1.24, L.icon, 2.1);
    } else if (cap.flag) {
      const fw = G.icon * 1.38, fh = G.icon * 1.035, fy = (h - fh) / 2, rr = fh * 0.16;
      c.save(); pill(c, mx, fy, fw, fh, rr); c.clip();
      if (flag) c.drawImage(flag, mx, fy, fw, fh); else { c.fillStyle = 'rgba(128,128,128,0.35)'; c.fillRect(mx, fy, fw, fh); }
      c.restore(); pill(c, mx, fy, fw, fh, rr); c.lineWidth = 1; c.strokeStyle = 'rgba(0,0,0,0.12)'; c.stroke();
    } else drawLineIcon(c, cap.icon || 'globe', mx, my, G.icon, L.icon);
    c.restore();
  }
  c.save();
  c.font = fnt; c.textBaseline = 'middle'; c.fillStyle = L.text;
  if (L.glow) { c.shadowColor = L.border; c.shadowBlur = G.font * 0.5; }
  else if (L.dots || fillMark) { c.shadowColor = 'rgba(0,0,0,0.55)'; c.shadowBlur = G.font * 0.25; c.shadowOffsetY = 1; }
  if (L.serif && !L.glow && !L.dots) { const g = c.createLinearGradient(0, h * 0.25, 0, h * 0.75); g.addColorStop(0, mixHex(hex(L.text), '#FFFFFF', 0.35)); g.addColorStop(1, L.text); c.fillStyle = g; }
  const gapM = mw ? mw + G.igap : 0;
  if (fillMark) { c.textAlign = 'center'; c.fillText(label, w / 2, h / 2 + G.font * 0.04); }
  else if (ar) { c.direction = 'rtl'; c.textAlign = 'right'; c.fillText(label, w - startPad - gapM, h / 2 + G.font * 0.04); }
  else { c.textAlign = 'left'; c.fillText(label, startPad + gapM, h / 2 + G.font * 0.04); }
  c.restore();
  if (tw < 0) missing = true;
  return missing;
}

// ── motion: the distance a row has travelled at t (px), a pure function of time ──
const easeIO = (x) => (x <= 0 ? 0 : x >= 1 ? 1 : x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2);
export function travelled(caps, row, t, tg, beats) {
  const v = Math.max(0, row.speed ?? 60) * (caps.scale || 1);
  if (caps.motion === 'beat' && beats && beats.length > 1) {
    // one step on each beat (the step is half a second's travel), eased over 0.35 s
    const step = v * 0.5; let n = 0; while (n < beats.length && beats[n] <= tg) n++;
    if (!n) return 0;
    return step * (n - 1 + easeIO((tg - beats[n - 1]) / 0.35));
  }
  if (caps.motion === 'ease' || caps.motion === 'beat') {
    // glide a step, rest, glide again: the same average speed as the smooth marquee
    const step = Math.max(80, (caps.size?.h ?? 64) * 3) * (caps.scale || 1), T = v > 0 ? step / v : 1e9, k = Math.floor(t / T);
    return step * (k + easeIO(((t - k * T) / T) / 0.6));
  }
  return v * t;
}

/** the row's laid-out capsules: [{cap, w}] plus its period (one full loop, gaps included) */
function rowLayout(caps, row, L, G) {
  const list = row.items.map((cap) => ({ cap, w: capW(cap, caps, L, G) }));
  const period = list.reduce((s, x) => s + x.w + G.gap, 0);
  return { list, period };
}

const LAYERS = [];
function layer(i, w, h) {
  let c = LAYERS[i];
  if (!c) c = LAYERS[i] = mkCanvas(1, 1);
  if (c.width < w || c.height < h) { c.width = Math.max(c.width, w); c.height = Math.max(c.height, h); }
  return c;
}

/** the row content drawn into g in local coordinates: the band's capsules between x 0 and w */
function drawRowContent(g, rows, caps, L, G, y, w, d, dens, x0 = 0) {
  for (const R of rows) {
    const { list, period } = R.lay; if (!list.length || period <= 0) continue;
    const off = ((R.dist % period) + period) % period;
    let x = R.dir === 'right' ? off - period : -off;
    x += x0;
    let i = 0, guard = 0;
    while (x < w && guard++ < 4000) {
      const it = list[i % list.length], cw = it.w;
      if (x + cw > x0 - 2) {
        const Lc = capLook(R.L, it.cap, i), b = capBitmap(it.cap, caps, Lc, G, dens);
        if (L.arc) {
          const u = (x + cw / 2 - w / 2) / (w / 2), A = G.h * 1.4, yo = A * u * u, ang = Math.atan2(2 * A * u, w / 2);
          g.save(); g.translate(x + cw / 2, y + G.h / 2 + yo); g.rotate(ang); g.drawImage(b.cv, -cw / 2 - b.m, -G.h / 2 - b.m, cw + b.m * 2, G.h + b.m * 2); g.restore();
        } else if (L.tilt) {
          const a = (((hashOf(it.cap.id) % 5) - 2) * 2.6 * Math.PI) / 180;
          g.save(); g.translate(x + cw / 2, y + G.h / 2); g.rotate(a); g.drawImage(b.cv, -cw / 2 - b.m, -G.h / 2 - b.m, cw + b.m * 2, G.h + b.m * 2); g.restore();
        } else g.drawImage(b.cv, x - b.m, y - b.m, cw + b.m * 2, G.h + b.m * 2);
        if (L.dots) { g.beginPath(); g.arc(x + cw + G.gap / 2, y + G.h / 2, Math.max(2, G.h * 0.06), 0, Math.PI * 2); g.fillStyle = R.L.icon; g.fill(); }
      }
      x += cw + G.gap; i++;
    }
  }
}

/**
 * draw the carousel into c (local coordinates: the item box w × h at the origin).
 * env = { t: seconds into the item, tg: timeline time, beats: [s] }
 */
export function drawCapsules(c, caps, env) {
  if (!caps?.rows?.length) return;
  const { w, h } = env, t = env.t || 0, tg = env.tg ?? t;
  const M = c.getTransform(), dens = Math.max(0.25, Math.min(4, Math.round(Math.hypot(M.a, M.b) * 8) / 8));
  const pre = presetById(caps.preset), any = pre.look('jobs', SITE);
  let rows = caps.rows.filter((r) => r.on !== false && r.items.length);
  if (!rows.length) return;
  // one big row: the jobs and countries interleaved, each keeping its own look
  let bands;
  if (any.merge) {
    const L0 = rowLook(caps, rows[0]), G = geom(caps, L0), mixed = [], n = Math.max(...rows.map((r) => r.items.length));
    for (let i = 0; i < n; i++) for (const r of rows) if (r.items[i]) mixed.push({ cap: r.items[i], L: rowLook(caps, r) });
    const lay = { list: mixed.map((x) => ({ cap: x.cap, w: capW(x.cap, caps, x.L, G), L: x.L })), period: 0 };
    lay.period = lay.list.reduce((s, x) => s + x.w + G.gap, 0);
    bands = [{ rows: [{ lay, dir: rows[0].dir, dist: travelled(caps, rows[0], t, tg, env.beats), L: L0, mixed: true }], L: L0, G, row: rows[0] }];
  } else bands = rows.map((r) => { const L = rowLook(caps, r), G = geom(caps, L); return { rows: [{ lay: rowLayout(caps, r, L, G), dir: r.dir, dist: travelled(caps, r, t, tg, env.beats), L }], L, G, row: r }; });
  const G0 = bands[0].G, bandPad = any.tab ? G0.h * 0.22 : 0;
  const total = bands.reduce((s, b) => s + b.G.h + bandPad * 2, 0) + (bands.length - 1) * G0.rowGap;
  let y = (h - total) / 2;
  bands.forEach((B, bi) => {
    const { L, G } = B, by = y + bandPad, R = B.rows[0];
    if (R.mixed) R.L = L;
    // ticker band behind the row
    if (L.tab) { c.save(); c.fillStyle = L.band; c.shadowColor = 'rgba(0,0,0,0.3)'; c.shadowBlur = 12; c.fillRect(0, y, w, G.h + bandPad * 2); c.restore(); }
    const tabW = L.tab ? tabWidth(caps, B.row, G) : 0;
    const fade = Math.max(0, Math.min(0.45, caps.fade || 0)), glass = !!L.glass && c.canvas;
    const needLayer = fade > 0 || glass || L.tab;
    const m = Math.ceil(G.h * 0.9 + L.blur * 2), ly0 = by - m, lh = G.h + m * 2 + (L.arc ? G.h * 1.6 : 0);
    if (L.iso) { c.save(); c.translate(w / 2, by + G.h / 2); c.transform(1, -0.16, 0.42, 0.78, 0, 0); c.translate(-w / 2, -(by + G.h / 2)); }
    if (!needLayer) {
      for (const r of B.rows) { if (r.mixed) drawMixed(c, r, caps, L, G, by, w, dens); else drawRowContent(c, [r], caps, L, G, by, w, 0, dens); }
    } else {
      const pw = Math.ceil(w * dens), ph = Math.ceil(lh * dens), cv = layer(bi, pw, ph), g = cv.getContext('2d');
      g.setTransform(1, 0, 0, 1, 0, 0); g.globalCompositeOperation = 'source-over'; g.filter = 'none'; g.clearRect(0, 0, pw, ph);
      g.setTransform(dens, 0, 0, dens, 0, -ly0 * dens);
      if (glass) {
        // the video behind the row, blurred, inside the pills only
        try {
          g.save(); g.beginPath(); g.rect(0, by - 2, w, G.h + 4); g.clip();   // only the pills' band is blurred
          g.setTransform(new DOMMatrix().scale(dens, dens).translate(0, -ly0).multiply(M.inverse()));
          g.filter = `blur(${Math.max(2, 10 * dens).toFixed(1)}px)`; g.drawImage(c.canvas, 0, 0); g.restore(); g.filter = 'none';
          g.globalCompositeOperation = 'destination-in'; g.fillStyle = '#000'; pillsPath(g, R, caps, L, G, by, w); g.fill();
          g.globalCompositeOperation = 'source-over';
        } catch { g.globalCompositeOperation = 'source-over'; g.filter = 'none'; }
      }
      for (const r of B.rows) { if (r.mixed) drawMixed(g, r, caps, L, G, by, w, dens); else drawRowContent(g, [r], caps, L, G, by, w, 0, dens, tabW); }
      if (fade > 0 || tabW) {
        const fw = w * fade, gr = g.createLinearGradient(tabW, 0, w, 0), f = Math.min(0.5, fw / Math.max(1, w - tabW));
        gr.addColorStop(0, 'rgba(0,0,0,0)'); gr.addColorStop(Math.max(0.0001, f), 'rgba(0,0,0,1)'); gr.addColorStop(Math.min(0.9999, 1 - f), 'rgba(0,0,0,1)'); gr.addColorStop(1, 'rgba(0,0,0,0)');
        g.globalCompositeOperation = 'destination-in'; g.fillStyle = fade > 0 ? gr : '#000'; g.fillRect(tabW, ly0, w - tabW, lh);
        if (tabW) g.clearRect(0, ly0, tabW, lh);
        g.globalCompositeOperation = 'source-over';
      }
      c.drawImage(cv, 0, 0, pw, ph, 0, ly0, w, lh);
    }
    if (L.iso) c.restore();
    if (L.tab) drawTab(c, caps, B.row, L, G, y, tabW, G.h + bandPad * 2);
    y += G.h + bandPad * 2 + G0.rowGap;
  });
}
function drawMixed(g, r, caps, L, G, y, w, dens) {
  const { list, period } = r.lay; if (!list.length) return;
  const off = ((r.dist % period) + period) % period;
  let x = r.dir === 'right' ? off - period : -off, i = 0, guard = 0;
  while (x < w && guard++ < 4000) {
    const it = list[i % list.length];
    if (x + it.w > -2) { const b = capBitmap(it.cap, caps, capLook(it.L, it.cap, i), G, dens); g.drawImage(b.cv, x - b.m, y - b.m, it.w + b.m * 2, G.h + b.m * 2); }
    x += it.w + G.gap; i++;
  }
}
function pillsPath(g, R, caps, L, G, y, w) {
  const { list, period } = R.lay; g.beginPath(); if (!list.length || period <= 0) return;
  const off = ((R.dist % period) + period) % period;
  let x = R.dir === 'right' ? off - period : -off, i = 0, guard = 0;
  while (x < w && guard++ < 4000) { const it = list[i % list.length]; const r = G.rad; g.roundRect ? g.roundRect(x, y, it.w, G.h, Math.min(r, G.h / 2)) : g.rect(x, y, it.w, G.h); x += it.w + G.gap; i++; }
}
const tabText = (caps, row) => (caps.lang === 'ar' ? caps.tabs?.[row.kind + 'Ar'] || '' : caps.tabs?.[row.kind] || '');
function tabWidth(caps, row, G) { const s = tabText(caps, row); return s ? Math.ceil(textW(s, `800 ${(G.font * 0.82).toFixed(1)}px ${FONT_SANS}`) + G.h * 0.9) : 0; }
function drawTab(c, caps, row, L, G, y, tw, bh) {
  const s = tabText(caps, row); if (!s) return;
  c.save(); c.fillStyle = L.tabFill; c.beginPath(); c.moveTo(0, y); c.lineTo(tw, y); c.lineTo(tw + bh * 0.28, y + bh / 2); c.lineTo(tw, y + bh); c.lineTo(0, y + bh); c.closePath();
  c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = 10; c.fill(); c.shadowColor = 'transparent';
  c.font = `800 ${(G.font * 0.82).toFixed(1)}px ${FONT_SANS}`; c.fillStyle = '#FFFFFF'; c.textBaseline = 'middle'; c.textAlign = 'center';
  if (caps.lang === 'ar') c.direction = 'rtl';
  c.fillText(s, tw / 2, y + bh / 2 + 1); c.restore();
}

/** the box height the carousel needs (canvas px), for its rows at its size and preset */
export function capsHeight(caps) {
  const rows = (caps.rows || []).filter((r) => r.on !== false && r.items.length), L = presetById(caps.preset).look('jobs', SITE), G = geom(caps, L);
  const n = L.merge ? Math.min(1, rows.length) : rows.length, pad = L.tab ? G.h * 0.44 : 0;
  return Math.ceil(Math.max(1, n) * (G.h + pad) + Math.max(0, n - 1) * G.rowGap + G.h * (L.arc ? 1.6 : 0.5));
}
/** one row's loop length at the carousel's scale (px) and the time it takes at its speed (s) */
export function rowPeriod(caps, rowIndex) {
  const r = caps.rows[rowIndex], L = rowLook(caps, r), G = geom(caps, L), { period } = rowLayout(caps, r, L, G);
  const v = Math.max(0, r.speed ?? 60) * (caps.scale || 1);
  return { px: period, s: v > 0 ? period / v : Infinity };
}

// ── edits (pure: they return a new caps; the inspector wraps each one in ONE undo step) ──
export const cloneCaps = (caps) => JSON.parse(JSON.stringify(caps || defaultCaps()));
export function setPreset(caps, id, { clear = false } = {}) {
  const k = cloneCaps(caps); k.preset = presetById(id).id;
  if (clear) for (const r of k.rows) { r.col = {}; for (const c of r.items) delete c.col; }
  return k;
}
export const hasOverrides = (caps) => (caps?.rows || []).some((r) => Object.keys(r.col || {}).length || r.items.some((c) => c.col && Object.keys(c.col).length));
export function bitmapCount() { return BMP.size; }

// the export (and a reopened project) waits for every flag a carousel shows before its first frame: preview = export
registerPrepare('capsules', async (project) => {
  const codes = [];
  for (const tr of project.tracks || []) for (const it of tr.items) if (it.type === 'theme.el' && it.style?.el === 'capsules') codes.push(...capsFlagCodes(it.style.caps));
  if (codes.length) await loadFlags(codes);
});
