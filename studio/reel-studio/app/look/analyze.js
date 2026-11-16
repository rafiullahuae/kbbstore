// KBB Studio · look · colour analysis: auto white balance + exposure, and "match all clips to this one".
// Samples a few frames of each clip (64 px, through the same exact-frame reader the export uses) and turns them into
// the numbers look.grade carries (wb, ev for correction; mg/mo for shot matching). Pure maths below, I/O on top.
import { MediaStore } from '../core/media.js';
import { findMedia, srcTime, itemEnd } from '../core/project.js';

const LW = [0.2126, 0.7152, 0.0722];

/** a few frames of a clip as one flat RGB Float32Array (0..1) */
export async function samplePixels(project, item, n = 6, size = 64) {
  const m = findMedia(project, item.media); if (!m || m.kind === 'audio') return null;
  const r = await new MediaStore({ getProject: () => project, engine: null }).openReader(m.id);
  const out = [];
  try {
    for (let i = 0; i < n; i++) {
      const t = m.kind === 'image' ? 0 : srcTime(item, item.t0 + ((itemEnd(item) - item.t0) * (i + 0.5)) / n);
      const f = await r.frame(t);
      const bm = await createImageBitmap(f, { resizeWidth: size, resizeHeight: size, resizeQuality: 'medium' });
      const c = new OffscreenCanvas(size, size).getContext('2d', { willReadFrequently: true }); c.drawImage(bm, 0, 0); bm.close();
      const d = c.getImageData(0, 0, size, size).data;
      for (let k = 0; k < d.length; k += 4) out.push(d[k] / 255, d[k + 1] / 255, d[k + 2] / 255);
      if (m.kind === 'image') break;
    }
  } finally { r?.close(); }
  return new Float32Array(out);
}

/** gray-world white balance on the mid-tones + exposure toward a mid-grey mean */
export function autoCorrect(px, strength = 0.85) {
  let s = [0, 0, 0], n = 0, lum = 0, all = 0;
  for (let i = 0; i < px.length; i += 3) {
    const r = px[i], g = px[i + 1], b = px[i + 2], l = r * LW[0] + g * LW[1] + b * LW[2];
    all += l;
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
    if (l < 0.12 || l > 0.94 || mx - mn > 0.55) continue;
    s[0] += r; s[1] += g; s[2] += b; lum += l; n++;
  }
  const meanAll = all / (px.length / 3);
  let wb = [1, 1, 1];
  if (n > 50) {
    const m = s.map((v) => v / n), L = lum / n;
    let g = m.map((v) => L / Math.max(1e-3, v));
    const k = L / (g[0] * m[0] * LW[0] + g[1] * m[1] * LW[1] + g[2] * m[2] * LW[2]); g = g.map((v) => v * k);
    wb = g.map((v) => +Math.max(0.75, Math.min(1.35, 1 + (v - 1) * strength)).toFixed(4));
  }
  const lumAfter = meanAll * (wb[0] * LW[0] + wb[1] * LW[1] + wb[2] * LW[2]);
  let ev = Math.log2(0.45 / Math.max(0.02, lumAfter));
  ev = Math.abs(ev) < 0.08 ? 0 : +Math.max(-1.2, Math.min(1.2, ev * 0.8)).toFixed(3);
  return { wb, ev };
}

/** per-channel mean and standard deviation after a clip's correction */
export function stats(px, fx = {}) {
  const mg = fx.mg || [1, 1, 1], mo = fx.mo || [0, 0, 0], wb = fx.wb || [1, 1, 1], k = Math.pow(2, fx.ev || 0);
  const mean = [0, 0, 0], sq = [0, 0, 0], n = px.length / 3;
  for (let i = 0; i < px.length; i += 3) for (let c = 0; c < 3; c++) { const v = Math.max(0, (px[i + c] * mg[c] + mo[c]) * wb[c] * k); mean[c] += v; sq[c] += v * v; }
  for (let c = 0; c < 3; c++) { mean[c] /= n; sq[c] = Math.sqrt(Math.max(0, sq[c] / n - mean[c] * mean[c])); }
  return { mean, std: sq };
}

/** gain/offset per channel that moves `px`'s statistics onto the reference's (Reinhard-style transfer, clamped) */
export function matchTransfer(px, ref) {
  const s = stats(px);
  const mg = [0, 1, 2].map((c) => +Math.max(0.6, Math.min(1.6, ref.std[c] / Math.max(1e-3, s.std[c]))).toFixed(4));
  const mo = [0, 1, 2].map((c) => +Math.max(-0.35, Math.min(0.35, ref.mean[c] - mg[c] * s.mean[c])).toFixed(4));
  return { mg, mo };
}
