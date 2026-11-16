// KBB Studio · Designs · a quick look at every generated picture before it is offered (no model, ~5 ms):
//   blank       -- almost one flat colour (a failed run)
//   dark        -- too dark to use behind a design
//   lettering   -- rows of small, sharp, repeated strokes that look like writing (the model sometimes invents signs);
//                  flagged so he can regenerate -- text never belongs in a picture
// Returns [{id, msg}] (empty = fine). Flags are shown on the candidate; nothing is silently dropped.
export function pictureCheck(canvas) {
  const W = 128, H = Math.round((128 * canvas.height) / canvas.width);
  const c = document.createElement('canvas'); c.width = W; c.height = H;
  const x = c.getContext('2d', { willReadFrequently: true }); x.drawImage(canvas, 0, 0, W, H);
  const px = x.getImageData(0, 0, W, H).data, Y = new Float32Array(W * H);
  let s = 0, s2 = 0;
  for (let i = 0; i < W * H; i++) { const y = 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2]; Y[i] = y; s += y; s2 += y * y; }
  const n = W * H, mean = s / n, sd = Math.sqrt(Math.max(0, s2 / n - mean * mean));
  const flags = [];
  if (sd < 6) flags.push({ id: 'blank', msg: 'Almost blank: regenerate' });
  if (mean < 28) flags.push({ id: 'dark', msg: 'Very dark' });
  // writing-like rows: many short, strong horizontal gradient reversals inside one band, with calm bands above and below
  const band = 8;
  for (let y0 = 0; y0 + band <= H; y0 += band / 2) {
    let flips = 0, strong = 0;
    for (let y = y0; y < y0 + band; y++) {
      let last = 0;
      for (let xx = 1; xx < W; xx++) { const d = Y[y * W + xx] - Y[y * W + xx - 1]; if (Math.abs(d) > 38) { strong++; if (last && Math.sign(d) !== Math.sign(last)) flips++; last = d; } }
    }
    const density = flips / (band * W);
    if (density > 0.09 && strong > band * W * 0.18) { flags.push({ id: 'lettering', msg: 'Something that looks like writing: regenerate or crop it away' }); break; }
  }
  return flags;
}
