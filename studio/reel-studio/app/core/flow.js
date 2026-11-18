// KBB Studio — core/flow.js: "Optical flow" smooth slow motion for the EXPORT (too heavy for a live monitor, exact and
// offline here). Between two real frames a and b it builds the frame at fraction w by motion compensation:
//   1. motion: block matching on a small luma copy (8×8 blocks, ±6 px search, a small bias to "no motion" so flat areas
//      stay still), then a 3×3 median over the vectors to remove outliers;
//   2. render: every output pixel follows the (bilinearly interpolated) vector field back into a by w·v and forward into b
//      by (1 − w)·v and mixes the two; where the match was poor (an occlusion, a new object) it falls back to the plain
//      cross-fade of the two frames at that spot, so it never smears worse than frame blending would.
// Pure JS on ImageData, no dependency; ~40–80 ms per 1080p frame on one core.

const SW = 192, BS = 8, R = 6;

function pixels(src, W, H, cache) {
  let c = cache.c;
  if (!c || c.width !== W || c.height !== H) { c = cache.c = new OffscreenCanvas(W, H); cache.x = c.getContext('2d', { willReadFrequently: true }); }
  cache.x.clearRect(0, 0, W, H); cache.x.drawImage(src, 0, 0, W, H);
  return cache.x.getImageData(0, 0, W, H).data;
}
function luma(px, n) { const y = new Float32Array(n); for (let i = 0, j = 0; i < n; i++, j += 4) y[i] = px[j] * 0.299 + px[j + 1] * 0.587 + px[j + 2] * 0.114; return y; }

const caches = { sa: {}, sb: {}, fa: {}, fb: {} };

/** the in-between frame at w (0 = a, 1 = b) into `out` (an OffscreenCanvas or canvas sized like a); returns out */
export async function interpolate(a, b, w, out) {
  const W = a.width, H = a.height, sh = Math.max(BS, Math.round((SW * H) / W));
  if (out.width !== W || out.height !== H) { out.width = W; out.height = H; }
  const ya = luma(pixels(a, SW, sh, caches.sa), SW * sh), yb = luma(pixels(b, SW, sh, caches.sb), SW * sh);
  const nx = Math.ceil(SW / BS), ny = Math.ceil(sh / BS), vx = new Float32Array(nx * ny), vy = new Float32Array(nx * ny), conf = new Float32Array(nx * ny);
  for (let by = 0; by < ny; by++) for (let bx = 0; bx < nx; bx++) {
    const x0 = bx * BS, y0 = by * BS, x1 = Math.min(SW, x0 + BS), y1 = Math.min(sh, y0 + BS);
    let best = Infinity, bdx = 0, bdy = 0, sad0 = 0;
    for (let dy = -R; dy <= R; dy++) for (let dx = -R; dx <= R; dx++) {
      if (x0 + dx < 0 || x1 + dx > SW || y0 + dy < 0 || y1 + dy > sh) continue;
      let s = 0;
      for (let y = y0; y < y1 && s < best; y++) { const ra = y * SW, rb = (y + dy) * SW + dx; for (let x = x0; x < x1; x++) s += Math.abs(ya[ra + x] - yb[rb + x]); }
      if (dx === 0 && dy === 0) { sad0 = s; s *= 0.85; }
      if (s < best) { best = s; bdx = dx; bdy = dy; }
    }
    const n = (x1 - x0) * (y1 - y0), k = by * nx + bx;
    vx[k] = bdx; vy[k] = bdy;
    // confidence: residual per pixel against the block's own contrast scale (flat or well-matched → 1, occluded → 0)
    const resid = (bdx || bdy ? best : sad0) / n;
    conf[k] = Math.max(0, Math.min(1, 1.6 - resid / 10));
  }
  // 3×3 median of the vectors
  const mx = new Float32Array(vx), my = new Float32Array(vy);
  for (let by = 0; by < ny; by++) for (let bx = 0; bx < nx; bx++) {
    const xs = [], ys = [];
    for (let j = -1; j <= 1; j++) for (let i = -1; i <= 1; i++) { const X = Math.min(nx - 1, Math.max(0, bx + i)), Y = Math.min(ny - 1, Math.max(0, by + j)); xs.push(vx[Y * nx + X]); ys.push(vy[Y * nx + X]); }
    xs.sort((p, q) => p - q); ys.sort((p, q) => p - q);
    mx[by * nx + bx] = xs[4]; my[by * nx + bx] = ys[4];
  }
  const A = pixels(a, W, H, caches.fa), B = pixels(b, W, H, caches.fb);
  const ctx = out.getContext('2d'), img = ctx.createImageData(W, H), O = img.data;
  const k = W / SW, gx = (W / nx), gy = (H / ny), wa = 1 - w;
  for (let y = 0; y < H; y++) {
    const fy = Math.min(ny - 1.001, Math.max(0, y / gy - 0.5)), j0 = Math.floor(fy), ty = fy - j0, j1 = Math.min(ny - 1, j0 + 1);
    for (let x = 0; x < W; x++) {
      const fx = Math.min(nx - 1.001, Math.max(0, x / gx - 0.5)), i0 = Math.floor(fx), tx = fx - i0, i1 = Math.min(nx - 1, i0 + 1);
      const q00 = j0 * nx + i0, q10 = j0 * nx + i1, q01 = j1 * nx + i0, q11 = j1 * nx + i1;
      const w00 = (1 - tx) * (1 - ty), w10 = tx * (1 - ty), w01 = (1 - tx) * ty, w11 = tx * ty;
      const ux = (mx[q00] * w00 + mx[q10] * w10 + mx[q01] * w01 + mx[q11] * w11) * k, uy = (my[q00] * w00 + my[q10] * w10 + my[q01] * w01 + my[q11] * w11) * k;
      const cf = conf[q00] * w00 + conf[q10] * w10 + conf[q01] * w01 + conf[q11] * w11;
      const ax = Math.min(W - 1, Math.max(0, Math.round(x - w * ux))), ay = Math.min(H - 1, Math.max(0, Math.round(y - w * uy)));
      const bx = Math.min(W - 1, Math.max(0, Math.round(x + wa * ux))), by2 = Math.min(H - 1, Math.max(0, Math.round(y + wa * uy)));
      const pa = (ay * W + ax) * 4, pb = (by2 * W + bx) * 4, p0 = (y * W + x) * 4;
      for (let c = 0; c < 3; c++) {
        const mc = A[pa + c] * wa + B[pb + c] * w, bc = A[p0 + c] * wa + B[p0 + c] * w;
        O[p0 + c] = mc * cf + bc * (1 - cf);
      }
      O[p0 + 3] = 255;
    }
  }
  ctx.putImageData(img, 0, 0);
  return out;
}
