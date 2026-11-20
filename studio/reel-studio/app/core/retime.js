// KBB Studio — core/retime.js (CONTRACT, owned by R1): speed, speed ramps, reverse and freeze frames as pure maths.
//
// Item fields (all optional; an item without them is a plain constant-speed clip exactly as before):
//   speed      the AVERAGE playback rate; on-timeline duration = (out - in) / speed — always, ramp or not, so every
//              module that only needs a duration keeps working unchanged.
//   curve      { pts:[[u, v], ...], a, b, preset? }   a speed ramp. pts: speed v (×) at u, u = position along the clip on
//              the TIMELINE (0 = its start, 1 = its end when it was made), sorted, eased with a sine between points
//              (smooth, no corners). [a, b] is the window of that domain the item shows now (a split or a trim moves it,
//              so the pieces of a ramped clip play exactly what the whole clip played). The curve's SHAPE is relative:
//              the item plays at  speed · v(u) / mean(v over [a, b]),  so the source covered is exactly out − in and
//              the duration is the integral of the curve:  D = ∫ ds / v.
//   reverse    true: the source plays backwards (out → in).
//   freeze     true: a hold of the frame at `in` for the item's duration (in..out only measures the duration; silent).
//   keepPitch  false: "tape" sound (pitch follows speed). Absent / true: pitch kept (time-stretch).
//   rampMute   true: the clip's sound is silent wherever its speed is away from 1× (soft edges, no clicks).
//   smooth     'off' | 'blend' | 'flow': how slow motion fills the frames between source frames (blend = cross-fade of
//              the two neighbouring real frames; flow = motion-interpolated, export only). Absent = 'off'.
//
// The mapping is analytic (the sine ease integrates in closed form) and inverted with a guarded Newton step, so
// timeline → source → timeline round-trips to below a microsecond: the playhead, the export, captions and cutaways all
// use the same two functions, srcTime and tlTime.

const PI = Math.PI;
const ease = (x) => (1 - Math.cos(PI * x)) / 2;
const easeInt = (x) => x / 2 - Math.sin(PI * x) / (2 * PI);          // ∫0^x ease
export const MIN_SPEED = 0.05, MAX_SPEED = 16;
const clampV = (v) => Math.max(MIN_SPEED, Math.min(MAX_SPEED, +v || 1));

/** the speed curve value at u (constant beyond the first / last point) */
export function curveV(pts, u) {
  const n = pts.length;
  if (!n) return 1;
  if (u <= pts[0][0]) return pts[0][1];
  if (u >= pts[n - 1][0]) return pts[n - 1][1];
  for (let i = 0; i < n - 1; i++) {
    const [u0, v0] = pts[i], [u1, v1] = pts[i + 1];
    if (u <= u1) return v0 + (v1 - v0) * ease((u - u0) / Math.max(1e-9, u1 - u0));
  }
  return pts[n - 1][1];
}

// cumulative integral at each point, cached per pts array (projects are cloned on every edit, so identity is a safe key)
const cumCache = new WeakMap();
function cum(pts) {
  let c = cumCache.get(pts);
  if (c) return c;
  c = [0];
  for (let i = 0; i < pts.length - 1; i++) { const [u0, v0] = pts[i], [u1, v1] = pts[i + 1]; c.push(c[i] + (u1 - u0) * (v0 + (v1 - v0) * 0.5)); }
  cumCache.set(pts, c);
  return c;
}

/** G(u) = ∫0^u v — linear beyond the ends (u may run outside [0, 1] after a trim that extends the clip) */
export function curveG(pts, u) {
  const n = pts.length;
  if (!n) return u;
  const c = cum(pts);
  if (u <= pts[0][0]) return (u - pts[0][0]) * pts[0][1] - pts[0][0] * 0 + gStart(pts);
  if (u >= pts[n - 1][0]) return c[n - 1] + gStart(pts) + (u - pts[n - 1][0]) * pts[n - 1][1];
  for (let i = 0; i < n - 1; i++) {
    const [u0, v0] = pts[i], [u1, v1] = pts[i + 1];
    if (u <= u1) { const L = Math.max(1e-9, u1 - u0), x = (u - u0) / L; return gStart(pts) + c[i] + L * (v0 * x + (v1 - v0) * easeInt(x)); }
  }
  return c[n - 1] + gStart(pts);
}
// the integral from 0 to the first point (constant v before it)
const gStart = (pts) => pts[0][0] * pts[0][1];

/** inverse of curveG: the u where G(u) = g */
export function curveGinv(pts, g) {
  const n = pts.length;
  if (!n) return g;
  const c = cum(pts), g0 = gStart(pts);
  if (g <= g0) return pts[0][0] + (g - g0) / pts[0][1];
  if (g >= g0 + c[n - 1]) return pts[n - 1][0] + (g - g0 - c[n - 1]) / pts[n - 1][1];
  let i = 0; while (i < n - 2 && g0 + c[i + 1] < g) i++;
  const [u0, v0] = pts[i], [u1, v1] = pts[i + 1], L = Math.max(1e-9, u1 - u0), target = (g - g0 - c[i]) / L;
  // solve v0·x + (v1−v0)·easeInt(x) = target on [0, 1]: monotonic (v > 0), Newton with a bisection guard
  let lo = 0, hi = 1, x = Math.min(1, Math.max(0, target / Math.max(1e-9, (v0 + v1) / 2)));
  for (let k = 0; k < 40; k++) {
    const f = v0 * x + (v1 - v0) * easeInt(x) - target;
    if (Math.abs(f) < 1e-13) break;
    if (f > 0) hi = x; else lo = x;
    const d = v0 + (v1 - v0) * ease(x);
    let nx = x - f / d;
    if (!(nx > lo && nx < hi)) nx = (lo + hi) / 2;
    x = nx;
  }
  return u0 + x * L;
}

const hasCurve = (it) => !!(it.curve && it.curve.pts && it.curve.pts.length >= 2);
const win = (it) => ({ a: it.curve.a ?? 0, b: it.curve.b ?? 1 });
export const isRetimed = (it) => !!(it && ((it.speed || 1) !== 1 || hasCurve(it) || it.reverse || it.freeze));
export const retimeDur = (it) => Math.max(0, (it.out - it.in) / (it.speed || 1));

/** the fraction 0..1 of the source range played at fraction x 0..1 of the item's time */
export function fracSrc(it, x) {
  if (!hasCurve(it)) return x;
  const { a, b } = win(it), pts = it.curve.pts, A = curveG(pts, a), B = curveG(pts, b);
  if (B - A <= 1e-12) return x;
  return (curveG(pts, a + x * (b - a)) - A) / (B - A);
}
/** inverse of fracSrc */
export function fracTime(it, f) {
  if (!hasCurve(it)) return f;
  const { a, b } = win(it), pts = it.curve.pts, A = curveG(pts, a), B = curveG(pts, b);
  if (B - A <= 1e-12 || b - a <= 1e-12) return f;
  return (curveGinv(pts, A + f * (B - A)) - a) / (b - a);
}

/** source time at timeline time t (clamped to the item) */
export function srcTime(it, t) {
  const D = retimeDur(it), S = it.out - it.in;
  if (it.freeze) return it.in;
  if (!hasCurve(it) && !it.reverse) {
    const s = Math.min(it.out, Math.max(it.in, it.in + (t - it.t0) * (it.speed || 1)));
    // a looped music bed (audio/music.js): source a..b repeats to fill the item
    const L = it.loop;
    return L && L.b - L.a > 0.05 && s >= L.b ? L.a + ((s - L.b) % (L.b - L.a)) : s;
  }
  const x = D > 0 ? Math.min(1, Math.max(0, (t - it.t0) / D)) : 0, f = fracSrc(it, x);
  return it.reverse ? it.out - f * S : it.in + f * S;
}

/** timeline time at which the item shows source time s (constant speed: extrapolated past the ends, like before) */
export function tlTime(it, s) {
  const D = retimeDur(it), S = it.out - it.in;
  if (it.freeze) return it.t0;
  if (!hasCurve(it) && !it.reverse) return it.t0 + (s - it.in) / (it.speed || 1);
  const f = S > 0 ? Math.min(1, Math.max(0, it.reverse ? (it.out - s) / S : (s - it.in) / S)) : 0;
  return it.t0 + fracTime(it, f) * D;
}

/** instantaneous playback rate at timeline time t (×, always positive; see it.reverse for the direction) */
export function speedAt(it, t) {
  if (it.freeze) return 0;
  const sp = it.speed || 1;
  if (!hasCurve(it)) return sp;
  const { a, b } = win(it), pts = it.curve.pts, D = retimeDur(it);
  const mean = (curveG(pts, b) - curveG(pts, a)) / Math.max(1e-9, b - a);
  const x = D > 0 ? Math.min(1, Math.max(0, (t - it.t0) / D)) : 0;
  return sp * curveV(pts, a + x * (b - a)) / Math.max(1e-9, mean);
}

/**
 * Does the item read its frames from a decoder stream (core/frames.js) rather than a playing <video>? Ramps, reverse,
 * freeze and smooth slow motion do — exact frames at any mapping, both neighbours for blending.
 */
export function usesFrameStream(it) {
  if (!it || it.type === 'audio') return false;
  return hasCurve(it) || !!it.reverse || !!it.freeze || ((it.smooth === 'blend' || it.smooth === 'flow') && (it.speed || 1) < 1);
}
/**
 * The preview monitor also reads a constant-speed clip from the decoder when the decode it needs is modest
 * (speed × fps × pixels ≤ 1080p at 60 fps): every tick then shows its exact frame. Faster than that (3× of 1080p30, 4K
 * at 2×) a playing <video> keeps time better, dropping frames instead of falling behind.
 */
export function previewStream(it, m) {
  if (usesFrameStream(it)) return true;
  if (!it || it.type === 'audio' || (it.speed || 1) === 1) return false;
  const px = (m?.w || 1920) * (m?.h || 1080), fps = m?.fps || 30;
  return (it.speed || 1) * fps * px <= 1.3e8;
}
/** blend weight is wanted at t (smooth slow motion and the clip slower than 1× there): true → blend a/b; null → plain frame */
export function blendWeight(it, t) {
  if (it.freeze || !(it.smooth === 'blend' || it.smooth === 'flow')) return null;
  return speedAt(it, t) < 0.999 ? true : null;
}

/** the mean of a curve over [a, b] (the `speed` a curve item gets for a given shape) */
export function curveMean(pts, a = 0, b = 1) { return (curveG(pts, b) - curveG(pts, a)) / Math.max(1e-9, b - a); }

/** the source range of timeline [t0, t1] on this item: [lo, hi] (sorted) */
export function srcRange(it, t0, t1) { const a = srcTime(it, t0), b = srcTime(it, t1); return a <= b ? [a, b] : [b, a]; }

/** props for the two halves of a split at timeline time t: { left, right } (exact for ramps, reverse and freeze) */
export function splitProps(it, t) {
  const D = retimeDur(it), dl = t - it.t0, dr = D - dl;
  if (it.freeze) return { left: { out: it.in + dl * (it.speed || 1) }, right: { t0: t, in: it.in, out: it.in + dr * (it.speed || 1) } };
  if (!hasCurve(it) && !it.reverse) { const cut = it.in + dl * (it.speed || 1); return { left: { out: cut }, right: { t0: t, in: cut } }; }
  const s = srcTime(it, t);
  const L = it.reverse ? { in: s, out: it.out } : { in: it.in, out: s };
  const R = it.reverse ? { in: it.in, out: s } : { in: s, out: it.out };
  L.speed = (L.out - L.in) / Math.max(1e-9, dl); R.speed = (R.out - R.in) / Math.max(1e-9, dr);
  if (hasCurve(it)) {
    const { a, b } = win(it), uc = a + (dl / Math.max(1e-9, D)) * (b - a);
    L.curve = Object.assign({}, it.curve, { a, b: uc }); R.curve = Object.assign({}, it.curve, { a: uc, b });
  }
  const left = { out: L.out, in: L.in, speed: L.speed }; if (L.curve) left.curve = L.curve;
  const right = { t0: t, in: R.in, out: R.out, speed: R.speed }; if (R.curve) right.curve = R.curve;
  return { left, right };
}

/**
 * props for trimming a retimed item to timeline [nt0, nt1] — the frames that stay keep their timing (a ramp keeps its
 * shape; extending past the ends continues at the end speed). maxSrc caps the source (media duration).
 */
export function trimProps(it, nt0, nt1, maxSrc = Infinity) {
  const D = retimeDur(it), S = it.out - it.in, sp = it.speed || 1;
  nt1 = Math.max(nt0 + 1e-3, nt1);
  if (it.freeze) return { t0: nt0, in: it.in, out: it.in + (nt1 - nt0) * sp };
  // source position (unclamped) at timeline time t, and the curve domain position there
  const uAt = (t) => { if (!hasCurve(it)) return null; const { a, b } = win(it); return a + ((t - it.t0) / Math.max(1e-9, D)) * (b - a); };
  const fAt = (t) => {
    if (!hasCurve(it)) return (t - it.t0) / Math.max(1e-9, D);
    const { a, b } = win(it), pts = it.curve.pts, A = curveG(pts, a), B = curveG(pts, b);
    return (curveG(pts, uAt(t)) - A) / Math.max(1e-12, B - A);
  };
  let f0 = fAt(nt0), f1 = fAt(nt1);
  // never past the media: 0 ≤ source ≤ maxSrc
  const srcOf = (f) => (it.reverse ? it.out - f * S : it.in + f * S);
  const limF = (f) => { const s = srcOf(f), c = Math.min(maxSrc, Math.max(0, s)); return it.reverse ? (it.out - c) / S : (c - it.in) / S; };
  const g0 = limF(f0), g1 = limF(f1);
  if (g0 !== f0 && hasCurve(it)) nt0 = it.t0 + fracTime(it, Math.max(-1e9, g0)) * D; else if (g0 !== f0) nt0 = it.t0 + g0 * D;
  if (g1 !== f1 && hasCurve(it)) nt1 = it.t0 + fracTime(it, g1) * D; else if (g1 !== f1) nt1 = it.t0 + g1 * D;
  f0 = g0; f1 = g1;
  const s0 = srcOf(f0), s1 = srcOf(f1), lo = Math.min(s0, s1), hi = Math.max(s0, s1);
  const out = { t0: nt0, in: lo, out: hi, speed: (hi - lo) / Math.max(1e-9, nt1 - nt0) };
  if (hasCurve(it)) out.curve = Object.assign({}, it.curve, { a: uAt(nt0), b: uAt(nt1) });
  return out;
}

/** props that stretch the item to a new duration (Shift-drag on an edge): same source, same ramp shape */
export function stretchProps(it, newDur) {
  const d = Math.max(0.04, newDur);
  return { speed: Math.max(MIN_SPEED, Math.min(MAX_SPEED, (it.out - it.in) / d)) };
}

/** props that set a curve (or clear it with null), with `speed` = its mean so the duration is the integral */
export function curveProps(it, pts, preset) {
  if (!pts) return { curve: null, speed: 1 };
  const clean = pts.map(([u, v]) => [Math.round(Math.min(1, Math.max(0, u)) * 10000) / 10000, Math.round(clampV(v) * 1000) / 1000]).sort((x, y) => x[0] - y[0]);
  const curve = { pts: clean, a: 0, b: 1 };
  if (preset) curve.preset = preset;
  return { curve, speed: +curveMean(clean, 0, 1).toFixed(6) };
}

// ── presets (shapes over the clip; the duration follows from them) ──
export const PRESETS = {
  'slowmo-hit': { label: 'Slow-mo hit', icon: '🎯', pts: [[0, 1], [0.3, 1], [0.42, 0.3], [0.62, 0.3], [0.76, 1], [1, 1]] },
  'speed-up': { label: 'Speed-up', icon: '⏩', pts: [[0, 1], [0.35, 1], [0.75, 3], [1, 3]] },
  'ramp-in': { label: 'Ramp in', icon: '↗', pts: [[0, 0.35], [0.55, 1], [1, 1]] },
  'ramp-out': { label: 'Ramp out', icon: '↘', pts: [[0, 1], [0.45, 1], [1, 0.35]] },
  'bullet-time': { label: 'Bullet time', icon: '🌀', pts: [[0, 2], [0.22, 2], [0.36, 0.15], [0.66, 0.15], [0.8, 2], [1, 2]] },
  montage: { label: 'Montage', icon: '🎞', pts: [[0, 2.5], [0.18, 2.5], [0.25, 0.5], [0.33, 0.5], [0.4, 2.5], [0.58, 2.5], [0.65, 0.5], [0.73, 0.5], [0.8, 2.5], [1, 2.5]] },
};

/**
 * Sound gain at a playback speed: 1 inside 0.25×–4×, fading smoothly (log-speed smoothstep) to silence at 0.18× and 5.5×
 * — beyond those a stretched voice is noise, so it goes quiet instead of clicking. rampMute: also silent wherever the
 * speed is away from 1× (≥ 6 % off fully silent, ≤ 1.5 % fully on).
 */
export function speedGain(v, rampMute = false) {
  const lv = Math.log(Math.max(1e-6, v));
  const sm = (e0, e1, x) => { const k = Math.min(1, Math.max(0, (x - e0) / (e1 - e0))); return k * k * (3 - 2 * k); };
  let g = sm(Math.log(0.18), Math.log(0.25), lv) * (1 - sm(Math.log(4), Math.log(5.5), lv));
  if (rampMute) g *= 1 - sm(0.015, 0.06, Math.abs(lv));
  return g;
}

/** everything that changes how an item's sound is rendered (a cache key for the retimed sound) */
export function retimeSig(it) {
  return JSON.stringify([it.media, +it.in.toFixed(5), +it.out.toFixed(5), +(it.speed || 1).toFixed(6), hasCurve(it) ? [it.curve.pts, it.curve.a ?? 0, it.curve.b ?? 1] : 0, !!it.reverse, !!it.freeze, it.keepPitch !== false, !!it.rampMute]);
}
