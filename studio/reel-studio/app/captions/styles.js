import { FONT_FAMILIES } from './fonts.js';
import { EXTRA_STYLES } from './styles-extra.js';

// Caption styles as DATA. The renderer (render.js) reads only these fields, so every style is
// fully editable from the panel: project.captions.style = { id, ...overrides } (or just the id).
//
// Geometry is in canvas px for a 1080-wide canvas and scales with canvas width.
// pos.y = where the caption block's centre sits inside the platform safe area (0 top … 1 bottom).

const D = {
  font: 'Montserrat', fontAr: 'Cairo', weight: 800, size: 72, case: 'none', lineHeight: 1.18, tracking: 0,
  color: '#ffffff', active: null, dim: null, activeBg: null,
  outline: { w: 0, color: '#000000' },
  shadow: { blur: 0, x: 0, y: 0, color: 'rgba(0,0,0,0.6)' },
  glow: { blur: 0, color: '#ff4fd8' },
  bg: { type: 'none', color: '#000000', opacity: 1, radius: 18, pad: 18 },
  anim: 'none', pos: { y: 0.72 },
  rowChars: 22, maxRows: 2, minDur: 0.7, maxDur: 4.5,
  emoji: false, accent: '#ffd400', bilingual: false,
};

// Each entry is a delta from D. Order = order of the picker tiles.
const RAW = [
  { id: 'word-box', name: 'Word box', group: 'Word by word', size: 78, weight: 900, case: 'upper',
    outline: { w: 10, color: '#000' }, activeBg: '#7c3aed', anim: 'highlight-box', rowChars: 16 },
  { id: 'word-color', name: 'Word colour', group: 'Word by word', size: 78, weight: 900, case: 'upper',
    outline: { w: 10, color: '#000' }, active: '#22e36b', anim: 'highlight-color', rowChars: 16 },
  { id: 'word-scale', name: 'Word zoom', group: 'Word by word', size: 76, weight: 900, case: 'upper',
    shadow: { blur: 18, x: 0, y: 6, color: 'rgba(0,0,0,0.75)' }, active: '#ffd400', anim: 'highlight-scale', rowChars: 16 },
  { id: 'karaoke', name: 'Karaoke fill', group: 'Word by word', size: 74, weight: 900,
    color: '#ffffff', dim: 'rgba(255,255,255,0.45)', active: '#ff3d7f', outline: { w: 8, color: '#1a0b14' }, anim: 'karaoke', rowChars: 20 },
  { id: 'pop-in', name: 'Pop-in words', group: 'Animated', size: 80, weight: 900, case: 'upper',
    outline: { w: 10, color: '#000' }, active: '#00e5ff', anim: 'pop', rowChars: 16 },
  { id: 'one-word', name: 'One word big', group: 'Animated', font: 'Anton', weight: 400, size: 150, case: 'upper',
    outline: { w: 12, color: '#000' }, active: '#ffd400', anim: 'one-word', pos: { y: 0.55 }, rowChars: 12, maxRows: 1 },
  { id: 'bold-outline', name: 'Bold outline', group: 'Classic', font: 'Bebas Neue', weight: 400, size: 110, case: 'upper',
    tracking: 2, outline: { w: 14, color: '#000' }, shadow: { blur: 0, x: 6, y: 8, color: '#000' }, rowChars: 18 },
  { id: 'soft-beauty', name: 'Soft beauty', group: 'Brand', font: 'Montserrat', weight: 600, size: 58,
    color: '#5b2a3c', active: '#c2185b', bg: { type: 'pill', color: '#ffe3ec', opacity: 0.94, radius: 40, pad: 22 },
    anim: 'highlight-color', rowChars: 26 },
  { id: 'luxury-serif', name: 'Luxury serif', group: 'Brand', font: 'Playfair Display', fontAr: 'Amiri', weight: 600, size: 70,
    color: '#f6e7c1', active: '#d4af37', shadow: { blur: 14, x: 0, y: 3, color: 'rgba(0,0,0,0.7)' }, tracking: 1,
    anim: 'fade', rowChars: 24 },
  { id: 'neon', name: 'Neon', group: 'Animated', font: 'Montserrat', weight: 800, size: 74, case: 'upper',
    color: '#fff6ff', active: '#ffffff', glow: { blur: 28, color: '#ff2bd6' }, anim: 'highlight-glow', rowChars: 18 },
  { id: 'typewriter', name: 'Typewriter', group: 'Animated', font: 'Special Elite', fontAr: 'Noto Naskh Arabic', weight: 400, size: 62,
    color: '#111111', bg: { type: 'box', color: '#fdf6e3', opacity: 0.96, radius: 6, pad: 22 }, anim: 'typewriter', rowChars: 24 },
  { id: 'bounce', name: 'Bounce', group: 'Animated', font: 'Bangers', weight: 400, size: 96, tracking: 2,
    color: '#ffffff', active: '#ffde00', outline: { w: 10, color: '#1b1b1b' }, anim: 'bounce', rowChars: 16 },
  { id: 'emoji-accent', name: 'Emoji accent', group: 'Animated', size: 72, weight: 900,
    outline: { w: 9, color: '#000' }, active: '#ffd400', anim: 'highlight-color', emoji: true, rowChars: 18 },
  { id: 'marker', name: 'Highlight marker', group: 'Classic', font: 'Montserrat', weight: 800, size: 66,
    color: '#111111', bg: { type: 'marker', color: '#fff200', opacity: 1, radius: 6, pad: 10 }, anim: 'marker', rowChars: 20 },
  { id: 'news', name: 'News lower-third', group: 'Pro', font: 'Inter', weight: 700, size: 50,
    color: '#ffffff', bg: { type: 'bar', color: '#c8102e', opacity: 1, radius: 0, pad: 24 }, accent: '#ffffff',
    anim: 'slide', pos: { y: 0.9 }, rowChars: 34, maxRows: 2 },
  { id: 'minimal', name: 'Minimal subtitle', group: 'Classic', font: 'Inter', weight: 500, size: 46,
    color: '#ffffff', bg: { type: 'box', color: '#000000', opacity: 0.55, radius: 10, pad: 14 }, pos: { y: 0.86 },
    rowChars: 36, maxRows: 2 },
  { id: 'arabic-first', name: 'Arabic first', group: 'Arabic', font: 'Cairo', fontAr: 'Cairo', weight: 800, size: 74,
    color: '#ffffff', active: '#ffd400', outline: { w: 9, color: '#000' }, anim: 'highlight-color', rowChars: 20 },
  { id: 'bilingual', name: 'Bilingual EN / AR', group: 'Arabic', font: 'Montserrat', fontAr: 'Cairo', weight: 800, size: 56,
    color: '#ffffff', active: '#ffd400', outline: { w: 8, color: '#000' }, bilingual: true, anim: 'highlight-color',
    pos: { y: 0.74 }, rowChars: 26, maxRows: 1 },
  // Professional consultancy (Planet Careers): calm, readable, no gimmicks.
  { id: 'corporate', name: 'Corporate lower-third', group: 'Pro', font: 'Inter', fontAr: 'Tajawal', weight: 600, size: 48,
    color: '#0b1f3a', bg: { type: 'bar', color: '#ffffff', opacity: 0.96, radius: 10, pad: 22 }, accent: '#14a3a3',
    anim: 'slide', pos: { y: 0.88 }, rowChars: 34, maxRows: 2 },
  { id: 'info-card', name: 'Info card (navy / gold)', group: 'Pro', font: 'Montserrat', fontAr: 'Tajawal', weight: 700, size: 54,
    color: '#ffffff', active: '#d4af37', bg: { type: 'card', color: '#0b1f3a', opacity: 0.95, radius: 22, pad: 28 },
    accent: '#d4af37', anim: 'highlight-color', pos: { y: 0.8 }, rowChars: 28, maxRows: 2 },
];

function merge(a, b) {
  const out = { ...a };
  for (const [k, v] of Object.entries(b || {})) {
    out[k] = v && typeof v === 'object' && !Array.isArray(v) && a[k] && typeof a[k] === 'object' ? { ...a[k], ...v } : v;
  }
  return out;
}

export const STYLES = [...RAW, ...EXTRA_STYLES].map((s) => merge(D, s));
// Captions round (16 Nov): the template gallery's 30+ more styles, after the first 20 and Lane C's extras.
for (const s of K_STYLES) if (!STYLES.some((x) => x.id === s.id)) STYLES.push(merge(D, s));
export const STYLE_IDS = STYLES.map((s) => s.id);
const BY_ID = Object.fromEntries(STYLES.map((s) => [s.id, s]));
export const DEFAULT_STYLE_ID = 'word-box';

export const ANIMS = ['none', 'highlight-box', 'highlight-color', 'highlight-scale', 'highlight-glow', 'karaoke', 'pop',
  'one-word', 'typewriter', 'bounce', 'fade', 'slide', 'marker', 'rise', 'wave', 'shake', 'zoom', 'glitch', 'underline', 'stamp',
  'outline-fill', 'rainbow'];
export const BG_TYPES = ['none', 'box', 'pill', 'word', 'marker', 'bar', 'card', 'tape', 'bubble', 'gradient', 'outline'];
export const CASES = ['none', 'upper', 'lower', 'title'];

/** project.captions.style (id string or {id, ...overrides}) -> full style object. Unknown id -> default. */
export function resolveStyle(spec) {
  const id = typeof spec === 'string' ? spec : spec && spec.id;
  const base = BY_ID[id] || BY_ID[DEFAULT_STYLE_ID];
  if (!spec || typeof spec === 'string') return base;
  const { id: _i, ...over } = spec;
  return merge(base, over);
}

/** The minimal {id, ...overrides} that reproduces `full` (what gets stored in the project). */
export function diffStyle(full) {
  const base = BY_ID[full.id] || BY_ID[DEFAULT_STYLE_ID];
  const out = { id: base.id };
  for (const [k, v] of Object.entries(full)) {
    if (k === 'id' || k === 'name' || k === 'group') continue;
    if (JSON.stringify(v) !== JSON.stringify(base[k])) out[k] = v;
  }
  return out;
}

/**
 * Per-workspace default caption style (core/project.js Workspace JSON).
 *   ws.captions = { style, font, colors:{text, active, bg, accent, outline} }   optional, explicit, wins
 *   otherwise derived: style from the industry (consultancy -> 'info-card', beauty -> 'word-box'),
 *   colours from ws.colors (primary/accent), font from ws.fonts.heading when it is one of ours.
 * Returns a style spec ready for project.captions.style.
 */
export function styleForWorkspace(ws) {
  if (!ws) return { id: DEFAULT_STYLE_ID };
  const c = ws.captions || {};
  const ind = String(ws.industry || '');
  const id = BY_ID[c.style] ? c.style
    : /consult|immigra|visa|study|legal|law|finance|corporate|recruit/i.test(ind) ? 'info-card'
    : DEFAULT_STYLE_ID;
  const base = BY_ID[id], spec = { id };
  const wc = ws.colors || {};
  // derived colours, by what each style uses its colours for
  if (isColor(wc.primary)) {
    if (base.activeBg) spec.activeBg = wc.primary;
    if (base.bg.type === 'card' || base.bg.type === 'bar') spec.bg = { ...base.bg, color: wc.primary };
  }
  if (isColor(wc.accent) && (base.bg.type === 'card' || base.bg.type === 'bar')) { spec.accent = wc.accent; spec.active = wc.accent; }
  const font = c.font || ws.fonts?.heading;
  if (font && FONT_OK(font)) spec.font = font;
  const col = c.colors || {};
  if (isColor(col.text)) spec.color = col.text;
  if (isColor(col.active)) { spec.active = col.active; if (base.activeBg) spec.activeBg = col.active; }
  if (isColor(col.accent)) spec.accent = col.accent;
  if (isColor(col.outline)) spec.outline = { ...base.outline, color: col.outline };
  if (isColor(col.bg)) spec.bg = { ...base.bg, ...(spec.bg || {}), color: col.bg };
  return spec;
}

/** The tile order for a workspace: its default style first, then the rest. */
export function stylesForWorkspace(kit) {
  const first = styleForWorkspace(kit).id;
  return [BY_ID[first], ...STYLES.filter((s) => s.id !== first)];
}

const FONT_OK = (f) => FONT_FAMILIES.includes(f);
export function isColor(v) {
  return typeof v === 'string' && /^(#[0-9a-f]{3,8}|rgba?\([\d.,\s%]+\)|hsla?\([\d.,\s%deg]+\))$/i.test(v.trim());
}

// Captions round: template data + gallery categories (kept at the end of the file, away from the other lanes' lines).
import { K_STYLES } from './styles-k.js';
export { CATEGORIES, catOf, keywordsOf } from './styles-k.js';
