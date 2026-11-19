// KBB Studio — one caption bar per clip (the owner: "each captions bar / element in the timeline must be separate").
//
// Captions are one word list (project.captions); what shows them on the timeline is the caption ITEM, and every item
// draws the words active at its time (render.js). Auto captions used to make ONE item from 0 to the last word, so a
// clip it ended before, or a script typed past it, never showed. Now each clip gets its own item, spanning exactly
// that clip and keyed to it (item.clip = the clip's id):
//   * spans  = the clips on video tracks, plus overlay clips that carry sound; clips that OVERLAP share one span
//              (two items over the same moment would draw every line twice); clips that only touch stay separate;
//   * items  = one per span that holds a caption word (captionItemCmds), its look (style, box) copied from the bar it
//              replaces, so a restyled caption layer keeps its style;
//   * follow = a history hook: when the clips move, trim, split, ripple or re-order, every per-clip bar is re-fitted to
//              its clip in the same undo step; a clip that is gone takes its bar with it; a piece split off a clip
//              that had a bar gets its own. A bar the owner moved or trimmed himself, or deleted, is left as he made it.
// The old single bar (no item.clip) is converted the next time captions are made; until then it works as before.

import { registerHistoryHook } from '../core/history.js';
import { itemDur, itemEnd, clone, createItem, findMedia, tracksOfKind, uid } from '../core/project.js';

const OVERLAP = 0.05;
const r4 = (v) => Math.round(v * 10000) / 10000;

/** the clips captions belong to: [{ it, tr, m }] (video tracks; overlay clips only when they carry sound) */
export function spanClips(p) {
  const out = [];
  for (const tr of p.tracks) {
    if (tr.kind !== 'video' && tr.kind !== 'overlay') continue;
    for (const it of tr.items) {
      if (it.type !== 'clip' || !it.media) continue;
      const m = findMedia(p, it.media);
      if (!m || m.kind === 'image') continue;
      if (tr.kind === 'overlay' && (tr.muted || it.muted || m.hasAudio === false)) continue;
      out.push({ it, tr, m });
    }
  }
  return out;
}

/** clips grouped where they overlap: [{ t0, t1, id (the earliest clip), ids, name, media }] sorted by time */
export function captionSpans(p) {
  const cs = spanClips(p).map((c) => ({ ...c, a: c.it.t0, b: itemEnd(c.it) })).filter((c) => c.b - c.a > 1e-3).sort((x, y) => x.a - y.a || y.b - x.b);
  const out = [];
  for (const c of cs) {
    const l = out[out.length - 1];
    if (l && c.a < l.t1 - OVERLAP) { l.t1 = Math.max(l.t1, c.b); l.ids.push(c.it.id); l.media.push(c.it.media); continue; }
    out.push({ t0: c.a, t1: c.b, id: c.it.id, ids: [c.it.id], name: c.m.name || 'clip', media: [c.it.media] });
  }
  return out;
}

const inSpan = (s, w) => { const mid = (w.t0 + w.t1) / 2; return mid >= s.t0 && mid < s.t1; };
const isBar = (i) => i.type === 'caption' && !i.capTrack;
const barName = (s) => `Captions · ${String(s.name).replace(/\.[a-z0-9]{2,4}$/i, '')}`;

/**
 * Commands that leave the first caption track holding one bar per clip span with caption words.
 * opts.only = Set of clip ids: touch only the bars of those clips (Auto captions on selected clips, a typed script);
 * other bars stay exactly as they are. An old single bar is converted (then every span with words gets its bar).
 */
export function captionItemCmds(p, cap, ops, opts = {}) {
  const track = tracksOfKind(p, 'caption')[0];
  if (!track || !ops) return [];
  const words = cap?.words || [];
  const bars = track.items.filter(isBar), legacy = bars.filter((i) => !i.clip), keyed = bars.filter((i) => i.clip);
  const tpl = keyed[0] || legacy[0] || null;
  let only = opts.only || null;
  if (legacy.length) only = null;
  const spans = captionSpans(p);
  const touch = (s) => !only || s.ids.some((id) => only.has(id));
  const cmds = [], adds = [], gone = [...legacy.map((i) => i.id)], used = new Set();
  for (const s of spans) {
    if (!touch(s)) { keyed.filter((i) => s.ids.includes(i.clip)).forEach((i) => used.add(i.id)); continue; }
    const has = words.some((w) => inSpan(s, w));
    const mine = keyed.filter((i) => s.ids.includes(i.clip) && !used.has(i.id));
    if (!has) { gone.push(...mine.map((i) => i.id)); mine.forEach((i) => used.add(i.id)); continue; }
    const [keep, ...extra] = mine;
    extra.forEach((i) => { gone.push(i.id); used.add(i.id); });
    const fit = { t0: r4(s.t0), in: 0, out: r4(s.t1 - s.t0), speed: 1, clip: s.id };
    if (keep) {
      used.add(keep.id);
      if (Math.abs(keep.t0 - fit.t0) > 1e-4 || Math.abs(itemDur(keep) - fit.out) > 1e-4 || keep.clip !== s.id) cmds.push(ops.setItem(keep.id, fit, 'Captions'));
    } else {
      const look = tpl ? { x: tpl.x, y: tpl.y, w: tpl.w, h: tpl.h, opacity: tpl.opacity ?? 1, ...(tpl.style ? { style: clone(tpl.style) } : {}) }
        : { x: 0, y: 0, w: p.canvas.w, h: p.canvas.h };
      adds.push({ trackId: track.id, item: createItem('caption', { ...look, ...fit, name: barName(s) }) });
    }
  }
  // bars whose clip is no longer on the timeline (only on a full rebuild: a partial one leaves other bars alone)
  if (!only) for (const i of keyed) if (!used.has(i.id) && !gone.includes(i.id)) gone.push(i.id);
  if (gone.length) cmds.unshift(ops.removeItems(gone, 'Captions'));
  if (adds.length) cmds.push(ops.addItems(adds, 'Captions'));
  return cmds;
}

// ── the bars follow their clips ──
const layoutSig = (p) => spanClips(p).map(({ it }) => `${it.id}:${it.media}:${r4(it.t0)}:${r4(itemDur(it))}`).join(';');

function state(p) {
  const track = p.tracks.find((t) => t.kind === 'caption');
  const bars = track && !track.locked ? track.items.filter((i) => isBar(i) && i.clip) : [];
  if (!bars.length) return null;
  const spans = captionSpans(p);
  return {
    sig: layoutSig(p), trackId: track.id,
    bars: new Map(bars.map((i) => [i.id, { t0: i.t0, dur: itemDur(i), clip: i.clip }])),
    clips: new Map(spanClips(p).map(({ it }) => [it.id, it.media])),
    barred: new Set(bars.flatMap((i) => spans.find((s) => s.ids.includes(i.clip))?.ids || [i.clip])),
  };
}

function follow(p, st) {
  if (layoutSig(p) === st.sig) return null;
  const track = p.tracks.find((t) => t.id === st.trackId);
  if (!track) return null;
  const spans = captionSpans(p), ch = [], held = new Set();
  const bars = track.items.filter((i) => isBar(i) && i.clip);
  for (const i of bars) {
    const was = st.bars.get(i.id);
    if (!was) continue;                                                   // a bar this edit added: leave it
    if (Math.abs(i.t0 - was.t0) > 1e-6 || Math.abs(itemDur(i) - was.dur) > 1e-6) { held.add(i.id); continue; }   // moved by the edit itself
    const s = spans.find((x) => x.ids.includes(i.clip));
    if (!s || held.has('span:' + s.id)) { ch.push({ id: i.id, after: null }); continue; }
    held.add('span:' + s.id);
    const after = { ...clone(i), t0: r4(s.t0), in: 0, out: r4(s.t1 - s.t0), speed: 1, clip: s.id };
    if (Math.abs(after.t0 - i.t0) > 1e-4 || Math.abs(after.out - itemDur(i)) > 1e-4 || after.clip !== i.clip) ch.push({ id: i.id, after });
  }
  // a piece split (or copied) off a clip that had a bar gets a bar of its own
  const tpl = bars[0];
  for (const s of spans) {
    if (held.has('span:' + s.id) || bars.some((i) => s.ids.includes(i.clip))) continue;
    const fresh = s.ids.filter((id) => !st.clips.has(id));
    if (!fresh.length || !s.media.some((m) => [...st.clips].some(([id, mm]) => mm === m && st.barred.has(id)))) continue;
    ch.push({ id: null, after: { ...clone(tpl), id: undefined, t0: r4(s.t0), in: 0, out: r4(s.t1 - s.t0), speed: 1, clip: s.id, name: barName(s), kf: {} } });
    held.add('span:' + s.id);
  }
  return ch.length ? swap(track.id, track, ch) : null;
}

/** one command over before/after item snapshots (after null = removed, id null = added); exact under coalescing */
function swap(trackId, track, changes) {
  const recs = changes.map((c) => {
    if (c.id == null) { const it = { ...c.after, id: uid('i') }; return { id: it.id, index: track.items.length, before: null, after: it }; }
    const index = track.items.findIndex((x) => x.id === c.id);
    return { id: c.id, index, before: clone(track.items[index]), after: c.after ? clone(c.after) : null };
  });
  const put = (q, which) => {
    const tr = q.tracks.find((t) => t.id === trackId); if (!tr) return;
    for (const r of which === 'after' ? recs : recs.slice().reverse()) {
      const v = r[which], i = tr.items.findIndex((x) => x.id === r.id);
      if (i >= 0) { if (v) tr.items[i] = clone(v); else tr.items.splice(i, 1); }
      else if (v) tr.items.splice(Math.min(r.index, tr.items.length), 0, clone(v));
    }
  };
  return { label: 'Captions follow the clips', do: (q) => put(q, 'after'), undo: (q) => put(q, 'before') };
}

registerHistoryHook({ id: 'captions.bars', before: state, after: (p, st) => (st ? follow(p, st) : null) });

export { state as barsState, follow as barsFollow };
