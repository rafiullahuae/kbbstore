// KBB Studio · viral · retention pacing (pure ESM).
// A score per second (0..100) from what keeps a viewer watching: cuts, zooms, text changes, motion and speech energy.
// Runs of low seconds are "dead zones"; the fixes (jump cut, punch-in, b-roll/sticker, text pop) live in commands.js.

export const WEIGHTS = { cut: 34, zoom: 24, text: 18, caption: 8, motion: 16, speech: 40 };
export const DEAD = { below: 32, minRun: 2 };

/**
 * events = { duration, cuts:[t], zooms:[t], texts:[t], captions:[t], motion:[{t, v 0..1}], speech:[{t, v 0..1}] }
 * -> { perSec:[{t, score, parts}], mean, dead:[{t0, t1, min}] }
 */
export function scorePacing(ev, opt = {}) {
  const D = Math.max(1, Math.ceil(ev.duration || 0));
  const W = { ...WEIGHTS, ...(opt.weights || {}) };
  const per = [];
  // an event keeps paying off for a moment after it happens: full in its own second, half in the next
  const bump = (arr, t, w) => { const i = Math.floor(t); if (i >= 0 && i < D) arr[i] += w; if (i + 1 < D) arr[i + 1] += w * 0.5; };
  const cut = new Float32Array(D), zoom = new Float32Array(D), text = new Float32Array(D), cap = new Float32Array(D);
  for (const t of ev.cuts || []) bump(cut, t, W.cut);
  for (const t of ev.zooms || []) bump(zoom, t, W.zoom);
  for (const t of ev.texts || []) bump(text, t, W.text);
  for (const t of ev.captions || []) { const i = Math.floor(t); if (i >= 0 && i < D) cap[i] += W.caption; }
  const avg = (list, i) => { const xs = (list || []).filter((s) => s.t >= i && s.t < i + 1); return xs.length ? xs.reduce((a, s) => a + s.v, 0) / xs.length : 0; };
  for (let i = 0; i < D; i++) {
    const parts = { cut: Math.min(W.cut, cut[i]), zoom: Math.min(W.zoom, zoom[i]), text: Math.min(W.text, text[i]), caption: Math.min(W.caption * 2, cap[i]), motion: W.motion * avg(ev.motion, i), speech: W.speech * avg(ev.speech, i) };
    const score = Math.min(100, Object.values(parts).reduce((a, b) => a + b, 0));
    per.push({ t: i, score: Math.round(score), parts: Object.fromEntries(Object.entries(parts).map(([k, v]) => [k, Math.round(v)])) });
  }
  const below = opt.below ?? DEAD.below, minRun = opt.minRun ?? DEAD.minRun, dead = [];
  let a = -1;
  for (let i = 0; i <= D; i++) {
    const low = i < D && per[i].score < below;
    if (low && a < 0) a = i;
    if (!low && a >= 0) { if (i - a >= minRun) dead.push({ t0: a, t1: Math.min(i, ev.duration || i), min: Math.min(...per.slice(a, i).map((p) => p.score)) }); a = -1; }
  }
  const mean = per.length ? Math.round(per.reduce((s, p) => s + p.score, 0) / per.length) : 0;
  // the first three seconds decide the swipe: report them on their own
  const hook = per.slice(0, 3);
  return { perSec: per, mean, hook: hook.length ? Math.round(hook.reduce((s, p) => s + p.score, 0) / hook.length) : 0, dead };
}

/** heat colour for a score (red -> amber -> green) */
export function heat(score) {
  const s = Math.max(0, Math.min(100, score)) / 100;
  const h = s < 0.5 ? 0 + 40 * (s / 0.5) : 40 + 90 * ((s - 0.5) / 0.5);
  return `hsl(${Math.round(h)} 80% ${Math.round(46 + 6 * s)}%)`;
}
