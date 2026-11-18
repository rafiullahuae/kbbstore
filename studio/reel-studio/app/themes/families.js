// KBB Studio · themes · DESIGN FAMILIES (2026-11, the owner: "bulletin templates we have enough ... now make totally
// different careers based, traveling based, occupations based, bright future based designs templates / animations").
// Four families of big, bright, full compositions that share NOTHING with the bulletin look (no bands, crawls or
// "breaking" bars), each piece in its own visual language:
//
//   careers      ladder (paper-cut) · cv (glass on a gradient mesh) · offer letter (warm flat) · path (Swiss type) ·
//                handshake + contract (warm flat)
//   travel       route map (warm flat) · suitcase (paper-cut) · plane window (soft gradient) · postcard stack (duotone) ·
//                passport collage (sketch that colours in) · departures board (kinetic split-flap)
//   occupations  worker portrait in uniform, 13 trades with their tools and workplace (warm flat) · pick-your-trade grid
//                (Swiss) · a day in the life (paper-cut panels) · uniform morph (duotone) · isometric work site ·
//                duotone photo frame
//   future       sunrise skyline (soft gradient) · door onto a new country (paper-cut) · growing tree (sketch) · home key +
//                family (warm flat) · graduation caps (kinetic) · goal constellation (gradient mesh) · growth chart
//                (isometric) · kinetic words · isometric city
//
// One drawing per piece, shared by the Ad Studio (designs/broadcast.js draws them as art elements) and the reels
// (themes/elements.js draws them as timeline elements): a pure function of (box, t, options), so preview = export.
// Every piece builds itself between t = 0 and ~3.6 s and keeps a gentle life afterwards (the still is t = 4).
// Words drawn inside a piece are in o.words (editable); Arabic words draw right to left; o.rtl mirrors the piece's
// direction (paths run right to left) while words still read normally. Values are placeholders only (XX, ‹edit›):
// never a visa rule, a fee, a salary or an approval rate. Original drawings only; the landmarks, flags and scenes come
// from the shared art kit (art.js).
import * as A from './art.js';
import { kitFor } from './kit.js';
import { textActive, textStyle, fitText, drawPlain, noteBox } from './text.js';

const { TAU, clamp, easeOut, easeIO, back, elastic, rnd, fr, mix, rgba, lum } = A;
const isAr = (s) => /[؀-ۿ]/.test(s || '');
const ez = (t, t0, d) => easeOut((t - t0) / d);
const pop = (t, t0, d = 0.5) => back(clamp((t - t0) / d));

export const FAMILIES = ['careers', 'travel', 'occupations', 'future'];
export const FAMILY_LABELS = { careers: 'Careers', travel: 'Travel', occupations: 'Occupations', future: 'Bright future' };
export const FAMILY_LABELS_AR = { careers: 'المسيرة المهنية', travel: 'السفر', occupations: 'المهن', future: 'مستقبل مشرق' };
export const FAMILY_ICONS = { careers: '🪜', travel: '🧳', occupations: '🦺', future: '🌅' };
/** each family's own bright colours: a b c d (accents), ink (dark text), paper (light ground), sky (deep ground) */
export const FAM_PAL = {
  careers: { a: '#FF6B4A', b: '#FFC93C', c: '#2EC4B6', d: '#3A4CCB', ink: '#1B1F3B', paper: '#FFF8EE', sky: '#2B3A9E' },
  travel: { a: '#0096E6', b: '#FFB703', c: '#FB5607', d: '#06C48F', ink: '#073B4C', paper: '#FFFBF2', sky: '#0A5DA8' },
  occupations: { a: '#FF8C42', b: '#FFD23F', c: '#22C3A6', d: '#5D5FEF', ink: '#1F2041', paper: '#F6F7FF', sky: '#3B3DBF' },
  future: { a: '#FF5FA2', b: '#FFC94A', c: '#4FD8B5', d: '#6C5CE7', ink: '#2B2D42', paper: '#FFF5F8', sky: '#4B3DB8' },
};

// ── words: one way to draw them (Arabic right to left; mirrored pieces keep words readable) ──
let MIR = false;
/** while a mirrored (right-to-left) piece draws, words flip back so they still read */
export const setMirror = (m) => { MIR = !!m; };
export function T(c, s, x, y, size, { col = '#ffffff', align = 'center', weight = 800, font = 'ui', maxW = 1e9, base = 'alphabetic', track = 0 } = {}) {
  if (!s) return 0;
  s = String(s); const ar = isAr(s);
  // a reel element's text pass (./text.js): the piece's word key, the element's text style, fitting long words
  const d = textActive() ? textStyle({ kind: ar ? 'ar' : 'fam', text: s, x, y, size, col, align, weight: ar ? Math.max(700, weight) : weight, family: ar ? A.FONT_AR : font === 'display' ? A.FONT_EN : A.FONT_UI, maxW, base, track: ar ? 0 : track, shadow: false }) : null;
  if (d?.custom) {
    fitText(c, d); c.save();
    if (MIR) { c.translate(d.x, d.y); c.scale(-1, 1); d.x = 0; d.y = 0; d.align = d.align === 'left' ? 'right' : d.align === 'right' ? 'left' : d.align; }
    const w = Math.min(d.maxW, drawPlain(c, d)); noteBox(c, d, w); c.restore();
    return w;
  }
  c.save();
  c.font = `${ar ? Math.max(700, weight) : weight} ${Math.round(size)}px ${ar ? A.FONT_AR : font === 'display' ? A.FONT_EN : A.FONT_UI}`;
  c.textBaseline = base; c.fillStyle = col;
  if (ar) c.direction = 'rtl';
  if (track && !ar && 'letterSpacing' in c) c.letterSpacing = `${(size * track).toFixed(1)}px`;
  let al = align;
  if (MIR) { c.translate(x, y); c.scale(-1, 1); x = 0; y = 0; al = align === 'left' ? 'right' : align === 'right' ? 'left' : align; }
  c.textAlign = al; c.fillText(s, x, y, maxW);
  const w = Math.min(maxW, c.measureText(s).width);
  if (d) noteBox(c, { ...d, x, y, align: al }, w);
  c.restore();
  return w;
}
export const rrect = (c, x, y, w, h, r) => { c.beginPath(); A.rr(c, x, y, w, h, r); };
export function shadowed(c, blur, dy, col, fn) { c.save(); c.shadowColor = col || 'rgba(20,20,60,0.28)'; c.shadowBlur = blur; c.shadowOffsetY = dy; fn(); c.restore(); }
/** a paper-cut layer: flat fill with a soft cast shadow and a lighter top edge */
export function paper(c, path, fill, u, depth = 1) {
  c.save(); c.shadowColor = 'rgba(30,20,60,0.32)'; c.shadowBlur = u * 0.03 * depth; c.shadowOffsetY = u * 0.012 * depth; c.fillStyle = fill; c.fill(path); c.restore();
  c.save(); c.clip(path); c.strokeStyle = rgba('#ffffff', 0.35); c.lineWidth = u * 0.006; c.stroke(path); c.restore();
}
export const wave = (x, y, w, h, ph, amp, k = 1.5) => { const p = new Path2D(); p.moveTo(x, y + h); for (let i = 0; i <= 32; i++) { const u = i / 32; p.lineTo(x + u * w, y + amp * Math.sin(u * TAU * k + ph) + amp * 0.5 * Math.sin(u * TAU * k * 2.3 + ph * 1.7)); } p.lineTo(x + w, y + h); p.closePath(); return p; };
export const SKIN = ['#F1C7A5', '#E0AC8A', '#C68863', '#8D5A3B'];
const HAIR = ['#2B1D16', '#3B2A20', '#14110F', '#5A3A22'];
export const bestOn = (bg) => (lum(bg) > 0.55 ? '#1b1f3b' : '#ffffff');

// ── people: one flat figure, posed (arms as two segments; angles in canvas radians, PI/2 = straight down) ──
export function person(c, x, feet, s, o = {}) {
  const skin = o.skin || SKIN[1], top = o.top || '#3A86FF', bottom = o.bottom || '#2B2D42', lw = s * 0.07;
  const sh = feet - s * 0.79, hip = feet - s * 0.47, hy = feet - s * 0.885, hr = s * 0.075;
  const st = o.step || 0;
  c.save(); c.lineCap = 'round'; c.lineJoin = 'round';
  // legs
  if (!o.noLegs) {
    c.strokeStyle = bottom; c.lineWidth = lw * 1.05;
    for (const k of [-1, 1]) { const sw = Math.sin(st * TAU + (k > 0 ? Math.PI : 0)) * s * 0.06; c.beginPath(); c.moveTo(x + k * s * 0.055, hip); c.lineTo(x + k * s * 0.06 + sw, feet - s * 0.03); c.stroke(); c.fillStyle = o.shoes || '#22223B'; c.beginPath(); c.ellipse(x + k * s * 0.07 + sw, feet - s * 0.02, s * 0.05, s * 0.025, 0, 0, TAU); c.fill(); }
  }
  // torso (a dress / long coat reaches lower)
  const tg = c.createLinearGradient(x - s * 0.15, 0, x + s * 0.15, 0); tg.addColorStop(0, mix(top, '#ffffff', 0.1)); tg.addColorStop(1, mix(top, '#000000', 0.12));
  c.fillStyle = tg; c.beginPath();
  const low = o.long ? feet - s * 0.28 : hip + s * 0.03;
  c.moveTo(x - s * 0.13, sh + s * 0.02); c.quadraticCurveTo(x - s * 0.15, sh + s * 0.2, x - (o.long ? s * 0.16 : s * 0.115), low); c.lineTo(x + (o.long ? s * 0.16 : s * 0.115), low); c.quadraticCurveTo(x + s * 0.15, sh + s * 0.2, x + s * 0.13, sh + s * 0.02);
  c.quadraticCurveTo(x, sh - s * 0.03, x - s * 0.13, sh + s * 0.02); c.fill();
  if (o.dress) o.dress(c, { x, sh, hip, s, low });
  // arms + hands
  const arms = o.arms || [[Math.PI / 2 + 0.18, Math.PI / 2 + 0.08], [Math.PI / 2 - 0.18, Math.PI / 2 - 0.08]], hands = [];
  arms.forEach(([a1, a2], i) => {
    const k = i ? 1 : -1, x0 = x + k * s * 0.12, y0 = sh + s * 0.04, L1 = s * 0.17, L2 = s * 0.16;
    const x1 = x0 + Math.cos(a1) * L1, y1 = y0 + Math.sin(a1) * L1, x2 = x1 + Math.cos(a2) * L2, y2 = y1 + Math.sin(a2) * L2;
    c.strokeStyle = o.sleeve || top; c.lineWidth = lw; c.beginPath(); c.moveTo(x0, y0); c.lineTo(x1, y1); c.lineTo(x2, y2); c.stroke();
    if (o.cuff) { c.strokeStyle = o.cuff; c.lineWidth = lw * 1.02; c.beginPath(); c.moveTo(x1 + (x2 - x1) * 0.75, y1 + (y2 - y1) * 0.75); c.lineTo(x2, y2); c.stroke(); }
    c.fillStyle = o.gloves || skin; c.beginPath(); c.arc(x2, y2, s * 0.034, 0, TAU); c.fill();
    hands.push([x2, y2]);
  });
  if (o.hold) o.hold(c, hands, s);
  // neck + head + face
  c.fillStyle = mix(skin, '#000000', 0.1); c.fillRect(x - s * 0.028, hy + hr * 0.7, s * 0.056, s * 0.05);
  if (o.hair === 'hijab') { c.fillStyle = o.hijab || '#7B6CF6'; c.beginPath(); c.ellipse(x, hy + hr * 0.15, hr * 1.25, hr * 1.38, 0, 0, TAU); c.fill(); c.beginPath(); c.moveTo(x - hr * 1.2, hy + hr * 0.6); c.quadraticCurveTo(x, hy + hr * 2.6, x + hr * 1.2, hy + hr * 0.6); c.fill(); }
  if (o.hair === 'long') { c.fillStyle = o.hairCol || HAIR[0]; c.beginPath(); c.ellipse(x, hy - hr * 0.1, hr * 1.15, hr * 1.05, 0, 0, TAU); c.fill(); for (const k of [-1, 1]) { c.beginPath(); A.rr(c, x + (k < 0 ? -hr * 1.18 : hr * 0.62), hy - hr * 0.2, hr * 0.56, hr * 1.9, hr * 0.28); c.fill(); } }   // long hair falls behind the shoulders, either side of the face
  const hg = c.createRadialGradient(x - hr * 0.3, hy - hr * 0.3, hr * 0.1, x, hy, hr * 1.1); hg.addColorStop(0, mix(skin, '#ffffff', 0.18)); hg.addColorStop(1, mix(skin, '#000000', 0.08));
  c.fillStyle = hg; c.beginPath(); c.ellipse(x, hy, hr * (o.hair === 'hijab' ? 0.86 : 1), hr * 1.08, 0, 0, TAU); c.fill();
  if (o.hair !== 'hijab' && !o.hat) { c.fillStyle = o.hairCol || HAIR[0]; c.beginPath(); c.ellipse(x, hy - hr * 0.42, hr * 1.04, hr * 0.7, 0, Math.PI, 0); c.fill(); if (o.hair === 'bun') { c.beginPath(); c.arc(x, hy - hr * 1.15, hr * 0.42, 0, TAU); c.fill(); } }
  c.fillStyle = '#2B1D16'; for (const k of [-1, 1]) { c.beginPath(); c.arc(x + k * hr * 0.36, hy - hr * 0.02, hr * 0.1, 0, TAU); c.fill(); }
  c.fillStyle = rgba('#ff7b7b', 0.35); for (const k of [-1, 1]) { c.beginPath(); c.arc(x + k * hr * 0.55, hy + hr * 0.3, hr * 0.16, 0, TAU); c.fill(); }
  c.strokeStyle = '#7a3b2e'; c.lineWidth = hr * 0.1; c.beginPath(); c.arc(x, hy + hr * 0.2, hr * 0.32, 0.25, Math.PI - 0.25); c.stroke();
  if (o.hat) o.hat(c, x, hy, hr);
  c.restore();
  return { hands, head: [x, hy, hr], sh, hip };
}

// ── the 13 trades: uniform, hat, tool in hand, workplace behind ──
const HAT = {
  nurse: (c, x, y, r) => { c.fillStyle = '#ffffff'; c.beginPath(); c.moveTo(x - r * 0.9, y - r * 0.55); c.lineTo(x - r * 0.75, y - r * 1.3); c.lineTo(x + r * 0.75, y - r * 1.3); c.lineTo(x + r * 0.9, y - r * 0.55); c.fill(); c.fillStyle = '#E63946'; c.fillRect(x - r * 0.1, y - r * 1.22, r * 0.2, r * 0.55); c.fillRect(x - r * 0.28, y - r * 1.04, r * 0.56, r * 0.2); },
  hard: (col) => (c, x, y, r) => { c.fillStyle = col; c.beginPath(); c.ellipse(x, y - r * 0.5, r * 1.12, r * 0.85, 0, Math.PI, 0); c.fill(); c.fillRect(x - r * 1.35, y - r * 0.56, r * 2.7, r * 0.22); c.fillStyle = rgba('#ffffff', 0.45); c.fillRect(x - r * 0.12, y - r * 1.3, r * 0.24, r * 0.7); },
  cap: (col) => (c, x, y, r) => { c.fillStyle = col; c.beginPath(); c.ellipse(x, y - r * 0.55, r * 1.05, r * 0.66, 0, Math.PI, 0); c.fill(); c.fillRect(x - r * 0.1, y - r * 0.62, r * 1.55, r * 0.2); },
  toque: (c, x, y, r) => { c.fillStyle = '#ffffff'; for (const [dx, dy, rr2] of [[-0.55, -1.55, 0.55], [0.55, -1.55, 0.55], [0, -1.8, 0.62]]) { c.beginPath(); c.arc(x + dx * r, y + dy * r, rr2 * r, 0, TAU); c.fill(); } c.fillRect(x - r * 0.95, y - r * 1.5, r * 1.9, r * 0.95); c.fillStyle = '#E5E8EE'; c.fillRect(x - r * 0.95, y - r * 0.75, r * 1.9, r * 0.18); },
  straw: (c, x, y, r) => { c.fillStyle = '#E9C46A'; c.beginPath(); c.ellipse(x, y - r * 0.6, r * 1.9, r * 0.38, 0, 0, TAU); c.fill(); c.beginPath(); c.ellipse(x, y - r * 0.85, r * 0.95, r * 0.75, 0, Math.PI, 0); c.fill(); c.fillStyle = '#D62828'; c.fillRect(x - r * 0.95, y - r * 0.9, r * 1.9, r * 0.18); },
  welder: (c, x, y, r) => { c.fillStyle = '#3D4451'; c.beginPath(); c.ellipse(x, y - r * 1.15, r * 1.1, r * 0.55, 0, 0, TAU); c.fill(); c.fillStyle = '#11151c'; rrect(c, x - r * 0.75, y - r * 1.38, r * 1.5, r * 0.32, r * 0.08); c.fill(); c.fillStyle = 'rgba(120,200,255,0.6)'; c.fillRect(x - r * 0.6, y - r * 1.33, r * 0.4, r * 0.08); c.fillStyle = '#2B1D16'; c.beginPath(); c.ellipse(x, y - r * 0.5, r * 1.02, r * 0.5, 0, Math.PI, 0); c.fill(); },
  band: (col) => (c, x, y, r) => { c.fillStyle = HAIR[1]; c.beginPath(); c.ellipse(x, y - r * 0.42, r * 1.04, r * 0.7, 0, Math.PI, 0); c.fill(); c.beginPath(); c.arc(x, y - r * 1.12, r * 0.42, 0, TAU); c.fill(); c.fillStyle = col; c.fillRect(x - r * 1.0, y - r * 0.72, r * 2, r * 0.22); },
};
const lineTool = (c, pts, col, w) => { c.save(); c.strokeStyle = col; c.lineWidth = w; c.lineCap = 'round'; c.beginPath(); pts.forEach(([X, Y], i) => (i ? c.lineTo(X, Y) : c.moveTo(X, Y))); c.stroke(); c.restore(); };
/** a tool held in the right hand (h = [x, y]); t animates sparks, steam, a blinking light */
const TOOL = {
  stetho: (c, [[lx, ly], [x, y]], s) => { c.strokeStyle = '#2B2D42'; c.lineWidth = s * 0.012; c.beginPath(); c.moveTo(x - s * 0.18, y - s * 0.34); c.quadraticCurveTo(x - s * 0.25, y - s * 0.1, x - s * 0.05, y - s * 0.02); c.stroke(); c.fillStyle = '#9AA5B8'; c.beginPath(); c.arc(x, y - s * 0.01, s * 0.03, 0, TAU); c.fill(); },
  clipboard: (c, [, [x, y]], s) => { c.fillStyle = '#8D6E63'; rrect(c, x - s * 0.1, y - s * 0.16, s * 0.15, s * 0.2, s * 0.01); c.fill(); c.fillStyle = '#ffffff'; c.fillRect(x - s * 0.088, y - s * 0.14, s * 0.126, s * 0.165); c.fillStyle = '#B0B8C8'; for (let i = 0; i < 4; i++) c.fillRect(x - s * 0.075, y - s * 0.12 + i * s * 0.035, s * 0.1, s * 0.01); c.fillStyle = '#2EC4B6'; c.beginPath(); c.arc(x - s * 0.03, y - s * 0.03, s * 0.02, 0, TAU); c.fill(); },
  wheel: (c, [[lx, ly], [x, y]], s) => { const cx = (lx + x) / 2, cy = (ly + y) / 2 + s * 0.02, r = s * 0.15; c.strokeStyle = '#22223B'; c.lineWidth = s * 0.03; c.beginPath(); c.arc(cx, cy, r, 0, TAU); c.stroke(); c.lineWidth = s * 0.022; c.beginPath(); c.moveTo(cx - r, cy); c.lineTo(cx + r, cy); c.moveTo(cx, cy); c.lineTo(cx, cy + r); c.stroke(); c.fillStyle = '#22223B'; c.beginPath(); c.arc(cx, cy, s * 0.03, 0, TAU); c.fill(); },
  torch: (c, [, [x, y]], s, t) => { lineTool(c, [[x, y], [x + s * 0.16, y - s * 0.1]], '#4A4E69', s * 0.03); c.fillStyle = '#ADB5BD'; c.fillRect(x + s * 0.15, y - s * 0.115, s * 0.04, s * 0.025); const fx = x + s * 0.2, fy = y - s * 0.1; c.save(); c.globalCompositeOperation = 'lighter'; c.drawImage(A.blob('#7FD3FF'), fx - s * 0.08, fy - s * 0.08, s * 0.16, s * 0.16); for (let i = 0; i < 14; i++) { const a = -0.3 + rnd(i, 3) * 2.4 + Math.sin(t * 9 + i) * 0.1, d = s * (0.04 + 0.18 * fr(t * 1.8 + rnd(i, 4))); c.fillStyle = i % 2 ? '#FFD23F' : '#FF8C42'; c.fillRect(fx + Math.cos(a) * d, fy + Math.sin(a) * d * 0.7 + d * d / s, s * 0.008, s * 0.008); } c.restore(); },
  meter: (c, [, [x, y]], s, t) => { c.fillStyle = '#FFB703'; rrect(c, x - s * 0.06, y - s * 0.14, s * 0.12, s * 0.18, s * 0.015); c.fill(); c.fillStyle = '#073B4C'; c.fillRect(x - s * 0.045, y - s * 0.12, s * 0.09, s * 0.05); c.fillStyle = Math.sin(t * 6) > 0 ? '#7CFC00' : '#2a5'; c.fillRect(x - s * 0.035, y - s * 0.11, s * 0.04, s * 0.03); c.strokeStyle = '#E63946'; c.lineWidth = s * 0.01; c.beginPath(); c.moveTo(x - s * 0.03, y + s * 0.04); c.quadraticCurveTo(x - s * 0.12, y + s * 0.18, x - s * 0.2, y + s * 0.12); c.stroke(); },
  pan: (c, [, [x, y]], s, t) => { lineTool(c, [[x, y], [x + s * 0.1, y - s * 0.02]], '#3D3D3D', s * 0.025); c.fillStyle = '#2F2F2F'; c.beginPath(); c.ellipse(x + s * 0.2, y - s * 0.03, s * 0.11, s * 0.035, 0, 0, TAU); c.fill(); for (let i = 0; i < 3; i++) { const yy = y - s * (0.08 + 0.12 * fr(t * 0.6 + i / 3)), a = 1 - fr(t * 0.6 + i / 3); c.strokeStyle = rgba('#ffffff', 0.7 * a); c.lineWidth = s * 0.012; c.beginPath(); c.moveTo(x + s * (0.15 + i * 0.05), yy + s * 0.04); c.quadraticCurveTo(x + s * (0.13 + i * 0.05), yy, x + s * (0.16 + i * 0.05), yy - s * 0.04); c.stroke(); } c.fillStyle = '#FF8C42'; c.beginPath(); c.arc(x + s * 0.17, y - s * 0.05, s * 0.025, 0, TAU); c.fill(); c.fillStyle = '#06C48F'; c.beginPath(); c.arc(x + s * 0.23, y - s * 0.05, s * 0.02, 0, TAU); c.fill(); },
  tray: (c, [, [x, y]], s) => { c.fillStyle = '#C0C7D1'; c.beginPath(); c.ellipse(x, y - s * 0.02, s * 0.15, s * 0.03, 0, 0, TAU); c.fill(); for (const [dx, col] of [[-0.07, '#FFB703'], [0, '#E63946'], [0.07, '#9BE7FF']]) { c.fillStyle = rgba(col, 0.85); c.beginPath(); c.moveTo(x + dx * s - s * 0.025, y - s * 0.03); c.lineTo(x + dx * s - s * 0.03, y - s * 0.13); c.lineTo(x + dx * s + s * 0.03, y - s * 0.13); c.lineTo(x + dx * s + s * 0.025, y - s * 0.03); c.fill(); } },
  towels: (c, [[lx, ly], [x, y]], s) => { const cx = (lx + x) / 2, cy = (ly + y) / 2; ['#4FD8B5', '#FFFFFF', '#FFC94A'].forEach((col, i) => { c.fillStyle = col; rrect(c, cx - s * 0.12, cy - s * 0.05 - i * s * 0.05, s * 0.24, s * 0.055, s * 0.02); c.fill(); c.strokeStyle = rgba('#000000', 0.08); c.lineWidth = s * 0.004; c.stroke(); }); },
  hammer: (c, [, [x, y]], s) => { lineTool(c, [[x, y + s * 0.04], [x + s * 0.02, y - s * 0.16]], '#A0522D', s * 0.028); c.fillStyle = '#6C757D'; rrect(c, x - s * 0.05, y - s * 0.2, s * 0.13, s * 0.05, s * 0.008); c.fill(); },
  scanner: (c, [, [x, y]], s, t) => { c.fillStyle = '#C8A26B'; c.fillRect(x - s * 0.24, y - s * 0.16, s * 0.2, s * 0.17); c.strokeStyle = '#A07A45'; c.lineWidth = s * 0.008; c.strokeRect(x - s * 0.24, y - s * 0.16, s * 0.2, s * 0.17); c.fillStyle = '#E8D3A8'; c.fillRect(x - s * 0.17, y - s * 0.16, s * 0.05, s * 0.17); c.fillStyle = '#22223B'; rrect(c, x - s * 0.02, y - s * 0.07, s * 0.05, s * 0.09, s * 0.01); c.fill(); c.fillStyle = Math.sin(t * 8) > 0 ? '#FF3B3B' : '#ff9a9a'; c.fillRect(x - s * 0.012, y - s * 0.06, s * 0.03, s * 0.012); },
  basket: (c, [[lx, ly], [x, y]], s) => { const cx = (lx + x) / 2, cy = (ly + y) / 2; for (const [dx, col, r] of [[-0.06, '#E63946', 0.035], [0.0, '#FF8C42', 0.04], [0.06, '#06C48F', 0.035], [-0.03, '#FFD23F', 0.03]]) { c.fillStyle = col; c.beginPath(); c.arc(cx + dx * s, cy - s * 0.04, r * s, 0, TAU); c.fill(); } c.fillStyle = '#B5793B'; c.beginPath(); c.moveTo(cx - s * 0.13, cy - s * 0.03); c.lineTo(cx + s * 0.13, cy - s * 0.03); c.lineTo(cx + s * 0.1, cy + s * 0.07); c.lineTo(cx - s * 0.1, cy + s * 0.07); c.fill(); c.strokeStyle = '#8A5A2B'; c.lineWidth = s * 0.006; for (let i = 0; i < 3; i++) { c.beginPath(); c.moveTo(cx - s * 0.12, cy + i * s * 0.03); c.lineTo(cx + s * 0.12, cy + i * s * 0.03); c.stroke(); } },
  wrench: (c, [, [x, y]], s) => { lineTool(c, [[x, y + s * 0.03], [x + s * 0.03, y - s * 0.18]], '#ADB5BD', s * 0.03); c.strokeStyle = '#ADB5BD'; c.lineWidth = s * 0.028; c.beginPath(); c.arc(x + s * 0.035, y - s * 0.2, s * 0.04, 0.6, TAU - 0.9); c.stroke(); },
  dryer: (c, [, [x, y]], s, t) => { c.fillStyle = '#FF5FA2'; rrect(c, x - s * 0.02, y - s * 0.14, s * 0.2, s * 0.08, s * 0.035); c.fill(); c.fillRect(x - s * 0.01, y - s * 0.08, s * 0.04, s * 0.1); c.fillStyle = '#2B2D42'; c.fillRect(x + s * 0.17, y - s * 0.135, s * 0.025, s * 0.07); c.strokeStyle = rgba('#ffffff', 0.7); c.lineWidth = s * 0.008; for (let i = 0; i < 3; i++) { const p = fr(t * 1.5 + i / 3); c.globalAlpha = 1 - p; c.beginPath(); c.moveTo(x + s * (0.2 + p * 0.12), y - s * (0.13 - i * 0.025)); c.lineTo(x + s * (0.24 + p * 0.12), y - s * (0.13 - i * 0.025)); c.stroke(); } c.globalAlpha = 1; },
};
const vest = (col) => (c, { x, sh, hip, s }) => { c.fillStyle = col; c.fillRect(x - s * 0.125, sh + s * 0.05, s * 0.07, hip - sh - s * 0.02); c.fillRect(x + s * 0.055, sh + s * 0.05, s * 0.07, hip - sh - s * 0.02); c.fillStyle = '#E9ECEF'; c.fillRect(x - s * 0.125, sh + s * 0.2, s * 0.07, s * 0.022); c.fillRect(x + s * 0.055, sh + s * 0.2, s * 0.07, s * 0.022); };
const apron = (col, bib = true) => (c, { x, sh, hip, s }) => { c.fillStyle = col; c.beginPath(); if (bib) { c.moveTo(x - s * 0.07, sh + s * 0.08); c.lineTo(x + s * 0.07, sh + s * 0.08); } else { c.moveTo(x - s * 0.1, hip - s * 0.08); c.lineTo(x + s * 0.1, hip - s * 0.08); } c.lineTo(x + s * 0.11, hip + s * 0.12); c.lineTo(x - s * 0.11, hip + s * 0.12); c.fill(); };
const vneck = (col) => (c, { x, sh, s }) => { c.fillStyle = col; c.beginPath(); c.moveTo(x - s * 0.045, sh - s * 0.005); c.lineTo(x, sh + s * 0.07); c.lineTo(x + s * 0.045, sh - s * 0.005); c.fill(); };
const buttons2 = (c, { x, sh, s }) => { c.fillStyle = '#D9DDE6'; for (const k of [-1, 1]) for (let i = 0; i < 3; i++) { c.beginPath(); c.arc(x + k * s * 0.04, sh + s * (0.08 + i * 0.07), s * 0.011, 0, TAU); c.fill(); } };
const both = (...fs) => (c, g) => fs.forEach((f) => f(c, g));
const HOLD_FRONT = [[Math.PI / 2 + 0.35, -0.2], [Math.PI / 2 - 0.35, Math.PI + 0.2]];
const HOLD_RIGHT = [[Math.PI / 2 + 0.15, Math.PI / 2 + 0.05], [Math.PI / 2 - 0.5, -0.5]];
const HOLD_UP = [[Math.PI / 2 + 0.15, Math.PI / 2 + 0.05], [Math.PI / 2 - 0.6, -1.2]];

/** the trades: name (EN, AR), uniform, hat, hair, tool, pose, workplace */
export const TRADES = {
  nurse: { en: 'Nurse', ar: 'ممرض/ة', top: '#2EC4B6', bottom: '#2EC4B6', hat: HAT.nurse, hair: 'bun', dress: vneck('#25A99D'), tool: TOOL.stetho, toolB: TOOL.clipboard, arms: HOLD_RIGHT, place: 'clinic' },
  caregiver: { en: 'Caregiver', ar: 'مقدّم/ة رعاية', top: '#9B8CFF', bottom: '#5A4FCF', hair: 'hijab', hijab: '#FF8FB1', dress: (c, { x, sh, s }) => { c.fillStyle = '#FF5FA2'; c.beginPath(); const hx = x + s * 0.06, hy = sh + s * 0.1, r = s * 0.025; c.moveTo(hx, hy + r); c.bezierCurveTo(hx - r * 2, hy - r * 0.5, hx - r, hy - r * 2, hx, hy - r * 0.6); c.bezierCurveTo(hx + r, hy - r * 2, hx + r * 2, hy - r * 0.5, hx, hy + r); c.fill(); }, tool: TOOL.towels, arms: HOLD_FRONT, place: 'home' },
  driver: { en: 'Driver', ar: 'سائق', top: '#3A86FF', bottom: '#22223B', hat: HAT.cap('#14213D'), tool: TOOL.wheel, arms: HOLD_FRONT, place: 'road', dress: (c, { x, sh, s }) => { c.fillStyle = '#FFD23F'; c.fillRect(x - s * 0.11, sh + s * 0.06, s * 0.05, s * 0.02); } },
  welder: { en: 'Welder', ar: 'لحّام', top: '#6B7280', bottom: '#374151', hat: HAT.welder, dress: apron('#8D5524'), gloves: '#A0522D', tool: TOOL.torch, arms: HOLD_RIGHT, place: 'workshop' },
  electrician: { en: 'Electrician', ar: 'كهربائي', top: '#FF9F1C', bottom: '#264653', hat: HAT.hard('#FFD60A'), dress: (c, { x, hip, s }) => { c.fillStyle = '#5C4033'; c.fillRect(x - s * 0.13, hip - s * 0.04, s * 0.26, s * 0.04); c.fillStyle = '#ADB5BD'; c.fillRect(x - s * 0.1, hip - s * 0.02, s * 0.02, s * 0.06); c.fillStyle = '#E63946'; c.fillRect(x + s * 0.07, hip - s * 0.02, s * 0.025, s * 0.07); }, tool: TOOL.meter, arms: HOLD_RIGHT, place: 'panel' },
  chef: { en: 'Chef', ar: 'طاهٍ', top: '#FFFFFF', bottom: '#2B2D42', hat: HAT.toque, dress: both(buttons2, (c, { x, sh, s }) => { c.fillStyle = '#E63946'; c.fillRect(x - s * 0.05, sh - s * 0.01, s * 0.1, s * 0.025); }), tool: TOOL.pan, arms: HOLD_RIGHT, place: 'kitchen' },
  waiter: { en: 'Waiter', ar: 'نادل', top: '#FFFFFF', bottom: '#1D1D2B', dress: both((c, { x, sh, hip, s }) => { c.fillStyle = '#1D1D2B'; c.beginPath(); c.moveTo(x - s * 0.125, sh + s * 0.04); c.lineTo(x - s * 0.04, sh + s * 0.04); c.lineTo(x, hip - s * 0.05); c.lineTo(x + s * 0.04, sh + s * 0.04); c.lineTo(x + s * 0.125, sh + s * 0.04); c.lineTo(x + s * 0.115, hip + s * 0.03); c.lineTo(x - s * 0.115, hip + s * 0.03); c.fill(); }, (c, { x, sh, s }) => { c.fillStyle = '#C1121F'; c.beginPath(); c.moveTo(x, sh + s * 0.02); c.lineTo(x - s * 0.04, sh); c.lineTo(x - s * 0.04, sh + s * 0.04); c.closePath(); c.moveTo(x, sh + s * 0.02); c.lineTo(x + s * 0.04, sh); c.lineTo(x + s * 0.04, sh + s * 0.04); c.closePath(); c.fill(); }), sleeve: '#FFFFFF', tool: TOOL.tray, arms: HOLD_UP, place: 'restaurant' },
  housekeeper: { en: 'Housekeeper', ar: 'مدبّر/ة منزل', top: '#4FD8B5', bottom: '#2B2D42', hair: 'bun', hat: HAT.band('#FFFFFF'), dress: apron('#FFFFFF'), tool: TOOL.towels, arms: HOLD_FRONT, place: 'hotel' },
  builder: { en: 'Construction', ar: 'عامل بناء', top: '#3A86FF', bottom: '#5C677D', hat: HAT.hard('#FF8C42'), dress: vest('#FF6B1A'), gloves: '#FFD23F', tool: TOOL.hammer, arms: HOLD_UP, place: 'site' },
  warehouse: { en: 'Warehouse', ar: 'مستودع', top: '#2B9348', bottom: '#264653', hat: HAT.hard('#F8F9FA'), dress: vest('#D9F99D'), tool: TOOL.scanner, arms: HOLD_RIGHT, place: 'warehouse' },
  farm: { en: 'Farm worker', ar: 'عامل زراعي', top: '#D62828', bottom: '#3D5A80', hat: HAT.straw, dress: (c, { x, sh, hip, s }) => { c.strokeStyle = rgba('#ffffff', 0.35); c.lineWidth = s * 0.008; for (let i = 0; i < 5; i++) { c.beginPath(); c.moveTo(x - s * 0.13, sh + s * (0.04 + i * 0.065)); c.lineTo(x + s * 0.13, sh + s * (0.04 + i * 0.065)); c.stroke(); c.beginPath(); c.moveTo(x - s * 0.1 + i * s * 0.05, sh); c.lineTo(x - s * 0.1 + i * s * 0.05, hip); c.stroke(); } }, tool: TOOL.basket, arms: HOLD_FRONT, place: 'farm' },
  mechanic: { en: 'Mechanic', ar: 'ميكانيكي', top: '#1F6FEB', bottom: '#1F6FEB', long: false, hat: HAT.cap('#E63946'), dress: (c, { x, sh, s }) => { c.fillStyle = '#ffffff'; rrect(c, x + s * 0.03, sh + s * 0.07, s * 0.07, s * 0.035, s * 0.008); c.fill(); c.fillStyle = rgba('#000000', 0.25); c.beginPath(); c.arc(x - s * 0.06, sh + s * 0.25, s * 0.02, 0, TAU); c.fill(); }, tool: TOOL.wrench, arms: HOLD_UP, place: 'garage' },
  beautician: { en: 'Beautician', ar: 'خبيرة تجميل', top: '#2B2D42', bottom: '#2B2D42', hair: 'long', hairCol: '#5A3A22', dress: (c, { x, sh, s }) => { c.fillStyle = '#FF5FA2'; c.fillRect(x - s * 0.13, sh + s * 0.02, s * 0.26, s * 0.012); c.fillStyle = '#ffffff'; c.beginPath(); c.arc(x - s * 0.06, sh + s * 0.1, s * 0.012, 0, TAU); c.fill(); }, tool: TOOL.dryer, arms: HOLD_UP, place: 'salon' },
};
export const TRADE_IDS = Object.keys(TRADES);
/** a brief's occupation words → a trade */
export function tradeOf(o) {
  o = String(o || '').toLowerCase();
  return /weld/.test(o) ? 'welder' : /electric/.test(o) ? 'electrician' : /nurse|paramedic/.test(o) ? 'nurse' : /care|elderly|nanny|babysit/.test(o) ? 'caregiver' : /driver|courier|rider|taxi/.test(o) ? 'driver'
    : /chef|cook|baker|kitchen/.test(o) ? 'chef' : /waiter|barista|bartender|steward/.test(o) ? 'waiter' : /housekeep|cleaner|room/.test(o) ? 'housekeeper' : /warehouse|forklift|packer|picker/.test(o) ? 'warehouse'
      : /farm|agri|harvest|fruit/.test(o) ? 'farm' : /mechanic|technician|auto/.test(o) ? 'mechanic' : /beaut|hair|salon|nail|makeup/.test(o) ? 'beautician'
        : /mason|carpenter|labour|builder|construct|scaffold|steel|tiler|plaster|roof|painter|plumb/.test(o) ? 'builder' : null;
}

/** the workplace behind a trade: a full scene inside w×h at the origin */
function place(c, kind, w, h, t, P) {
  const u = Math.min(w, h), sky = (a, b) => A.gradRect(c, 0, 0, w, h, [[0, a], [1, b]]);
  const floor = (y, col) => { c.fillStyle = col; c.fillRect(0, y, w, h - y); };
  switch (kind) {
    case 'clinic': { sky('#DFF7F4', '#BDEDE6'); c.fillStyle = '#ffffff'; rrect(c, w * 0.06, h * 0.12, w * 0.3, h * 0.3, u * 0.02); c.fill(); c.fillStyle = '#9BE7FF'; c.fillRect(w * 0.08, h * 0.14, w * 0.26, h * 0.26); c.fillStyle = '#E63946'; c.fillRect(w * 0.76, h * 0.12, w * 0.04, w * 0.12); c.fillRect(w * 0.72, h * 0.12 + w * 0.04, w * 0.12, w * 0.04); floor(h * 0.78, '#9ED9D0'); c.fillStyle = '#ffffff'; rrect(c, w * 0.62, h * 0.62, w * 0.34, h * 0.08, u * 0.02); c.fill(); c.fillStyle = '#4FB3BF'; c.fillRect(w * 0.62, h * 0.7, w * 0.34, h * 0.03); break; }
    case 'home': { sky('#FFF1E6', '#FFD9C2'); c.fillStyle = '#ffffff'; rrect(c, w * 0.68, h * 0.12, w * 0.24, h * 0.28, u * 0.02); c.fill(); c.fillStyle = '#A8E0FF'; c.fillRect(w * 0.7, h * 0.14, w * 0.2, h * 0.24); floor(h * 0.8, '#E9B98A'); c.fillStyle = '#9B5DE5'; rrect(c, w * 0.05, h * 0.55, w * 0.28, h * 0.25, u * 0.04); c.fill(); c.fillStyle = '#B388F5'; rrect(c, w * 0.08, h * 0.45, w * 0.22, h * 0.14, u * 0.03); c.fill(); c.fillStyle = '#2B9348'; c.beginPath(); c.ellipse(w * 0.88, h * 0.64, w * 0.06, h * 0.08, 0, 0, TAU); c.fill(); c.fillStyle = '#E76F51'; c.fillRect(w * 0.85, h * 0.68, w * 0.06, h * 0.1); break; }
    case 'road': { sky('#7CC6FF', '#D8F0FF'); c.fillStyle = '#9BD770'; c.fillRect(0, h * 0.62, w, h * 0.38); c.fillStyle = '#3D4451'; c.beginPath(); c.moveTo(w * 0.35, h * 0.62); c.lineTo(w * 0.65, h * 0.62); c.lineTo(w * 1.1, h); c.lineTo(-w * 0.1, h); c.fill(); c.fillStyle = '#FFD23F'; for (let i = 0; i < 6; i++) { const k = fr(i / 6 + t * 0.25), y = h * (0.62 + 0.38 * k * k), ww = w * 0.012 * (1 + k * 3); c.fillRect(w / 2 - ww / 2, y, ww, h * 0.03 * (0.4 + k)); } c.fillStyle = '#FF6B4A'; rrect(c, w * 0.04, h * 0.44, w * 0.26, h * 0.14, u * 0.02); c.fill(); c.fillStyle = '#BDE0FE'; for (let i = 0; i < 4; i++) c.fillRect(w * (0.06 + i * 0.06), h * 0.46, w * 0.045, h * 0.05); c.fillStyle = '#22223B'; for (const k of [0.09, 0.25]) { c.beginPath(); c.arc(w * k, h * 0.585, u * 0.025, 0, TAU); c.fill(); } break; }
    case 'workshop': case 'garage': { sky(kind === 'garage' ? '#E7ECF5' : '#3D4451', kind === 'garage' ? '#C9D3E3' : '#1F2633'); c.fillStyle = kind === 'garage' ? '#B8C4D6' : '#4A5568'; for (let i = 0; i < 4; i++) c.fillRect(w * (0.05 + i * 0.24), h * 0.08, w * 0.03, h * 0.6); floor(h * 0.78, kind === 'garage' ? '#8D99AE' : '#2D3748'); if (kind === 'garage') { c.fillStyle = '#E63946'; rrect(c, w * 0.55, h * 0.56, w * 0.42, h * 0.14, u * 0.04); c.fill(); c.fillStyle = '#BDE0FE'; c.fillRect(w * 0.64, h * 0.52, w * 0.2, h * 0.06); c.fillStyle = '#22223B'; for (const k of [0.62, 0.9]) { c.beginPath(); c.arc(w * k, h * 0.71, u * 0.04, 0, TAU); c.fill(); } } else { c.save(); c.globalCompositeOperation = 'lighter'; c.drawImage(A.blob('#FF8C42'), w * 0.5, h * 0.2, w * 0.6, w * 0.6); c.restore(); c.fillStyle = '#718096'; c.fillRect(w * 0.6, h * 0.62, w * 0.36, h * 0.05); } break; }
    case 'panel': { sky('#FFF6D6', '#FFE29A'); c.fillStyle = '#ADB5BD'; rrect(c, w * 0.66, h * 0.14, w * 0.26, h * 0.4, u * 0.02); c.fill(); c.fillStyle = '#495057'; for (let i = 0; i < 6; i++) c.fillRect(w * (0.69 + (i % 3) * 0.075), h * (0.18 + Math.floor(i / 3) * 0.14), w * 0.05, h * 0.1); c.strokeStyle = '#264653'; c.lineWidth = u * 0.012; for (let i = 0; i < 3; i++) { c.beginPath(); c.moveTo(w * (0.7 + i * 0.08), h * 0.54); c.bezierCurveTo(w * (0.7 + i * 0.08), h * 0.7, w * (0.4 - i * 0.1), h * 0.6, w * (0.3 - i * 0.1), h * 0.95); c.stroke(); } c.fillStyle = Math.sin(t * 5) > 0 ? '#06D6A0' : '#0a7'; c.beginPath(); c.arc(w * 0.79, h * 0.5, u * 0.012, 0, TAU); c.fill(); floor(h * 0.84, '#F4A261'); break; }
    case 'kitchen': { sky('#FFFFFF', '#E9F1F7'); c.strokeStyle = '#D6E2EA'; c.lineWidth = u * 0.004; for (let y = 0; y < h * 0.62; y += u * 0.06) { c.beginPath(); c.moveTo(0, y); c.lineTo(w, y); c.stroke(); } for (let x = 0; x < w; x += u * 0.06) { c.beginPath(); c.moveTo(x, 0); c.lineTo(x, h * 0.62); c.stroke(); } c.fillStyle = '#ADB5BD'; c.beginPath(); c.moveTo(w * 0.6, h * 0.04); c.lineTo(w * 0.96, h * 0.04); c.lineTo(w * 1.0, h * 0.22); c.lineTo(w * 0.56, h * 0.22); c.fill(); c.fillStyle = '#6C757D'; c.fillRect(0, h * 0.62, w, h * 0.06); floor(h * 0.68, '#495057'); for (let i = 0; i < 3; i++) { c.fillStyle = '#E63946'; c.beginPath(); c.arc(w * (0.12 + i * 0.12), h * 0.32, u * 0.035, 0, TAU); c.fill(); c.fillRect(w * (0.12 + i * 0.12) - u * 0.004, h * 0.2, u * 0.008, h * 0.1); } break; }
    case 'restaurant': { sky('#FFE8D6', '#F8C9A8'); for (let i = 0; i < 3; i++) { const lx = w * (0.18 + i * 0.32); c.strokeStyle = '#6D4C41'; c.lineWidth = u * 0.004; c.beginPath(); c.moveTo(lx, 0); c.lineTo(lx, h * 0.14); c.stroke(); c.fillStyle = '#FFB703'; c.beginPath(); c.arc(lx, h * 0.17, u * 0.05, Math.PI, 0); c.fill(); c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha = 0.5 + 0.1 * Math.sin(t * 2 + i); c.drawImage(A.blob('#FFD580'), lx - u * 0.15, h * 0.1, u * 0.3, u * 0.3); c.restore(); } floor(h * 0.74, '#B5651D'); c.fillStyle = '#ffffff'; for (const k of [0.1, 0.78]) { c.beginPath(); c.ellipse(w * k, h * 0.7, w * 0.12, h * 0.025, 0, 0, TAU); c.fill(); c.fillStyle = '#8D6E63'; c.fillRect(w * k - u * 0.01, h * 0.7, u * 0.02, h * 0.12); c.fillStyle = '#ffffff'; } break; }
    case 'hotel': { sky('#E8F6FF', '#C9E8FF'); c.fillStyle = '#FFFFFF'; rrect(c, w * 0.55, h * 0.56, w * 0.44, h * 0.16, u * 0.02); c.fill(); c.fillStyle = '#4FD8B5'; c.fillRect(w * 0.55, h * 0.64, w * 0.44, h * 0.08); c.fillStyle = '#8D6E63'; c.fillRect(w * 0.55, h * 0.44, w * 0.06, h * 0.28); c.fillStyle = '#FFC94A'; c.beginPath(); c.moveTo(w * 0.08, h * 0.3); c.lineTo(w * 0.2, h * 0.3); c.lineTo(w * 0.23, h * 0.42); c.lineTo(w * 0.05, h * 0.42); c.fill(); c.fillStyle = '#6D4C41'; c.fillRect(w * 0.135, h * 0.42, u * 0.01, h * 0.2); floor(h * 0.8, '#B8C7D9'); break; }
    case 'site': { sky('#8ED1FC', '#E0F4FF'); c.strokeStyle = '#FFB703'; c.lineWidth = u * 0.014; c.beginPath(); c.moveTo(w * 0.82, h * 0.78); c.lineTo(w * 0.82, h * 0.08); c.lineTo(w * 0.2, h * 0.08); c.moveTo(w * 0.82, h * 0.12); c.lineTo(w * 0.95, h * 0.08); c.stroke(); for (let y = h * 0.12; y < h * 0.78; y += h * 0.06) { c.lineWidth = u * 0.005; c.beginPath(); c.moveTo(w * 0.8, y); c.lineTo(w * 0.84, y + h * 0.06); c.stroke(); } const sw = Math.sin(t * 1.1) * 0.03; c.strokeStyle = '#495057'; c.lineWidth = u * 0.004; c.beginPath(); c.moveTo(w * (0.3 + sw), h * 0.08); c.lineTo(w * (0.3 + sw), h * 0.3); c.stroke(); c.fillStyle = '#E76F51'; c.fillRect(w * (0.24 + sw), h * 0.3, w * 0.12, h * 0.04); c.strokeStyle = '#6C757D'; c.lineWidth = u * 0.008; for (let i = 0; i < 4; i++) { c.beginPath(); c.moveTo(w * 0.02, h * (0.4 + i * 0.1)); c.lineTo(w * 0.3, h * (0.4 + i * 0.1)); c.stroke(); } for (const k of [0.04, 0.28]) { c.beginPath(); c.moveTo(w * k, h * 0.36); c.lineTo(w * k, h * 0.8); c.stroke(); } floor(h * 0.8, '#D4A373'); break; }
    case 'warehouse': { sky('#F1F3F5', '#DEE2E6'); for (let r = 0; r < 2; r++) { const rx = r ? w * 0.68 : w * 0.02, rw = w * 0.3; c.fillStyle = '#1F6FEB'; c.fillRect(rx, h * 0.12, u * 0.012, h * 0.68); c.fillRect(rx + rw, h * 0.12, u * 0.012, h * 0.68); c.fillStyle = '#FF8C42'; for (let k = 0; k < 4; k++) c.fillRect(rx, h * (0.25 + k * 0.17), rw, u * 0.012); for (let k = 0; k < 4; k++) for (let j = 0; j < 3; j++) { c.fillStyle = ['#C8A26B', '#D9B77E', '#B98E52'][(k + j + r) % 3]; c.fillRect(rx + u * 0.02 + j * rw * 0.32, h * (0.25 + k * 0.17) - h * 0.1, rw * 0.28, h * 0.1); } } floor(h * 0.8, '#ADB5BD'); c.fillStyle = '#FFD23F'; for (let i = 0; i < 8; i++) c.fillRect(w * (i * 0.14 - 0.02 + fr(t * 0.05) * 0.14), h * 0.88, w * 0.07, h * 0.015); break; }
    case 'farm': { sky('#9FE3FF', '#FFF3C4'); c.save(); c.globalCompositeOperation = 'lighter'; c.drawImage(A.blob('#FFE066'), w * 0.6, -h * 0.05, u * 0.5, u * 0.5); c.restore(); c.fillStyle = '#FFE066'; c.beginPath(); c.arc(w * 0.85, h * 0.12, u * 0.07, 0, TAU); c.fill(); c.fillStyle = '#80B918'; c.beginPath(); c.moveTo(0, h * 0.6); c.quadraticCurveTo(w * 0.3, h * 0.5, w * 0.6, h * 0.6); c.quadraticCurveTo(w * 0.85, h * 0.66, w, h * 0.56); c.lineTo(w, h); c.lineTo(0, h); c.fill(); c.strokeStyle = '#55A630'; c.lineWidth = u * 0.012; for (let i = -4; i < 10; i++) { c.beginPath(); c.moveTo(w * 0.5, h * 0.6); c.lineTo(w * (0.5 + i * 0.16), h); c.stroke(); } c.fillStyle = '#C1121F'; c.beginPath(); c.moveTo(w * 0.04, h * 0.58); c.lineTo(w * 0.04, h * 0.42); c.lineTo(w * 0.13, h * 0.34); c.lineTo(w * 0.22, h * 0.42); c.lineTo(w * 0.22, h * 0.58); c.fill(); c.fillStyle = '#ffffff'; c.fillRect(w * 0.1, h * 0.48, w * 0.06, h * 0.1); break; }
    case 'salon': { sky('#FFE3EF', '#FFC6DD'); c.fillStyle = '#FFFFFF'; rrect(c, w * 0.62, h * 0.08, w * 0.32, h * 0.44, u * 0.04); c.fill(); c.fillStyle = '#D7F0FF'; rrect(c, w * 0.645, h * 0.1, w * 0.27, h * 0.4, u * 0.03); c.fill(); for (let i = 0; i < 5; i++) { c.fillStyle = Math.sin(t * 3 + i) > -0.6 ? '#FFF3B0' : '#FFE066'; c.beginPath(); c.arc(w * 0.62, h * (0.12 + i * 0.09), u * 0.014, 0, TAU); c.fill(); c.beginPath(); c.arc(w * 0.94, h * (0.12 + i * 0.09), u * 0.014, 0, TAU); c.fill(); } c.fillStyle = '#2B2D42'; rrect(c, w * 0.66, h * 0.62, w * 0.24, h * 0.14, u * 0.03); c.fill(); c.fillRect(w * 0.77, h * 0.76, w * 0.02, h * 0.1); floor(h * 0.86, '#F7A1C4'); break; }
    default: sky(P.paper, mix(P.paper, P.a, 0.2));
  }
}

/** a worker portrait: workplace + the worker in uniform with the trade's tool, from the knees up (box at the origin) */
export function workerPortrait(c, role, w, h, t, P, { pose = 0, skin = 1, scale = 1.3, cx = 0.5, noPlace = false } = {}) {
  const R = TRADES[role] || TRADES.nurse;
  if (!noPlace) place(c, R.place, w, h, t, P);
  const s = Math.min(h * scale, w * 1.9), feet = h + s * 0.32, x = w * cx;
  const breathe = Math.sin(t * 2.1 + pose) * s * 0.004;
  // a soft floor shadow and a halo behind the figure
  c.save(); c.globalAlpha = 0.55; c.drawImage(A.blob('#ffffff'), x - s * 0.35, feet - s * 1.05, s * 0.7, s * 0.7); c.restore();
  return person(c, x, feet + breathe, s, { top: R.top, bottom: R.bottom, hat: R.hat, hair: R.hair, hairCol: R.hairCol, hijab: R.hijab, dress: R.dress, gloves: R.gloves, sleeve: R.sleeve, skin: SKIN[(skin + role.length) % SKIN.length], arms: R.arms, noLegs: false, hold: (cc, hands, ss) => { R.tool?.(cc, hands, ss, t); R.toolB?.(cc, hands, ss, t); } });
}
