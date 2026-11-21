// Ad design, rendered in the browser on <canvas> from the shop's own product
// photos (cut out by a local segmentation model, cutout.js) over original
// procedural art (art.js) -- or over an AI-generated, product-free scene
// (generators.js) with the REAL product composited on top.
//
// Ten families x three formats x Arabic/English. Arabic first: El Messiri /
// Cairo / Tajawal, right-aligned RTL, no kashida (tatweel is stripped, no
// letter-spacing on Arabic), Arabic-Indic or Latin digits per design.
// Reels/Stories (9:16): nothing written in the top 14% or the bottom 20%.
// Every draw records a layout (product boxes, text boxes with their colours)
// that quality.js scores; the same draw() animates (t = entrance 0..1,
// time = seconds) for motion ads.
import { img, blobURL } from '../api.js';
import { cut } from './cutout.js';
import * as K from './art.js';

export const FORMATS = { '1:1': [1080, 1080], '4:5': [1080, 1350], '9:16': [1080, 1920] };
export const FAMILIES = ['hero', 'glow', 'ingredient', 'offer', 'routine', 'editorial', 'petals', 'texture', 'qa', 'collection'];
export const TEMPLATES = FAMILIES; // older name
export const FAMILY_LABELS = {
  hero: ['Hero on a plinth', 'المنتج البطل'], glow: ['Glass-skin glow', 'توهّج زجاجي'], ingredient: ['Ingredient callouts', 'المكوّن البطل'],
  offer: ['Price spotlight', 'السعر في الواجهة'], routine: ['Routine steps', 'خطوات الروتين'], editorial: ['Editorial', 'تحريري فاخر'],
  petals: ['Petals & silk', 'بتلات وحرير'], texture: ['Texture swatch', 'القوام'], qa: ['Question & answer', 'سؤال وجواب'], collection: ['Collection', 'مجموعة'],
};
const ALIAS = { clean: 'hero' };

// ---------- fonts ----------
let fontsReady = null;
export function loadFonts() {
  if (!fontsReady) {
    const extra = [['El Messiri', 'el-messiri-arabic-wght-normal.woff2', 'U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF, U+200C-200F'], ['El Messiri', 'el-messiri-latin-wght-normal.woff2', 'U+0000-00FF']];
    const base = new URL('../../vendor/fonts/', import.meta.url).href;
    for (const [fam, file, range] of extra) {
      try { const f = new FontFace(fam, `url(${base}${file}) format("woff2")`, { weight: '400 700', unicodeRange: range }); document.fonts.add(f); f.load().catch(() => {}); } catch {}
    }
    fontsReady = Promise.all(['700 60px "El Messiri"', '600 60px "El Messiri"', '800 60px Cairo', '700 60px Cairo', '400 40px Tajawal', '700 40px Tajawal', '600 60px "Playfair Display"', '700 40px "Plus Jakarta Sans"', '400 40px Inter', '600 40px Inter']
      .map((f) => document.fonts.load(f, 'Aa ابج ٠١٢'))).catch(() => {});
  }
  return fontsReady;
}

// ---------- assets ----------
const imgCache = new Map();
function element(u) {
  return new Promise((ok) => {
    const im = new Image();
    im.decoding = 'async';
    im.onload = () => ok(im);
    im.onerror = () => ok(null);
    if (/^(blob:|data:)/.test(u)) im.src = u;
    else blobURL(/^\//.test(u) ? u : img(u)).then((b) => { if (b) im.src = b; else ok(null); });
  });
}
export function loadImage(u) {
  if (!u) return Promise.resolve(null);
  if (!imgCache.has(u)) imgCache.set(u, element(u).then((im) => (im ? cut(u, im) : null)));
  return imgCache.get(u);
}
const bgCache = new Map();
function loadBg(b) {
  if (!b || !b.url) return Promise.resolve(null);
  if (!bgCache.has(b.url)) {
    bgCache.set(b.url, (async () => {
      const u = /^(blob:|data:)/.test(b.url) ? b.url : await blobURL(b.url);
      if (!u) return null;
      if (b.kind === 'video') {
        const v = document.createElement('video');
        v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = u;
        await new Promise((ok) => { v.onloadeddata = ok; v.onerror = ok; });
        return v.videoWidth ? v : null;
      }
      return element(u);
    })());
  }
  return bgCache.get(b.url);
}
// A = [product canvases...] with A.bg (AI background image or video) when set.
export async function assets(spec) {
  await loadFonts();
  const A = await Promise.all((spec.images || []).slice(0, 3).map(loadImage));
  A.bg = await loadBg(spec.bg);
  return A;
}

// ---------- animation + layout state for the current draw ----------
let M = { t: 1, time: 0, noText: false, L: null, s: null };
const ease = (v) => 1 - Math.pow(1 - Math.max(0, Math.min(1, v)), 3);
const seg = (a, b) => ease((M.t - a) / (b - a));

// ---------- text ----------
const TATWEEL = /ـ/g;
const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩';
export function digits(str, s) { const v = String(str || '').replace(TATWEEL, ''); return s.lang === 'ar' && s.numerals !== 'latn' ? v.replace(/[0-9]/g, (d) => AR_DIGITS[d]) : v; }
function face(lang, role) {
  if (lang === 'ar') return { display: '700 {s}px "El Messiri", Cairo', head: '800 {s}px Cairo', body: '500 {s}px Tajawal, Cairo', label: '700 {s}px Tajawal, Cairo', num: '800 {s}px Cairo' }[role];
  return { display: '600 {s}px "Playfair Display"', head: '800 {s}px "Plus Jakarta Sans"', body: '400 {s}px Inter', label: '600 {s}px Inter', num: '800 {s}px "Plus Jakarta Sans"' }[role];
}
function setFont(x, lang, role, size, track = 0) {
  x.font = face(lang, role).replace('{s}', Math.round(size));
  if ('letterSpacing' in x) x.letterSpacing = lang === 'ar' ? '0px' : `${Math.round(size * track)}px`; // never on Arabic: it breaks the joins
}
function wrap(x, text, maxW) {
  const words = String(text || '').split(/\s+/).filter(Boolean), lines = [];
  let cur = '';
  for (const w of words) { const t = cur ? cur + ' ' + w : w; if (x.measureText(t).width <= maxW || !cur) cur = t; else { lines.push(cur); cur = w; } }
  if (cur) lines.push(cur);
  return lines;
}
// Balanced wrap: avoid a lonely last word (fill lines evenly).
function balanced(x, text, maxW, maxLines) {
  let lines = wrap(x, text, maxW);
  if (lines.length > 1 && lines.length <= maxLines) {
    let lo = maxW * 0.5, hi = maxW;
    for (let i = 0; i < 8; i++) { const mid = (lo + hi) / 2; if (wrap(x, text, mid).length <= lines.length) hi = mid; else lo = mid; }
    lines = wrap(x, text, hi);
  }
  return lines;
}
function fit(x, lang, role, text, maxW, size, maxLines, minSize, track = 0) {
  for (let s = size; s >= minSize; s -= Math.max(2, size * 0.04)) { setFont(x, lang, role, s, track); const l = balanced(x, text, maxW, maxLines); if (l.length <= maxLines) return { lines: l, size: s }; }
  setFont(x, lang, role, minSize, track);
  const l = wrap(x, text, maxW);
  if (l.length > maxLines) { l.length = maxLines; l[maxLines - 1] = l[maxLines - 1].replace(/\s+\S*$/, '') + '…'; }
  return { lines: l, size: minSize };
}
const LH = { display: { ar: 1.34, en: 1.06 }, head: { ar: 1.3, en: 1.1 }, body: { ar: 1.45, en: 1.32 }, label: { ar: 1.3, en: 1.2 }, num: { ar: 1.1, en: 1.0 } };

// Draw (or with M.noText only measure+record) a text block. Returns {bottom, w}.
// o: {text, role, size, minSize, maxW, maxLines, x, y, align: left|right|center, color, track, wipe:[a,b], upper}
function text(x, o) {
  const s = M.s, lang = o.lang || s.lang;
  let str = lang === 'ar' ? digits(o.text, s) : String(o.text || '');
  if (o.upper && lang !== 'ar') str = str.toUpperCase();
  if (!str.trim()) return { bottom: o.y, w: 0, h: 0 };
  const f = fit(x, lang, o.role, str, o.maxW, o.size, o.maxLines || 2, o.minSize || o.size * 0.6, o.track || 0);
  const lh = f.size * LH[o.role][lang === 'ar' ? 'ar' : 'en'];
  if (o.measure) return { bottom: o.y + f.lines.length * lh, w: Math.max(...f.lines.map((l) => x.measureText(l).width)), h: f.lines.length * lh, size: f.size };
  const p = o.wipe ? seg(o.wipe[0], o.wipe[1]) : 1;
  x.save();
  x.direction = lang === 'ar' ? 'rtl' : 'ltr';
  x.textAlign = o.align || 'center';
  x.textBaseline = 'alphabetic';
  x.fillStyle = o.color;
  let maxw = 0;
  f.lines.forEach((ln, i) => {
    const w = x.measureText(ln).width, top = o.y + i * lh, base = top + f.size * (lang === 'ar' ? 1.02 : 0.92);
    const left = x.textAlign === 'center' ? o.x - w / 2 : x.textAlign === 'right' ? o.x - w : o.x;
    maxw = Math.max(maxw, w);
    M.L.text.push({ x: left, y: top, w, h: lh, size: f.size, color: o.color, lang, role: o.role, text: ln });
    if (M.noText || p <= 0) return;
    x.save();
    if (p < 1) { // reading-direction wipe: whole words keep their Arabic joins
      const k = Math.max(0, Math.min(1, p * 1.15 - i * 0.12));
      x.globalAlpha *= k;
      x.beginPath();
      if (lang === 'ar') x.rect(left + w * (1 - k) - 4, top - lh, w * k + 8, lh * 3); else x.rect(left - 4, top - lh, w * k + 8, lh * 3);
      x.clip();
    }
    if (o.shadow) { x.shadowColor = o.shadow; x.shadowBlur = f.size * 0.25; }
    x.fillText(ln, o.x, base);
    x.restore();
  });
  x.restore();
  if ('letterSpacing' in x) x.letterSpacing = '0px';
  return { bottom: o.y + f.lines.length * lh, w: maxw, h: f.lines.length * lh, size: f.size };
}
// Pill chip (CTA, price, bullet). Returns its width.
function pill(x, o) {
  const s = M.s, lang = o.lang || s.lang;
  const str = lang === 'ar' ? digits(o.text, s) : String(o.text || '');
  if (!str) return 0;
  setFont(x, lang, o.role || 'label', o.size, lang === 'ar' ? 0 : 0.02);
  const w = Math.min(o.maxW || 1e9, x.measureText(str).width + o.size * (o.padX || 1.1) * 2), h = o.size * (o.hMul || 2.15);
  const left = o.align === 'left' ? o.x : o.align === 'right' ? o.x - w : o.x - w / 2, top = o.y - h / 2;
  const a = o.wipe ? seg(o.wipe[0], o.wipe[1]) : 1;
  M.L.chips.push({ x: left, y: top, w, h, size: o.size, color: o.fg, bg: o.bg, lang, text: str });
  if (M.noText) { x.save(); x.globalAlpha *= a; x.fillStyle = o.bg; x.beginPath(); x.roundRect(left, top, w, h, h / 2); x.fill(); x.restore(); return w; }
  x.save();
  x.globalAlpha *= a;
  if (o.glow) { x.shadowColor = o.glow; x.shadowBlur = h * 0.5; x.shadowOffsetY = h * 0.12; }
  x.fillStyle = o.bg; x.beginPath(); x.roundRect(left, top, w, h, h / 2); x.fill();
  x.shadowColor = 'transparent';
  if (o.stroke) { x.strokeStyle = o.stroke; x.lineWidth = Math.max(1.5, o.size * 0.08); x.stroke(); }
  x.fillStyle = o.fg; x.textAlign = 'center'; x.textBaseline = 'middle'; x.direction = lang === 'ar' ? 'rtl' : 'ltr';
  x.fillText(str, left + w / 2, o.y + o.size * (lang === 'ar' ? 0.08 : 0.04), w - o.size);
  x.restore();
  if ('letterSpacing' in x) x.letterSpacing = '0px';
  return w;
}

// ---------- product ----------
// box: {cx, base (y of the surface it stands on), maxW, maxH}; o: {shadow, reflect, delay, float, glow}
function product(x, im, box, o = {}) {
  const t = seg(o.delay || 0, (o.delay || 0) + 0.55);
  if (!im) {
    const w = box.maxH * 0.45, h = box.maxH * 0.9;
    x.save(); x.fillStyle = 'rgba(198,57,95,.10)'; x.beginPath(); x.roundRect(box.cx - w / 2, box.base - h, w, h, w * 0.2); x.fill(); x.restore();
    M.L.product.push({ x: box.cx - w / 2, y: box.base - h, w, h, placeholder: true });
    return { x: box.cx - w / 2, y: box.base - h, w, h };
  }
  const k = Math.min(box.maxW / im.width, box.maxH / im.height) * (0.94 + 0.06 * t) * (o.scale || 1);
  const w = im.width * k, h = im.height * k;
  const fl = o.float ? Math.sin(M.time * 1.3 + (o.phase || 0)) * h * 0.012 : 0;
  const X = box.cx - w / 2, Y = box.base - h - (1 - t) * h * 0.08 + fl;
  M.L.product.push({ x: X, y: Y, w, h, cutout: !!im.cutout });
  x.save();
  x.globalAlpha *= Math.min(1, t * 1.6) * (o.alpha || 1);
  if (!im.cutout) { // a photo with its own background: present it as a framed print
    x.save(); x.shadowColor = 'rgba(43,31,38,.28)'; x.shadowBlur = w * 0.08; x.shadowOffsetY = w * 0.03;
    x.fillStyle = '#fff'; x.beginPath(); x.roundRect(X - w * 0.025, Y - w * 0.025, w * 1.05, h + w * 0.05, w * 0.05); x.fill(); x.restore();
    x.save(); x.beginPath(); x.roundRect(X, Y, w, h, w * 0.035); x.clip(); x.drawImage(im, X, Y, w, h); x.restore();
    x.restore();
    return { x: X, y: Y, w, h };
  }
  if (o.glow) { const g = x.createRadialGradient(box.cx, Y + h * 0.55, 0, box.cx, Y + h * 0.55, Math.max(w, h) * 0.75); g.addColorStop(0, K.rgba(o.glow, o.glowA || 0.55)); g.addColorStop(1, K.rgba(o.glow, 0)); x.fillStyle = g; x.fillRect(box.cx - Math.max(w, h), Y - h * 0.3, Math.max(w, h) * 2, h * 1.6); }
  if (o.shadow !== false) { // contact shadow + soft cast shadow
    const g = x.createRadialGradient(box.cx, box.base, 0, box.cx, box.base, w * 0.62);
    g.addColorStop(0, 'rgba(43,31,38,.34)'); g.addColorStop(0.5, 'rgba(43,31,38,.12)'); g.addColorStop(1, 'rgba(43,31,38,0)');
    x.fillStyle = g; x.beginPath(); x.ellipse(box.cx, box.base - 2, w * 0.62, Math.max(6, w * 0.075), 0, 0, Math.PI * 2); x.fill();
  }
  if (o.reflect) {
    const r = document.createElement('canvas'); r.width = Math.max(1, Math.round(w)); r.height = Math.max(1, Math.round(Math.min(h * 0.45, o.reflectH || 1e9)));
    const rc = r.getContext('2d');
    rc.translate(0, h); rc.scale(1, -1); rc.drawImage(im, 0, 0, w, h);
    rc.setTransform(1, 0, 0, 1, 0, 0); rc.globalCompositeOperation = 'destination-in';
    const fg = rc.createLinearGradient(0, 0, 0, r.height); fg.addColorStop(0, 'rgba(0,0,0,.28)'); fg.addColorStop(1, 'rgba(0,0,0,0)');
    rc.fillStyle = fg; rc.fillRect(0, 0, r.width, r.height);
    x.drawImage(r, X, box.base + 2 + fl * 0.5, w, r.height);
  }
  x.save();
  x.shadowColor = 'rgba(43,31,38,.20)'; x.shadowBlur = Math.max(8, w * 0.06); x.shadowOffsetY = w * 0.02; x.shadowOffsetX = o.lightFrom === 'right' ? -w * 0.02 : w * 0.02;
  x.drawImage(im, X, Y, w, h);
  x.restore();
  // light sweep across the product during the reveal (motion)
  if (M.t < 1 && t > 0.2) {
    const sw = document.createElement('canvas'); sw.width = Math.max(1, Math.round(w)); sw.height = Math.max(1, Math.round(h));
    const sc = sw.getContext('2d'); sc.drawImage(im, 0, 0, w, h); sc.globalCompositeOperation = 'source-atop';
    const p = (t - 0.2) / 0.8, gx = sc.createLinearGradient(w * (p * 2 - 1), 0, w * (p * 2 - 0.4), h);
    gx.addColorStop(0, 'rgba(255,255,255,0)'); gx.addColorStop(0.5, 'rgba(255,255,255,.55)'); gx.addColorStop(1, 'rgba(255,255,255,0)');
    sc.fillStyle = gx; sc.fillRect(0, 0, w, h);
    x.drawImage(sw, X, Y, w, h);
  }
  x.restore();

  return { x: X, y: Y, w, h };
}

// ---------- shared pieces ----------
const CTA = { SHOP_NOW: ['Shop now', 'تسوّقي الآن'], ORDER_NOW: ['Order now', 'اطلبي الآن'], LEARN_MORE: ['Learn more', 'اعرفي المزيد'], BUY_NOW: ['Buy now', 'اشتري الآن'] };
const cta = (s) => (CTA[s.cta] || CTA.SHOP_NOW)[s.lang === 'ar' ? 1 : 0];
const ar = () => M.s.lang === 'ar';
// zones: the area text may use. 9:16 keeps the top 14% and bottom 20% free (Reels/Stories UI).
function Z(W, H) {
  const tall = H / W > 1.6;
  return tall ? { top: H * 0.152, bot: H * 0.792, m: W * 0.075, tall, sq: false } : { top: H * 0.062, bot: H * 0.945, m: W * 0.068, tall: false, sq: H / W < 1.1 };
}
function eyebrow(s) { return s.eyebrow || (s.badge ? s.badge : ar() ? 'كي بيوتي بليس' : 'K-BEAUTY BLISS'); }
function brandMark(x, W, H, z, color) {
  if (M.noText) return;
  setFont(x, 'en', 'label', W * 0.017, 0.32);
  x.fillStyle = color; x.textAlign = 'center'; x.direction = 'ltr';
  x.globalAlpha = 0.8;
  x.fillText('KBEAUTYBLISS.COM', W / 2, z.tall ? z.top - W * 0.035 : H - W * 0.018);
  x.globalAlpha = 1;
  if ('letterSpacing' in x) x.letterSpacing = '0px';
}
// Eyebrow + headline + sub. align: center | side (reading side). Returns bottom y.
function header(x, s, P, o) {
  const W = M.L.W, side = o.align === 'side';
  const al = side ? (ar() ? 'right' : 'left') : 'center';
  const X = side ? (ar() ? o.x1 : o.x0) : (o.x0 + o.x1) / 2, maxW = o.x1 - o.x0;
  let y = o.y;
  if (o.eyebrow !== false) {
    const r = text(x, { text: o.eyebrowText || eyebrow(s), role: 'label', size: W * (ar() ? 0.03 : 0.021), maxW, maxLines: 1, x: X, y, align: al, color: o.eyebrowColor || P.deep, track: 0.22, upper: true, wipe: [0, 0.3] });
    y = r.bottom + W * 0.012;
  }
  const tallF = M.L.H / M.L.W > 1.6;
  const hd = text(x, { text: s.headline, role: o.role || 'display', size: (o.size || W * 0.088) * (tallF ? 0.9 : 1), minSize: W * 0.05, maxW, maxLines: o.lines || 2, x: X, y, align: al, color: o.color || P.ink, wipe: [0.08, 0.5], shadow: o.shadow });
  y = hd.bottom;
  if (s.sub && o.sub !== false) {
    const sb = text(x, { text: s.sub, role: 'body', size: W * (ar() ? 0.036 : 0.033), maxW: maxW * 0.95, maxLines: 1, x: X, y: y + W * 0.008, align: al, color: o.subColor || P.ink2, wipe: [0.25, 0.65] });
    y = sb.bottom;
  }
  return y;
}
// Bullet chips in a row (reading order). Returns height used.
function chipRow(x, s, P, cx, y, maxW, o = {}) {
  if (o.compact) return 0;
  const W = M.L.W, b = (s.bullets || []).slice(0, o.n || 3).map((v) => String(v).replace(TATWEEL, ''));
  if (!b.length) return 0;
  const size = W * (ar() ? 0.027 : 0.024), gap = W * 0.016;
  setFont(x, s.lang, 'label', size, ar() ? 0 : 0.02);
  let ws = b.map((v) => x.measureText(ar() ? digits(v, s) : v).width + size * 2.2);
  while (ws.reduce((a, v) => a + v, 0) + gap * (ws.length - 1) > maxW && ws.length > 1) { b.pop(); ws.pop(); }
  const total = ws.reduce((a, v) => a + v, 0) + gap * (ws.length - 1);
  let left = cx - total / 2;
  const order = ar() ? [...b.keys()].reverse() : [...b.keys()];
  order.forEach((i, n) => { pill(x, { text: b[i], x: left, y, size, bg: o.bg || 'rgba(255,255,255,.82)', fg: o.fg || P.ink, align: 'left', padX: 1.1, wipe: [0.4 + n * 0.08, 0.75 + n * 0.08], stroke: o.stroke }); left += ws[i] + gap; });
  return size * 2.15;
}
// price + CTA as one chip ("AED 105 · Shop now"), or the CTA alone.
function buy(x, s, P, cx, y, o = {}) {
  const W = M.L.W;
  const label = s.price && o.price !== false ? `${s.price}  ·  ${cta(s)}` : cta(s);
  return pill(x, { text: label, x: cx, y, align: o.align, size: o.size || W * (ar() ? 0.034 : 0.03), bg: o.bg || P.deep, fg: o.fg || '#FFFFFF', glow: o.glow === false ? null : K.rgba(o.bg || P.deep, 0.35), wipe: [0.55, 0.9], stroke: o.stroke, padX: 1.3, role: 'label' });
}
// AI background (image or video frame) when the design has one, else the family's own art.
function backdrop(x, W, H, A, P, own) {
  const b = A && A.bg;
  if (!b) { own(); return false; }
  const bw = b.videoWidth || b.naturalWidth || b.width, bh = b.videoHeight || b.naturalHeight || b.height;
  const k = Math.max(W / bw, H / bh) * (1 + 0.03 * Math.min(1, M.time / 12));
  x.drawImage(b, (W - bw * k) / 2, (H - bh * k) / 2, bw * k, bh * k);
  // scrims for legibility: top for the headline, bottom for the CTA
  const g = x.createLinearGradient(0, 0, 0, H);
  g.addColorStop(0, K.rgba(P.bg1, 0.82)); g.addColorStop(0.3, K.rgba(P.bg1, 0.0)); g.addColorStop(0.75, K.rgba(P.bg1, 0)); g.addColorStop(1, K.rgba(P.bg1, 0.7));
  x.fillStyle = g; x.fillRect(0, 0, W, H);
  return true;
}
// the distinct product photos: the same photo twice (one product named twice, or one set's photo) is drawn ONCE
// (the owner, 21 Nov: the Anua set repeated three times across a routine "looks weird")
const imgs = (A) => [...new Set(A.filter(Boolean))];

// ---------- families ----------
const F = {};

F.hero = (x, s, W, H, A, P) => {
  const z = Z(W, H);
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [P.bg1, P.bg2], 90);
    K.meshBlobs(x, W, H, [P.tint, P.accSoft, '#FFFFFF'], 11, M.time, 0.5);
    const sun = x.createRadialGradient(W / 2, H * 0.5, 0, W / 2, H * 0.5, W * 0.5);
    sun.addColorStop(0, 'rgba(255,255,255,.95)'); sun.addColorStop(0.6, K.rgba(P.accSoft, 0.45)); sun.addColorStop(1, K.rgba(P.accSoft, 0));
    x.fillStyle = sun; x.fillRect(0, 0, W, H);
    K.lightLeak(x, W, H, M.t, M.time, '#FFE3D3', 0.28);
  });
  const compact = z.sq || z.tall;
  const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: z.top, sub: !z.sq, size: W * (z.sq ? 0.078 : 0.088) });
  const footY = z.tall ? z.bot - W * 0.04 : H - W * (z.sq ? 0.065 : 0.085);
  const chipsY = footY - W * (compact ? 0 : 0.095);
  const podTop = chipsY - W * (z.sq ? 0.085 : 0.1);
  K.podium(x, W / 2, podTop, W * (z.sq ? 0.5 : 0.56), z.tall ? H - podTop : W * 0.2, '#F3E6DF', mix2(P), 'stone');
  if (!A.bg) K.ripples(x, W / 2, podTop, W * 0.25, W * 0.04, P.acc, M.time, 4, M.t);
  product(x, A[0], { cx: W / 2, base: podTop + W * 0.012, maxW: W * 0.7, maxH: podTop - hb - W * 0.025 }, { reflect: false, float: true });
  chipRow(x, s, P, W / 2, chipsY, W - z.m * 2, { compact });
  buy(x, s, P, W / 2, footY);
  brandMark(x, W, H, z, P.ink2);
};
const mix2 = (P) => K.mix(P.tint, '#B9A49A', 0.5);

F.glow = (x, s, W, H, A, P) => {
  const z = Z(W, H);
  const floor = z.tall ? H * 0.695 : z.sq ? H * 0.77 : H * 0.71;
  backdrop(x, W, H, A, P, () => {
    const g = x.createRadialGradient(W * 0.5, H * 0.38, 0, W * 0.5, H * 0.45, Math.max(W, H) * 0.8);
    g.addColorStop(0, '#FFF5F7'); g.addColorStop(0.45, K.mix(P.mid, '#FFFFFF', 0.25)); g.addColorStop(1, K.mix(P.mid, P.deep, 0.18));
    x.fillStyle = g; x.fillRect(0, 0, W, H);
    K.meshBlobs(x, W, H, [P.accSoft, '#FFE0D6', '#FFFFFF'], 4, M.time, 0.45);
    K.caustics(x, W, H, H * 0.05, floor, '#FFFFFF', M.time, 8, 0.2);
    K.bokeh(x, W, H, 7, ['#FFFFFF', '#FFE3D6', P.accSoft], 16, M.t, M.time);
    K.lightLeak(x, W, H, M.t, M.time, '#FFD2C6', 0.4);
    K.horizon(x, W, floor, H, K.rgba('#FFFFFF', 0.28), K.rgba(P.mid, 0.2));
  });
  // top: eyebrow + headline (no empty card: the glass card is sized to its words)
  const top = z.top;
  const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: top, color: P.plum, eyebrowColor: P.deep, sub: false });
  const prodTop = hb + W * 0.03;
  product(x, A[0], { cx: W / 2, base: floor, maxW: W * 0.66, maxH: floor - prodTop }, { reflect: true, reflectH: W * 0.03, glow: '#FFFFFF', float: true });
  // glass card: sub + chips + CTA
  const cardY = floor + W * (z.tall ? 0.025 : 0.035), cardH = (z.tall ? z.bot : H - W * 0.035) - cardY;
  K.glass(x, z.m, cardY, W - z.m * 2, cardH, W * 0.045, '#FFFFFF', 0.6);
  let y = cardY + W * (z.tall ? 0.022 : 0.03);
  if (s.sub && cardH > W * 0.19) y = text(x, { text: s.sub, role: 'body', size: W * (ar() ? (z.sq ? 0.034 : 0.038) : (z.sq ? 0.031 : 0.034)), maxW: W - z.m * 3, maxLines: 1, x: W / 2, y, align: 'center', color: P.plum, wipe: [0.3, 0.7] }).bottom + W * 0.012;
  const room = cardY + cardH - y;
  if (room > W * 0.2) { chipRow(x, s, P, W / 2, y + W * 0.035, W - z.m * 3, { bg: 'rgba(255,255,255,.75)', fg: P.plum }); y += W * 0.085; }
  buy(x, s, P, W / 2, Math.min(cardY + cardH - W * 0.06, y + (cardY + cardH - y) / 2), { bg: P.deep });
  brandMark(x, W, H, z, P.plum);
};

F.ingredient = (x, s, W, H, A, P) => {
  const z = Z(W, H), right = ar();
  const word = (s.bullets && s.bullets[0]) || s.angle || '';
  const green = /heartleaf|centella|cica|tea|green|plum|leaf|هارتليف|سنتيلا|شاي/i.test((s.angle || '') + word + (s.headline || ''));
  const leafA = green ? '#7FB68A' : K.mix(P.acc, '#7FB68A', 0.3), leafB = green ? '#C9E4C5' : K.mix(P.accSoft, '#FFFFFF', 0.3);
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [K.mix(P.bg1, leafB, 0.25), P.bg2], 120);
    K.meshBlobs(x, W, H, [leafB, P.accSoft, '#FFFFFF'], 21, M.time, 0.45);
    const r = K.rng(5), pcx = right ? W * 0.3 : W * 0.7;
    for (let i = 0; i < 9; i++) K.leaf(x, pcx + (r() - 0.5) * W * 0.6, H * (0.55 + r() * 0.45), W * (0.16 + r() * 0.16), (r() - 0.5) * 2.2 + Math.sin(M.time * 0.7 + i) * 0.04, K.rgba(leafA, 0.55), K.rgba(leafB, 0.75));
    for (let i = 0; i < 7; i++) K.droplet(x, W * r(), H * (0.25 + r() * 0.6) + M.time * 8 * (i % 2), W * (0.008 + r() * 0.018), leafA, 0.9);
  });
  if (z.tall) {
    const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: z.top, eyebrowText: right ? 'المكوّن البطل' : 'Hero ingredient', sub: false, size: W * 0.08 });
    const base = z.bot - W * 0.095;
    const pr = product(x, A[0], { cx: W / 2, base, maxW: W * 0.6, maxH: base - hb - W * 0.05 }, { float: true });
    callouts(x, s, P, pr, W, [z.m, W - z.m]);
    buy(x, s, P, W / 2, z.bot - W * 0.045);
  } else {
    const colW = W * 0.44, x0 = right ? W - z.m - colW : z.m, x1 = x0 + colW;
    let y = z.top + H * 0.03;
    y = text(x, { text: right ? 'المكوّن البطل' : 'Hero ingredient', role: 'label', size: W * (right ? 0.03 : 0.021), maxW: colW, maxLines: 1, x: right ? x1 : x0, y, align: right ? 'right' : 'left', color: P.deep, track: 0.22, upper: true, wipe: [0, 0.3] }).bottom + W * 0.015;
    y = text(x, { text: word, role: 'display', size: W * 0.1, minSize: W * 0.06, maxW: colW, maxLines: 2, x: right ? x1 : x0, y, align: right ? 'right' : 'left', color: P.plum, wipe: [0.08, 0.45] }).bottom + W * 0.01;
    x.save(); x.strokeStyle = P.gold; x.lineWidth = 3; x.beginPath(); x.moveTo(right ? x1 : x0, y); x.lineTo(right ? x1 - W * 0.1 * seg(0.2, 0.6) : x0 + W * 0.1 * seg(0.2, 0.6), y); x.stroke(); x.restore();
    y += W * 0.03;
    y = text(x, { text: s.headline, role: 'head', size: W * (right ? 0.046 : 0.042), maxW: colW, maxLines: 3, x: right ? x1 : x0, y, align: right ? 'right' : 'left', color: P.ink, wipe: [0.25, 0.6] }).bottom;
    if (s.sub) y = text(x, { text: s.sub, role: 'body', size: W * 0.032, maxW: colW, maxLines: 2, x: right ? x1 : x0, y: y + W * 0.012, align: right ? 'right' : 'left', color: P.ink2, wipe: [0.35, 0.7] }).bottom;
    const pcx = right ? W * 0.29 : W * 0.71, base = H - W * (z.sq ? 0.07 : 0.09);
    const pr = product(x, A[0], { cx: pcx, base, maxW: W * 0.46, maxH: base - z.top - H * 0.02 }, { float: true });
    // benefit chips under the column, gold callout line to the product
    const bl = (s.bullets || []).slice(1, 3);
    let cy = Math.max(y + W * 0.07, H * (z.sq ? 0.66 : 0.62));
    bl.forEach((b, i) => {
      const w = pill(x, { text: b, x: right ? x1 : x0, y: cy, size: W * (right ? 0.029 : 0.026), bg: 'rgba(255,255,255,.88)', fg: P.ink, align: right ? 'right' : 'left', wipe: [0.45 + i * 0.1, 0.8 + i * 0.1] });
      const sx = right ? x1 - w : x0 + w, ex = right ? pr.x + pr.w * 0.85 : pr.x + pr.w * 0.15, ey = pr.y + pr.h * (0.45 + i * 0.18);
      line(x, sx + (right ? -6 : 6), cy, ex, ey, P.gold, seg(0.55 + i * 0.1, 0.9));
      cy += W * 0.085;
    });
    buy(x, s, P, right ? x1 : x0, Math.min(H - W * 0.07, cy + W * 0.03), { align: right ? 'right' : 'left' });
  }
  brandMark(x, W, H, z, P.ink2);
};
function line(x, x0, y0, x1, y1, color, p) {
  if (p <= 0 || M.noText) return;
  x.save(); x.strokeStyle = color; x.fillStyle = color; x.lineWidth = 2.5;
  x.beginPath(); x.moveTo(x0, y0); x.lineTo(x0 + (x1 - x0) * p, y0 + (y1 - y0) * p); x.stroke();
  x.beginPath(); x.arc(x0, y0, 5, 0, Math.PI * 2); x.fill();
  if (p > 0.98) { x.beginPath(); x.arc(x1, y1, 7, 0, Math.PI * 2); x.fill(); x.strokeStyle = '#fff'; x.lineWidth = 2; x.stroke(); }
  x.restore();
}
// callout pills either side of the product (9:16)
function callouts(x, s, P, pr, W, [l, r]) {
  const bl = (s.bullets || []).slice(1, 3);
  bl.forEach((b, i) => {
    const leftSide = (i === 0) !== ar(), y = pr.y + pr.h * (0.3 + i * 0.32);
    const w = pill(x, { text: b, x: leftSide ? l : r, y, size: W * (ar() ? 0.03 : 0.027), bg: 'rgba(255,255,255,.9)', fg: P.ink, align: leftSide ? 'left' : 'right', wipe: [0.45 + i * 0.1, 0.8 + i * 0.1] });
    line(x, leftSide ? l + w + 6 : r - w - 6, y, leftSide ? pr.x + pr.w * 0.25 : pr.x + pr.w * 0.75, y + W * 0.02, P.gold, seg(0.55 + i * 0.1, 0.9));
  });
}

F.offer = (x, s, W, H, A, P) => {
  const z = Z(W, H);
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [K.mix(P.deep, P.plum, 0.55), P.deep, K.mix(P.plum, P.deep, 0.3)], 115);
    K.silk(x, W, H, P.deep, K.mix(P.rose, P.deep, 0.35), M.time, 9, 4);
    K.sparkles(x, W, H, 13, 22, P.goldLight, M.t, M.time);
  });
  // a soft deep scrim behind the heading: the silk highlights and gold sparkles behind small white text (the brand
  // eyebrow) measured 4.0:1 on some photos -- the quality gate refused 4 of 60 -- so the top band is held dark enough
  const scrim = x.createLinearGradient(0, 0, 0, H * 0.42);
  scrim.addColorStop(0, K.rgba(P.deep, 0.62)); scrim.addColorStop(0.7, K.rgba(P.deep, 0.35)); scrim.addColorStop(1, K.rgba(P.deep, 0));
  x.fillStyle = scrim; x.fillRect(0, 0, W, H * 0.42);
  const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: z.top, color: '#FFFFFF', eyebrowColor: '#FFFFFF', subColor: '#FFFFFF', size: W * (z.sq ? 0.078 : 0.088), sub: !(z.sq || z.tall) });
  const footY = z.tall ? z.bot - W * 0.045 : H - W * 0.065;
  const podTop = footY - W * (z.tall ? 0.085 : 0.11);
  const pc = (hb + podTop) / 2;
  x.save(); x.strokeStyle = K.rgba(P.goldLight, 0.85 * seg(0.1, 0.6)); x.lineWidth = W * 0.006; x.beginPath(); x.arc(W / 2, pc, Math.min(W * 0.36, (podTop - hb) * 0.55), 0, Math.PI * 2); x.stroke(); x.restore();
  const halo = x.createRadialGradient(W / 2, pc, 0, W / 2, pc, W * 0.4); halo.addColorStop(0, 'rgba(255,255,255,.55)'); halo.addColorStop(1, 'rgba(255,255,255,0)'); x.fillStyle = halo; x.fillRect(0, hb, W, podTop - hb);
  K.podium(x, W / 2, podTop, W * 0.5, z.tall ? H - podTop : W * 0.16, '#FBE9EE', K.mix(P.deep, '#FFFFFF', 0.6), 'glass');
  const pr = product(x, A[0], { cx: W / 2, base: podTop + W * 0.012, maxW: W * 0.62, maxH: podTop - hb - W * 0.02 }, { float: true });
  if (s.price) { // the price is a fact, never a discount unless a promotion is approved (brain rule)
    const r = W * (z.sq ? 0.105 : 0.115) * seg(0.35, 0.75), bx = ar() ? Math.max(z.m + r, pr.x - r * 0.35) : Math.min(W - z.m - r, pr.x + pr.w + r * 0.35), by = pr.y + r * 0.9;
    if (r > 2) {
      x.save();
      const g = x.createLinearGradient(bx - r, by - r, bx + r, by + r); g.addColorStop(0, '#F3DDB0'); g.addColorStop(0.5, P.gold); g.addColorStop(1, '#9C7A3C');
      x.shadowColor = 'rgba(0,0,0,.25)'; x.shadowBlur = r * 0.3; x.fillStyle = g; x.beginPath(); x.arc(bx, by, r, 0, Math.PI * 2); x.fill(); x.restore();
      x.save(); x.strokeStyle = 'rgba(255,255,255,.6)'; x.lineWidth = 2; x.beginPath(); x.arc(bx, by, r * 0.88, 0, Math.PI * 2); x.stroke(); x.restore();
      const parts = String(s.price).match(/^(\D*)([\d.,]+)(\D*)$/) || [null, '', s.price, ''];
      const cur = (parts[1] + parts[3]).trim();
      text(x, { text: parts[2], role: 'num', size: r * 0.62, maxW: r * 1.6, maxLines: 1, x: bx, y: by - r * 0.52, align: 'center', color: P.plum });
      if (cur) text(x, { text: cur, role: 'label', size: r * 0.26, maxW: r * 1.4, maxLines: 1, x: bx, y: by + r * 0.12, align: 'center', color: P.plum });
    }
  }
  buy(x, s, P, W / 2, footY, { bg: '#FFFFFF', fg: P.deep, price: false, glow: false });
  brandMark(x, W, H, z, 'rgba(255,255,255,.85)');
};

F.routine = (x, s, W, H, A, P) => {
  const z = Z(W, H), im = imgs(A);
  if (im.length < 2) return F.collection(x, s, W, H, A, P); // one product (or one set): one photo on a podium, never repeated
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [P.bg1, K.mix(P.bg2, '#FFFFFF', 0.3)], 90);
    K.meshBlobs(x, W, H, [P.accSoft, '#FFE8DD'], 31, M.time, 0.4);
  });
  const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: z.top, size: W * 0.08 });
  const n = Math.max(1, Math.min(3, im.length)); // one column per DIFFERENT product
  const labelsH = W * 0.11, ledge = (z.tall ? z.bot - W * 0.12 : H - W * 0.12) - labelsH;
  if (!A.bg) K.horizon(x, W, ledge, ledge + W * 0.06, '#F7EEEA', '#EADBD5');
  const colW = (W - z.m * 2) / n;
  for (let i = 0; i < n; i++) {
    const k = ar() ? n - 1 - i : i, cx = z.m + colW * (k + 0.5), d = 0.12 + i * 0.12;
    product(x, im[i], { cx, base: ledge + W * 0.01, maxW: colW * 0.86, maxH: ledge - hb - W * 0.11 }, { delay: d, phase: i });
    const ny = hb + W * 0.06, r = W * 0.03 * seg(d, d + 0.3);
    if (r > 1) { x.save(); x.fillStyle = P.goldLight; x.strokeStyle = P.gold; x.lineWidth = 3; x.beginPath(); x.arc(cx, ny, r, 0, Math.PI * 2); x.fill(); x.stroke(); x.restore(); text(x, { text: String(i + 1), role: 'num', size: W * 0.032, maxW: W * 0.06, maxLines: 1, x: cx, y: ny - W * 0.019, align: 'center', color: P.plum }); }
    const lbl = (s.bullets || [])[i];
    if (lbl) text(x, { text: lbl, role: 'label', size: W * (ar() ? 0.03 : 0.027), maxW: colW * 0.92, maxLines: 2, x: cx, y: ledge + W * 0.035, align: 'center', color: P.ink, wipe: [d + 0.2, d + 0.5] });
  }
  if (n > 1 && !M.noText) { x.save(); x.strokeStyle = K.rgba(P.gold, 0.6); x.setLineDash([8, 10]); x.lineWidth = 2.5; x.beginPath(); x.moveTo(z.m + colW * 0.5 + W * 0.04, hb + W * 0.06); x.lineTo(z.m + colW * (n - 0.5) - W * 0.04, hb + W * 0.06); x.stroke(); x.restore(); }
  buy(x, s, P, W / 2, z.tall ? z.bot - W * 0.045 : H - W * 0.06);
  brandMark(x, W, H, z, P.ink2);
};

F.editorial = (x, s, W, H, A, P) => {
  const z = Z(W, H), cream = '#FFF4EC';
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [K.mix(P.plum, '#000000', 0.15), K.mix(P.deep, P.plum, 0.4)], 100);
    K.silk(x, W, H, P.plum, K.mix(P.deep, '#E9A0B4', 0.35), M.time, 17, 6);
    K.vignette(x, W, H, '#14070C', 0.35);
    const sc = x.createLinearGradient(0, 0, 0, H * 0.5); sc.addColorStop(0, K.rgba(P.plum, 0.7)); sc.addColorStop(1, K.rgba(P.plum, 0)); // headline scrim
    x.fillStyle = sc; x.fillRect(0, 0, W, H * 0.5);
  });
  const ed = ar() ? 'مختارات كي بيوتي' : 'THE K-BEAUTY EDIT';
  if (!M.noText) { x.save(); x.strokeStyle = K.rgba(P.goldLight, 0.8 * seg(0, 0.4)); x.lineWidth = 2; x.beginPath(); x.moveTo(z.m, z.top + W * 0.012); x.lineTo(W - z.m, z.top + W * 0.012); x.stroke(); x.restore(); }
  text(x, { text: ed, role: 'label', size: W * (ar() ? 0.03 : 0.02), maxW: W * 0.6, maxLines: 1, x: W / 2, y: z.top + W * 0.03, align: 'center', color: cream, track: 0.35, wipe: [0, 0.3] });
  const hb = text(x, { text: s.headline, role: 'display', size: W * (z.sq ? 0.085 : z.tall ? 0.088 : 0.1), minSize: W * 0.06, maxW: W - z.m * 2, maxLines: 2, x: W / 2, y: z.top + W * 0.085, align: 'center', color: cream, wipe: [0.05, 0.45], shadow: 'rgba(20,7,12,.45)' }).bottom;
  const compact = z.sq || z.tall;
  const footY = z.tall ? z.bot - W * 0.045 : H - W * 0.065;
  const subY = footY - W * (compact ? 0.0 : 0.1);
  const base = subY - W * (z.tall ? 0.045 : compact ? 0.07 : 0.035);
  product(x, A[0], { cx: W / 2, base, maxW: W * 0.66, maxH: base - hb - W * 0.01 }, { glow: K.mix(P.deep, '#FFFFFF', 0.3), glowA: 0.3, float: true });
  if (s.sub && !compact) text(x, { text: s.sub, role: 'body', size: W * (ar() ? 0.036 : 0.032), maxW: W - z.m * 2, maxLines: 1, x: W / 2, y: subY - W * 0.01, align: 'center', color: K.mix(cream, P.goldLight, 0.3), wipe: [0.35, 0.7] });
  buy(x, s, P, W / 2, footY, { bg: P.goldLight, fg: P.plum, glow: false });
  if (!M.noText) brandMark(x, W, H, z, K.rgba(cream, 0.75));
};

F.petals = (x, s, W, H, A, P) => {
  const z = Z(W, H);
  const pc = [K.mix('#F4A7B9', P.acc, 0.15), '#F9C7D3', '#FFFFFF', K.mix('#E8879F', P.acc, 0.1)];
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, ['#FFF7F8', K.mix('#FBE1E8', P.acc, 0.06)], 90);
    K.silk(x, W, H, '#FBE1E8', '#FFFFFF', M.time, 23, 4);
    K.petals(x, W, H, 41, 12, pc, M.t, M.time, { x: 0, y: H * 0.3, w: W, h: H * 0.75 });
  });
  const compact = z.sq || z.tall;
  const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: z.top, color: P.deep, eyebrowColor: P.deep, sub: !z.sq });
  const footY = z.tall ? z.bot - W * 0.045 : H - W * 0.065;
  const chipsY = footY - W * (compact ? 0 : 0.09);
  const base = chipsY - W * (z.tall ? 0.055 : 0.07);
  const halo = x.createRadialGradient(W / 2, base - W * 0.2, 0, W / 2, base - W * 0.2, W * 0.5); halo.addColorStop(0, 'rgba(255,255,255,.9)'); halo.addColorStop(1, 'rgba(255,255,255,0)'); x.fillStyle = halo; x.fillRect(0, hb, W, base - hb + W * 0.05);
  const r = K.rng(77);
  if (!A.bg) for (let i = 0; i < 14; i++) K.petal(x, W / 2 + (r() - 0.5) * W * 0.55, base + W * 0.01 + (r() - 0.3) * W * 0.04, W * (0.022 + r() * 0.02), r() * 6.28, pc[i % 4], '#FFFFFF', 0.95);
  product(x, A[0], { cx: W / 2, base, maxW: W * 0.66, maxH: base - hb - W * 0.02 }, { float: true });
  if (!A.bg) K.petals(x, W, H, 43, 5, pc, M.t, M.time + 3, { x: 0, y: H * 0.45, w: W, h: H * 0.5 });
  chipRow(x, s, P, W / 2, chipsY, W - z.m * 2, { bg: 'rgba(255,255,255,.88)', fg: P.deep, compact });
  buy(x, s, P, W / 2, footY, { bg: P.rose });
  brandMark(x, W, H, z, P.ink2);
};

F.texture = (x, s, W, H, A, P) => {
  const z = Z(W, H), right = ar();
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [P.bg1, K.mix(P.bg2, P.accSoft, 0.3)], 60);
    K.meshBlobs(x, W, H, [P.accSoft, '#FFFFFF'], 51, M.time, 0.5);
  });
  const compact = z.sq || z.tall;
  const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: z.top, eyebrowText: right ? 'القوام' : 'The texture', size: W * (z.sq ? 0.076 : 0.084), sub: !z.sq });
  const footY = z.tall ? z.bot - W * 0.045 : H - W * 0.065;
  const chipsY = footY - W * (compact ? 0 : 0.09), base = chipsY - W * (z.tall ? 0.05 : 0.06);
  const sw = Math.min(W * 0.36, (base - hb) * 0.42), scx = right ? W * 0.68 : W * 0.32, scy = base - sw * 1.0;
  if (!A.bg) {
    K.swirl(x, scx, scy, sw * (0.7 + 0.3 * seg(0, 0.6)), K.mix('#FBE3EA', P.accSoft, 0.25), K.mix('#F2BFCD', P.acc, 0.12), M.time);
    const r = K.rng(9); for (let i = 0; i < 6; i++) K.droplet(x, scx + (r() - 0.5) * sw * 2, scy + (r() - 0.5) * sw * 1.6, W * (0.01 + r() * 0.015), P.acc, 0.9);
  }
  product(x, A[0], { cx: right ? W * 0.38 : W * 0.62, base, maxW: W * 0.56, maxH: base - hb - W * 0.02 }, { float: true });
  chipRow(x, s, P, W / 2, chipsY, W - z.m * 2, { compact });
  buy(x, s, P, W / 2, footY);
  brandMark(x, W, H, z, P.ink2);
};

F.qa = (x, s, W, H, A, P) => {
  const z = Z(W, H), right = ar();
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [P.bg1, P.bg2], 90);
    K.bokeh(x, W, H, 11, ['#FFFFFF', P.accSoft], 8, M.t, M.time, 1.4);
  });
  const q = s.question || (right ? 'ما الذي يميّزه؟' : 'What makes it special?');
  // question bubble (from the reader), reading side
  setFont(x, s.lang, 'head', W * 0.045);
  const qw = Math.min(W - z.m * 2, x.measureText(digits(q, s)).width + W * 0.12);
  const bx = right ? W - z.m - qw : z.m, by = z.top, bh = W * 0.12;
  if (!M.noText || true) {
    x.save(); x.globalAlpha *= seg(0, 0.3); x.fillStyle = P.plum; x.beginPath(); x.roundRect(bx, by, qw, bh, bh * 0.4); x.fill();
    const tx = right ? bx + qw - W * 0.08 : bx + W * 0.08; x.beginPath(); x.moveTo(tx, by + bh - 2); x.lineTo(tx + (right ? W * 0.02 : -W * 0.02), by + bh + W * 0.035); x.lineTo(tx + (right ? -W * 0.035 : W * 0.035), by + bh - 2); x.fill(); x.restore();
  }
  text(x, { text: q, role: 'head', size: W * 0.045, maxW: qw - W * 0.08, maxLines: 1, x: right ? bx + qw - W * 0.045 : bx + W * 0.045, y: by + bh * 0.2, align: right ? 'right' : 'left', color: '#FFFFFF', wipe: [0.05, 0.35] });
  const top = by + bh + W * 0.06;
  const footY = z.tall ? z.bot - W * 0.045 : H - W * 0.07;
  const base = footY - W * 0.07;
  // product large on the far side, answer card on the reading side
  product(x, A[0], { cx: right ? W * 0.3 : W * 0.7, base, maxW: W * 0.5, maxH: base - top }, { float: true });
  const cw = W * 0.5, cx0 = right ? W - z.m - cw : z.m, cy = top + (base - top) * 0.2;
  const hd = { text: s.headline, role: 'display', size: W * 0.056, minSize: W * 0.04, maxW: cw - W * 0.08, maxLines: 3, align: right ? 'right' : 'left', color: P.ink, wipe: [0.2, 0.55] };
  const bl = (s.bullets || []).slice(0, 3);
  const tx0 = right ? cx0 + cw - W * 0.04 : cx0 + W * 0.04;
  let estH = text(x, Object.assign({}, hd, { x: tx0, y: 0, measure: true })).bottom + W * 0.01;
  for (const b of bl) estH = text(x, { text: '✓ ' + b, role: 'label', size: W * (right ? 0.032 : 0.029), maxW: cw - W * 0.08, maxLines: 1, x: tx0, y: estH, align: right ? 'right' : 'left', measure: true }).bottom + W * 0.008;
  estH += W * 0.06;
  x.save(); x.globalAlpha *= seg(0.15, 0.45); x.shadowColor = 'rgba(43,31,38,.12)'; x.shadowBlur = 40; x.shadowOffsetY = 10; x.fillStyle = 'rgba(255,255,255,.94)'; x.beginPath(); x.roundRect(cx0, cy, cw, estH, W * 0.04); x.fill(); x.restore();
  const tx = right ? cx0 + cw - W * 0.04 : cx0 + W * 0.04;
  let y = text(x, Object.assign(hd, { x: tx, y: cy + W * 0.035 })).bottom + W * 0.01;
  bl.forEach((b, i) => { y = text(x, { text: '✓ ' + b, role: 'label', size: W * (right ? 0.032 : 0.029), maxW: cw - W * 0.08, maxLines: 1, x: tx, y, align: right ? 'right' : 'left', color: P.deep, wipe: [0.35 + i * 0.1, 0.7 + i * 0.1] }).bottom + W * 0.008; });
  buy(x, s, P, W / 2, footY);
  brandMark(x, W, H, z, P.ink2);
};

F.collection = (x, s, W, H, A, P) => {
  const z = Z(W, H), im = imgs(A);
  backdrop(x, W, H, A, P, () => {
    K.wash(x, W, H, [P.bg1, P.bg2], 90);
    K.meshBlobs(x, W, H, [P.accSoft, '#FFFFFF', '#FFE6DA'], 61, M.time, 0.5);
    K.lightLeak(x, W, H, M.t, M.time, '#FFE3D3', 0.25);
  });
  const hb = header(x, s, P, { x0: z.m, x1: W - z.m, y: z.top, eyebrowText: ar() ? 'مجموعة مختارة' : 'The edit', sub: !z.sq });
  const footY = z.tall ? z.bot - W * 0.045 : H - W * 0.065;
  const chipsY = footY - W * 0.09, podTop = chipsY - W * 0.1;
  K.podium(x, W / 2, podTop, W * 0.86, z.tall ? H - podTop : W * 0.12, '#F3E6DF', mix2(P), 'stone');
  const maxH = podTop - hb - W * 0.04, n = Math.max(1, Math.min(3, im.length));
  const order = n === 3 ? [0, 2, 1] : n === 2 ? [1, 0] : [0]; // back ones first, hero (index 0) in front
  const pos = n === 3 ? [0.5, 0.24, 0.76] : n === 2 ? [0.4, 0.62] : [0.5];
  for (const i of order) {
    const front = i === 0, cx = W * (ar() ? 1 - pos[i] : pos[i]);
    product(x, im[i], { cx, base: podTop + W * (front ? 0.03 : 0.0), maxW: W * (n === 1 ? 0.6 : 0.36), maxH: maxH * (front ? 1 : 0.82) }, { delay: front ? 0 : 0.12, phase: i, float: true });
  }
  chipRow(x, s, P, W / 2, chipsY, W - z.m * 2);
  buy(x, s, P, W / 2, footY);
  brandMark(x, W, H, z, P.ink2);
};

// ---------- public ----------
export function familyOf(spec) { const f = ALIAS[spec.template] || spec.template; return F[f] ? f : 'hero'; }
export function themeOf(spec, A) { return K.theme(spec.palette || (A && A[0] && A[0].palette) || []); }

// draw(ctx, spec, W, H, A, t=1, opts{time, noText}) -> layout
export function draw(ctx, spec, W, H, A, t = 1, opts = {}) {
  const s = Object.assign({}, spec, { headline: String(spec.headline || '').replace(TATWEEL, ''), sub: String(spec.sub || '').replace(TATWEEL, '') });
  const P = themeOf(spec, A);
  M = { t, time: opts.time || 0, noText: !!opts.noText, s, L: { W, H, format: H / W > 1.6 ? '9:16' : H / W > 1.15 ? '4:5' : '1:1', lang: s.lang, family: familyOf(spec), product: [], text: [], chips: [], bg: !!(A && A.bg) } };
  ctx.save();
  ctx.clearRect(0, 0, W, H);
  F[familyOf(spec)](ctx, s, W, H, A || [], P);
  K.grain(ctx, W, H, 0.03);
  ctx.restore();
  return M.L;
}

export async function renderSpec(spec, format, canvas, opts = {}) {
  const [W, H] = FORMATS[format] || FORMATS['1:1'];
  const cv = canvas || document.createElement('canvas');
  cv.width = W; cv.height = H;
  const x = cv.getContext('2d', { willReadFrequently: !!opts.read });
  const A = opts.assets || await assets(spec);
  cv.layout = draw(x, spec, W, H, A, 1, opts);
  cv.assets = A;
  return cv;
}

// Carousel: one card per product (1:1), the background runs continuously
// across the cards (swipe reveals one long set), the last card is the offer.
export async function renderCarousel(spec) {
  const A = await assets(spec), im = imgs(A), n = Math.max(1, im.length) + 1, W = 1080, H = 1080;
  const P = themeOf(spec, A), wide = document.createElement('canvas');
  wide.width = W * n; wide.height = H;
  const w = wide.getContext('2d');
  K.wash(w, W * n, H, [P.bg1, P.bg2, P.bg1], 0);
  K.silk(w, W * n, H, P.bg2, '#FFFFFF', 0, 7, 3 + n);
  K.meshBlobs(w, W * n, H, [P.accSoft, '#FFFFFF'], 3, 0, 0.45);
  K.horizon(w, W * n, H * 0.78, H, '#F7EEEA', '#EADBD5');
  const cards = [];
  for (let i = 0; i < n; i++) {
    const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
    const x = cv.getContext('2d');
    const k = spec.lang === 'ar' ? n - 1 - i : i; // Arabic swipes right-to-left
    x.drawImage(wide, k * W, 0, W, H, 0, 0, W, H);
    M = { t: 1, time: 0, noText: false, s: spec, L: { W, H, format: '1:1', lang: spec.lang, family: 'carousel', product: [], text: [], chips: [] } };
    const z = Z(W, H);
    if (i < n - 1) {
      const lbl = (spec.bullets || [])[i] || spec.headline;
      text(x, { text: String(i + 1).padStart(2, '0'), role: 'num', size: W * 0.05, maxW: W * 0.2, maxLines: 1, x: spec.lang === 'ar' ? W - z.m : z.m, y: z.top, align: spec.lang === 'ar' ? 'right' : 'left', color: P.gold });
      text(x, { text: i === 0 ? spec.headline : lbl, role: 'display', size: W * 0.075, minSize: W * 0.05, maxW: W - z.m * 2, maxLines: 2, x: W / 2, y: z.top + W * 0.08, align: 'center', color: P.ink });
      product(x, im[i], { cx: W / 2, base: H * 0.8, maxW: W * 0.6, maxH: H * 0.52 }, { reflect: true });
    } else {
      x.fillStyle = K.rgba(P.deep, 0.92); x.fillRect(0, 0, W, H);
      text(x, { text: spec.sub || spec.headline, role: 'display', size: W * 0.075, maxW: W - z.m * 2, maxLines: 2, x: W / 2, y: H * 0.3, align: 'center', color: '#FFFFFF' });
      if (spec.price) text(x, { text: spec.price, role: 'num', size: W * 0.09, maxW: W * 0.8, maxLines: 1, x: W / 2, y: H * 0.5, align: 'center', color: P.goldLight });
      buy(x, spec, P, W / 2, H * 0.75, { bg: '#FFFFFF', fg: P.deep, price: false });
    }
    K.grain(x, W, H, 0.03);
    cv.layout = M.L;
    cards.push(cv);
  }
  return cards;
}

// quality-gated shipping (quality.js) -- main.js imports renderPending from here
export { renderPending } from './quality.js';

export function toPNG(cv) {
  return new Promise((ok) => cv.toBlob((b) => { const r = new FileReader(); r.onload = () => ok({ b64: String(r.result).split(',')[1], bytes: b.size }); r.readAsDataURL(b); }, 'image/png'));
}

// The GENERATOR HOOK of the old renderer stays: registerGenerator('name',
// fn(spec, format, {W,H}) -> CanvasImageSource) for a fully custom layer.
const generators = {};
export function registerGenerator(name, fn) { generators[name] = fn; }
if (typeof window !== 'undefined') window.KAA_GENERATORS = { register: registerGenerator, list: () => Object.keys(generators) };
