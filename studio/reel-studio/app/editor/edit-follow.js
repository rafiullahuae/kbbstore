// KBB Studio — what hangs off the clips follows the edit (a core/history.js follow-up hook: the same undo step).
//
//   elements   theme elements, text, stickers, shapes and b-roll (a clip on an overlay track: the auto b-roll is put on
//              the word it shows, with its card and name in one group) placed over a clip on the main track ride with
//              that clip: their start is anchored to the MEDIA time under it (clip id + source second), so a trim,
//              split, ripple delete (Remove silences too), re-order or move of the clips carries them to where that
//              moment of the recording now plays. An element
//              whose moment was cut away stays where it is (or moves with its clip's start when the clip itself moved).
//              The elements a theme placed (auto 'theme': title, lower third, signature) ride their clip the same way.
//              Not followed: clips on a second video track, sound (music, voice-over), markers, whole-video theme
//              items (overlay, grade, intro, end card: themes/followReel re-derives those), cutaways and captions
//              (their own hooks), and anything on a locked track.
//   transitions on a cut (theme.trans, look.transition: style.a / style.b = the clip ids either side) stay centred on
//              their cut. When the cut changes they re-anchor: the incoming clip keeps its transition (it gets whatever
//              clip now ends where it starts), else the outgoing one keeps it; when there is no cut there any more (a
//              lift left a gap, a rejoin) or another transition already holds that cut, it goes. A wipe on a cut (the
//              theme's own on every cut, auto 'theme', or "whole video · on every cut") does the same, found by the cut
//              it is centred on, on any video track.
// An item the edit itself moved or re-timed (dragged along with the clip, nudged) is left alone. Stateless: anchors are
// worked out from the layout BEFORE each command, so old projects need nothing stored and open unchanged.

import { registerHistoryHook } from '../core/history.js';
import { itemDur, itemEnd, clone, srcTime } from '../core/project.js';
import { retimeSig } from '../core/retime.js';
import { mainTrack, cutsOf, isTransition, transitionOnCut, tlOf } from './edit-ops.js';

const FOLLOW_TYPES = new Set(['theme.el', 'text', 'sticker', 'shape', 'viral.placeholder']);
const EPS = 0.02;

/** clips on the main track, as plain snapshots */
const mainClips = (p) => (mainTrack(p)?.items || []).filter((c) => c.type === 'clip');
const sigOf = (clips) => clips.map((c) => `${c.id}:${c.t0.toFixed(4)}:${retimeSig(c)}`).join(';');
/** every video track's clips (a wipe can sit on a cut of a second video track) */
const videoClips = (p) => p.tracks.filter((t) => t.kind === 'video').flatMap((t) => t.items.filter((c) => c.type === 'clip'));
// a wipe on a cut (the theme's own on every cut, or "whole video · on every cut") stays centred on its cut like a
// transition; it is found by its centre, since it names no clips
const isWipe = (it) => it.type === 'theme.el' && (it.style?.el === 'wipe' || it.style?.whole === 'cuts');
// the theme's whole-reel items (overlay, grade, intro / end card, their sounds: whole or derived from the reel) are
// re-derived by themes/followReel; the elements a theme placed on a moment (title, lower third, signature) ride their
// clip like the owner's own; a sound effect rides its moment like an element
const followsClip = (tr, it) => !tr.locked && !it.style?.whole && !(it.auto === 'theme' && it.type !== 'theme.el') && !isWipe(it) &&
  ((FOLLOW_TYPES.has(it.type) && tr.kind !== 'video') || (it.type === 'clip' && tr.kind === 'overlay') || (it.type === 'audio' && tr.kind === 'sfx' && !tr.voice && !it.unlinkedFrom));
/** cuts on every video track (a theme puts a wipe on each): [{ a, b, t }] */
function videoCuts(p) {
  const out = [];
  for (const tr of p.tracks) if (tr.kind === 'video') {
    const list = tr.items.filter((i) => i.type === 'clip').slice().sort((x, y) => x.t0 - y.t0);
    for (let i = 0; i + 1 < list.length; i++) if (Math.abs(itemEnd(list[i]) - list[i + 1].t0) < 0.08) out.push({ a: list[i], b: list[i + 1], t: list[i + 1].t0 });
  }
  return out;
}
/** where the pictures end (video, overlay, text tracks) */
function picturesEnd(p) { let e = 0; for (const tr of p.tracks) if (tr.kind === 'video' || tr.kind === 'overlay' || tr.kind === 'text') for (const it of tr.items) e = Math.max(e, itemEnd(it)); return e; }

function state(p) {
  const clips = mainClips(p);
  if (!clips.length) return null;
  const snap = clips.map((c) => ({ ...c, dur: itemDur(c) }));   // shallow: a curve object is replaced by edits, never mutated
  const els = new Map(), trans = new Map(), vcuts = videoCuts(p);
  for (const tr of p.tracks) for (const it of tr.items) {
    if (isTransition(it)) { trans.set(it.id, { t0: it.t0, dur: itemDur(it) }); continue; }
    if (isWipe(it) && !tr.locked) {   // the clips either side of the cut it is centred on (none: it is left alone)
      const mid = it.t0 + itemDur(it) / 2, c = vcuts.find((x) => Math.abs(x.t - mid) < 0.05);
      if (c) trans.set(it.id, { t0: it.t0, dur: itemDur(it), a: c.a.id, b: c.b.id });
      continue;
    }
    if (!followsClip(tr, it)) continue;
    const c = snap.find((x) => it.t0 >= x.t0 - 1e-6 && it.t0 < x.t0 + x.dur - 1e-6);
    els.set(it.id, { t0: it.t0, dur: itemDur(it), c: c?.id || null, cT0: c?.t0, m: c?.media || null, s: c && !c.freeze ? srcTime(c, it.t0) : null });
  }
  const caps = captionItems(p), pics = picturesEnd(p);
  // music that ends with the reel (or runs past it): when a cut shortens the pictures it is trimmed to end with them
  const music = new Map();
  for (const tr of p.tracks) if (tr.kind === 'music' && !tr.locked) for (const it of tr.items) if (itemEnd(it) >= pics - 0.05) music.set(it.id, itemEnd(it));
  if (!els.size && !trans.size && !caps.length && !music.size) return null;
  return { sig: sigOf(videoClips(p)), els, trans, pics, music, content: contentEnd(p), caps: new Map(caps.map((c) => [c.id, itemEnd(c)])) };
}
/** the caption layer items (type 'caption', one per caption track, spanning 0..end) */
const captionItems = (p) => p.tracks.filter((t) => t.kind === 'caption' && !t.locked).flatMap((t) => t.items.filter((i) => i.type === 'caption' && Math.abs(i.t0) < 1e-6));
/** where the pictures and sound end (the caption layer never holds the reel open: Auto captions caps it at this) */
function contentEnd(p) { let e = 0; for (const tr of p.tracks) if (tr.kind !== 'caption') for (const it of tr.items) e = Math.max(e, itemEnd(it)); return e; }

/** where an anchored element starts now (null: leave it) */
function placeEl(clips, a) {
  if (!a.c || !a.m) return null;
  const own = clips.find((c) => c.id === a.c);
  if (own && a.s >= own.in - EPS && a.s <= own.out + EPS) return tlOf(own, Math.min(own.out, Math.max(own.in, a.s)));
  let best = null, bd = Infinity;
  for (const c of clips) {
    if (c.media !== a.m || a.s < c.in - EPS || a.s > c.out - EPS) continue;
    const t = tlOf(c, Math.max(c.in, a.s)), d = Math.abs(t - a.t0);
    if (d < bd) { bd = d; best = t; }
  }
  if (best != null) return best;
  if (own && Math.abs(own.t0 - a.cT0) > 1e-4) return a.t0 + (own.t0 - a.cT0);   // its moment is gone but its clip moved
  return null;
}

function follow(p, st) {
  const clips = mainClips(p);
  if (sigOf(videoClips(p)) === st.sig) return null;
  const ch = [];   // [{ id, before, after }]
  const byId = new Map(); for (const tr of p.tracks) for (const it of tr.items) byId.set(it.id, { tr, it });
  // elements
  for (const [id, a] of st.els) {
    const hit = byId.get(id); if (!hit) continue;
    const it = hit.it;
    if (Math.abs(it.t0 - a.t0) > 1e-6 || Math.abs(itemDur(it) - a.dur) > 1e-6) continue;   // the edit moved it itself
    const t = placeEl(clips, a);
    if (t == null || Math.abs(t - it.t0) < 1e-4) continue;
    ch.push({ id, after: { ...clone(it), t0: Math.max(0, Math.round(t * 10000) / 10000) } });
  }
  // transitions, and the wipes on cuts (theirs and the theme's): re-centred on their cut, gone with it
  const cuts = cutsOf(p), vcuts = videoCuts(p), held = new Set(), list = [];
  for (const [id, a] of st.trans) { const hit = byId.get(id); if (hit) list.push({ it: hit.it, wipe: !!a.a, A: a.a || hit.it.style?.a, B: a.b || hit.it.style?.b }); }
  const vclips = new Set(vcuts.flatMap((c) => [c.a.id, c.b.id]));
  for (const tr of p.tracks) if (tr.kind === 'video') for (const c of tr.items) vclips.add(c.id);
  const has = (x) => vclips.has(x);
  // the ones whose cut is intact first, then incoming clip kept, then outgoing clip kept
  const rank = ({ A, B, wipe }) => ((wipe ? vcuts : cuts).some((c) => c.a.id === A && c.b.id === B) ? 0 : has(B) ? 1 : has(A) ? 2 : 3);
  list.sort((x, y) => rank(x) - rank(y));
  for (const { it, wipe, A, B } of list) {
    // a transition always sits on a cut (a drag puts it on one itself), so it is re-placed even when the edit moved it
    const cs = wipe ? vcuts : cuts;
    const cut = cs.find((c) => c.a.id === A && c.b.id === B) || (has(B) && cs.find((c) => c.b.id === B)) || (has(A) && cs.find((c) => c.a.id === A)) || null;
    const key = cut && `${wipe ? 'w' : 't'}|${cut.a.id}|${cut.b.id}`;
    if (!cut || held.has(key)) { ch.push({ id: it.id, after: null }); continue; }
    held.add(key);
    if (wipe) {   // a wipe keeps its length, centred on the cut
      const t0 = Math.max(0, Math.round((cut.t - itemDur(it) / 2) * 10000) / 10000);
      if (Math.abs(t0 - it.t0) >= 1e-4) ch.push({ id: it.id, after: { ...clone(it), t0 } });
      continue;
    }
    const props = transitionOnCut(it, cut);
    if (Math.abs(props.t0 - it.t0) < 1e-4 && Math.abs(props.out - it.out) < 1e-4 && cut.a.id === A && cut.b.id === B) continue;
    ch.push({ id: it.id, after: { ...clone(it), ...props } });
  }
  // music stays where it is (it is not cut with the clips), but a bed that ran to the end of the reel ends with the
  // shorter reel instead of holding the export open past the pictures (its fade-out kept)
  const pics = picturesEnd(p);
  if (pics < st.pics - 1e-4 && pics > 0) for (const [id, was] of st.music) {
    const hit = byId.get(id); if (!hit) continue;
    const it = hit.it;
    if (Math.abs(itemEnd(it) - was) > 1e-6 || it.t0 >= pics - 0.05 || itemEnd(it) <= pics + 1e-4) continue;
    const out = it.in + (pics - it.t0) * (it.speed || 1);
    const after = { ...clone(it), out: Math.round(out * 10000) / 10000 };
    ch.push({ id, after });
    hit.it = after;   // the caption layer below measures the reel with the trimmed music
  }
  // the caption layer: a ripple that shortens the reel shortens it too (else the export runs on past the pictures);
  // one that lengthens it extends a layer that was ending with the reel
  const now = new Map(ch.map((c) => [c.id, c.after]));
  let end = 0;
  for (const [id, x] of byId) { if (x.tr.kind === 'caption') continue; const it = now.has(id) ? now.get(id) : x.it; if (it) end = Math.max(end, itemEnd(it)); }
  if (Math.abs(end - st.content) > 1e-4) for (const c of captionItems(p)) {
    const was = st.caps.get(c.id); if (was == null || Math.abs(itemEnd(c) - was) > 1e-6) continue;
    const want = end < was - 1e-4 ? end : Math.abs(was - st.content) < 1e-3 ? end : was;
    if (Math.abs(want - was) > 1e-4) ch.push({ id: c.id, after: { ...clone(c), out: Math.round((c.in + (want - c.t0) * (c.speed || 1)) * 10000) / 10000 } });
  }
  return ch.length ? swap(p, ch) : null;
}

/** a command that swaps whole items between before/after snapshots (after null = removed); exact under coalescing */
function swap(p, changes) {
  const recs = [];
  for (const c of changes) for (const tr of p.tracks) {
    const i = tr.items.findIndex((x) => x.id === c.id);
    if (i >= 0) { recs.push({ trackId: tr.id, index: i, before: clone(tr.items[i]), after: c.after ? clone(c.after) : null }); break; }
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
  return { label: 'Elements follow the clips', do: (q) => put(q, 'after'), undo: (q) => put(q, 'before') };
}

registerHistoryHook({ id: 'edit.follow', before: state, after: (p, st) => (st ? follow(p, st) : null) });

export { state as followState, follow as followAfter };
