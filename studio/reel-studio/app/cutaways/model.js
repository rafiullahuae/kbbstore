// KBB Studio · cutaways — show something while talking (pure functions, no DOM).
//
// A cutaway is ONE item on the overlay track, type 'cutaway', full frame, over a marked range [t0, t1):
//   media?   an image or another video (its own sound muted unless style.mix > 0)
//   style    { fit: 'fill'|'blur'|'pip'|'split', kb (Ken Burns 0..0.2), tin, tout ('none'|'dissolve'|'slide'|'zoom'|'whip'|
//              'wipe'|'flash'), tdur, corner ('tr'|'tl'|'br'|'bl', pip), mix (0..1 its own sound), slide? (see slide.js) }
//   link     { m, s0, s1, c }  the SOURCE range of the talking clip under it (media id, source s, clip id) — so the cutaway
//            follows trims, splits, ripples, speed changes and re-ordering of that clip, and goes when that range does.
// Only the picture changes over the range: the voice, music and captions are untouched (a cutaway is never audible
// unless its own video's sound is mixed in on purpose, and it is drawn below the caption track).

import { createItem, findMedia, itemDur, itemEnd, tracksOfKind, clone, uid } from '../core/project.js';
import { speechClips, anchorOf, layoutSig } from '../sync/follow.js';

export const TYPE = 'cutaway';
export const FITS = { fill: 'Fill (Ken Burns)', blur: 'Fit + blurred background', pip: 'Speaker in a corner bubble', split: 'Split screen' };
export const TRANSITIONS = { none: 'Cut', dissolve: 'Dissolve', slide: 'Slide', zoom: 'Zoom', whip: 'Whip', wipe: 'Wipe', flash: 'Flash' };
export const DEFAULT_STYLE = { fit: 'fill', kb: 0.08, tin: 'dissolve', tout: 'dissolve', tdur: 0.25, corner: 'tr', mix: 0 };

const r4 = (x) => Math.round(x * 10000) / 10000;
export const isCutaway = (it) => it?.type === TYPE;
export function cutaways(p) { const out = []; for (const tr of p.tracks) for (const it of tr.items) if (isCutaway(it)) out.push({ track: tr, item: it }); return out; }

/** frame-exact range: both ends snapped to the frame grid (so the edges land within one frame of the marks) */
export function snapRange(p, t0, t1) {
  const f = p.canvas.fps || 30;
  const a = Math.round(t0 * f) / f, b = Math.round(t1 * f) / f;
  return [r4(Math.max(0, Math.min(a, b))), r4(Math.max(a, b))];
}

/** link of a timeline range to the talking clip under it */
export function linkFor(p, t0, t1) {
  const a = anchorOf(speechClips(p), t0, t1);
  return a ? { m: a[0], s0: a[1], s1: r4(a[1] + (t1 - t0) * (speedAt(p, t0) || 1)), c: a[3] } : null;
}
const speedAt = (p, t) => { for (const c of speechClips(p)) if (t >= c.t0 && t < itemEnd(c)) return c.speed || 1; return 1; };

/**
 * A new cutaway item over [t0, t1). what = { media?: Media, slide?: {...}, name? }, style overrides DEFAULT_STYLE.
 * A video cutaway starts at its own beginning (or `srcIn`) and is muted unless style.mix > 0.
 */
export function makeCutaway(p, t0, t1, what = {}, style = {}) {
  [t0, t1] = snapRange(p, t0, t1);
  const dur = t1 - t0, m = what.media || null, st = { ...DEFAULT_STYLE, ...style };
  if (what.slide) st.slide = clone(what.slide);
  let inn = 0, out = dur, speed = 1;
  if (m && m.kind === 'video') {
    inn = Math.max(0, Math.min(what.srcIn || 0, Math.max(0, (m.dur || dur) - dur)));
    out = inn + dur;
    if (m.dur && m.dur < dur) { speed = r4(m.dur / dur); inn = 0; out = m.dur; }   // a short clip slows to fill the range
  }
  return createItem(TYPE, {
    t0, in: r4(inn), out: r4(out), speed, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h,
    media: m ? m.id : undefined, muted: !(st.mix > 0), volume: st.mix > 0 ? st.mix : 0,
    style: st, link: linkFor(p, t0, t1), name: what.name || (what.slide ? 'Slide · ' + (what.slide.title || 'untitled') : 'Cutaway · ' + (m?.name || 'picture')),
  });
}

/** the overlay track cutaways go on (the first unlocked one), or null */
export const cutawayTrack = (p) => tracksOfKind(p, 'overlay').find((t) => !t.locked) || tracksOfKind(p, 'overlay')[0] || null;

/** timeline segments that show source range [s0, s1] of media m: [[t0, t1, clip]] sorted, contiguous runs merged */
export function segmentsOf(clips, m, s0, s1, fps = 30) {
  const segs = [];
  for (const c of clips) {
    if (c.media !== m) continue;
    const a = Math.max(s0, c.in), b = Math.min(s1, c.out);
    if (b - a < 1 / fps / 2) continue;
    const sp = c.speed || 1;
    segs.push([c.t0 + (a - c.in) / sp, c.t0 + (b - c.in) / sp, c]);
  }
  segs.sort((x, y) => x[0] - y[0]);
  const runs = [];
  for (const s of segs) { const r = runs[runs.length - 1]; if (r && s[0] - r[1] < 1 / fps) { r[1] = Math.max(r[1], s[1]); r[3] += s[1] - s[0]; } else runs.push([s[0], s[1], s[2], s[1] - s[0]]); }
  return runs;
}

/** where a linked cutaway belongs now: {t0, dur} or null (its range is no longer on the timeline) */
export function placeLinked(p, it, clips = speechClips(p)) {
  const L = it.link; if (!L) return null;
  const runs = segmentsOf(clips, L.m, L.s0, L.s1, p.canvas.fps || 30);
  if (!runs.length) return null;
  // the run holding the clip it was made on, else the longest
  const run = runs.find((r) => r[2].id === L.c) || runs.reduce((a, b) => (b[3] > a[3] ? b : a));
  const [t0, t1] = snapRange(p, run[0], run[1]);
  return { t0, dur: r4(t1 - t0) };
}

/** item fields for a cutaway shown over [t0, t0 + dur): keeps the picture's own start, re-times its length */
export function retimed(p, it, t0, dur) {
  const m = it.media ? findMedia(p, it.media) : null, sp = it.speed || 1;
  let out = r4(it.in + dur * sp);
  if (m && m.kind === 'video' && m.dur && out > m.dur) out = r4(m.dur);
  return { t0: r4(t0), out: m && m.kind === 'video' ? out : r4(it.in + dur) };
}

/** state the follow hook compares against: layout signature + each cutaway's timing */
export function cutawayState(p) {
  const cuts = new Map();
  for (const { item } of cutaways(p)) cuts.set(item.id, [item.t0, itemDur(item), item.link ? item.link.s0 : null]);
  return { sig: layoutSig(p), cuts };
}

/**
 * The follow-up after an edit: [{id, after: item|null}] for each cutaway that has to move, be re-linked or go.
 *   clips changed     -> every linked cutaway is placed from its link (gone when its range was deleted);
 *   only it changed   -> (moved, trimmed, newly added) re-link it from where it now sits.
 */
export function followChanges(p, st) {
  const out = [], clips = speechClips(p), layoutChanged = layoutSig(p) !== st.sig;
  for (const { item } of cutaways(p)) {
    const was = st.cuts.get(item.id), dur = itemDur(item);
    const moved = !was || Math.abs(was[0] - item.t0) > 1e-4 || Math.abs(was[1] - dur) > 1e-4;
    if (layoutChanged && item.link) {
      const pl = placeLinked(p, item, clips);
      if (!pl) { out.push({ id: item.id, after: null }); continue; }
      if (Math.abs(pl.t0 - item.t0) > 1e-4 || Math.abs(pl.dur - dur) > 1e-4) out.push({ id: item.id, after: { ...clone(item), ...retimed(p, item, pl.t0, pl.dur) } });
    } else if (moved) {
      const link = linkFor(p, item.t0, item.t0 + dur);
      if (JSON.stringify(link) !== JSON.stringify(item.link || null)) out.push({ id: item.id, after: { ...clone(item), link } });
    }
  }
  return out;
}

/** a command that swaps whole items (by id, in place) between before/after snapshots; after null = removed */
export function itemsSwap(p, label, changes) {
  const recs = [];
  for (const c of changes) {
    for (const tr of p.tracks) {
      const i = tr.items.findIndex((x) => x.id === c.id);
      if (i >= 0) { recs.push({ trackId: tr.id, index: i, before: clone(tr.items[i]), after: c.after ? clone(c.after) : null }); break; }
    }
  }
  if (!recs.length) return null;
  const put = (q, which) => {
    for (const r of which === 'after' ? recs : recs.slice().reverse()) {
      const tr = q.tracks.find((t) => t.id === r.trackId); if (!tr) continue;
      const v = r[which], i = tr.items.findIndex((x) => x.id === r.before.id);
      if (i >= 0) { if (v) tr.items[i] = clone(v); else tr.items.splice(i, 1); }
      else if (v) tr.items.splice(Math.min(r.index, tr.items.length), 0, clone(v));
    }
  };
  return { label, do: (q) => put(q, 'after'), undo: (q) => put(q, 'before') };
}

export { uid };
