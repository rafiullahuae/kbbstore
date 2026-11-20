// KBB Studio · themes · SLIM CONTACT CARDS (20 Nov 2026). The owner: "I didn't like the CTA, i want something nice,
// animated continues and with less height stuff. please make almost 10 different CTAS and with full controls of
// hide-unhide anything or change the colors etc".
//
// Ten one-row bars, half the height of the old cards or less (7:1 to 9:1), each with a motion that LOOPS for the whole
// time it is on screen (not only an entrance): a shine, a ticker, a running light, a ringing phone, a sliding underline,
// flowing colours, a breathing glow, a typewriter, drifting sparkles, a soft neon. Pure function of the item and its
// time (no timers, no state, no canvases made per frame): preview = export.
//
//   style.variant     a CTA2_DESIGNS id (cta.js lists them first; the old 15 designs draw exactly as before)
//   style.ctaLook     { show: {part: bool}, col: {role: '#rrggbb'}, speed: 'slow'|'normal'|'fast', amt: 0..1, round: 0..1 }
//                     every key optional: a part the design shows by default stays on, a colour comes from the company
//                     or theme palette (style.palette) until one of its own is picked
//
// Every word goes through text.js (tx + the art primitives): 'bothLbl' (the label line) and 'phone' (the number) are
// inspector fields, double-click on the video, Text style.
import * as A from './art.js';
import { tx } from './text.js';
import { drawIcon } from './el2-icons.js';

const { clamp, easeOut, easeIO, TAU, mix, rgba, rnd, fr } = A;

/** the slim designs: id, label, shape (w / h), default roundness, the parts that start hidden, a still moment for "Animation off" */
export const CTA2_DESIGNS = [
  { id: 'shimmer', label: 'Glass shimmer', ar: 7, round: 1, off: [], still: 0.42, note: 'Frosted pill, a shine sweeping across it' },
  { id: 'ticker', label: 'Ticker strip', ar: 9, round: 0.4, off: ['border'], still: 0.2, note: 'Call or WhatsApp · your number, scrolling past' },
  { id: 'runner', label: 'Running light', ar: 7.5, round: 1, off: [], still: 0.15, note: 'A light running round an outlined capsule' },
  { id: 'ringing', label: 'Ringing phone', ar: 7, round: 1, off: ['border'], still: 0.3, note: 'The phone rings and ripples, on a loop' },
  { id: 'underline', label: 'Minimal underline', ar: 8, round: 0.3, off: ['bg', 'chips'], still: 0.5, note: 'Just the words, an accent bar sliding beneath' },
  { id: 'flow', label: 'Gradient flow', ar: 8.5, round: 1, off: ['border'], still: 0.3, note: 'Colours flowing along a one-line bar' },
  { id: 'split', label: 'Split chip', ar: 7.4, round: 1, off: ['border'], still: 0.25, note: 'Icon chip + number, a breathing glow' },
  { id: 'typewriter', label: 'Typewriter number', ar: 8, round: 0.5, off: ['border'], still: 0.6, note: 'The number types itself, caret blinking' },
  { id: 'sparkle', label: 'Sparkle bar', ar: 7.5, round: 1, off: ['border'], on: ['sparkles'], still: 0.4, note: 'Tiny sparkles drifting across the bar' },
  { id: 'neon', label: 'Soft neon', ar: 7.5, round: 1, off: [], still: 0.5, note: 'A glowing outline, breathing gently' },
].map((d) => ({ ...d, slim: true }));
export const CTA2_IDS = new Set(CTA2_DESIGNS.map((d) => d.id));

/** what can be shown or hidden (every design has all of them; 'sparkles' starts on only on the Sparkle bar) */
export const CTA2_PARTS = [
  ['phone', '📞 Phone icon'], ['wa', '💬 WhatsApp icon'], ['chips', 'Icon circles'], ['label', 'Label line'], ['number', 'Number'],
  ['bg', 'Background'], ['border', 'Border'], ['glow', 'Glow / shine'], ['sparkles', 'Sparkles'], ['anim', 'Animation'],
];
/** the colours that can be picked */
export const CTA2_COLORS = [['bg', 'Background'], ['text', 'Number'], ['label', 'Label'], ['icon', 'Icon'], ['chip', 'Icon circle'], ['accent', 'Accent / border'], ['glow', 'Glow / sparkles']];
export const CTA2_SPEEDS = { slow: 0.6, normal: 1, fast: 1.6 };
const HEX = /^#[0-9a-f]{6}$/i;

/** the design's own colours from the palette P {bg, bg2, fg, acc, accFg} (before any of his own) */
function baseColors(id, P) {
  const C = { bg: P.bg, text: P.fg, label: mix(P.fg, P.acc, 0.35), icon: P.accFg, chip: P.acc, accent: P.acc, glow: P.acc };
  if (id === 'shimmer') { const ink = A.lum(P.bg) > 0.55 ? mix(P.bg, '#000000', 0.6) : P.bg; Object.assign(C, { bg: mix(P.bg, '#ffffff', 0.8), text: mix(ink, '#000000', 0.35), label: ink, chip: ink, icon: '#ffffff', accent: '#ffffff', glow: mix(P.acc, '#ffffff', 0.3) }); }
  if (id === 'underline') { C.text = '#ffffff'; C.label = mix('#ffffff', P.acc, 0.45); }
  if (id === 'neon') { C.bg = mix(P.bg, '#000000', 0.72); C.text = '#ffffff'; C.label = mix(P.acc, '#ffffff', 0.25); C.accent = mix(P.acc, '#ffffff', 0.2); C.chip = C.accent; C.icon = C.accent; }
  if (id === 'sparkle') C.glow = mix('#ffffff', P.acc, 0.25);
  return C;
}

/** everything a slim card's look depends on: which parts show, the colours, the clock */
export function lookOf(style, des, P) {
  const L = style?.ctaLook || {}, sh = L.show || {}, col = L.col || {};
  const on = (k) => (sh[k] != null ? !!sh[k] : k === 'sparkles' ? !!des.on?.includes(k) : !des.off.includes(k));
  const base = baseColors(des.id, P), C = {};
  for (const k of Object.keys(base)) C[k] = HEX.test(col[k] || '') ? col[k] : base[k];
  // the neon rings and icons are the outline's own light: they follow a picked accent unless given their own
  if (des.id === 'neon') for (const k of ['chip', 'icon']) if (!HEX.test(col[k] || '')) C[k] = C.accent;
  // with the circles off an icon sits on the bar: the circle's colour is its ink (unless he picked an icon colour)
  const ink = on('chips') ? C.icon : HEX.test(col.icon || '') ? col.icon : C.chip;
  const amt = Number.isFinite(+L.amt) && L.amt !== null && L.amt !== '' ? clamp(+L.amt) : 0.6;
  const round = Number.isFinite(+L.round) && L.round !== null && L.round !== '' ? clamp(+L.round) : des.round;
  return { on, C, ink, spd: CTA2_SPEEDS[L.speed] || 1, amt, round, anim: on('anim') };
}

// ── small pieces ──
const ringShake = (t) => { const f = fr(t / 1.6); return f < 0.25 ? Math.sin(f * 4 * TAU * 3) * 0.13 * (1 - f * 4) : 0; };
const shape = (c, x, y, w, h, r) => { c.beginPath(); A.rr(c, x, y, w, h, r); };
function fitSize(c, text, size, weight, maxW, family = A.FONT_UI) {
  c.save(); c.font = `${weight} ${Math.round(size)}px ${family}`; const w = c.measureText(text).width; c.restore();
  return w > maxW ? Math.max(size * 0.45, size * maxW / w) : size;
}
/** a soft drop shadow under the bar (device-pixel sizes: the same look at any preview size) */
function lift(c, ch, a = 0.28) { const k = A.devScale(c); c.shadowColor = `rgba(0,0,0,${a})`; c.shadowBlur = ch * 0.16 * k; c.shadowOffsetY = ch * 0.04 * k; }
function glowOn(c, ch, col, a, blur) { const k = A.devScale(c); c.shadowColor = rgba(col, a); c.shadowBlur = ch * blur * k; c.shadowOffsetX = 0; c.shadowOffsetY = 0; }
function sparkleStar(c, x, y, r) { c.beginPath(); c.moveTo(x, y - r); c.quadraticCurveTo(x, y, x + r, y); c.quadraticCurveTo(x, y, x, y + r); c.quadraticCurveTo(x, y, x - r, y); c.quadraticCurveTo(x, y, x, y - r); c.fill(); }
/** sparkles drifting left to right and twinkling (wrap outside the bar: no pop) */
function sparkles(c, cw, ch, T, L) {
  const n = Math.round(7 + 11 * L.amt);
  c.fillStyle = L.C.glow;
  for (let i = 0; i < n; i++) {
    const v = 0.05 + rnd(i, 1) * 0.07, x = fr(rnd(i, 2) + T * v) * (cw + ch * 0.6) - ch * 0.3, y = ch * (0.16 + 0.68 * rnd(i, 3)) + Math.sin(T * 1.3 + i * 2.1) * ch * 0.05;
    const tw = 0.5 + 0.5 * Math.sin(T * (2.2 + rnd(i, 4) * 2.6) + i * 1.7), r = ch * (0.06 + 0.08 * rnd(i, 5)) * (0.55 + 0.45 * tw);
    c.globalAlpha = 0.25 + 0.75 * tw; sparkleStar(c, x, y, r);
  }
  c.globalAlpha = 1;
}

/** the icons on the left: each a circle (or bare) — returns the x where the words can start, or null with none shown */
function icons(c, L, x0, cy, cr, T, { shake = true, ring = false, bob = true } = {}) {
  const list = [];
  if (L.on('phone')) list.push('phone');
  if (L.on('wa')) list.push('chat');
  if (!list.length) return null;
  const chips = L.on('chips'), step = cr * 2.15;
  list.forEach((id, i) => {
    const cx = x0 + cr + step * i, dy = bob && L.anim ? Math.sin(T * 2.4 + i * 1.4) * cr * 0.06 : 0;
    if (ring && L.on('glow')) {
      // ripples out of the circle, two at a time
      for (const ph of [0, 0.5]) { const p = fr(T / 1.6 + ph + i * 0.25); c.strokeStyle = rgba(L.C.glow, (1 - p) * (0.35 + 0.5 * L.amt)); c.lineWidth = cr * 0.1; c.beginPath(); c.arc(cx, cy, cr * (1 + 0.42 * easeOut(p)), 0, TAU); c.stroke(); }
    }
    c.save(); c.translate(cx, cy + dy);
    if (id === 'phone' && shake && L.anim) c.rotate(ringShake(T + 0.4));
    if (chips) { c.fillStyle = L.C.chip; c.beginPath(); c.arc(0, 0, cr, 0, TAU); c.fill(); }
    drawIcon(c, id, 0, 0, cr * (chips ? 1.12 : 1.5), L.ink, { inner: chips ? L.C.chip : L.C.text });
    c.restore();
  });
  return x0 + cr * 2 + step * (list.length - 1);
}

/**
 * the words: the label over the number (or one of them alone, bigger). Returns the number's {x, w, y, size} (for an
 * underline or a caret). typed: how many characters of the number to show (the typewriter)
 */
function words(c, S, L, W, x0, maxW, ch, { typed = null, shadow = false } = {}) {
  const lab = L.on('label'), nu = L.on('number'), ar = W.ar;
  if (shadow) { const k = A.devScale(c); c.shadowColor = 'rgba(0,0,0,0.55)'; c.shadowBlur = ch * 0.12 * k; c.shadowOffsetY = ch * 0.02 * k; }
  if (lab) {
    const v = tx(S, 'bothLbl', W.word('bothLbl')), size = nu ? ch * 0.17 : ch * 0.25, y = nu ? ch * 0.415 : ch * 0.6;
    if (ar) A.arabicText(c, v, x0 + maxW, y + size * 0.1, size * 1.12, 1, { col: L.C.label, align: 'right', maxW, shadow: false });
    else A.uiText(c, v, x0, y, size, { col: L.C.label, weight: 800, track: 0.13, maxW });
  }
  let out = null;
  if (nu) {
    const full = tx(S, 'phone', W.num), size0 = lab ? ch * 0.33 : ch * 0.4, y = lab ? ch * 0.77 : ch * 0.64, size = fitSize(c, full, size0, 800, maxW);
    const shown = typed == null ? full : full.slice(0, typed);
    const w = shown ? A.uiText(c, shown, x0, y, size, { col: L.C.text, weight: 800, maxW }) : 0;
    out = { x: x0, w, y, size };
  }
  if (shadow) { c.shadowColor = 'rgba(0,0,0,0)'; c.shadowBlur = 0; c.shadowOffsetY = 0; }
  return out;
}

// ── the designs: (c, S, L, W, cw, ch, T) with the bar at 0,0 cw×ch ──
const D = {};

D.shimmer = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round;
  if (L.on('bg')) {
    c.save(); lift(c, ch);
    const g = c.createLinearGradient(0, 0, 0, ch); g.addColorStop(0, rgba(mix(L.C.bg, '#ffffff', 0.25), 0.86)); g.addColorStop(1, rgba(L.C.bg, 0.8));
    c.fillStyle = g; shape(c, 0, 0, cw, ch, r); c.fill(); c.restore();
    // the frosted top half
    c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); c.fillStyle = 'rgba(255,255,255,0.22)'; c.fillRect(0, 0, cw, ch * 0.46); c.restore();
  }
  if (L.on('glow')) {
    // a diagonal shine crossing the bar, again and again
    c.save(); shape(c, 0, 0, cw, ch, r); c.clip();
    const sx = -cw * 0.3 + fr(T / 2.6) * cw * 1.6, g = c.createLinearGradient(sx - ch * 1.2, 0, sx + ch * 1.2, ch * 0.6);
    const a = 0.3 + 0.4 * L.amt, sh = L.C.glow; g.addColorStop(0, rgba(sh, 0)); g.addColorStop(0.5, rgba(sh, a)); g.addColorStop(1, rgba(sh, 0));
    c.fillStyle = g; c.fillRect(0, 0, cw, ch); c.restore();
  }
  if (L.on('border')) { c.strokeStyle = rgba(L.C.accent, 0.9); c.lineWidth = ch * 0.035; shape(c, ch * 0.015, ch * 0.015, cw - ch * 0.03, ch * 0.97, r); c.stroke(); }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  const xi = icons(c, L, ch * 0.16, ch / 2, ch * 0.33, T), x0 = xi == null ? Math.max(ch * 0.4, r * 0.7) : xi + ch * 0.2;
  words(c, S, L, W, x0, cw - x0 - Math.max(ch * 0.3, r * 0.6), ch, { shadow: !L.on('bg') });
};

D.ticker = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round;
  if (L.on('bg')) { c.save(); lift(c, ch); c.fillStyle = L.C.bg; shape(c, 0, 0, cw, ch, r); c.fill(); c.restore(); }
  if (L.on('border')) { c.strokeStyle = L.C.accent; c.lineWidth = ch * 0.035; shape(c, ch * 0.018, ch * 0.018, cw - ch * 0.036, ch * 0.964, r); c.stroke(); }
  const xi = icons(c, L, ch * 0.14, ch / 2, ch * 0.32, T, { bob: false }), xa = xi == null ? Math.max(ch * 0.2, r * 0.5) : xi + ch * 0.12, xb = cw - Math.max(ch * 0.12, r * 0.45);
  if (xi != null) { c.fillStyle = rgba(L.C.accent, 0.8); c.fillRect(xi + ch * 0.05, ch * 0.24, Math.max(1, ch * 0.03), ch * 0.52); }
  // the scrolling line: label · number · label · number … (each copy is a real text slot: tx before every draw)
  const parts = [];
  if (L.on('label')) parts.push(['bothLbl', W.word('bothLbl'), L.C.label, W.ar ? A.FONT_AR : A.FONT_UI]);
  if (L.on('number')) parts.push(['phone', W.num, L.C.text, A.FONT_UI]);
  if (!parts.length || xb - xa < ch) return;
  const size = ch * 0.36, gap = ch * 0.55, y = ch * 0.63;
  const widths = parts.map(([k, d, , fam]) => A.rawText(c, tx(S, k, d), 0, 0, size, { family: fam, weight: 800, measure: true }) || 0);
  const seg = widths.reduce((a, b) => a + b + gap, 0);
  if (seg < 1) return;
  const off = fr(T * ch * 1.1 / seg) * seg;
  c.save(); c.beginPath(); c.rect(xa, 0, xb - xa, ch); c.clip();
  for (let x = xa - off; x < xb; ) {
    parts.forEach(([k, d, col, fam], i) => {
      if (x + widths[i] > xa && x < xb) A.rawText(c, tx(S, k, d), x, y, size, { family: fam, weight: 800, col });
      x += widths[i];
      c.fillStyle = L.C.accent; c.beginPath(); c.arc(x + gap / 2, y - size * 0.34, size * 0.13, 0, TAU); c.fill();
      x += gap;
    });
  }
  // soft edges where the words come in and go (only over the bar's own colour: never a hole in the video)
  if (L.on('bg')) {
    const e = ch * 0.45;
    for (const [x1, x2] of [[xa, xa + e], [xb, xb - e]]) { const g = c.createLinearGradient(x1, 0, x2, 0); g.addColorStop(0, rgba(L.C.bg, 1)); g.addColorStop(1, rgba(L.C.bg, 0)); c.fillStyle = g; c.fillRect(Math.min(x1, x2), 0, e, ch); }
  }
  c.restore();
  if (L.on('glow')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); const p = 0.5 + 0.5 * Math.sin(T * TAU / 2.4); c.fillStyle = rgba(L.C.glow, (0.35 + 0.45 * L.amt) * (0.5 + 0.5 * p)); c.fillRect(0, ch * 0.92, cw, ch * 0.08); c.restore(); }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
};

D.runner = (c, S, L, W, cw, ch, T) => {
  const lw = ch * 0.055, r = ch / 2 * L.round, ri = Math.max(0, Math.min(r - lw / 2, (ch - lw) / 2));
  if (L.on('bg')) { c.save(); lift(c, ch, 0.22); c.fillStyle = rgba(L.C.bg, 0.86); shape(c, 0, 0, cw, ch, r); c.fill(); c.restore(); }
  const path = () => shape(c, lw / 2, lw / 2, cw - lw, ch - lw, ri);
  if (L.on('border')) { c.strokeStyle = rgba(L.C.accent, 0.38); c.lineWidth = lw * 0.7; path(); c.stroke(); }
  // the light: a bright dash going round the outline (dash + gap = the outline's exact length: it loops seamlessly)
  if (L.on('glow') || L.on('border')) {
    const per = 2 * (cw - lw - 2 * ri) + 2 * (ch - lw - 2 * ri) + TAU * ri, len = per * (0.12 + 0.1 * L.amt);
    c.save(); c.lineCap = 'round'; c.lineWidth = lw; c.strokeStyle = L.C.accent;
    if (L.on('glow')) glowOn(c, ch, L.C.glow, 1, 0.22 + 0.25 * L.amt);
    const heads = L.amt > 0.45 ? [0, 0.5] : [0];
    c.setLineDash(heads.length > 1 ? [len, per / 2 - len] : [len, per - len]);
    c.lineDashOffset = -fr(T / 3) * per; path(); c.stroke();
    c.restore();
  }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  const xi = icons(c, L, ch * 0.17, ch / 2, ch * 0.3, T), x0 = xi == null ? Math.max(ch * 0.4, r * 0.7) : xi + ch * 0.2;
  words(c, S, L, W, x0, cw - x0 - Math.max(ch * 0.3, r * 0.6), ch, { shadow: !L.on('bg') });
};

D.ringing = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round;
  if (L.on('bg')) {
    c.save(); lift(c, ch);
    const g = c.createLinearGradient(0, 0, cw, 0); g.addColorStop(0, mix(L.C.bg, '#ffffff', 0.06)); g.addColorStop(1, mix(L.C.bg, L.C.accent, 0.3));
    c.fillStyle = g; shape(c, 0, 0, cw, ch, r); c.fill(); c.restore();
  }
  if (L.on('border')) { c.strokeStyle = L.C.accent; c.lineWidth = ch * 0.035; shape(c, ch * 0.018, ch * 0.018, cw - ch * 0.036, ch * 0.964, r); c.stroke(); }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  const xi = icons(c, L, ch * 0.17, ch / 2, ch * 0.3, T, { ring: true }), x0 = xi == null ? Math.max(ch * 0.4, r * 0.7) : xi + ch * 0.22;
  words(c, S, L, W, x0, cw - x0 - Math.max(ch * 0.3, r * 0.6), ch, { shadow: !L.on('bg') });
};

D.underline = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round;
  if (L.on('bg')) { c.save(); lift(c, ch, 0.2); c.fillStyle = rgba(L.C.bg, 0.82); shape(c, 0, 0, cw, ch, r); c.fill(); c.restore(); }
  if (L.on('sparkles')) sparkles(c, cw, ch, T, L);
  const xi = icons(c, L, ch * 0.06, ch * 0.5, ch * 0.28, T), x0 = xi == null ? ch * 0.12 : xi + ch * 0.2;
  const n = words(c, S, L, W, x0, cw - x0 - ch * 0.15, ch * 0.92, { shadow: !L.on('bg') });
  // the line under the words: a quiet track, a bright bar sliding along it and back
  const lx = xi == null ? x0 : ch * 0.06, tw = Math.max(ch, (n ? n.x + n.w : cw * 0.6) - lx), ly = ch * 0.9, lh = ch * 0.05;
  if (L.on('border')) { c.fillStyle = rgba(L.C.accent, 0.32); c.fillRect(lx, ly, tw, lh); }
  const sw = tw * (0.22 + 0.16 * L.amt), p = easeIO(1 - Math.abs(1 - 2 * fr(T / 3.2)));
  c.save(); if (L.on('glow')) glowOn(c, ch, L.C.glow, 0.85, 0.22 + 0.2 * L.amt);
  c.fillStyle = L.C.accent; c.beginPath(); A.rr(c, lx + (tw - sw) * p, ly - lh * 0.2, sw, lh * 1.4, lh * 0.7); c.fill(); c.restore();
};

D.flow = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round;
  if (L.on('bg')) {
    c.save(); lift(c, ch); c.fillStyle = L.C.bg; shape(c, 0, 0, cw, ch, r); c.fill(); c.restore();
    // one gradient (bar colour → accent → bar colour) tiled twice and sliding: a seamless flow
    const Lw = cw * 1.3, g = c.createLinearGradient(0, 0, Lw, 0), mid = mix(L.C.bg, L.C.accent, 0.25 + 0.5 * L.amt);
    g.addColorStop(0, L.C.bg); g.addColorStop(0.5, mid); g.addColorStop(1, L.C.bg);
    c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); c.fillStyle = g;
    const x0 = -fr(T / 4.5) * Lw;
    for (let k = 0; k < 2; k++) { c.save(); c.translate(x0 + k * Lw, 0); c.fillRect(0, 0, Lw, ch); c.restore(); }
    if (L.on('glow')) { c.fillStyle = rgba(mix('#ffffff', L.C.glow, 0.4), 0.2 + 0.15 * L.amt); c.fillRect(0, 0, cw, ch * 0.42); c.fillStyle = rgba(L.C.glow, 0.7); c.fillRect(0, ch * 0.93, cw, ch * 0.07); }
    c.restore();
  }
  if (L.on('border')) { c.strokeStyle = L.C.accent; c.lineWidth = ch * 0.035; shape(c, ch * 0.018, ch * 0.018, cw - ch * 0.036, ch * 0.964, r); c.stroke(); }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  // one line: icons · LABEL | number
  const xi = icons(c, L, ch * 0.13, ch / 2, ch * 0.3, T), x0 = xi == null ? Math.max(ch * 0.4, r * 0.75) : xi + ch * 0.2, xe = cw - Math.max(ch * 0.3, r * 0.7);
  const shadow = !L.on('bg'), k = A.devScale(c);
  if (shadow) { c.shadowColor = 'rgba(0,0,0,0.55)'; c.shadowBlur = ch * 0.12 * k; }
  let x = x0;
  if (L.on('label')) {
    const v = tx(S, 'bothLbl', W.word('bothLbl')), size = ch * 0.21, mw = L.on('number') ? (xe - x0) * 0.42 : xe - x0;
    if (W.ar) { A.arabicText(c, v, x + mw, ch * 0.6, size * 1.15, 1, { col: L.C.label, align: 'right', maxW: mw, shadow: false }); x += mw; }
    else x += Math.min(mw, A.uiText(c, v, x, ch * 0.585, size, { col: L.C.label, weight: 800, track: 0.12, maxW: mw }) + v.length * size * 0.12);
    if (L.on('number')) { x += ch * 0.2; c.fillStyle = rgba(L.C.label, 0.6); c.fillRect(x, ch * 0.3, Math.max(1, ch * 0.025), ch * 0.4); x += ch * 0.22; }
  }
  if (L.on('number')) { const v = tx(S, 'phone', W.num), mw = xe - x; A.uiText(c, v, x, ch * 0.64, fitSize(c, v, ch * 0.37, 800, mw), { col: L.C.text, weight: 800, maxW: mw }); }
  if (shadow) { c.shadowColor = 'rgba(0,0,0,0)'; c.shadowBlur = 0; }
};

D.split = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round, n = (L.on('phone') ? 1 : 0) + (L.on('wa') ? 1 : 0), bw = n ? ch * (0.42 + 0.62 * n) : 0;
  if (L.on('bg')) {
    c.save();
    if (L.on('glow')) { const b = 0.5 + 0.5 * Math.sin(T * TAU / 2.6); glowOn(c, ch, L.C.glow, 0.55 + 0.35 * L.amt * b, 0.12 + 0.4 * L.amt * b); } else lift(c, ch);
    c.fillStyle = L.C.bg; shape(c, 0, 0, cw, ch, r); c.fill(); c.restore();
  } else if (L.on('glow') && n && L.on('chips')) {
    c.save(); const b = 0.5 + 0.5 * Math.sin(T * TAU / 2.6); glowOn(c, ch, L.C.glow, 0.55 + 0.35 * L.amt * b, 0.12 + 0.4 * L.amt * b);
    c.fillStyle = L.C.chip; shape(c, 0, 0, bw + ch * 0.14, ch, r); c.fill(); c.restore();
  }
  if (n && L.on('chips')) {
    // the icon block: the left end of the bar, its edge slanted
    c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); c.fillStyle = L.C.chip;
    c.beginPath(); c.moveTo(0, 0); c.lineTo(bw + ch * 0.14, 0); c.lineTo(bw - ch * 0.14, ch); c.lineTo(0, ch); c.closePath(); c.fill();
    c.fillStyle = 'rgba(255,255,255,0.14)'; c.fillRect(0, 0, bw + ch * 0.14, ch * 0.45);
    c.strokeStyle = L.C.accent; c.lineWidth = ch * 0.05; c.beginPath(); c.moveTo(bw + ch * 0.14, 0); c.lineTo(bw - ch * 0.14, ch); c.stroke(); c.restore();
  }
  if (L.on('border')) { c.strokeStyle = L.C.accent; c.lineWidth = ch * 0.035; shape(c, ch * 0.018, ch * 0.018, cw - ch * 0.036, ch * 0.964, r); c.stroke(); }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  // the icons, bare on the block (the block is their circle)
  let x = Math.max(ch * 0.2, r * 0.55) - ch * 0.04;
  const ids = []; if (L.on('phone')) ids.push('phone'); if (L.on('wa')) ids.push('chat');
  const ink = L.on('chips') ? L.C.icon : L.ink;
  ids.forEach((id, i) => {
    const s = ch * 0.46, cx = x + s / 2 + i * ch * 0.6, dy = L.anim ? Math.sin(T * 2.4 + i * 1.4) * ch * 0.025 : 0;
    c.save(); c.translate(cx, ch / 2 + dy); if (id === 'phone' && L.anim) c.rotate(ringShake(T + 0.4));
    drawIcon(c, id, 0, 0, s, ink, { inner: L.on('chips') ? L.C.chip : L.C.text }); c.restore();
  });
  const x0 = n ? bw + ch * 0.3 : Math.max(ch * 0.4, r * 0.75);
  words(c, S, L, W, x0, cw - x0 - Math.max(ch * 0.3, r * 0.6), ch, { shadow: !L.on('bg') });
};

D.typewriter = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round;
  if (L.on('bg')) { c.save(); lift(c, ch); c.fillStyle = L.C.bg; shape(c, 0, 0, cw, ch, r); c.fill(); c.restore(); }
  if (L.on('border')) { c.strokeStyle = L.C.accent; c.lineWidth = ch * 0.035; shape(c, ch * 0.018, ch * 0.018, cw - ch * 0.036, ch * 0.964, r); c.stroke(); }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  const xi = icons(c, L, ch * 0.16, ch / 2, ch * 0.31, T), x0 = xi == null ? Math.max(ch * 0.4, r * 0.7) : xi + ch * 0.2;
  // types in (1.6 s), holds, rubs out (0.5 s), a breath, again: 5.4 s a round
  const full = String(S.txt?.phone ?? W.num), f = T % 5.4, N = full.length;
  const typed = !L.anim ? N : f < 1.6 ? Math.floor(N * f / 1.6) + 1 : f < 4.5 ? N : f < 5.0 ? Math.max(0, Math.ceil(N * (1 - (f - 4.5) / 0.5))) : 0;
  const n = words(c, S, L, W, x0, cw - x0 - Math.max(ch * 0.3, r * 0.6), ch, { typed: Math.min(N, typed), shadow: !L.on('bg') });
  if (n && L.on('glow')) {
    // a soft halo where the words are being typed (it follows the caret)
    const hx = n.x + n.w, hy = n.y - n.size * 0.35, g = c.createRadialGradient(hx, hy, 0, hx, hy, ch * 0.55);
    g.addColorStop(0, rgba(L.C.glow, 0.12 + 0.25 * L.amt)); g.addColorStop(1, rgba(L.C.glow, 0));
    c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); c.fillStyle = g; c.fillRect(hx - ch, 0, ch * 2, ch); c.restore();
  }
  if (n && L.anim && (f < 1.6 || f >= 4.5 || fr(T / 0.9) < 0.55)) {
    c.save(); if (L.on('glow')) glowOn(c, ch, L.C.glow, 0.9, 0.15 + 0.15 * L.amt);
    c.fillStyle = L.C.accent; c.fillRect(n.x + n.w + n.size * 0.08, n.y - n.size * 0.8, Math.max(1.5, n.size * 0.09), n.size * 0.95); c.restore();
  }
};

D.sparkle = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round;
  if (L.on('bg')) {
    c.save(); lift(c, ch);
    const g = c.createLinearGradient(0, 0, cw, ch); g.addColorStop(0, mix(L.C.bg, '#ffffff', 0.08)); g.addColorStop(1, mix(L.C.bg, L.C.accent, 0.28));
    c.fillStyle = g; shape(c, 0, 0, cw, ch, r); c.fill(); c.restore();
  }
  if (L.on('glow')) {
    // a soft glow wandering along the bar
    c.save(); shape(c, 0, 0, cw, ch, r); c.clip();
    const gx = cw * (0.5 + 0.42 * Math.sin(T * TAU / 6)), g = c.createRadialGradient(gx, ch * 0.5, 0, gx, ch * 0.5, ch * 1.6);
    g.addColorStop(0, rgba(L.C.glow, 0.18 + 0.22 * L.amt)); g.addColorStop(1, rgba(L.C.glow, 0)); c.fillStyle = g; c.fillRect(0, 0, cw, ch); c.restore();
  }
  if (L.on('border')) { c.strokeStyle = L.C.accent; c.lineWidth = ch * 0.035; shape(c, ch * 0.018, ch * 0.018, cw - ch * 0.036, ch * 0.964, r); c.stroke(); }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  const xi = icons(c, L, ch * 0.16, ch / 2, ch * 0.32, T), x0 = xi == null ? Math.max(ch * 0.4, r * 0.7) : xi + ch * 0.2;
  words(c, S, L, W, x0, cw - x0 - Math.max(ch * 0.3, r * 0.6), ch, { shadow: !L.on('bg') });
};

D.neon = (c, S, L, W, cw, ch, T) => {
  const r = ch / 2 * L.round, lw = ch * 0.04, b = 0.5 + 0.5 * Math.sin(T * TAU / 3);
  if (L.on('bg')) { c.save(); lift(c, ch, 0.3); c.fillStyle = rgba(L.C.bg, 0.86); shape(c, 0, 0, cw, ch, r); c.fill(); c.restore(); }
  if (L.on('border')) {
    c.save(); c.strokeStyle = L.C.accent; c.lineWidth = lw;
    if (L.on('glow')) glowOn(c, ch, L.C.glow, 0.6 + 0.4 * b, (0.12 + 0.3 * L.amt) * (0.6 + 0.4 * b));
    shape(c, lw, lw, cw - lw * 2, ch - lw * 2, Math.max(0, r - lw)); c.stroke();
    c.shadowColor = 'rgba(0,0,0,0)'; c.strokeStyle = rgba('#ffffff', 0.55); c.lineWidth = lw * 0.35; c.stroke(); c.restore();
  }
  if (L.on('sparkles')) { c.save(); shape(c, 0, 0, cw, ch, r); c.clip(); sparkles(c, cw, ch, T, L); c.restore(); }
  // the icons as neon rings (the circle is a ring, not a disc)
  const ids = []; if (L.on('phone')) ids.push('phone'); if (L.on('wa')) ids.push('chat');
  const cr = ch * 0.27, x1 = ch * 0.2 + cr;
  ids.forEach((id, i) => {
    const cx = x1 + i * cr * 2.3;
    c.save(); c.translate(cx, ch / 2); if (L.on('glow')) glowOn(c, ch, L.C.glow, 0.5 + 0.4 * b, 0.12 * (0.5 + L.amt));
    if (id === 'phone' && L.anim) c.rotate(ringShake(T + 0.4));
    if (L.on('chips')) { c.strokeStyle = L.C.chip; c.lineWidth = ch * 0.028; c.beginPath(); c.arc(0, 0, cr, 0, TAU); c.stroke(); }
    drawIcon(c, id, 0, 0, cr * (L.on('chips') ? 1.05 : 1.45), L.C.icon, { inner: L.C.bg }); c.restore();
  });
  const x0 = ids.length ? x1 + (ids.length - 1) * cr * 2.3 + cr + ch * 0.22 : Math.max(ch * 0.4, r * 0.75);
  c.save(); if (L.on('glow')) glowOn(c, ch, L.C.glow, 0.45 + 0.3 * b, 0.1);
  words(c, S, L, W, x0, cw - x0 - Math.max(ch * 0.3, r * 0.6), ch); c.restore();
};

/**
 * draw a slim card into the item's box. W = { num, word(key), ar }. In: a fade and a short rise (0.4 s); out: a fade
 * (0.3 s); between them the design's own loop runs on T = time × speed (Animation off: a still moment of the loop).
 */
export function drawCta2(c, S, des, P, W) {
  const ii = S.inn(0.4), oo = S.out(0.3), k = Math.min(ii, oo); if (k <= 0.001) return;
  const L = lookOf(S.style, des, P), T = L.anim ? S.tl * L.spd : des.still * 4;
  const cw = Math.min(S.w, S.h * des.ar), ch = cw / des.ar, x = (S.w - cw) / 2, y = (S.h - ch) / 2;
  c.save(); c.globalAlpha *= clamp(k);
  c.translate(x, y + (1 - easeOut(S.tl / 0.4)) * ch * 0.35 + (1 - oo) * ch * 0.15);
  (D[des.id] || D.shimmer)(c, S, L, W, cw, ch, T);
  c.restore();
}
