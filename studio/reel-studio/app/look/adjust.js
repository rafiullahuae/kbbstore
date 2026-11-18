// KBB Studio · look · Adjust: manual light, colour and detail sliders + "✨ Enhance video", per clip.
//
//   item.fx {type:'look.adjust', exp, bri, con, hi, sh, wh, bl, temp, tint, sat, vib, sharp, clar, vig, dn, up,
//            enh: {parts:{auto, low, dn, sharp, skin, up}, wb:[r,g,b], ev, lift, sh, dn, sharp, up, report}}
//
// Every slider is 0 at "no change" (double-click a slider to get back there). The numbers go to the ONE look pass in
// gl.js (`adjust()` in the shader), so the preview and the export draw the same picture. Enhance video stores what it
// measured in `enh`; each of its parts can be switched off without losing the rest, and the manual sliders add on top.
// Pure maths only here (plus the analysis, which samples frames in the page): the UI is adjust-panel.js.

export const SLIDERS = [
  // group, key, label, min, max, step, format
  ['light', 'exp', 'Exposure', -2, 2, 0.01, (v) => (v > 0 ? '+' : '') + v.toFixed(2)],   // stops (EV)
  ['light', 'bri', 'Brightness', -1, 1, 0.01, null],
  ['light', 'con', 'Contrast', -1, 1, 0.01, null],
  ['light', 'hi', 'Highlights', -1, 1, 0.01, null],
  ['light', 'sh', 'Shadows', -1, 1, 0.01, null],
  ['light', 'wh', 'Whites', -1, 1, 0.01, null],
  ['light', 'bl', 'Blacks', -1, 1, 0.01, null],
  ['colour', 'temp', 'Temperature', -1, 1, 0.01, null],
  ['colour', 'tint', 'Tint', -1, 1, 0.01, null],
  ['colour', 'sat', 'Saturation', -1, 1, 0.01, null],
  ['colour', 'vib', 'Vibrance', -1, 1, 0.01, null],
  ['detail', 'sharp', 'Sharpness', 0, 1, 0.01, null],
  ['detail', 'clar', 'Clarity', -1, 1, 0.01, null],
  ['detail', 'dn', 'Denoise', 0, 1, 0.01, null],
  ['detail', 'vig', 'Vignette', -1, 1, 0.01, null],
];
export const KEYS = SLIDERS.map((s) => s[1]);
export const PARTS = [
  ['auto', 'Exposure + white balance', 'Measured from the clip: a neutral grey and a healthy brightness'],
  ['low', 'Low-light lift', 'Opens dark footage and recovers the shadows'],
  ['dn', 'Denoise', 'Removes grain from low-light or phone footage (edges stay sharp)'],
  ['sharp', 'Sharpen', 'Crisper detail, without sharpening the noise'],
  ['skin', 'Protect skin tones', 'Keeps faces natural while colour is boosted'],
  ['up', 'Upscale on export', 'Small clips are enlarged with a sharp Lanczos filter, not blurred'],
];
export const fmt = (v) => (v > 0 ? '+' : '') + Math.round(v * 100);
export const adjustFx = (o = {}) => Object.assign({ type: 'look.adjust' }, o);
const num = (v, d = 0) => (Number.isFinite(+v) ? +v : d);

/** is this fx a no-op? (then the clip draws exactly like it has no adjustment) */
export function isNeutral(fx) {
  if (!fx) return true;
  if (KEYS.some((k) => Math.abs(num(fx[k])) > 1e-4) || fx.up) return false;
  const e = fx.enh; if (!e) return true;
  return !Object.values(e.parts || {}).some(Boolean);
}

/** the shader's numbers for one look.adjust entry (manual sliders + the switched-on parts of Enhance) */
export function adjustUniforms(fx, { upscale = false } = {}) {
  const e = fx?.enh, P = e?.parts || {};
  const v = (k) => num(fx?.[k]);
  const ev = v('exp') + (P.auto ? num(e.ev) : 0);
  const wb = P.auto && Array.isArray(e.wb) ? e.wb : [1, 1, 1];
  const temp = v('temp'), tint = v('tint');
  // temperature / tint as channel gains, luminance kept (a warm push lifts red, drops blue; tint +: magenta)
  let g = [wb[0] * (1 + temp * 0.18 + tint * 0.05), wb[1] * (1 - tint * 0.1), wb[2] * (1 - temp * 0.18 + tint * 0.05)];
  const lw = 0.2126 * g[0] + 0.7152 * g[1] + 0.0722 * g[2], lw0 = 0.2126 * wb[0] + 0.7152 * wb[1] + 0.0722 * wb[2];
  const k = Math.pow(2, ev) * (lw0 / Math.max(1e-3, lw));
  g = g.map((x) => x * k);
  const lift = P.low ? num(e.lift) : 0;
  return {
    hasAdj: 1, Again: g,
    Abri: v('bri'), Acon: Math.max(-1, Math.min(1, v('con') + (P.auto ? num(e.con) : 0))), Ahi: v('hi'), Ash: Math.max(-1, Math.min(1.5, v('sh') + (P.low ? num(e.sh) : 0))), Awh: v('wh'), Abl: v('bl'),
    Asat: v('sat'), Avib: Math.max(-1, Math.min(1, v('vib') + (P.auto ? num(e.vib) : 0))), Aclar: v('clar'), Avig: v('vig'),
    Asharp: Math.min(1, v('sharp') + (P.sharp ? num(e.sharp) : 0)),
    Adn: Math.min(1, v('dn') + (P.dn ? num(e.dn) : 0)),
    Askin: P.skin ? 1 : 0.35, Alift: lift,
    upS: upscale ? 1 : 0,
  };
}
/** does this fx ask for the Lanczos enlargement (manual switch or Enhance's part)? */
export const wantsUpscale = (fx) => !!(fx && (fx.up || (fx.enh?.parts?.up && fx.enh.up)));

// ── analysis (Enhance video) ──
const LW = [0.2126, 0.7152, 0.0722];
/** noise sigma (0..1 units) of a grey picture, Immerkær's fast estimator (Laplacian-difference mask), edges excluded */
export function noiseSigma(Y, w, h) {
  let s = 0, n = 0;
  // ignore strong edges: they are picture, not noise
  for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) {
    const i = y * w + x;
    const gx = Math.abs(Y[i + 1] - Y[i - 1]), gy = Math.abs(Y[i + w] - Y[i - w]);
    if (gx + gy > 0.12) continue;
    const v = Y[i - w - 1] - 2 * Y[i - w] + Y[i - w + 1] - 2 * Y[i - 1] + 4 * Y[i] - 2 * Y[i + 1] + Y[i + w - 1] - 2 * Y[i + w] + Y[i + w + 1];
    s += Math.abs(v); n++;
  }
  return n ? Math.sqrt(Math.PI / 2) * s / (6 * n) : 0;
}
/** percentile of an array of luma values */
const pct = (arr, p) => { const a = Float32Array.from(arr).sort(); return a[Math.min(a.length - 1, Math.floor(p * (a.length - 1)))] || 0; };

/**
 * What Enhance video does to one clip, from its measurements.
 *   px     flat RGB 0..1 (a few frames, small)     auto: {wb, ev} (analyze.js autoCorrect on the same pixels)
 *   noise  noise sigma at the source's own resolution (0..1)     srcLong  the source's long side   outLong  the output's
 */
export function enhancePlan(px, auto, noise, srcLong, outLong) {
  const lum = []; for (let i = 0; i < px.length; i += 3) lum.push(px[i] * LW[0] + px[i + 1] * LW[1] + px[i + 2] * LW[2]);
  const mean = lum.reduce((a, b) => a + b, 0) / Math.max(1, lum.length), p10 = pct(lum, 0.1), p50 = pct(lum, 0.5);
  const dark = mean < 0.32;
  // auto exposure is held back on dark footage: the lift does the shadows, exposure the overall level
  const ev = dark ? Math.min(1.2, Math.max(auto.ev, Math.log2(0.36 / Math.max(0.03, mean)) * 0.55)) : auto.ev;
  const lift = dark ? +Math.min(0.7, Math.max(0, (0.3 - p50) * 2.2)).toFixed(3) : 0;
  const sh = +Math.min(0.6, Math.max(0, (0.12 - p10) * 4 + (dark ? 0.15 : 0))).toFixed(3);
  // denoise: grain the eye notices (sigma ~1.5/255 and up); dark footage gets more (the lift raises its noise too)
  const nz = noise * 255;
  const dn = nz < 1.3 ? 0 : +Math.min(0.9, (nz - 1.3) * 0.12 + (dark ? 0.15 : 0)).toFixed(3);
  const up = srcLong > 0 && outLong > srcLong * 1.15;
  const sharp = +Math.min(0.6, 0.2 + (up ? 0.2 : 0) + (dn > 0 ? 0.05 : 0)).toFixed(3);
  // a lift leaves the picture a little flat: give back some contrast and colour (gently; skin is protected)
  const con = dark ? 0.15 : 0.05, vib = dark ? 0.2 : 0.1;
  return {
    parts: { auto: true, low: lift > 0 || sh > 0, dn: dn > 0, sharp: true, skin: true, up },
    wb: auto.wb, ev: +ev.toFixed(3), lift, sh, con, vib, dn, sharp, up,
    report: { mean: +mean.toFixed(3), p10: +p10.toFixed(3), noise: +nz.toFixed(2), src: srcLong, out: outLong },
  };
}
