// KBB Studio · content · cover / thumbnail maker. Picks the best frames (sharp, a face, lively, well exposed), sets a
// bold title in the brand style off the face, checks it still reads on the profile grid (1/6 scale, contrast ≥ 4.5:1)
// and exports two PNGs: the 9:16 reel cover (1080×1920) and the 3:4 Instagram grid cover (1080×1440).
import { luma, sharpness } from '../auto/lib/video.js';
import { exportsDir } from '../core/config.js';
import { FONT_FAMILIES } from '../captions/fonts.js';
import { brandPop, contrast, rgbHex, lum } from './lib/catalogue.js';
import { mainVideo, ops } from './lib/items.js';
import { topTerms } from './lib/keywords.js';

export const COVER_SIZES = { reel: { w: 1080, h: 1920 }, grid: { w: 1080, h: 1440 } };
const GRID_SCALE = 6, MIN_PX_AT_GRID = 9, MIN_CONTRAST = 4.5;

/** sample frames of the main clip and score them: [{t (timeline), src (source s), score, sharp, face, lively, bitmap}] */
export async function pickFrames(ctx, { n = 18, keep = 3 } = {}) {
  const mv = mainVideo(ctx.project);
  if (!mv) return [];
  const { item, media } = mv, dur = (item.out - item.in) / (item.speed || 1), out = [];
  const oc = new OffscreenCanvas(160, Math.max(2, Math.round(160 * (media.h || 9) / (media.w || 16)))), g = oc.getContext('2d', { willReadFrequently: true });
  for (let i = 0; i < n; i++) {
    const tl = item.t0 + dur * (0.06 + 0.88 * i / Math.max(1, n - 1)), src = item.in + (tl - item.t0) * (item.speed || 1);
    const bm = await ctx.media.frameAt(media.id, src, 480);
    g.drawImage(bm, 0, 0, oc.width, oc.height);
    const d = g.getImageData(0, 0, oc.width, oc.height), y = luma({ w: oc.width, h: oc.height, data: d.data });
    let m = 0; for (const v of y) m += v; m /= y.length;
    let cf = 0; { let rg = 0, yb = 0, rg2 = 0, yb2 = 0; const N = d.data.length / 4; for (let j = 0; j < d.data.length; j += 4) { const a = d.data[j] - d.data[j + 1], b = 0.5 * (d.data[j] + d.data[j + 1]) - d.data[j + 2]; rg += a; yb += b; rg2 += a * a; yb2 += b * b; } const sr = Math.sqrt(rg2 / N - (rg / N) ** 2), sy = Math.sqrt(yb2 / N - (yb / N) ** 2); cf = Math.sqrt(sr * sr + sy * sy) + 0.3 * Math.hypot(rg / N, yb / N); }
    out.push({ t: tl, src, bitmap: bm, sharp: sharpness(y, oc.width, oc.height), exposure: 1 - Math.min(1, Math.abs(m - 128) / 128), colour: cf, face: null, lively: 0, y, w: oc.width, h: oc.height });
  }
  try {
    const { trackFaces } = await import('../auto/faces.js');
    const copies = await Promise.all(out.map((f) => createImageBitmap(f.bitmap)));
    const fs = await trackFaces(copies.map((b, i) => ({ t: out[i].t, bitmap: b })));
    fs.forEach((f, i) => { if (f && f.x != null) out[i].face = f; });
  } catch (e) { console.warn('[content] cover faces:', e.message); }
  const maxS = Math.max(1, ...out.map((f) => f.sharp)), maxC = Math.max(1, ...out.map((f) => f.colour));
  for (const f of out) {
    // lively: detail inside the face box (an open mouth, raised brows and teeth add edges a blank stare has not)
    if (f.face) {
      const x0 = Math.max(1, Math.floor((f.face.x - f.face.w / 2) * f.w)), x1 = Math.min(f.w - 2, Math.ceil((f.face.x + f.face.w / 2) * f.w));
      const y0 = Math.max(1, Math.floor((f.face.y - f.face.h / 2) * f.h)), y1 = Math.min(f.h - 2, Math.ceil((f.face.y + f.face.h / 2) * f.h));
      let s = 0, s2 = 0, k = 0; for (let r = y0; r < y1; r++) for (let c = x0; c < x1; c++) { const i = r * f.w + c, v = 4 * f.y[i] - f.y[i - 1] - f.y[i + 1] - f.y[i - f.w] - f.y[i + f.w]; s += v; s2 += v * v; k++; }
      f.lively = k ? Math.min(1, Math.sqrt(Math.max(0, s2 / k - (s / k) ** 2)) / 40) : 0;
    }
    f.score = 0.35 * f.sharp / maxS + 0.25 * (f.face ? Math.min(1, 0.4 + f.face.w * 2) * (f.face.conf || 0.8) : 0) + 0.15 * f.lively + 0.15 * f.exposure + 0.1 * f.colour / maxC;
    delete f.y;
  }
  out.sort((a, b) => b.score - a.score);
  for (const f of out.slice(keep)) f.bitmap.close?.();
  return out.slice(0, keep).map((f) => ({ ...f, sharp: +f.sharp.toFixed(1), score: +f.score.toFixed(3), lively: +f.lively.toFixed(2) }));
}

/** a default title: the reel name, or the strongest words of the transcript */
export function suggestTitle(p) {
  if (p.meta?.content?.series?.title) return p.meta.content.series.title;
  const hook = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'text' && i.t0 < 3 && i.text && i.text.length > 6);
  if (hook) return hook.text.replace(/\s+/g, ' ').slice(0, 48);
  if (p.name && !/^(Untitled|New) reel/i.test(p.name)) return p.name.slice(0, 48);
  const words = (p.captions?.words || []).map((w) => w.w).join(' ');
  return words ? topTerms(words, 3).join(' ') : 'Watch this';
}

function fontFor(ws) {
  const f = ws?.fonts?.heading;
  return f && FONT_FAMILIES.includes(f) && !/serif|playfair/i.test(f) ? f : 'Montserrat';
}
function wrapWords(g, words, maxW) {
  const lines = []; let line = [];
  for (const w of words) { const tl = [...line, w].join(' '); if (g.measureText(tl).width <= maxW || !line.length) line.push(w); else { lines.push(line); line = [w]; } }
  if (line.length) lines.push(line); return lines;
}

/**
 * Draw one cover. Returns {canvas, metrics:{size, lines, box, contrast, popContrast, gridPx, readable, plate}}.
 * frame: ImageBitmap (or null), face: {x,y,w,h} 0..1 of the frame, title: string.
 */
export function drawCover(frame, face, title, ws, size, opts = {}) {
  const { w: W, h: H } = size, c = new OffscreenCanvas(W, H), g = c.getContext('2d', { willReadFrequently: true });
  const pop = brandPop(ws), font = fontFor(ws);
  // background: the frame, cover-fitted, nudged so the face sits in the upper-middle (or a brand gradient)
  if (frame) {
    const fw = frame.width, fh = frame.height, s = Math.max(W / fw, H / fh), vw = W / s, vh = H / s;
    const fx = face ? face.x * fw : fw / 2, fy = face ? face.y * fh : fh / 2;
    const sx = Math.max(0, Math.min(fw - vw, fx - vw / 2)), sy = Math.max(0, Math.min(fh - vh, fy - vh * 0.38));
    g.drawImage(frame, sx, sy, vw, vh, 0, 0, W, H);
    if (face) opts.faceBox = { x: (face.x * fw - face.w * fw / 2 - sx) * s, y: (face.y * fh - face.h * fh / 2 - sy) * s, w: face.w * fw * s, h: face.h * fh * s };
  } else {
    const gr = g.createLinearGradient(0, 0, W, H); gr.addColorStop(0, ws.colors?.primary || '#2a2228'); gr.addColorStop(1, ws.colors?.secondary || '#111111'); g.fillStyle = gr; g.fillRect(0, 0, W, H);
  }
  // title area: the band away from the face, always inside the centre 3:4 of a 9:16 cover (the grid crop)
  const band = H > W * 1.5 ? { y0: (H - W * 4 / 3) / 2 + H * 0.04, y1: (H + W * 4 / 3) / 2 - H * 0.04 } : { y0: H * 0.06, y1: H * 0.94 };
  const fb = opts.faceBox, faceMid = fb ? (fb.y + fb.h / 2) / H : 0.35;
  const bottom = faceMid < 0.55;
  const words = String(title || '').trim().split(/\s+/).filter(Boolean).slice(0, 9);
  const maxW = W * 0.84, maxH = (band.y1 - band.y0) * 0.42;
  let px = Math.round(W * 0.15), lines;
  for (; px > 40; px -= 4) {
    g.font = `900 ${px}px "${font}", "Montserrat", sans-serif`;
    lines = wrapWords(g, words.map((w) => w.toUpperCase()), maxW);
    if (lines.length <= 3 && lines.length * px * 1.08 <= maxH) break;
  }
  const lh = px * 1.08, th = lines.length * lh, pad = px * 0.42;
  const top = bottom ? band.y1 - th - pad * 2 : band.y0;
  const box = { x: W * 0.08 - pad, y: top, w: maxW + pad * 2, h: th + pad * 2 };
  // the word to colour: the longest content word (the keyword), never the whole line
  const kw = words.map((w) => w.toUpperCase()).filter((w) => w.length > 3).sort((a, b) => b.length - a.length)[0];
  // scrim behind the title, darkened until white text AND the brand word both pass 4.5:1 on what is behind them
  const sample = () => { const d = g.getImageData(Math.max(0, box.x | 0), Math.max(0, box.y | 0), Math.min(W, box.w | 0), Math.min(H, box.h | 0)).data; let r = 0, gg = 0, b = 0, n = 0, worst = 0;
    for (let j = 0; j < d.length; j += 16) { r += d[j]; gg += d[j + 1]; b += d[j + 2]; n++; worst = Math.max(worst, lum(rgbHex(d[j], d[j + 1], d[j + 2]))); }
    return { mean: rgbHex(r / n, gg / n, b / n), worst }; };
  const base = g.getImageData(0, 0, W, H);
  let alpha = 0, plate = 'scrim', m;
  const popOk = contrast(pop, '#0b0b0f') >= MIN_CONTRAST;
  for (let step = 0; step < 12; step++) {
    g.putImageData(base, 0, 0);
    if (alpha > 0) {
      const gr = g.createLinearGradient(0, bottom ? box.y - H * 0.12 : box.y + box.h + H * 0.12, 0, bottom ? box.y + box.h : box.y);
      gr.addColorStop(0, 'rgba(8,8,12,0)'); gr.addColorStop(0.35, `rgba(8,8,12,${alpha})`); gr.addColorStop(1, `rgba(8,8,12,${alpha})`);
      g.fillStyle = gr; g.fillRect(0, Math.min(box.y, bottom ? box.y - H * 0.12 : box.y), W, box.h + H * 0.12);
    }
    m = sample();
    // the brightest pixel behind the text decides (the mean can hide a white patch)
    const bgWorst = rgbHex(...[0, 0, 0].map(() => 255 * Math.pow(m.worst, 1 / 2.2)));
    if (contrast('#ffffff', bgWorst) >= MIN_CONTRAST && (!kw || !popOk || contrast(pop, bgWorst) >= MIN_CONTRAST)) break;
    alpha = Math.min(0.94, alpha + 0.12);
    if (step === 11) plate = 'solid';
  }
  if (plate === 'solid') { g.fillStyle = '#0b0b0f'; g.beginPath(); g.roundRect(box.x, box.y, box.w, box.h, px * 0.3); g.fill(); m = sample(); }
  // the title
  g.font = `900 ${px}px "${font}", "Montserrat", sans-serif`; g.textBaseline = 'middle'; g.textAlign = 'left';
  g.lineJoin = 'round';
  lines.forEach((ln, i) => {
    const full = ln.join(' '), lw = g.measureText(full).width; let x = W / 2 - lw / 2; const y = box.y + pad + lh * i + lh / 2;
    for (const w of ln) {
      const col = w === kw && popOk ? pop : '#ffffff';
      g.lineWidth = px * 0.1; g.strokeStyle = 'rgba(0,0,0,.55)'; g.strokeText(w, x, y);
      g.fillStyle = col; g.fillText(w, x, y);
      x += g.measureText(w + ' ').width;
    }
  });
  // a small brand mark: the company's colour bar under the title
  g.fillStyle = pop; g.fillRect(W / 2 - W * 0.06, bottom ? box.y - px * 0.25 : box.y + box.h + px * 0.1, W * 0.12, Math.max(6, px * 0.09));
  const bgWorst = rgbHex(...[0, 0, 0].map(() => 255 * Math.pow(m.worst, 1 / 2.2)));
  const metrics = { size: px, lines: lines.length, box: { x: Math.round(box.x), y: Math.round(box.y), w: Math.round(box.w), h: Math.round(box.h) }, keyword: kw || null,
    contrast: +contrast('#ffffff', bgWorst).toFixed(2), popContrast: kw && popOk ? +contrast(pop, bgWorst).toFixed(2) : null, plate: alpha > 0 || plate === 'solid' ? plate : 'none',
    gridPx: +(px / GRID_SCALE * (W / 1080)).toFixed(1), face: fb ? { x: Math.round(fb.x), y: Math.round(fb.y), w: Math.round(fb.w), h: Math.round(fb.h) } : null };
  metrics.readable = metrics.gridPx >= MIN_PX_AT_GRID && metrics.contrast >= MIN_CONTRAST && (metrics.popContrast == null || metrics.popContrast >= MIN_CONTRAST);
  metrics.overFace = fb ? !(box.y + box.h <= fb.y || box.y >= fb.y + fb.h) : false;
  return { canvas: c, metrics };
}

/** the 1/6-scale grid preview the readability check looks at (180×240) */
export function gridThumb(canvas) {
  const t = new OffscreenCanvas(Math.round(canvas.width / GRID_SCALE), Math.round(canvas.height / GRID_SCALE));
  t.getContext('2d').drawImage(canvas, 0, 0, t.width, t.height); return t;
}

/** make + export both covers. Returns {files:[{path,w,h,bytes}], metrics:{reel, grid}, frames:[{t,score,...}], title} */
export async function makeCover(ctx, { title, frameIndex = 0, save = true } = {}) {
  const p = ctx.project, ws = ctx.workspace || {};
  const font = fontFor(ws);
  if (document.fonts) await Promise.all([document.fonts.load(`900 80px "${font}"`), document.fonts.load('900 80px "Montserrat"')]).catch(() => {});
  const frames = await pickFrames(ctx);
  const f = frames[Math.min(frameIndex, frames.length - 1)] || null;
  title = (title || suggestTitle(p)).trim();
  let reel = drawCover(f?.bitmap || null, f?.face || null, title, ws, COVER_SIZES.reel);
  let grid = drawCover(f?.bitmap || null, f?.face || null, title, ws, COVER_SIZES.grid);
  // too small on the grid? shorten the title to its strongest words and draw again
  if (!grid.metrics.readable && title.split(/\s+/).length > 4) {
    title = title.split(/\s+/).filter((w) => w.length > 2).slice(0, 4).join(' ');
    reel = drawCover(f?.bitmap || null, f?.face || null, title, ws, COVER_SIZES.reel);
    grid = drawCover(f?.bitmap || null, f?.face || null, title, ws, COVER_SIZES.grid);
  }
  const files = [];
  const base = String(p.name || 'reel').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 40) || 'reel';
  const urls = {};
  for (const [tag, r] of [['cover-9x16', reel], ['grid-3x4', grid]]) {
    const blob = await r.canvas.convertToBlob({ type: 'image/png' });
    urls[tag] = URL.createObjectURL(blob);
    const path = `${exportsDir(p.workspace)}/${base}-${tag}.png`;
    if (save && ctx.engine?.available) await ctx.engine.writeFile(path, blob);
    files.push({ path, w: r.canvas.width, h: r.canvas.height, bytes: blob.size, url: urls[tag] });
  }
  const gt = gridThumb(grid.canvas), gb = await gt.convertToBlob({ type: 'image/png' });
  const res = { title, files, gridThumb: URL.createObjectURL(gb), metrics: { reel: reel.metrics, grid: grid.metrics }, frames: frames.map((x) => ({ t: +x.t.toFixed(2), score: x.score, sharp: x.sharp, face: !!x.face, lively: x.lively })) };
  ctx.history.apply(ops.setProject({ meta: { ...p.meta, content: { ...(p.meta?.content || {}), cover: { title, t: f ? +f.t.toFixed(2) : null, files: files.map((x) => x.path), metrics: res.metrics } } } }, 'Cover'));
  for (const x of frames) x.bitmap?.close?.();
  return res;
}
