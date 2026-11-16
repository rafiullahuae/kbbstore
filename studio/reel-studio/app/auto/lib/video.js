// KBB Studio · automation · video analysis (pure ESM). Frames are {t, w, h, data: RGBA Uint8ClampedArray},
// already downscaled (the worker uses ~96 px wide). Nothing here touches the DOM.

export function luma(f) {
  const n = f.w * f.h, y = new Float32Array(n), d = f.data;
  for (let i = 0, j = 0; i < n; i++, j += 4) y[i] = 0.299 * d[j] + 0.587 * d[j + 1] + 0.114 * d[j + 2];
  return y;
}
/** 4×4×4 RGB histogram + 16-bin luma, normalised. */
export function histogram(f) {
  const h = new Float32Array(64 + 16), d = f.data, n = f.w * f.h;
  for (let j = 0; j < n * 4; j += 4) {
    h[((d[j] >> 6) << 4) | ((d[j + 1] >> 6) << 2) | (d[j + 2] >> 6)]++;
    h[64 + ((0.299 * d[j] + 0.587 * d[j + 1] + 0.114 * d[j + 2]) >> 4)]++;
  }
  for (let i = 0; i < h.length; i++) h[i] /= n;
  return h;
}
/** 0 (same) .. 1 (nothing in common): 1 - histogram intersection, colour and luma halves averaged. */
export function histDiff(a, b) {
  let c = 0, l = 0;
  for (let i = 0; i < 64; i++) c += Math.min(a[i], b[i]);
  for (let i = 64; i < 80; i++) l += Math.min(a[i], b[i]);
  return 1 - (c + l) / 2;
}
/** Variance of the Laplacian on luma, normalised for size: low = blurry. */
export function sharpness(y, w, h) {
  let s = 0, s2 = 0, n = 0;
  for (let r = 1; r < h - 1; r++) for (let c = 1; c < w - 1; c++) {
    const i = r * w + c, v = 4 * y[i] - y[i - 1] - y[i + 1] - y[i - w] - y[i + w];
    s += v; s2 += v * v; n++;
  }
  const m = s / n; return s2 / n - m * m;
}
/** Global shift between two luma frames (integer search ±R px) and residual motion. */
export function globalShift(a, b, w, h, R = 3) {
  let best = Infinity, bx = 0, by = 0;
  for (let dy = -R; dy <= R; dy++) for (let dx = -R; dx <= R; dx++) {
    let s = 0, n = 0;
    for (let r = R; r < h - R; r += 2) for (let c = R; c < w - R; c += 2) { s += Math.abs(a[r * w + c] - b[(r + dy) * w + c + dx]); n++; }
    s /= n; if (s < best) { best = s; bx = dx; by = dy; }
  }
  return { dx: bx, dy: by, residual: best };
}

/**
 * Analyse a sequence of sampled frames (ascending t).
 * Returns per-frame stats, scene cuts and flags for blur / dark / shake.
 */
export function analyseFrames(frames, opt = {}) {
  const out = [], cuts = [];
  let prevH = null, prevY = null, shifts = [];
  for (const f of frames) {
    const y = luma(f), H = histogram(f);
    let mean = 0; for (const v of y) mean += v; mean /= y.length;
    const st = { t: f.t, luma: mean, sharp: sharpness(y, f.w, f.h), diff: prevH ? histDiff(prevH, H) : 0, motion: 0, dx: 0, dy: 0 };
    if (prevY) { const g = globalShift(prevY, y, f.w, f.h); st.dx = g.dx; st.dy = g.dy; st.motion = g.residual / 255; }
    out.push(st); prevH = H; prevY = y;
  }
  // scene cuts: diff well above its local neighbourhood and above an absolute floor
  const minGap = opt.minScene ?? 0.6, W = 6;
  for (let i = 1; i < out.length; i++) {
    const nb = []; for (let k = Math.max(1, i - W); k <= Math.min(out.length - 1, i + W); k++) if (k !== i) nb.push(out[k].diff);
    nb.sort((a, b) => a - b);
    const med = nb.length ? nb[nb.length >> 1] : 0;
    const d = out[i].diff;
    if (d > (opt.cutAbs ?? 0.28) && d > med * 2.5 + 0.08 && (!cuts.length || out[i].t - cuts[cuts.length - 1].t >= minGap)) {
      cuts.push({ t: +((out[i].t + out[i - 1].t) / 2).toFixed(3), strength: +d.toFixed(3) });
    }
  }
  // flags
  const sh = out.map(s => s.sharp).sort((a, b) => a - b), medSharp = sh[sh.length >> 1] || 1;
  for (let i = 0; i < out.length; i++) {
    const s = out[i];
    // shake: jitter of the global shift around its 5-frame mean
    let mx = 0, my = 0, k = 0; for (let j = Math.max(1, i - 2); j <= Math.min(out.length - 1, i + 2); j++) { mx += out[j].dx; my += out[j].dy; k++; }
    const jit = k ? Math.hypot(s.dx - mx / k, s.dy - my / k) : 0;
    s.shake = +jit.toFixed(2);
    s.dark = s.luma < (opt.darkLuma ?? 28);
    s.blurry = s.sharp < Math.max(opt.blurAbs ?? 18, medSharp * 0.25);
    s.shaky = jit > 1.2;
    s.bad = s.dark || s.blurry || s.shaky;
  }
  const scenes = []; let a = frames.length ? frames[0].t : 0;
  for (const c of cuts) { scenes.push({ t0: a, t1: c.t }); a = c.t; }
  if (frames.length) scenes.push({ t0: a, t1: opt.duration ?? frames[frames.length - 1].t });
  const motion = out.reduce((s, x) => s + x.motion, 0) / (out.length || 1);
  return { frames: out, cuts, scenes, stats: { motion: +motion.toFixed(4), cutsPerMin: +(cuts.length / Math.max(1e-6, ((opt.duration ?? (frames.at(-1)?.t || 1)) / 60))).toFixed(2), badShare: +(out.filter(s => s.bad).length / (out.length || 1)).toFixed(3) } };
}

/** Fallback subject finder when the face model is not available: skin-tone / saliency centroid. */
export function subjectCentroid(f) {
  const d = f.data; let sx = 0, sy = 0, sw = 0;
  for (let r = 0; r < f.h; r++) for (let c = 0; c < f.w; c++) {
    const j = (r * f.w + c) * 4, R = d[j], G = d[j + 1], B = d[j + 2];
    const skin = R > 95 && G > 40 && B > 20 && R > G && R > B && R - Math.min(G, B) > 15 && Math.abs(R - G) > 15;
    const wgt = skin ? 1 : 0;
    sx += c * wgt; sy += r * wgt; sw += wgt;
  }
  if (sw < f.w * f.h * 0.004) return null;
  return { x: (sx / sw + 0.5) / f.w, y: (sy / sw + 0.5) / f.h, conf: Math.min(1, sw / (f.w * f.h * 0.05)) };
}

/**
 * Smooth auto-reframe. subjects: [{t, x, y, conf}] in 0..1 of the source (null = nobody seen).
 * Returns crop keyframes [{t, x, y, w, h}] in source px for target aspect (w/h), with a dead-zone and a
 * critically-damped follow, so the frame glides instead of jittering.
 */
export function reframe(subjects, src, aspect, opt = {}) {
  const ar = aspect.w / aspect.h, sw = src.w, shh = src.h;
  let cw = sw, ch = sw / ar; if (ch > shh) { ch = shh; cw = shh * ar; }
  const dead = opt.deadZone ?? 0.06, stiff = opt.stiffness ?? 3.2, step = opt.step ?? 1 / 15;
  const tEnd = opt.duration ?? (subjects.length ? subjects[subjects.length - 1].t : 0);
  const targetAt = (t) => { // nearest seen subject (hold the last one), centre if none yet
    let best = null; for (const s of subjects) { if (s && s.x != null && s.t <= t + 1e-6) best = s; else if (s.t > t) break; }
    return best ? { x: best.x * sw, y: best.y * shh } : { x: sw / 2, y: shh / 2 };
  };
  let x = targetAt(0).x, y = targetAt(0).y, vx = 0, vy = 0;
  const kfs = [];
  const w0 = 2 * Math.PI * stiff / 6;
  for (let t = 0; t <= tEnd + 1e-6; t += step) {
    const g = targetAt(t);
    const ex = g.x - x, ey = g.y - y;
    const tx = Math.abs(ex) > dead * cw ? g.x - Math.sign(ex) * dead * cw : x;
    const ty = Math.abs(ey) > dead * ch ? g.y - Math.sign(ey) * dead * ch : y;
    const ax = w0 * w0 * (tx - x) - 2 * w0 * vx, ay = w0 * w0 * (ty - y) - 2 * w0 * vy;
    vx += ax * step; vy += ay * step; x += vx * step; y += vy * step;
    const cx = Math.max(cw / 2, Math.min(sw - cw / 2, x)), cy = Math.max(ch / 2, Math.min(shh - ch / 2, y - ch * 0.08));
    kfs.push({ t: +t.toFixed(3), x: +(cx - cw / 2).toFixed(1), y: +(cy - ch / 2).toFixed(1), w: +cw.toFixed(1), h: +ch.toFixed(1) });
  }
  // thin: keep a keyframe only where linear interpolation would drift > 2 px
  const thin = [kfs[0]];
  for (let i = 1; i < kfs.length - 1; i++) {
    const a = thin[thin.length - 1], b = kfs[i + 1], f = (kfs[i].t - a.t) / (b.t - a.t);
    if (Math.abs(a.x + (b.x - a.x) * f - kfs[i].x) > 2 || Math.abs(a.y + (b.y - a.y) * f - kfs[i].y) > 2) thin.push(kfs[i]);
  }
  if (kfs.length > 1) thin.push(kfs[kfs.length - 1]);
  return thin;
}

/** Crop keyframes -> an item's box keyframes on a canvas (the clip layer cover-fits its box). */
export function cropToItemKf(crop, src, canvas, itemT0 = 0, srcIn = 0) {
  const kf = { x: [], y: [], w: [], h: [] };
  for (const c of crop) {
    const s = canvas.w / c.w; // scale so the crop window fills the canvas
    const t = +(c.t - srcIn).toFixed(3); if (t < -1e-6) continue;
    kf.x.push([t, +(-c.x * s).toFixed(1), 'linear']); kf.y.push([t, +(-c.y * s).toFixed(1), 'linear']);
    kf.w.push([t, +(src.w * s).toFixed(1), 'linear']); kf.h.push([t, +(src.h * s).toFixed(1), 'linear']);
  }
  return kf;
}
