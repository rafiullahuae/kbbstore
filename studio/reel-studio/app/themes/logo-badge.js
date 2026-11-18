// KBB Studio · themes · LOGO BADGE: the company's logo on a plate, in one click (the owner, 18 Nov: "logo we would need
// with one click, in circle, rectangle or square, prepare the logos accordingly").
//
//   theme.el, style.el 'logo', style.variant 'circle' | 'rsquare' | 'square' | 'pill' | 'bare'
//   style.logo / style.logoFull   the brand kit's mark and full logo (⚙ Configure → Companies), copied in when placed
//   style.badge = { corner: 'tl'|'tr'|'bl'|'br'|'free', size: 0.1..0.5 of the frame's short side, ring, shadow,
//                   intro: 'pop'|'fade'|'spin', plate: 'auto'|'light'|'dark'|'brand'|'#rrggbb', pad: 0..0.3 }
//
// Preparing a logo (prepareLogo, once per file, cached): it is drawn into a 512 px canvas, then
//   1. trimmed to what is drawn (transparent edges, or white / near-white margins),
//   2. a solid background (a filled square the logo sits on, an app icon's plate) is removed by flood-filling from the
//      edges — only the colour that touches the border goes, so a white letter inside a dark mark stays,
//   3. measured: how much of its box it fills (for optical padding), its average brightness and colours,
//   4. the plate colour is chosen for contrast: the candidate (white, near-black, the brand's colours) whose contrast with
//      the logo's own pixels is best.
// Drawing reads only that cache (the export waits for it through registerPrepare), so preview = export.
import { registerPrepare } from '../core/render.js';
import * as A from './art.js';
import { tx } from './text.js';

const { clamp, easeOut, back, TAU, mix, rgba, lum, onColor } = A;

export const LOGO_SHAPES = [
  { id: 'circle', label: 'Circle', ar: 1 },
  { id: 'rsquare', label: 'Rounded square', ar: 1 },
  { id: 'square', label: 'Square', ar: 1 },
  { id: 'pill', label: 'Rectangle (pill / banner)', ar: 2.6 },
  { id: 'bare', label: 'Bare (no plate)', ar: 1 },
];
export const shapeById = (id) => LOGO_SHAPES.find((s) => s.id === id) || LOGO_SHAPES[0];
export const BADGE_DEFAULTS = { corner: 'tr', size: 0.2, ring: true, shadow: true, intro: 'pop', plate: 'auto', pad: 0.14 };
export const badgeOf = (s) => Object.assign({}, BADGE_DEFAULTS, s?.badge || {});

// ── preparing ──
const PREP = new Map();   // src -> { ready, out: { cv, w, h, fill, lum, cols, removed, trim, plateAuto } | null }
const urlOf = (src) => (/^(data|blob|https?):/.test(src) ? src : '/' + String(src).split('/').map(encodeURIComponent).join('/'));
const hex2 = (r, g, b) => '#' + [r, g, b].map((v) => Math.round(clamp(v / 255) * 255).toString(16).padStart(2, '0')).join('');
const rl = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
const relLum = (r, g, b) => 0.2126 * rl(r) + 0.7152 * rl(g) + 0.0722 * rl(b);
const contrast = (a, b) => (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
const hexRgb = (h) => { const m = /^#?([0-9a-f]{6})$/i.exec(h || ''); const n = m ? parseInt(m[1], 16) : 0; return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };

/**
 * The pure pixel work (also used by the checks on synthetic logos): rgba = Uint8ClampedArray w×h×4, modified in place.
 * Returns { box:{x,y,w,h}, removed: 'none'|'transparent'|'white'|'solid', bg: '#rrggbb'|null, fill, lum, cols }.
 */
export function prepPixels(px, w, h) {
  const at = (x, y) => (y * w + x) * 4;
  const alphaBox = () => {
    let x0 = w, y0 = h, x1 = -1, y1 = -1;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (px[at(x, y) + 3] > 12) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    return x1 < 0 ? { x: 0, y: 0, w, h } : { x: x0, y: y0, w: x1 - x0 + 1, h: y1 - y0 + 1 };
  };
  let removed = 'none', bg = null;
  let box = alphaBox();
  if (box.w < w || box.h < h) removed = 'transparent';
  // the border of what is drawn: one solid colour on most of it -> a background to flood away
  const border = [];
  for (let x = box.x; x < box.x + box.w; x++) border.push([x, box.y], [x, box.y + box.h - 1]);
  for (let y = box.y; y < box.y + box.h; y++) border.push([box.x, y], [box.x + box.w - 1, y]);
  const opaque = border.filter(([x, y]) => px[at(x, y) + 3] > 200);
  if (opaque.length > border.length * 0.6) {
    // the most common border colour (quantised), then its share of the border
    const hist = new Map();
    for (const [x, y] of opaque) { const i = at(x, y), k = (px[i] >> 4) * 256 + (px[i + 1] >> 4) * 16 + (px[i + 2] >> 4); hist.set(k, (hist.get(k) || 0) + 1); }
    const [kb] = [...hist.entries()].sort((a, b) => b[1] - a[1])[0];
    let sr = 0, sg = 0, sb = 0, n = 0;
    for (const [x, y] of opaque) { const i = at(x, y), k = (px[i] >> 4) * 256 + (px[i + 1] >> 4) * 16 + (px[i + 2] >> 4); if (k === kb) { sr += px[i]; sg += px[i + 1]; sb += px[i + 2]; n++; } }
    const R = sr / n, G2 = sg / n, B = sb / n, TOL = 52, d = (i) => Math.hypot(px[i] - R, px[i + 1] - G2, px[i + 2] - B);
    const share = opaque.filter(([x, y]) => d(at(x, y)) < TOL).length / border.length;
    if (share > 0.7) {
      // flood from every border pixel of that colour; a soft edge between TOL and 1.6 TOL keeps anti-aliasing smooth
      const seen = new Uint8Array(w * h), stack = [];
      for (const [x, y] of border) if (d(at(x, y)) < TOL * 1.6 && px[at(x, y) + 3] > 0) { seen[y * w + x] = 1; stack.push(y * w + x); }
      while (stack.length) {
        const p = stack.pop(), x = p % w, y = (p - x) / w, i = p * 4, dd = d(i);
        if (dd < TOL) px[i + 3] = 0;
        else { px[i + 3] = Math.round(px[i + 3] * clamp((dd - TOL) / (TOL * 0.6))); continue; }   // an edge pixel: soften, stop
        for (const q of [x > 0 ? p - 1 : -1, x < w - 1 ? p + 1 : -1, y > 0 ? p - w : -1, y < h - 1 ? p + w : -1]) {
          if (q < 0 || seen[q]) continue; seen[q] = 1;
          if (px[q * 4 + 3] > 0 && d(q * 4) < TOL * 1.6) stack.push(q);
        }
      }
      bg = hex2(R, G2, B); removed = R > 235 && G2 > 235 && B > 235 ? 'white' : 'solid';
      box = alphaBox();
    }
  }
  // measure what is left: fill share, mean colour and brightness (alpha-weighted), a few dominant colours
  let a = 0, sr = 0, sg = 0, sb = 0, sl = 0; const hist = new Map();
  for (let y = box.y; y < box.y + box.h; y++) for (let x = box.x; x < box.x + box.w; x++) {
    const i = at(x, y), al = px[i + 3] / 255; if (al < 0.05) continue;
    a += al; sr += px[i] * al; sg += px[i + 1] * al; sb += px[i + 2] * al; sl += relLum(px[i], px[i + 1], px[i + 2]) * al;
    const k = ((px[i] >> 5) << 6) | ((px[i + 1] >> 5) << 3) | (px[i + 2] >> 5); hist.set(k, (hist.get(k) || 0) + al);
  }
  const cols = [...hist.entries()].sort((p, q) => q[1] - p[1]).slice(0, 4).map(([k, n]) => ({ col: hex2(((k >> 6) & 7) * 36 + 18, ((k >> 3) & 7) * 36 + 18, (k & 7) * 36 + 18), share: n / Math.max(1, a) }));
  return { box, removed, bg, fill: a / Math.max(1, box.w * box.h), lum: a ? sl / a : 0.5, mean: a ? hex2(sr / a, sg / a, sb / a) : '#808080', cols };
}

/** the plate colour with the best contrast against the logo's own colours (candidates: white, ink, brand colours) */
export function choosePlate(info, brand = {}) {
  const cands = ['#ffffff', '#111318', brand.primary, brand.secondary, brand.soft, brand.text].filter((c) => /^#[0-9a-f]{6}$/i.test(c || ''));
  let best = '#ffffff', bs = -1;
  for (const p of [...new Set(cands.map((c) => c.toLowerCase()))]) {
    const pl = relLum(...hexRgb(p));
    // the worst-served colour of the logo decides (weighted a little by its share), so a two-colour mark stays legible
    let s = 0, worst = 99; for (const { col, share } of info.cols) { const cr = contrast(pl, relLum(...hexRgb(col))); s += cr * share; worst = Math.min(worst, cr); }
    const score = worst * 0.7 + s * 0.3 + (p === '#ffffff' || p === '#111318' ? 0.15 : 0);
    if (score > bs) { bs = score; best = p; }
  }
  return best;
}

export function prepareLogo(src) {
  if (!src) return Promise.resolve(null);
  if (PREP.has(src)) return PREP.get(src).ready;
  const rec = { out: null };
  rec.ready = new Promise((res) => {
    const im = new Image();
    im.onload = () => {
      try {
        // 512 px on the long side (an SVG is rasterised there, a large bitmap scaled down to it)
        const nw = im.naturalWidth || 512, nh = im.naturalHeight || 512, k = 512 / Math.max(nw, nh);
        const sw = Math.max(1, Math.round(nw * k)), sh = Math.max(1, Math.round(nh * k));
        const cv = document.createElement('canvas'); cv.width = sw; cv.height = sh;
        const g = cv.getContext('2d', { willReadFrequently: true }); g.drawImage(im, 0, 0, sw, sh);
        const id = g.getImageData(0, 0, sw, sh), info = prepPixels(id.data, sw, sh);
        g.putImageData(id, 0, 0);
        const out = document.createElement('canvas'); out.width = info.box.w; out.height = info.box.h;
        out.getContext('2d').drawImage(cv, info.box.x, info.box.y, info.box.w, info.box.h, 0, 0, info.box.w, info.box.h);
        rec.out = { cv: out, w: info.box.w, h: info.box.h, ...info, trim: { x: info.box.x, y: info.box.y, from: [sw, sh] } };
      } catch (e) { console.warn('[logo] prepare', e); }
      res(rec.out);
    };
    im.onerror = () => res(null);
    im.src = urlOf(src);
  });
  PREP.set(src, rec);
  return rec.ready;
}
export const preparedLogo = (src) => (src ? PREP.get(src)?.out || null : null);

/** which file a badge shows: the wide logo on a pill (when the kit has one), else the mark */
export const logoSrcOf = (style) => (style?.variant === 'pill' && style?.logoFull ? style.logoFull : style?.logo || style?.logoFull || null);

registerPrepare('el2-logo', async (project) => {
  const jobs = [];
  for (const tr of project.tracks || []) for (const it of tr.items) if (it.type === 'theme.el' && it.style?.el === 'logo') jobs.push(prepareLogo(logoSrcOf(it.style)));
  await Promise.all(jobs);
});

/** the plate colour a badge uses now */
export function plateOf(style, L) {
  const b = badgeOf(style), br = style?.brandColors || {};
  if (/^#[0-9a-f]{6}$/i.test(b.plate)) return b.plate;
  if (b.plate === 'light') return '#ffffff';
  if (b.plate === 'dark') return '#111318';
  if (b.plate === 'brand') return br.primary || '#1d3557';
  return L ? choosePlate(L, br) : '#ffffff';
}

// ── drawing ──
let pending = false;
export function drawLogoBadge(c, K, S) {
  const s = S.style || {}, b = badgeOf(s), shape = shapeById(s.variant), src = logoSrcOf(s), L = preparedLogo(src);
  if (src && !L && !PREP.has(src)) { prepareLogo(src).then(() => { if (!pending) { pending = true; requestAnimationFrame(() => { pending = false; window.kbbEditor?.preview?.draw?.(); }); } }); }
  const { w, h } = S, oo = S.out(0.3);
  // intro: pop (scale with a little overshoot), fade, or spin (a coin turning to face you)
  const ti = clamp(S.tl / 0.6);
  let sc = 1, a = Math.min(oo, 1), sx = 1, rot = 0;
  if (b.intro === 'pop') { sc = 0.2 + 0.8 * back(ti); a *= clamp(ti * 3); }
  else if (b.intro === 'fade') { a *= easeOut(ti); sc = 0.96 + 0.04 * easeOut(ti); }
  else if (b.intro === 'spin') { const k = easeOut(ti); sx = Math.cos((1 - k) * Math.PI * 2); rot = (1 - k) * -0.4; a *= clamp(ti * 4); }
  if (a <= 0.002) return;
  const plate = plateOf(s, L), ring = b.ring ? (lum(plate) > 0.7 ? (s.brandColors?.primary || K.colors.primary) : '#ffffff') : null;
  const bw = Math.min(w, h * shape.ar), bh = bw / shape.ar, x = (w - bw) / 2, y = (h - bh) / 2, ds = A.devScale(c);
  c.save(); c.globalAlpha *= a;
  c.translate(w / 2, h / 2); c.rotate(rot); c.scale(sc * sx || 0.001, sc); c.translate(-w / 2, -h / 2);
  const path = () => {
    c.beginPath();
    if (shape.id === 'circle') c.arc(w / 2, h / 2, bw / 2, 0, TAU);
    else if (shape.id === 'rsquare') A.rr(c, x, y, bw, bh, bw * 0.22);
    else if (shape.id === 'square') c.rect(x, y, bw, bh);
    else if (shape.id === 'pill') A.rr(c, x, y, bw, bh, bh / 2);
  };
  const u = Math.min(bw, bh);
  if (shape.id !== 'bare') {
    c.save();
    if (b.shadow) { c.shadowColor = 'rgba(0,0,0,0.38)'; c.shadowBlur = u * 0.14 * ds; c.shadowOffsetY = u * 0.04 * ds; }
    path(); c.fillStyle = plate; c.fill(); c.restore();
    // a soft top light on the plate
    c.save(); path(); c.clip(); const gl = c.createLinearGradient(0, y, 0, y + bh); gl.addColorStop(0, rgba('#ffffff', 0.16)); gl.addColorStop(0.5, rgba('#ffffff', 0)); c.fillStyle = gl; c.fillRect(x, y, bw, bh); c.restore();
    if (ring) { c.save(); path(); c.lineWidth = u * 0.045; c.strokeStyle = ring; c.stroke(); c.restore(); }
  }
  // the logo, fitted with optical padding: inscribed in a circle, inset on a square, and a sparse mark (low fill) a
  // little larger so it carries the same visual weight as a solid one
  if (L) {
    const pad = shape.id === 'bare' ? 0 : clamp(b.pad, 0, 0.35), la = L.w / L.h, boost = 1 + clamp(0.55 - L.fill, 0, 0.4) * 0.35;
    let iw, ih;
    if (shape.id === 'circle') { const d = bw * (1 - pad * 2); iw = d * la / Math.hypot(la, 1); ih = d / Math.hypot(la, 1); }
    else { iw = bw * (1 - pad * 2) - (shape.id === 'pill' ? bh * 0.3 : 0); ih = bh * (1 - pad * 2); }
    let lw = iw, lh = lw / la; if (lh > ih) { lh = ih; lw = lh * la; }
    lw *= boost; lh *= boost; if (lw > iw * 1.06) { const k = iw * 1.06 / lw; lw *= k; lh *= k; } if (lh > ih * 1.06) { const k = ih * 1.06 / lh; lw *= k; lh *= k; }
    c.save();
    if (shape.id === 'bare' && b.shadow) { c.shadowColor = 'rgba(0,0,0,0.45)'; c.shadowBlur = u * 0.06 * ds; c.shadowOffsetY = u * 0.02 * ds; }
    if (shape.id !== 'bare') { path(); c.clip(); }
    c.drawImage(L.cv, w / 2 - lw / 2, h / 2 - lh / 2, lw, lh);
    c.restore();
  } else if (!src || PREP.get(src)?.out === null) {
    // no logo in the kit (or it failed to load): the company's initial, editable
    const name = tx(S, 'brand', (s.brand || 'Logo').trim());
    A.uiText(c, name.slice(0, 2).toUpperCase(), w / 2, h / 2 + u * 0.12, u * 0.34, { col: onColor(plate), weight: 900, align: 'center', maxW: bw * 0.8 });
  }
  c.restore();
}

/** the badge's box for a corner, inside the platform's safe area (canvas px) */
export function logoBox(variant, corner, size, W, H, aspect) {
  const sh = shapeById(variant), u = Math.min(W, H), s = clamp(+size || BADGE_DEFAULTS.size, 0.06, 0.6) * u;
  const bw = Math.round(sh.ar > 1 ? s * Math.min(sh.ar, 2.2) : s), bh = Math.round(sh.ar > 1 ? bw / sh.ar : s);
  const tall = aspect === '9:16' || H > W * 1.3;
  // inside the action-safe box (9:16: clear of the top bar and the right-hand buttons' column)
  const sx = W * (tall ? 0.06 : 0.05), sy = H * (tall ? 0.08 : 0.05), ex = W - W * (tall ? 0.12 : 0.05), ey = H - H * (tall ? 0.2 : 0.05);
  const left = corner === 'tl' || corner === 'bl', top = corner === 'tl' || corner === 'tr';
  return { x: Math.round(left ? sx : ex - bw), y: Math.round(top ? sy : ey - bh), w: bw, h: bh };
}
export { mix };
