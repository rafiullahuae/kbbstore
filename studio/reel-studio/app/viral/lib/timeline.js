// KBB Studio · viral · timeline surgery (pure ESM, edits a project IN PLACE — call it inside ops.mutate so it undoes).
//   cutRanges(p, ranges, opt)   remove timeline ranges: clips on video tracks split into their kept parts (with audio
//                               micro-fades at every join so nothing clicks), everything later ripples left, music is
//                               shortened (never chopped), captions, markers and fx times follow.
//   insertGap(p, at, d)         open d seconds at `at` (everything from `at` on moves right; spanning items stretch)
//   mapTime(ranges)             t -> t' after the cut
import { uid, clone, itemDur, itemEnd, findMedia, tlTime } from '../../core/project.js';

const EPS = 1e-6;

export function normRanges(ranges) {
  const rs = ranges.map((r) => [Math.max(0, +(r.a ?? r[0])), +(r.b ?? r[1])]).filter(([a, b]) => b - a > 1e-4).sort((x, y) => x[0] - y[0]);
  const out = [];
  for (const r of rs) { const l = out[out.length - 1]; if (l && r[0] <= l[1] + EPS) l[1] = Math.max(l[1], r[1]); else out.push(r.slice()); }
  return out;
}
/** seconds removed before t (part of a range when t falls inside it) */
export function removedBefore(rs, t) { let s = 0; for (const [a, b] of rs) { if (a >= t) break; s += Math.min(b, t) - a; } return s; }
export const mapTime = (ranges) => { const rs = normRanges(ranges); return (t) => t - removedBefore(rs, t); };

/** the parts of [s, e] not covered by the ranges */
function keptParts(rs, s, e) {
  const out = []; let x = s;
  for (const [a, b] of rs) { if (b <= x + EPS) continue; if (a >= e - EPS) break; if (a > x + EPS) out.push([x, a]); x = Math.max(x, b); }
  if (e > x + EPS) out.push([x, e]);
  return out;
}

const shiftFx = (fx, map) => (fx || []).map((f) => (typeof f.t0 === 'number' ? { ...f, t0: +map(f.t0).toFixed(4) } : f));

function microFade(item, fadeIn, fadeOut, f) {
  const d = itemDur(item); if (d <= 2 * f + 0.01 || item.kf?.volume?.length) return;
  const v = item.volume ?? 1, k = [];
  k.push([0, fadeIn ? 0 : v, 'linear']); k.push([+f.toFixed(4), v, 'linear']); k.push([+(d - f).toFixed(4), v, 'linear']); k.push([+d.toFixed(4), fadeOut ? 0 : v, 'linear']);
  item.kf = { ...(item.kf || {}), volume: k };
}

/**
 * opt = { fade: 0.012 (s, audio micro-fade at each join; 0 = none) }
 * returns { removed, joins:[t'] } (join times on the NEW timeline)
 */
export function cutRanges(p, ranges, opt = {}) {
  const rs = normRanges(ranges); if (!rs.length) return { removed: 0, joins: [] };
  const fade = opt.fade ?? 0.012;
  const map = (t) => t - removedBefore(rs, t);
  const joins = new Set();
  for (const tr of p.tracks) {
    const next = [];
    for (const it of tr.items) {
      const s = it.t0, e = itemEnd(it), sp = it.speed || 1;
      const m = it.media ? findMedia(p, it.media) : null;
      const splittable = tr.kind === 'video' && m && m.kind === 'video' && it.type === 'clip';
      if (splittable) {
        const parts = keptParts(rs, s, e);
        parts.forEach(([x, y]) => {
          const c = clone(it);
          if (parts.length > 1 || x > s + EPS || y < e - EPS) c.id = x > s + EPS ? uid('i') : it.id;
          c.t0 = +map(x).toFixed(4); c.in = +(it.in + (x - s) * sp).toFixed(4); c.out = +(it.in + (y - s) * sp).toFixed(4);
          if (c.kf) for (const k of Object.keys(c.kf)) c.kf[k] = c.kf[k].map(([kt, v, ez]) => [+(kt - (x - s) / sp).toFixed(4), v, ez]);   // evalProp interpolates across a negative first key
          c.fx = shiftFx(c.fx, map);
          const inJoin = x > s + EPS || rs.some(([, b]) => Math.abs(b - x) < 1e-3), outJoin = y < e - EPS || rs.some(([a]) => Math.abs(a - y) < 1e-3);
          if (fade > 0 && (inJoin || outJoin)) microFade(c, inJoin, outJoin, fade);
          if (inJoin) { joins.add(+c.t0.toFixed(3)); c.fadeIn = 0; }
          if (outJoin) c.fadeOut = 0;
          next.push(c);
        });
        continue;
      }
      const c = clone(it);
      const ns = map(s), ne = map(e);
      if (it.type === 'caption') { c.out = +(c.in + (ne - ns) * sp).toFixed(4); c.t0 = +ns.toFixed(4); next.push(c); continue; }
      if (tr.kind === 'sfx') { c.t0 = +ns.toFixed(4); next.push(c); continue; }         // a sound effect keeps its length
      if (ne - ns < 0.05 && e - s > 0.05) continue;                                        // lived entirely inside a cut
      c.t0 = +ns.toFixed(4); c.out = +(c.in + (ne - ns) * sp).toFixed(4);                // music / overlays: shorter, never chopped
      c.fx = shiftFx(c.fx, map);
      if (c.kf && ne - ns < e - s - EPS) for (const k of Object.keys(c.kf)) c.kf[k] = c.kf[k].map(([kt, v, ez]) => [+(map(s + kt) - ns).toFixed(4), v, ez]);
      next.push(c);
    }
    tr.items = next;
  }
  const remapCap = (cap) => {
    if (!cap?.words?.length) return;
    cap.words = cap.words.map((w) => ({ ...w, t0: +map(w.t0).toFixed(4), t1: +Math.max(map(w.t0) + 0.02, map(w.t1)).toFixed(4) }));
    cap.lines = (cap.lines || []).map((l) => ({ ...l, t0: +map(l.t0).toFixed(4), t1: +map(l.t1).toFixed(4) }));
  };
  remapCap(p.captions); remapCap(p.captions?.alt);
  p.markers = (p.markers || []).map((k) => ({ ...k, t: +map(k.t).toFixed(4) }));
  return { removed: rs.reduce((a, [x, y]) => a + y - x, 0), joins: [...joins].sort((a, b) => a - b) };
}

/** open d seconds at `at`: later items move right; an item spanning `at` (captions, music, a long overlay) stretches */
export function insertGap(p, at, d) {
  const sh = (t) => (t >= at - EPS ? t + d : t);
  for (const tr of p.tracks) for (const it of tr.items) {
    if (it.type === 'caption' && itemEnd(it) > at - EPS) { if (it.t0 > at + EPS) it.t0 = +(it.t0 + d).toFixed(4); else it.out = +(it.out + d * (it.speed || 1)).toFixed(4); continue; }
    if (it.t0 >= at - EPS) { it.t0 = +(it.t0 + d).toFixed(4); it.fx = shiftFx(it.fx, sh); continue; }
    if (itemEnd(it) > at + EPS && (it.type === 'caption' || !it.media)) it.out = +(it.out + d * (it.speed || 1)).toFixed(4);
  }
  const shiftCap = (cap) => {
    if (!cap?.words?.length) return;
    cap.words = cap.words.map((w) => ({ ...w, t0: +sh(w.t0).toFixed(4), t1: +sh(w.t1).toFixed(4) }));
    cap.lines = (cap.lines || []).map((l) => ({ ...l, t0: +sh(l.t0).toFixed(4), t1: +sh(l.t1).toFixed(4) }));
  };
  shiftCap(p.captions); shiftCap(p.captions?.alt);
  p.markers = (p.markers || []).map((k) => ({ ...k, t: +sh(k.t).toFixed(4) }));
}

/** clips of the main video track, in time order */
export const mainClips = (p) => (p.tracks.find((t) => t.kind === 'video')?.items || []).filter((i) => i.type === 'clip').slice().sort((a, b) => a.t0 - b.t0);
/** the clip on the main track playing at t */
export const clipAt = (p, t) => mainClips(p).find((c) => t >= c.t0 - EPS && t < itemEnd(c) - EPS) || null;
/** source time -> timeline times for one media, through every clip that shows it */
export function srcToTimeline(p, mediaId, ranges) {
  const out = [];
  for (const c of mainClips(p)) if (c.media === mediaId) for (const [a, b] of ranges) {
    const s = Math.max(a, c.in), e = Math.min(b, c.out); if (e > s) { const x = tlTime(c, s), y = tlTime(c, e); out.push([Math.min(x, y), Math.max(x, y)]); }
  }
  return out.sort((x, y) => x[0] - y[0]);
}
