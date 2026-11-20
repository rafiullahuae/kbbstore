// Ad image design, rendered in the browser on <canvas> from the shop's own
// product photos + our templates (original art only). Six template families,
// three formats, Arabic RTL typography. The same draw() animates for motion
// ads (t = 0..1 entrance progress).
//
// GENERATOR HOOK: a future lane plugs AI image/video models in here:
//   registerGenerator('name', async (spec, format, size) => CanvasImageSource)
// and a DesignSpec with spec.generator = 'name' is rendered by it instead of a
// template (its output still gets the safe-zone text layer when
// spec.overlay_text is true). Nothing else in the pipeline changes.
import { api, img, blobURL } from '../api.js';

export const FORMATS = { '1:1': [1080, 1080], '4:5': [1080, 1350], '9:16': [1080, 1920] };
export const TEMPLATES = ['clean', 'glow', 'ingredient', 'offer', 'routine', 'qa'];
const C = { pink: '#E0567B', deep: '#C13E63', plum: '#5A1F35', blush: '#FFF0F4', peach: '#F6D5C2', cream: '#FFF8F5', ink: '#2A2228', ink2: '#5E545A', gold: '#E9C9A4', white: '#FFFFFF' };

const generators = {};
export function registerGenerator(name, fn) { generators[name] = fn; }
if (typeof window !== 'undefined') window.KAA_GENERATORS = { register: registerGenerator, list: () => Object.keys(generators) };

let fontsReady = null;
export function loadFonts() {
  if (!fontsReady) {
    fontsReady = Promise.all(['800 60px Cairo', '700 60px Cairo', '500 40px Cairo', '400 40px Tajawal', '700 40px Tajawal', '600 60px "Playfair Display"', '700 40px "Plus Jakarta Sans"', '400 40px Inter', '600 40px Inter']
      .map((f) => document.fonts.load(f, 'Aa ابج'))).catch(() => {});
  }
  return fontsReady;
}

const imgCache = new Map();
export function loadImage(u) {
  if (!u) return Promise.resolve(null);
  if (!imgCache.has(u)) {
    imgCache.set(u, new Promise((ok) => {
      const im = new Image();
      im.decoding = 'async';
      im.onload = () => ok(cutout(im));
      im.onerror = () => ok(null);
      if (/^(blob:|data:)/.test(u)) im.src = u;
      else blobURL(/^\//.test(u) ? u : img(u)).then((b) => { if (b) im.src = b; else ok(null); });
    }));
  }
  return imgCache.get(u);
}

// Clean product cut-out: remove the near-white studio background that is
// connected to the photo's border (flood fill), feather the edge. Works on a
// <= 900 px copy; returns a canvas with transparency.
function cutout(im) {
  const s = Math.min(1, 900 / Math.max(im.naturalWidth, im.naturalHeight));
  const w = Math.max(1, Math.round(im.naturalWidth * s)), h = Math.max(1, Math.round(im.naturalHeight * s));
  const cv = document.createElement('canvas');
  cv.width = w; cv.height = h;
  const x = cv.getContext('2d', { willReadFrequently: true });
  x.drawImage(im, 0, 0, w, h);
  let d;
  try { d = x.getImageData(0, 0, w, h); } catch { return cv; }
  const p = d.data, seen = new Uint8Array(w * h), q = new Int32Array(w * h);
  const bgish = (i) => { const r = p[i * 4], g = p[i * 4 + 1], b = p[i * 4 + 2]; const mx = Math.max(r, g, b), mn = Math.min(r, g, b); return mx > 228 && mx - mn < 26; };
  let head = 0, tail = 0;
  const push = (i) => { if (!seen[i] && bgish(i)) { seen[i] = 1; q[tail++] = i; } };
  for (let i = 0; i < w; i++) { push(i); push((h - 1) * w + i); }
  for (let j = 0; j < h; j++) { push(j * w); push(j * w + w - 1); }
  while (head < tail) {
    const i = q[head++], cx = i % w, cy = (i - cx) / w;
    if (cx > 0) push(i - 1); if (cx < w - 1) push(i + 1); if (cy > 0) push(i - w); if (cy < h - 1) push(i + w);
  }
  if (tail < w * h * 0.08) return cv; // no white studio background: keep the photo as is
  for (let i = 0; i < w * h; i++) if (seen[i]) p[i * 4 + 3] = 0;
  // feather: half-transparent for pixels next to the removed area
  for (let i = w; i < w * h - w; i++) if (!seen[i] && (seen[i - 1] || seen[i + 1] || seen[i - w] || seen[i + w])) p[i * 4 + 3] = 150;
  x.putImageData(d, 0, 0);
  cv.cutout = true;
  return cv;
}

// ---------- drawing helpers ----------
const ease = (v) => 1 - Math.pow(1 - Math.max(0, Math.min(1, v)), 3);
const seg = (t, a, b) => ease((t - a) / (b - a));
function rr(x, X, Y, W, H, R) { x.beginPath(); x.roundRect ? x.roundRect(X, Y, W, H, R) : x.rect(X, Y, W, H); }
function face(lang, role) {
  if (lang === 'ar') return role === 'head' ? '800 {s}px Cairo' : role === 'serif' ? '700 {s}px Cairo' : role === 'bold' ? '700 {s}px Tajawal' : '400 {s}px Tajawal';
  return role === 'head' ? '800 {s}px "Plus Jakarta Sans"' : role === 'serif' ? '600 {s}px "Playfair Display"' : role === 'bold' ? '600 {s}px Inter' : '400 {s}px Inter';
}
function setFont(x, lang, role, size) { x.font = face(lang, role).replace('{s}', Math.round(size)); }
// wrap text into lines that fit maxW (canvas text metrics, no DOM layout)
function wrap(x, text, maxW, maxLines) {
  const words = String(text || '').split(/\s+/).filter(Boolean);
  const lines = [];
  let cur = '';
  for (const w of words) {
    const tryL = cur ? cur + ' ' + w : w;
    if (x.measureText(tryL).width <= maxW || !cur) cur = tryL; else { lines.push(cur); cur = w; }
  }
  if (cur) lines.push(cur);
  if (lines.length > maxLines) { lines.length = maxLines; lines[maxLines - 1] = lines[maxLines - 1].replace(/\s+\S*$/, '') + '…'; }
  return lines;
}
// fit a headline: shrink until it fits in maxLines
function fitText(x, lang, role, text, maxW, size, maxLines, minSize = 34) {
  for (let s = size; s >= minSize; s -= 4) { setFont(x, lang, role, s); const l = wrap(x, text, maxW, 99); if (l.length <= maxLines) return { lines: l, size: s }; }
  setFont(x, lang, role, minSize);
  return { lines: wrap(x, text, maxW, maxLines), size: minSize };
}
function textBlock(x, lang, lines, cx, y, size, color, align, alpha = 1, lh = 1.18) {
  x.save();
  x.globalAlpha *= alpha;
  x.fillStyle = color;
  x.direction = lang === 'ar' ? 'rtl' : 'ltr';
  x.textAlign = align;
  x.textBaseline = 'alphabetic';
  lines.forEach((l, i) => x.fillText(l, cx, y + size + i * size * lh));
  x.restore();
  return y + lines.length * size * lh + size * 0.25;
}
function pill(x, lang, text, cx, cy, size, bg, fg, align = 'center', padX = 0.9) {
  setFont(x, lang, 'bold', size);
  x.direction = lang === 'ar' ? 'rtl' : 'ltr';
  const w = x.measureText(text).width + size * padX * 2, h = size * 2;
  const left = align === 'center' ? cx - w / 2 : align === 'right' ? cx - w : cx;
  x.fillStyle = bg; rr(x, left, cy - h / 2, w, h, h / 2); x.fill();
  x.fillStyle = fg; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText(text, left + w / 2, cy + size * 0.06);
  x.textBaseline = 'alphabetic';
  return w;
}
function product(x, im, cx, cy, maxW, maxH, t = 1, shadow = true) {
  if (!im) { x.fillStyle = 'rgba(193,62,99,.12)'; rr(x, cx - maxW * 0.18, cy - maxH * 0.4, maxW * 0.36, maxH * 0.8, 40); x.fill(); return; }
  const s = Math.min(maxW / im.width, maxH / im.height) * (0.92 + 0.08 * t);
  const w = im.width * s, h = im.height * s;
  const y = cy - h / 2 + (1 - t) * 60;
  x.save();
  x.globalAlpha *= Math.min(1, t * 1.4);
  if (shadow && im.cutout) {
    const g = x.createRadialGradient(cx, cy + h / 2, 4, cx, cy + h / 2, w * 0.55);
    g.addColorStop(0, 'rgba(42,34,40,.28)'); g.addColorStop(1, 'rgba(42,34,40,0)');
    x.fillStyle = g; x.beginPath(); x.ellipse(cx, cy + h / 2 - 6, w * 0.5, h * 0.06, 0, 0, Math.PI * 2); x.fill();
  }
  if (im.cutout) { x.drawImage(im, cx - w / 2, y, w, h); }
  else { x.save(); rr(x, cx - w / 2, y, w, h, 36); x.clip(); x.drawImage(im, cx - w / 2, y, w, h); x.restore(); }
  x.restore();
}
function bokeh(x, W, H, seed, colors, n = 14, t = 1) {
  let s = seed;
  const r = () => ((s = (s * 9301 + 49297) % 233280) / 233280);
  for (let i = 0; i < n; i++) {
    const cx = r() * W, cy = r() * H + (1 - t) * 40 * (i % 3), rad = (0.03 + r() * 0.11) * W;
    const g = x.createRadialGradient(cx, cy, 0, cx, cy, rad);
    g.addColorStop(0, colors[i % colors.length]); g.addColorStop(1, 'rgba(255,255,255,0)');
    x.fillStyle = g; x.beginPath(); x.arc(cx, cy, rad, 0, Math.PI * 2); x.fill();
  }
}
function brandMark(x, lang, W, y, color) {
  setFont(x, 'en', 'bold', W * 0.022);
  x.fillStyle = color; x.textAlign = 'center'; x.direction = 'ltr';
  x.fillText('K-BEAUTY BLISS', W / 2, y);
}

// Layout zones per format. 9:16 keeps text out of the top 14% and bottom 35%
// where Reels/Stories UI sits; 4:5 and 1:1 use the full frame.
function zones(W, H) {
  if (H / W > 1.6) return { top: H * 0.15, textBottom: H * 0.36, prodTop: H * 0.36, prodBottom: H * 0.64, foot: H * 0.62, safeBottom: H * 0.65, tall: true };
  if (H / W > 1.2) return { top: H * 0.07, textBottom: H * 0.36, prodTop: H * 0.37, prodBottom: H * 0.83, foot: H * 0.9, safeBottom: H, tall: false };
  return { top: H * 0.07, textBottom: H * 0.42, prodTop: H * 0.4, prodBottom: H * 0.85, foot: H * 0.92, safeBottom: H, tall: false };
}

// ---------- templates ----------
const T = {};
T.clean = (x, s, W, H, A, t) => {
  const z = zones(W, H), ar = s.lang === 'ar', align = 'center';
  const g = x.createLinearGradient(0, 0, 0, H); g.addColorStop(0, C.cream); g.addColorStop(1, '#FBEFF1');
  x.fillStyle = g; x.fillRect(0, 0, W, H);
  x.fillStyle = 'rgba(224,86,123,.10)'; x.beginPath(); x.arc(W / 2, (z.prodTop + z.prodBottom) / 2, W * 0.36 * (0.85 + 0.15 * t), 0, Math.PI * 2); x.fill();
  x.strokeStyle = 'rgba(193,62,99,.25)'; x.lineWidth = 2; x.beginPath(); x.arc(W / 2, (z.prodTop + z.prodBottom) / 2, W * 0.42, 0, Math.PI * 2); x.stroke();
  if (s.badge) pill(x, 'en', String(s.badge).toUpperCase(), W / 2, z.top + 10, W * 0.022, C.ink, C.white);
  const hd = fitText(x, s.lang, ar ? 'head' : 'serif', s.headline, W * 0.84, W * 0.075, 2);
  let y = textBlock(x, s.lang, hd.lines, W / 2, z.top + W * 0.05, hd.size, C.ink, align, seg(t, 0.1, 0.5));
  if (s.sub) { setFont(x, s.lang, 'body', W * 0.034); y = textBlock(x, s.lang, wrap(x, s.sub, W * 0.8, 1), W / 2, y, W * 0.034, C.ink2, align, seg(t, 0.25, 0.6)); }
  product(x, A[0], W / 2, (z.prodTop + z.prodBottom) / 2, W * 0.62, z.prodBottom - z.prodTop, seg(t, 0, 0.6));
  chips(x, s, W, z.tall ? z.prodBottom - W * 0.01 : z.foot - W * 0.075, t, 'rgba(255,255,255,.85)', C.ink);
  if (!z.tall) { pill(x, s.lang, s.price ? `${s.price}  ·  ${cta(s)}` : cta(s), W / 2, z.foot, W * 0.028, C.deep, C.white); }
  brandMark(x, s.lang, W, z.tall ? z.top - W * 0.035 : H - W * 0.02, 'rgba(42,34,40,.45)');
};
T.glow = (x, s, W, H, A, t) => {
  const z = zones(W, H), ar = s.lang === 'ar';
  const g = x.createRadialGradient(W * 0.3, H * 0.2, 0, W * 0.5, H * 0.5, Math.max(W, H) * 0.8);
  g.addColorStop(0, '#FFE3EA'); g.addColorStop(0.45, '#F7C6D3'); g.addColorStop(1, '#E9A6B8');
  x.fillStyle = g; x.fillRect(0, 0, W, H);
  bokeh(x, W, H, 7, ['rgba(255,255,255,.75)', 'rgba(255,214,196,.55)', 'rgba(255,240,244,.6)'], 16, t);
  // dewy light sweep
  const sw = x.createLinearGradient(0, 0, W, H); sw.addColorStop(0.35, 'rgba(255,255,255,0)'); sw.addColorStop(0.5, `rgba(255,255,255,${0.35 * seg(t, 0.2, 0.9)})`); sw.addColorStop(0.65, 'rgba(255,255,255,0)');
  x.fillStyle = sw; x.fillRect(0, 0, W, H);
  const pc = (z.prodTop + z.prodBottom) / 2;
  const halo = x.createRadialGradient(W / 2, pc, 0, W / 2, pc, W * 0.42); halo.addColorStop(0, 'rgba(255,255,255,.9)'); halo.addColorStop(1, 'rgba(255,255,255,0)');
  x.fillStyle = halo; x.fillRect(0, z.prodTop - W * 0.1, W, z.prodBottom - z.prodTop + W * 0.2);
  product(x, A[0], W / 2, pc, W * 0.6, z.prodBottom - z.prodTop, seg(t, 0, 0.6));
  // glass card for the text
  const cardY = z.top - W * 0.01, cardH = z.textBottom - z.top - W * 0.02;
  x.save(); x.globalAlpha = seg(t, 0.05, 0.4);
  x.fillStyle = 'rgba(255,255,255,.38)'; rr(x, W * 0.07, cardY, W * 0.86, cardH, 40); x.fill();
  x.strokeStyle = 'rgba(255,255,255,.75)'; x.lineWidth = 2; x.stroke(); x.restore();
  const hd = fitText(x, s.lang, ar ? 'head' : 'serif', s.headline, W * 0.76, W * 0.074, 2);
  let y = textBlock(x, s.lang, hd.lines, W / 2, cardY + W * 0.03, hd.size, C.plum, 'center', seg(t, 0.15, 0.55));
  if (s.sub) { setFont(x, s.lang, 'body', W * 0.034); textBlock(x, s.lang, wrap(x, s.sub, W * 0.72, 1), W / 2, y, W * 0.034, C.deep, 'center', seg(t, 0.3, 0.7)); }
  if (!z.tall) pill(x, s.lang, cta(s), W / 2, z.foot, W * 0.03, C.white, C.deep);
  else pill(x, s.lang, s.badge || 'K-BEAUTY', W / 2, z.prodBottom + W * 0.01, W * 0.024, 'rgba(255,255,255,.8)', C.deep);
  brandMark(x, s.lang, W, z.tall ? z.top - W * 0.035 : H - W * 0.022, 'rgba(90,31,53,.55)');
};
T.ingredient = (x, s, W, H, A, t) => {
  const z = zones(W, H), ar = s.lang === 'ar';
  x.fillStyle = C.blush; x.fillRect(0, 0, W, H);
  // colour field + droplets
  const fieldW = W * 0.5, fx = ar ? W - fieldW : 0;
  const g = x.createLinearGradient(fx, 0, fx + fieldW, H); g.addColorStop(0, C.plum); g.addColorStop(1, C.deep);
  x.fillStyle = g; x.fillRect(fx, 0, fieldW, H);
  for (let i = 0; i < 5; i++) { x.fillStyle = `rgba(255,255,255,${0.08 + i * 0.03})`; x.beginPath(); x.arc(fx + fieldW * (0.2 + 0.15 * i), H * (0.82 - 0.06 * i) + (1 - t) * 30, W * (0.018 + 0.006 * i), 0, Math.PI * 2); x.fill(); }
  const word = (s.bullets && s.bullets[0]) || s.angle || '';
  const tx = ar ? W - W * 0.05 : W * 0.05, al = ar ? 'right' : 'left';
  const top = z.tall ? z.top + W * 0.02 : H * 0.12;
  setFont(x, s.lang, 'bold', W * 0.026); x.fillStyle = 'rgba(255,255,255,.75)'; x.textAlign = al; x.direction = ar ? 'rtl' : 'ltr';
  x.fillText(ar ? 'المكوّن البطل' : 'HERO INGREDIENT', tx, top);
  const wd = fitText(x, s.lang, ar ? 'head' : 'serif', word, fieldW * 0.86, W * 0.085, 3, 40);
  let y = textBlock(x, s.lang, wd.lines, tx, top + W * 0.01, wd.size, C.white, al, seg(t, 0.1, 0.5));
  setFont(x, s.lang, 'bold', W * 0.034);
  y = textBlock(x, s.lang, wrap(x, s.headline, fieldW * 0.86, 3), tx, y + W * 0.02, W * 0.034, C.gold, al, seg(t, 0.3, 0.7));
  setFont(x, s.lang, 'body', W * 0.028);
  (s.bullets || []).slice(1, 3).forEach((b, i) => { y = textBlock(x, s.lang, ['• ' + b], tx, y + W * 0.005, W * 0.028, 'rgba(255,255,255,.9)', al, seg(t, 0.4 + i * 0.1, 0.8)); });
  const pcx = ar ? fieldW / 2 : W - (W - fieldW) / 2;
  const pTop = z.tall ? z.prodTop - W * 0.1 : H * 0.12, pBot = z.tall ? z.prodBottom : H * 0.84;
  product(x, A[0], pcx, (pTop + pBot) / 2, (W - fieldW) * 0.84, pBot - pTop, seg(t, 0, 0.6));
  if (!z.tall) pill(x, s.lang, cta(s), pcx, H * 0.92, W * 0.026, C.deep, C.white);
  brandMark(x, s.lang, W, z.tall ? z.top - W * 0.035 : H - W * 0.02, 'rgba(42,34,40,.4)');
};
T.offer = (x, s, W, H, A, t) => {
  const z = zones(W, H), ar = s.lang === 'ar';
  const g = x.createLinearGradient(0, 0, W, H); g.addColorStop(0, C.pink); g.addColorStop(1, C.deep);
  x.fillStyle = g; x.fillRect(0, 0, W, H);
  x.save(); x.globalAlpha = 0.18; x.fillStyle = C.white; x.translate(W / 2, H / 2); x.rotate(-0.5); x.fillRect(-W, -W * 0.08 + (1 - t) * 80, W * 2, W * 0.16); x.restore();
  bokeh(x, W, H, 3, ['rgba(255,255,255,.25)', 'rgba(255,214,196,.25)'], 8, t);
  const hd = fitText(x, s.lang, 'head', s.headline, W * 0.84, W * 0.08, 2);
  let y = textBlock(x, s.lang, hd.lines, W / 2, z.top + W * 0.01, hd.size, C.white, 'center', seg(t, 0.1, 0.5));
  if (s.sub) { setFont(x, s.lang, 'body', W * 0.036); textBlock(x, s.lang, wrap(x, s.sub, W * 0.8, 1), W / 2, y, W * 0.036, 'rgba(255,255,255,.9)', 'center', seg(t, 0.3, 0.7)); }
  const pc = (z.prodTop + z.prodBottom) / 2;
  x.fillStyle = 'rgba(255,255,255,.95)'; x.beginPath(); x.arc(W / 2, pc, W * 0.3 * (0.8 + 0.2 * t), 0, Math.PI * 2); x.fill();
  product(x, A[0], W / 2, pc, W * 0.46, (z.prodBottom - z.prodTop) * 0.85, seg(t, 0.05, 0.6));
  if (s.price) { // price badge: a fact, never a discount unless the brain was told a promotion is approved
    const bx = W * (ar ? 0.22 : 0.78), by = pc - W * 0.24, r = W * 0.1 * seg(t, 0.4, 0.8);
    x.fillStyle = C.ink; x.beginPath(); x.arc(bx, by, r, 0, Math.PI * 2); x.fill();
    if (r > 4) { setFont(x, s.lang, 'bold', W * 0.03); x.fillStyle = C.white; x.textAlign = 'center'; x.textBaseline = 'middle'; x.direction = ar ? 'rtl' : 'ltr'; const pl = wrap(x, s.price, r * 1.6, 2); pl.forEach((l, i) => x.fillText(l, bx, by + (i - (pl.length - 1) / 2) * W * 0.034)); x.textBaseline = 'alphabetic'; }
  }
  if (!z.tall) pill(x, s.lang, cta(s), W / 2, z.foot, W * 0.03, C.white, C.deep);
  brandMark(x, s.lang, W, z.tall ? z.top - W * 0.035 : H - W * 0.022, 'rgba(255,255,255,.7)');
};
T.routine = (x, s, W, H, A, t) => {
  const z = zones(W, H), ar = s.lang === 'ar';
  const g = x.createLinearGradient(0, 0, 0, H); g.addColorStop(0, '#FFF4F0'); g.addColorStop(1, '#FBE3E9');
  x.fillStyle = g; x.fillRect(0, 0, W, H);
  const hd = fitText(x, s.lang, ar ? 'head' : 'serif', s.headline, W * 0.84, W * 0.07, 2);
  let y = textBlock(x, s.lang, hd.lines, W / 2, z.top, hd.size, C.ink, 'center', seg(t, 0.05, 0.4));
  if (s.sub) { setFont(x, s.lang, 'body', W * 0.032); textBlock(x, s.lang, wrap(x, s.sub, W * 0.8, 1), W / 2, y, W * 0.032, C.deep, 'center', seg(t, 0.2, 0.5)); }
  const steps = Math.max(1, Math.min(3, Math.max(A.filter(Boolean).length, (s.bullets || []).length)));
  const top = z.prodTop, bot = z.tall ? z.prodBottom : z.foot - W * 0.08;
  const colW = W * 0.86 / steps;
  x.strokeStyle = 'rgba(193,62,99,.35)'; x.lineWidth = 3; x.setLineDash([10, 10]);
  x.beginPath(); x.moveTo(W * 0.07 + colW / 2, top + W * 0.05); x.lineTo(W * 0.93 - colW / 2, top + W * 0.05); x.stroke(); x.setLineDash([]);
  for (let i = 0; i < steps; i++) {
    const k = ar ? steps - 1 - i : i, cx = W * 0.07 + colW * (k + 0.5), tt = seg(t, 0.15 + i * 0.15, 0.55 + i * 0.15);
    x.fillStyle = C.deep; x.beginPath(); x.arc(cx, top + W * 0.05, W * 0.035 * Math.max(0.01, tt), 0, Math.PI * 2); x.fill();
    setFont(x, 'en', 'bold', W * 0.034); x.fillStyle = C.white; x.textAlign = 'center'; x.textBaseline = 'middle'; x.globalAlpha = tt; x.fillText(String(i + 1), cx, top + W * 0.052); x.textBaseline = 'alphabetic'; x.globalAlpha = 1;
    x.fillStyle = 'rgba(255,255,255,.75)'; rr(x, cx - colW * 0.44, top + W * 0.1, colW * 0.88, bot - top - W * 0.2, 30); x.fill();
    product(x, A[i] || A[0], cx, top + W * 0.1 + (bot - top - W * 0.28) / 2, colW * 0.74, bot - top - W * 0.34, tt, false);
    setFont(x, s.lang, 'bold', W * 0.027);
    textBlock(x, s.lang, wrap(x, (s.bullets || [])[i] || '', colW * 0.8, 2), cx, bot - W * 0.17, W * 0.027, C.ink, 'center', tt);
  }
  if (!z.tall) pill(x, s.lang, cta(s), W / 2, z.foot, W * 0.028, C.deep, C.white);
  brandMark(x, s.lang, W, z.tall ? z.top - W * 0.035 : H - W * 0.02, 'rgba(42,34,40,.45)');
};
T.qa = (x, s, W, H, A, t) => {
  const z = zones(W, H), ar = s.lang === 'ar';
  x.fillStyle = C.cream; x.fillRect(0, 0, W, H);
  bokeh(x, W, H, 11, ['rgba(246,213,194,.6)', 'rgba(255,240,244,.8)'], 6, t);
  const q = s.sub || (ar ? 'ما الذي يميّزه؟' : 'What makes it special?');
  // question bubble
  setFont(x, s.lang, 'bold', W * 0.04);
  const ql = wrap(x, q, W * 0.66, 2);
  const bw = W * 0.76, bh = ql.length * W * 0.05 + W * 0.07, bx = ar ? W * 0.17 : W * 0.07, by = z.top;
  x.save(); x.globalAlpha = seg(t, 0, 0.3); x.fillStyle = C.ink; rr(x, bx, by, bw, bh, 34); x.fill();
  x.beginPath(); const tailX = ar ? bx + bw - W * 0.08 : bx + W * 0.08; x.moveTo(tailX, by + bh - 2); x.lineTo(tailX + (ar ? 20 : -20), by + bh + 30); x.lineTo(tailX + (ar ? -30 : 30), by + bh - 2); x.fill();
  textBlock(x, s.lang, ql, ar ? bx + bw - W * 0.05 : bx + W * 0.05, by + W * 0.012, W * 0.04, C.white, ar ? 'right' : 'left'); x.restore();
  // answer card
  const ay = by + bh + W * 0.06, ax = ar ? W * 0.07 : W * 0.17, aw = W * 0.76;
  const hd = fitText(x, s.lang, ar ? 'head' : 'serif', s.headline, aw - W * 0.1, W * 0.055, 2, 34);
  const bl = (s.bullets || []).slice(0, 3);
  const ah = hd.lines.length * hd.size * 1.2 + bl.length * W * 0.045 + W * 0.08;
  x.save(); x.globalAlpha = seg(t, 0.2, 0.5); x.fillStyle = C.white; x.shadowColor = 'rgba(42,34,40,.12)'; x.shadowBlur = 30; rr(x, ax, ay, aw, ah, 34); x.fill(); x.restore();
  const tx = ar ? ax + aw - W * 0.05 : ax + W * 0.05, al = ar ? 'right' : 'left';
  let y = textBlock(x, s.lang, hd.lines, tx, ay + W * 0.015, hd.size, C.ink, al, seg(t, 0.25, 0.55));
  setFont(x, s.lang, 'body', W * 0.032);
  bl.forEach((b, i) => { y = textBlock(x, s.lang, ['✓ ' + b], tx, y - W * 0.005, W * 0.032, C.deep, al, seg(t, 0.35 + i * 0.1, 0.75)); });
  const pTop = ay + ah + W * 0.02, pBot = z.tall ? z.safeBottom + W * 0.12 : H * 0.88;
  product(x, A[0], W / 2, (pTop + pBot) / 2, W * 0.5, Math.max(W * 0.2, pBot - pTop), seg(t, 0.1, 0.6));
  setFont(x, 'en', 'body', W * 0.018); x.fillStyle = 'rgba(42,34,40,.45)'; x.textAlign = 'center'; x.direction = 'ltr';
  x.fillText(ar ? 'أسئلة شائعة عن المنتج' : 'Product Q&A', W / 2, z.tall ? z.top - W * 0.035 : H - W * 0.02);
};
function chips(x, s, W, y, t, bg, fg) {
  const b = (s.bullets || []).slice(0, 3);
  if (!b.length) return;
  setFont(x, s.lang, 'bold', W * 0.024);
  x.direction = s.lang === 'ar' ? 'rtl' : 'ltr';
  const ws = b.map((v) => x.measureText(v).width + W * 0.05);
  const total = ws.reduce((a, v) => a + v, 0) + W * 0.02 * (b.length - 1);
  let cx = W / 2 - total / 2;
  const order = s.lang === 'ar' ? [...b.keys()].reverse() : [...b.keys()];
  order.forEach((i, n) => { x.save(); x.globalAlpha = seg(t, 0.4 + n * 0.1, 0.8); pill(x, s.lang, b[i], cx, y, W * 0.024, bg, fg, 'left', 1.04); x.restore(); cx += ws[i] + W * 0.02; });
}
const CTA = { SHOP_NOW: ['Shop now', 'تسوّقي الآن'], ORDER_NOW: ['Order now', 'اطلبي الآن'], LEARN_MORE: ['Learn more', 'اعرفي المزيد'], BUY_NOW: ['Buy now', 'اشتري الآن'] };
const cta = (s) => (CTA[s.cta] || CTA.SHOP_NOW)[s.lang === 'ar' ? 1 : 0];

// ---------- public ----------
export async function assets(spec) { await loadFonts(); return Promise.all((spec.images || []).slice(0, 3).map(loadImage)); }

export function draw(ctx, spec, W, H, A, t = 1) {
  ctx.save();
  ctx.clearRect(0, 0, W, H);
  (T[spec.template] || T.clean)(ctx, spec, W, H, A, t);
  ctx.restore();
}

export async function renderSpec(spec, format, canvas) {
  const [W, H] = FORMATS[format] || FORMATS['1:1'];
  const cv = canvas || document.createElement('canvas');
  cv.width = W; cv.height = H;
  const x = cv.getContext('2d');
  if (spec.generator && generators[spec.generator]) {
    const out = await generators[spec.generator](spec, format, { W, H });
    x.drawImage(out, 0, 0, W, H);
    return cv;
  }
  draw(x, spec, W, H, await assets(spec), 1);
  return cv;
}

export function toPNG(cv) {
  return new Promise((ok) => cv.toBlob((b) => { const r = new FileReader(); r.onload = () => ok({ b64: String(r.result).split(',')[1], bytes: b.size }); r.readAsDataURL(b); }, 'image/png'));
}

const done = new Set();
// Render every pending design in all its formats and hand the PNGs to the
// engine (POST /api/creatives takes exactly the model.Creative fields).
export async function renderPending(designs, after) {
  const ids = [];
  for (const d of designs) {
    if (done.has(d.id)) continue;
    done.add(d.id);
    for (const f of d.formats || ['1:1']) {
      const { b64 } = await toPNG(await renderSpec(d, f));
      await api('/api/creatives', { method: 'POST', body: { lang: d.lang, format: f, png: b64, headline: d.headline, primary_text: d.primary_text || d.sub || '', cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids || [], angle: d.angle || '', source: 'brain' } });
    }
    ids.push(d.id);
  }
  if (ids.length && after) await after(ids);
  return ids.length;
}
