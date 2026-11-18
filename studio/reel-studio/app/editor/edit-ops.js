// KBB Studio — edit operations on the timeline (pure: project in, ONE history command out, no DOM).
//
//   splitCmd(p, targets, t)            blade: split items at a frame (the selected ones, or everything under t)
//   rangeCmd(p, a, b, { ripple })      delete a range [a, b) from the main video track(s): ripple (the gap closes, the
//                                      default) or lift (the gap stays). Clips across the edges are trimmed / split.
//   joinCmd(p, ids)                    rejoin adjacent pieces of the same recording (the opposite of a blade cut)
//   unlinkAudioCmd(p, ids)             a clip's own sound becomes its own item on a sound track (moves on its own)
//   nudgeTimeCmd(p, hits, frames)      move items in time by whole frames (comma / period)
//   rippleTrimProps(...)               the trims a ripple trim (Alt) applies to the items after the edge
//   placeCmd(p, moving, target)        drop items (moved, or new from the bin) on a track: magnetic insert on the main
//                                      track, overwrite with Alt, free elsewhere, a new track when asked or overlapping
//
// Everything that hangs off the clips (captions, cutaways, theme elements, transitions on cuts) is NOT touched here: the
// follow hooks (sync/follow.js, cutaways, editor/edit-follow.js) re-place it inside the SAME undo step. Durations always
// come from itemDur() and source times from srcTime() (core/project.js), so speed curves slot in in one place.

import { ops } from '../core/history.js';
import { itemDur, itemEnd, srcTime, tlTime, uid, clone, findTrack, findMedia, createTrack, createItem, VISUAL_KINDS, AUDIO_KINDS } from '../core/project.js';
import { fits } from './items.js';
import { splitProps } from '../core/retime.js';

const EPS = 1e-4;
const r4 = (x) => Math.round(x * 10000) / 10000;
export const fpsOf = (p) => p.canvas?.fps || 30;
/** a time on the project's frame grid */
export const snapFrame = (p, t) => Math.round(t * fpsOf(p)) / fpsOf(p);
/** timeline time of source time s inside item it (core/retime.js tlTime: speed, ramps, reverse, freeze) */
export const tlOf = (it, s) => tlTime(it, s);

/** the main (magnetic) track: the bottom video track */
export const mainTrack = (p) => p.tracks.find((t) => t.kind === 'video') || null;
export const isMain = (p, track) => !!track && track === mainTrack(p);
const TRANS = new Set(['theme.trans', 'look.transition']);
export const isTransition = (it) => TRANS.has(it?.type);

/** cuts on the main track: [{ a, b, t }] (clip a ends where clip b starts) */
export function cutsOf(p) {
  const vt = mainTrack(p); if (!vt) return [];
  const list = vt.items.filter((i) => i.type === 'clip').slice().sort((x, y) => x.t0 - y.t0), out = [];
  for (let i = 0; i + 1 < list.length; i++) if (Math.abs(itemEnd(list[i]) - list[i + 1].t0) < 0.05) out.push({ a: list[i], b: list[i + 1], t: list[i + 1].t0 });
  return out;
}

// ── split ──
/** the right half of `item` split at timeline t (a new id; the left keeps the old one) — through core/retime.js, so a
 *  ramp, a reversed clip or a hold is cut exactly: both halves play what the whole played */
function rightHalf(item, t) {
  const right = Object.assign(clone(item), splitProps(item, t).right); right.id = uid('i'); right.fadeIn = 0; right.kf = {};
  return right;
}
/** the props the left half keeps when `item` is cut at timeline t */
const leftHalf = (item, t) => Object.assign(splitProps(item, t).left, { fadeOut: 0 });
/**
 * Split `targets` ([{track, item}], or every unlocked item under t when empty) at frame t.
 * Returns { cmd, ids: [right halves] } or null when nothing is under t.
 */
export function splitCmd(p, targets, t) {
  t = snapFrame(p, t);
  const minW = 1 / fpsOf(p) / 2;
  // nothing chosen: every item under t, except the caption layer (one item that draws all the captions: cutting it
  // would only make the caption layer two items; the captions themselves follow the clips)
  let list = targets && targets.length ? targets : p.tracks.filter((tr) => !tr.locked).flatMap((track) => track.items.filter((it) => it.type !== 'caption').map((item) => ({ track, item })));
  list = list.filter(({ track, item }) => !track.locked && t > item.t0 + minW && t < itemEnd(item) - minW);
  if (!list.length) return null;
  const cmds = [], ids = [], groups = new Map();
  for (const { track, item } of list) {
    const right = rightHalf(item, t);
    // split a linked group: the right halves form their own group (they stay linked to each other)
    if (item.group) { if (!groups.has(item.group)) groups.set(item.group, uid('g')); right.group = groups.get(item.group); }
    cmds.push(ops.setItem(item.id, leftHalf(item, t), 'Split'));
    cmds.push(ops.addItems([{ trackId: track.id, item: right, index: track.items.indexOf(item) + 1 }]));
    ids.push(right.id);
  }
  return { cmd: ops.batch('Split', cmds), ids };
}

// ── range delete (ripple / lift) ──
/**
 * Remove [a, b) from the given tracks (default: the unlocked video tracks). Clips over an edge are trimmed, a clip over
 * both edges is split around the hole. ripple: everything on those tracks after b moves left by b − a.
 * Returns a command or null.
 */
export function rangeCmd(p, a, b, { ripple = true, trackIds = null } = {}) {
  a = snapFrame(p, Math.max(0, Math.min(a, b))); b = snapFrame(p, Math.max(a, b));
  if (b - a < 1 / fpsOf(p) / 2) return null;
  const tracks = p.tracks.filter((tr) => !tr.locked && (trackIds ? trackIds.includes(tr.id) : tr.kind === 'video'));
  const set = [], add = [], rm = [], d = ripple ? b - a : 0;
  let touched = false;
  for (const tr of tracks) {
    tr.items.forEach((it, index) => {
      const s = it.t0, e = itemEnd(it);
      if (e <= a + EPS) return;                                        // wholly before
      if (s >= b - EPS) { if (d) { set.push({ id: it.id, props: { t0: (s - d) } }); } return; }   // wholly after
      touched = true;
      if (s >= a - EPS && e <= b + EPS) { rm.push(it.id); return; }   // wholly inside
      if (s < a && e > b) {                                            // over both edges: split around the hole
        const right = rightHalf(it, b); right.t0 = b - d;
        set.push({ id: it.id, props: leftHalf(it, a) });
        add.push({ trackId: tr.id, item: right, index: index + 1 });
        return;
      }
      if (s < a) { set.push({ id: it.id, props: leftHalf(it, a) }); return; }   // over a
      set.push({ id: it.id, props: { ...splitProps(it, b).right, t0: b - d, fadeIn: 0, kf: {} } });   // over b
    });
  }
  if (!touched) return null;
  const cmds = [];
  if (set.length) cmds.push(ops.setItems(set, ripple ? 'Ripple delete' : 'Lift'));
  if (add.length) cmds.push(ops.addItems(add, 'Split'));
  if (rm.length) cmds.push(ops.removeItems(rm, 'Delete'));
  return ops.batch(ripple ? `Ripple delete ${(b - a).toFixed(2)} s` : `Lift ${(b - a).toFixed(2)} s`, cmds);
}

// ── rejoin ──
/** can b be joined onto a? (same track, same recording, b continues a in source AND on the timeline, same speed) */
export function joinable(p, a, b) {
  if (!a || !b || a.type !== b.type || a.media !== b.media || Math.abs((a.speed || 1) - (b.speed || 1)) > 1e-6) return false;
  // pieces of a ramp, a reversed clip or a hold are not rejoined (each piece carries its own part of the mapping)
  if (a.curve || b.curve || a.reverse || b.reverse || a.freeze || b.freeze) return false;
  const f = 1 / fpsOf(p) / 2;
  if (Math.abs(itemEnd(a) - b.t0) > f) return false;
  if (!a.media) return a.type !== 'clip' && a.text === b.text && JSON.stringify(a.style || null) === JSON.stringify(b.style || null);
  return Math.abs(a.out - b.in) < f * (a.speed || 1);
}
/**
 * Rejoin adjacent pieces: the given ids (each joined with the next piece that continues it), or, with one id / none,
 * the piece and its right neighbour. Transitions sitting on a joined cut go (there is no cut any more).
 */
export function joinCmd(p, ids) {
  const want = new Set(ids || []), set = [], rm = [], gone = new Set();
  for (const tr of p.tracks) {
    if (tr.locked) continue;
    const list = tr.items.slice().sort((x, y) => x.t0 - y.t0);
    for (let i = 0; i < list.length; i++) {
      let a = list[i]; if (gone.has(a.id) || !want.has(a.id)) continue;
      let out = a.out, fadeOut = a.fadeOut, end = itemEnd(a), j = i + 1, any = false;
      while (j < list.length) {
        const b = list[j];
        const probe = { ...a, out, t0: a.t0 };   // a as it would be after the joins so far
        if (!(want.size === 1 || want.has(b.id)) || !joinable(p, probe, b)) break;
        out = b.out; fadeOut = b.fadeOut; end = itemEnd(b); rm.push(b.id); gone.add(b.id); any = true; j++;
        if (want.size === 1) break;
      }
      if (any) set.push({ id: a.id, props: a.media ? { out: (out), fadeOut } : { out: (a.in + (end - a.t0) * (a.speed || 1)), fadeOut } });
    }
  }
  if (!rm.length) return null;
  // transitions on a cut that no longer exists
  for (const tr of p.tracks) for (const it of tr.items) if (isTransition(it) && (gone.has(it.style?.b) && set.some((s) => s.id === it.style?.a))) rm.push(it.id);
  return ops.batch('Join', [ops.setItems(set, 'Join'), ops.removeItems(rm, 'Join')]);
}

// ── linked audio ──
/** the first unlocked track of kind (null when none) */
const firstOf = (p, kind) => p.tracks.find((t) => t.kind === kind && !t.locked) || null;
/**
 * Unlink a clip's own sound: an 'audio' item with the same source range and speed on a "Voice" sound track, and the
 * clip goes silent. The sound gets its own media entry (kind 'audio', same file) so the preview plays it from its own
 * <audio> element and the two can sit at different times. After this they move apart; undo links them again.
 */
export function unlinkAudioCmd(p, hits) {
  const list = hits.filter(({ track, item }) => { const m = item.media && findMedia(p, item.media); return item.type === 'clip' && m && m.kind === 'video' && m.hasAudio && !item.muted && !track.locked; });
  if (!list.length) return null;
  const cmds = [], adds = [], made = new Map();
  let tr = p.tracks.find((t) => t.kind === 'sfx' && !t.locked && t.voice);
  if (!tr) { tr = Object.assign(createTrack('sfx', 'Voice'), { voice: true }); cmds.push(ops.addTrack(tr, p.tracks.length)); }
  for (const { item } of list) {
    const m = findMedia(p, item.media);
    let snd = made.get(m.id) || p.media.find((x) => x.kind === 'audio' && x.of === m.id);
    if (!snd) { snd = { id: uid('m'), kind: 'audio', of: m.id, name: m.name + ' (sound)', src: m.src, dur: m.dur, hasAudio: true, size: m.size }; if (m.audio) snd.audio = clone(m.audio); cmds.push(ops.addMedia(snd, 'Unlink audio')); }
    made.set(m.id, snd);
    adds.push({ trackId: tr.id, item: createItem('audio', { media: snd.id, t0: item.t0, in: item.in, out: item.out, speed: item.speed || 1, volume: item.volume ?? 1, fadeIn: item.fadeIn || 0, fadeOut: item.fadeOut || 0, name: '🔊 ' + (item.name || 'Sound'), unlinkedFrom: item.id }) });
  }
  cmds.push(ops.setItems(list.map(({ item }) => ({ id: item.id, props: { muted: true, audioUnlinked: true } })), 'Unlink audio'));
  cmds.push(ops.addItems(adds, 'Unlink audio'));
  return { cmd: ops.batch('Unlink audio', cmds), ids: adds.map((a) => a.item.id) };
}

// ── nudge in time ──
export function nudgeTimeCmd(p, hits, frames) {
  const list = hits.filter((x) => !x.track.locked); if (!list.length) return null;
  const dt = frames / fpsOf(p), lo = Math.min(...list.map((x) => x.item.t0));
  const d = Math.max(dt, -lo);
  if (Math.abs(d) < EPS) return null;
  const cmd = ops.moveItems(list.map(({ item }) => ({ id: item.id, t0: snapFrame(p, item.t0 + d) })), 'Nudge');
  return cmd;
}

// ── ripple trim ──
/** the items on `track` that a ripple trim of `item` carries (everything starting at or after its end) */
export const rippleFollowers = (track, item) => track.items.filter((o) => o.id !== item.id && o.t0 >= itemEnd(item) - 1e-3).map((o) => ({ id: o.id, t0: o.t0 }));

// ── place / move onto a track ──
/** does [t0, t1) on track hit anything not in `skip`? */
export function overlaps(track, t0, t1, skip) {
  for (const it of track.items) if (!skip.has(it.id) && it.t0 < t1 - 1e-3 && itemEnd(it) > t0 + 1e-3) return true;
  return false;
}
/** a new track for items of `type` and the index it goes in: visual ones on top of the stack (z-order), sound at the end */
export function newTrackFor(p, type, aboveTrack = null) {
  if (type === 'audio') return { track: createTrack('sfx'), index: p.tracks.length };
  const kind = type === 'clip' ? 'overlay' : type === 'text' ? 'text' : 'overlay';
  let index;
  if (aboveTrack) index = p.tracks.indexOf(aboveTrack) + 1;
  else { const lastVis = p.tracks.reduce((a, t, i) => (VISUAL_KINDS.has(t.kind) && t.kind !== 'caption' ? i : a), -1); index = lastVis + 1; }
  const n = p.tracks.filter((t) => t.kind === kind).length + 1;
  return { track: createTrack(kind, createTrack(kind).name + ' ' + n), index };
}

/**
 * Where a block lands on the main track in insert mode.
 *   pointer t (timeline s, as seen with the moving items still in place) -> { anchor, side, t } :
 *   over a clip: before it (first half) or after it (second half); in a gap or past the end: at t itself.
 */
export function insertPoint(p, t, skip) {
  const vt = mainTrack(p); if (!vt) return { anchor: null, side: null, t };
  for (const c of vt.items) {
    if (skip.has(c.id)) continue;
    const s = c.t0, e = itemEnd(c);
    if (t >= s && t < e) return t < (s + e) / 2 ? { anchor: c.id, side: 'before', t: s } : { anchor: c.id, side: 'after', t: e };
  }
  return { anchor: null, side: null, t };
}

/**
 * ONE command that puts `moving` ([{ id?, item, fromTrackId? }] — existing items carry id, new ones (from the bin)
 * carry only item) at block start `t0` (each keeps its offset from the earliest) on target:
 *   target = { trackId } | { newTrack: { track, index } }
 *   mode   = 'insert' (main track: later clips are pushed, the hole the moved clips leave closes) | 'overwrite' (what is
 *            under the block on the target track is cut away) | 'free' (just put there)
 *   rows   = optional Map(id -> trackId) for items that change track on their own (multi-row drags); default: target
 * Returns { cmd, ids }.
 */
export function placeCmd(p, moving, t0, target, mode = 'free', { insert = null, rows = null, label = 'Move' } = {}) {
  const cmds = [];
  let targetId = target.trackId;
  if (target.newTrack) { cmds.push(ops.addTrack(target.newTrack.track, target.newTrack.index, 'New track')); targetId = target.newTrack.track.id; }
  const tgt = target.newTrack ? target.newTrack.track : findTrack(p, targetId);
  const skip = new Set(moving.filter((m) => m.id).map((m) => m.id));
  const tid = (m) => rows?.get(m.id || m.item.id) || targetId;
  // the block is measured from the earliest item that lands on the target track (riders keep their offset from it)
  const onT = moving.filter((m) => tid(m) === targetId);
  const lo = Math.min(...(onT.length ? onT : moving).map((m) => m.item.t0));
  const block = moving.map((m) => ({ ...m, rel: m.item.t0 - lo, dur: itemDur(m.item), trackId: tid(m) }));
  const onTarget = block.filter((m) => m.trackId === targetId);
  const L = onTarget.length ? Math.max(...onTarget.map((m) => m.rel + m.dur)) - Math.min(...onTarget.map((m) => m.rel)) : 0;
  let T = snapFrame(p, Math.max(0, t0));   // a block placed by the pointer lands on a frame
  const shifts = new Map();   // non-moving item id -> new t0
  const main = mainTrack(p);
  if (mode === 'insert' && tgt === main) {
    // 1. the hole each moved main-track clip leaves closes (ripple), 2. the block goes before/after its anchor clip,
    // 3. later clips are pushed just enough to make room
    const rest = main.items.filter((c) => !skip.has(c.id)).map((c) => ({ id: c.id, t0: c.t0, dur: itemDur(c) }));
    const leaving = moving.filter((m) => m.id && m.fromTrackId === main.id).map((m) => ({ t0: m.item.t0, end: itemEnd(m.item), dur: itemDur(m.item) })).sort((a, b) => b.t0 - a.t0);
    let pointerShift = 0;
    for (const h of leaving) {
      for (const c of rest) if (c.t0 >= h.end - 1e-3) c.t0 -= h.dur;
      if (h.end <= t0 + 1e-3) pointerShift += h.dur;
    }
    if (insert?.anchor) { const a = rest.find((c) => c.id === insert.anchor); if (a) T = insert.side === 'before' ? a.t0 : a.t0 + a.dur; }
    else T = snapFrame(p, Math.max(0, t0 - pointerShift));
    const later = rest.filter((c) => c.t0 >= T - 1e-3).sort((a, b) => a.t0 - b.t0);
    const push = later.length ? Math.max(0, T + L - later[0].t0) : 0;
    for (const c of rest) {
      const nt = c.t0 >= T - 1e-3 ? c.t0 + push : c.t0;
      const old = main.items.find((x) => x.id === c.id).t0;
      if (Math.abs(nt - old) > 1e-4) shifts.set(c.id, (nt));
    }
  } else if (mode === 'free' && main) {
    // a clip lifted off the magnetic main track (to an overlay, a new track): the hole it leaves closes
    const out = block.filter((m) => m.id && m.fromTrackId === main.id && m.trackId !== main.id).map((m) => ({ end: itemEnd(m.item), dur: itemDur(m.item) }));
    if (out.length) for (const c of main.items) {
      if (skip.has(c.id)) continue;
      const d = out.reduce((s, h) => s + (c.t0 >= h.end - 1e-3 ? h.dur : 0), 0);
      if (d > 1e-4) shifts.set(c.id, (c.t0 - d));
    }
  } else if (mode === 'overwrite' && tgt && !target.newTrack) {
    const cut = rangeOnTrack(p, tgt, T, T + L, skip);
    if (cut) cmds.push(cut);
  }
  if (shifts.size) cmds.push(ops.setItems([...shifts].map(([id, t]) => ({ id, props: { t0: t } })), label));
  // the moving items themselves
  const moves = block.filter((m) => m.id).map((m) => ({ id: m.id, t0: (Math.max(0, T + m.rel)), trackId: m.trackId }));
  if (moves.length) cmds.push(ops.moveItems(moves, label));
  const adds = block.filter((m) => !m.id).map((m) => ({ trackId: m.trackId, item: Object.assign(clone(m.item), { t0: (T + m.rel) }) }));
  if (adds.length) cmds.push(ops.addItems(adds, label));
  return { cmd: ops.batch(label, cmds), ids: block.map((m) => m.id || m.item.id), t: T };
}

/** lift [a, b) out of one track, ignoring `skip` (overwrite) */
function rangeOnTrack(p, track, a, b, skip) {
  const set = [], add = [], rm = [];
  track.items.forEach((it, index) => {
    if (skip.has(it.id)) return;
    const s = it.t0, e = itemEnd(it);
    if (e <= a + EPS || s >= b - EPS) return;
    if (s >= a - EPS && e <= b + EPS) { rm.push(it.id); return; }
    if (s < a && e > b) { const right = rightHalf(it, b); set.push({ id: it.id, props: leftHalf(it, a) }); add.push({ trackId: track.id, item: right, index: index + 1 }); return; }
    if (s < a) { set.push({ id: it.id, props: leftHalf(it, a) }); return; }
    set.push({ id: it.id, props: { ...splitProps(it, b).right, t0: b, fadeIn: 0, kf: {} } });
  });
  const cmds = [];
  if (set.length) cmds.push(ops.setItems(set, 'Overwrite'));
  if (add.length) cmds.push(ops.addItems(add, 'Overwrite'));
  if (rm.length) cmds.push(ops.removeItems(rm, 'Overwrite'));
  return cmds.length ? ops.batch('Overwrite', cmds) : null;
}

/** the cut nearest t (null when there is none within maxD seconds) */
export function nearestCut(p, t, maxD = Infinity) {
  let best = null;
  for (const c of cutsOf(p)) if (Math.abs(c.t - t) <= maxD && (!best || Math.abs(c.t - t) < Math.abs(best.t - t))) best = c;
  return best;
}
/** props that sit a transition centred on cut c (its length kept, never longer than either clip allows) */
export function transitionOnCut(it, c) {
  const d = itemDur(it), half = Math.min(d / 2, Math.max(0.05, itemDur(c.a) / 2), Math.max(0.05, itemDur(c.b) / 2));
  return { t0: r4(c.t - half), in: 0, out: r4(half * 2 * (it.speed || 1)), style: { ...it.style, a: c.a.id, b: c.b.id } };
}

export { fits, AUDIO_KINDS };
