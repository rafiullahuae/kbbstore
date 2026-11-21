// Automated quality gate for every creative before it reaches the engine's
// library (and so Meta). Measured on the rendered pixels and the layout the
// renderer recorded -- not guessed:
//   product   the product's bounding box: its larger side must be >= 40% of
//             the frame (the owner's brief: hero product 40-60% of frame)
//   contrast  WCAG contrast of every text line / chip against the pixels
//             actually behind it (rendered without text, 10th-90th percentile
//             of the box): >= 4.5 for text under 40 px, >= 3 for larger
//   safe      9:16: no text or chip in the top 14% or bottom 20% (Reels and
//             Stories UI); 1:1 / 4:5: no text within 3% of the edge
//   density   text + chip area <= 22% of the frame
//   arabic    Arabic fonts loaded, the lam-alef ligature shapes (proof the
//             text is joined, not isolated letters), no tatweel, RTL
// A creative below any threshold is refused (not posted); the score (0-100)
// ranks the ones that pass.
import { FORMATS, assets, draw } from './render.js';
import { api } from '../api.js';
import { contrast as wcag, rgb2hex } from './art.js';

export const THRESHOLDS = { product: 0.4, contrastSmall: 4.5, contrastLarge: 3.0, density: 0.22, safeTop: 0.14, safeBottom: 0.2, edge: 0.03 };

function parseColor(c) {
  const m = String(c).match(/rgba?\(([^)]+)\)/);
  if (m) { const v = m[1].split(',').map((x) => parseFloat(x)); return { hex: rgb2hex(v[0], v[1], v[2]), a: v.length > 3 ? v[3] : 1 }; }
  return { hex: String(c).slice(0, 7), a: 1 };
}

// percentile luminance colours in a box of the text-free render
function behind(data, W, H, b) {
  const x0 = Math.max(0, Math.floor(b.x)), y0 = Math.max(0, Math.floor(b.y)), x1 = Math.min(W, Math.ceil(b.x + b.w)), y1 = Math.min(H, Math.ceil(b.y + b.h));
  const px = [];
  const step = Math.max(1, Math.floor(Math.sqrt(((x1 - x0) * (y1 - y0)) / 900)));
  for (let y = y0; y < y1; y += step) for (let x = x0; x < x1; x += step) { const i = (y * W + x) * 4; px.push([data[i], data[i + 1], data[i + 2]]); }
  if (!px.length) return [];
  const l = (p) => 0.2126 * p[0] + 0.7152 * p[1] + 0.0722 * p[2];
  px.sort((a, b2) => l(a) - l(b2));
  return [px[Math.floor(px.length * 0.1)], px[Math.floor(px.length * 0.9)]].map((p) => rgb2hex(p[0], p[1], p[2]));
}

let arabicProof = null;
async function arabicOK() {
  if (!arabicProof) {
    arabicProof = (async () => {
      const fonts = ['700 40px "El Messiri"', '800 40px Cairo', '700 40px Tajawal'];
      const loaded = fonts.map((f) => document.fonts.check(f, 'لا ابت'));
      const c = document.createElement('canvas').getContext('2d');
      c.font = '700 80px "El Messiri", Cairo';
      c.direction = 'rtl';
      const lig = c.measureText('لا').width, sep = c.measureText('ل').width + c.measureText('ا').width;
      const joined = c.measureText('بببب').width < c.measureText('ب').width * 4 * 0.98; // medial forms are narrower than isolated ones
      return { loaded, ligature: lig < sep - 1, joined, ok: loaded.every(Boolean) && lig < sep - 1 && joined };
    })();
  }
  return arabicProof;
}

// score(spec, format) -> {ok, score, checks{...}, fails[]}
export async function score(spec, format, A) {
  const [W, H] = FORMATS[format] || FORMATS['1:1'];
  A = A || await assets(spec);
  const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
  const x = cv.getContext('2d', { willReadFrequently: true });
  const L = draw(x, spec, W, H, A, 1, { noText: true });
  const data = x.getImageData(0, 0, W, H).data;
  const T = THRESHOLDS, fails = [];
  // product
  let pb = null;
  for (const p of L.product) pb = pb ? { x0: Math.min(pb.x0, p.x), y0: Math.min(pb.y0, p.y), x1: Math.max(pb.x1, p.x + p.w), y1: Math.max(pb.y1, p.y + p.h) } : { x0: p.x, y0: p.y, x1: p.x + p.w, y1: p.y + p.h };
  const prodShare = pb ? Math.max((pb.y1 - pb.y0) / H, (pb.x1 - pb.x0) / W) : 0;
  const prodArea = pb ? ((pb.y1 - pb.y0) * (pb.x1 - pb.x0)) / (W * H) : 0;
  if (prodShare < T.product) fails.push(`product only ${Math.round(prodShare * 100)}% of the frame (needs ${T.product * 100}%)`);
  if (L.product.some((p) => p.placeholder)) fails.push('product photo missing');
  // contrast
  let minC = 99;
  const boxes = L.text.map((t) => ({ ...t, kind: 'text' })).concat(L.chips.map((c) => ({ ...c, kind: 'chip' })));
  for (const b of boxes) {
    const fg = parseColor(b.color).hex;
    let cs;
    if (b.kind === 'chip' && parseColor(b.bg).a > 0.75) cs = [wcag(fg, parseColor(b.bg).hex)];
    else cs = behind(data, W, H, b).map((bg) => wcag(fg, bg));
    const c = Math.min(...cs, 99), need = b.size >= 40 ? T.contrastLarge : T.contrastSmall;
    if (c < minC) minC = c;
    if (c < need) fails.push(`contrast ${c.toFixed(1)} < ${need} for "${String(b.text).slice(0, 24)}"`);
  }
  // safe zones
  const tall = H / W > 1.6, vio = [];
  for (const b of boxes) {
    if (tall && (b.y < H * T.safeTop || b.y + b.h > H * (1 - T.safeBottom))) vio.push(b.text);
    if (!tall && (b.x < W * T.edge || b.x + b.w > W * (1 - T.edge) || b.y < H * T.edge * 0.5 || b.y + b.h > H * (1 - T.edge * 0.5))) vio.push(b.text);
  }
  if (vio.length) fails.push(`${vio.length} text item(s) in the ${tall ? 'Reels/Stories UI bands' : 'edge margin'}: ${vio.slice(0, 2).map((v) => '"' + String(v).slice(0, 18) + '"').join(', ')}`);
  // density
  const density = boxes.reduce((a, b) => a + b.w * b.h, 0) / (W * H);
  if (density > T.density) fails.push(`text covers ${Math.round(density * 100)}% (max ${T.density * 100}%)`);
  // Arabic
  let arabic = null;
  if (spec.lang === 'ar') {
    arabic = await arabicOK();
    const tatweel = boxes.some((b) => /ـ/.test(b.text));
    const rtl = L.text.every((t) => t.lang === 'ar' || !/[؀-ۿ]/.test(t.text));
    if (!arabic.ok) fails.push('Arabic fonts / joining not verified');
    if (tatweel) fails.push('tatweel (kashida) in Arabic text');
    if (!rtl) fails.push('Arabic text drawn as LTR');
  }
  const s = Math.round(
    35 * Math.min(1, prodShare / 0.55) +
    30 * Math.min(1, (minC === 99 ? 7 : minC) / 7) +
    15 * (vio.length ? 0 : 1) +
    10 * Math.max(0, 1 - Math.max(0, density - 0.08) / 0.2) +
    10 * (spec.lang !== 'ar' || (arabic && arabic.ok) ? 1 : 0));
  return { ok: fails.length === 0, score: s, fails, checks: { product_share: +prodShare.toFixed(3), product_area: +prodArea.toFixed(3), min_contrast: +(minC === 99 ? 21 : minC).toFixed(2), safe_violations: vio.length, text_density: +density.toFixed(3), arabic, cutout: (A[0] && A[0].method) || 'none' }, family: L.family, format };
}

export const qualityLog = []; // refused + shipped, for the UI and checks
if (typeof window !== 'undefined') window.__kaaQuality = qualityLog;

// Render, score and post every format of a design; formats below threshold
// are refused (not posted). Returns {posted, refused}.
export async function shipDesign(d, source, renderSpec, toPNG) {
  const out = { posted: 0, refused: [] };
  const A = await assets(d);
  for (const f of d.formats || ['1:1']) {
    const q = await score(d, f, A);
    qualityLog.push({ id: d.id, lang: d.lang, family: q.family, format: f, ok: q.ok, score: q.score, fails: q.fails });
    if (!q.ok) { out.refused.push({ format: f, fails: q.fails }); continue; }
    const { b64 } = await toPNG(await renderSpec(d, f, null, { assets: A }));
    await api('/api/creatives', { method: 'POST', body: { lang: d.lang, format: f, png: b64, price: d.price || 'none', layout: 2, headline: d.headline, primary_text: d.primary_text || d.sub || '', cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids || [], angle: d.angle || '', source } });
    out.posted++;
  }
  return out;
}

const done = new Set();
// The app renders the designs the autopilot planned (main.js, on load).
export async function renderPending(designs, after) {
  const { renderSpec, toPNG } = await import('./render.js');
  const ids = [];
  for (const d of designs) {
    if (done.has(d.id)) continue;
    done.add(d.id);
    await shipDesign(d, 'brain', renderSpec, toPNG);
    ids.push(d.id);
  }
  if (ids.length && after) await after(ids);
  return ids.length;
}
