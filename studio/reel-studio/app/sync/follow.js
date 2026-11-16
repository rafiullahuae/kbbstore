// KBB Studio · word-perfect sync — captions (and cutaways) follow the MEDIA time of what is under them.
//
// Every caption word carries an anchor: src = [mediaId, sourceStart, sourceEnd, clipId] — WHERE in the recording the
// word is said, not where it sits on the timeline. After any edit that moves, trims, splits, speeds up, ripples or
// re-orders the clips on the video track, each word's timeline time is worked out again from its anchor, through the
// clips as they are now. Preview and export draw from the same project.captions, so they share this one clock.
//   * an edit that changes the captions themselves (auto captions, typing, retiming a word, remove silences' remap)
//     is trusted: the words are re-anchored from their new timeline times;
//   * an edit that changes only the clips: the words are re-placed from their anchors;
//   * a word whose recording is no longer on the timeline (trimmed off, deleted) waits in captions.offcut and comes
//     back if that part of the recording does (trim extended again, undo).
// Runs as a core/history.js follow-up hook: the change is part of the SAME undo step as the edit that caused it.

import { itemDur, findMedia, clone } from '../core/project.js';
import { buildLines, finishTimes, carryLineOverrides } from '../captions/model.js';
import { resolveStyle } from '../captions/styles.js';

const EPS = 0.02;   // s: an anchor this close to a clip edge still belongs to it

/** the clips speech can come from: type 'clip' with a media on the video track(s), in track order */
export function speechClips(p) {
  const out = [];
  for (const tr of p.tracks) {
    if (tr.kind !== 'video') continue;
    for (const it of tr.items) if (it.type === 'clip' && it.media) { const m = findMedia(p, it.media); if (m && m.kind !== 'image') out.push(it); }
  }
  return out;
}

/** a signature of the speech clips' layout (changes iff some word could move) */
export function layoutSig(p) {
  let s = '';
  for (const c of speechClips(p)) s += `${c.id}:${c.media}:${c.t0.toFixed(4)}:${c.in.toFixed(4)}:${c.out.toFixed(4)}:${c.speed || 1};`;
  return s;
}

/** the clip under timeline time t (first match in track order) */
export function clipAt(clips, t) {
  for (const c of clips) if (t >= c.t0 - 1e-6 && t < c.t0 + itemDur(c) - 1e-6) return c;
  return null;
}
const toSrc = (c, t) => c.in + (t - c.t0) * (c.speed || 1);
const toTl = (c, s) => c.t0 + (s - c.in) / (c.speed || 1);

/** anchor for a timeline span [t0, t1]: [media, s0, s1, clip] or null (nothing under it) */
export function anchorOf(clips, t0, t1) {
  const c = clipAt(clips, t0) || clipAt(clips, Math.min(t1, t0 + 0.05));
  if (!c) return null;
  const s0 = toSrc(c, Math.max(t0, c.t0)), s1 = Math.min(c.out, toSrc(c, t1));
  return [c.media, r4(s0), r4(Math.max(s0, s1)), c.id];
}

/**
 * timeline time of source time s of media m: the clip `cid` when it still holds s, else the clip of that media holding s
 * nearest to `hint`. null when no clip shows s.
 */
export function placeSrc(clips, m, s, cid, hint) {
  let best = null, bd = Infinity;
  for (const c of clips) {
    if (c.media !== m || s < c.in - EPS || s > c.out + EPS) continue;
    const t = toTl(c, Math.min(c.out, Math.max(c.in, s)));
    if (c.id === cid) return { t, c };
    const d = Math.abs(t - (hint ?? t)); if (d < bd) { bd = d; best = { t, c }; }
  }
  return best;
}

/** does a word's anchor still give its timeline time? */
const consistent = (clips, w) => {
  if (!w.src) return false;
  const p = placeSrc(clips, w.src[0], w.src[1], w.src[3], w.t0);
  return !!p && Math.abs(p.t - w.t0) < 0.0015;
};

/** re-anchor every word from its timeline time (words that already agree keep their anchor). Returns null if none changed. */
export function anchorWords(words, clips) {
  let changed = false;
  const out = words.map((w) => {
    if (consistent(clips, w)) return w;
    const a = anchorOf(clips, w.t0, w.t1);
    if (!a && !w.src) return w;
    changed = true;
    const o = { ...w }; if (a) o.src = a; else delete o.src;
    return o;
  });
  return changed ? out : null;
}

/** place every word (and offcut word) from its anchor; returns { words, offcut, order } (order changed = lines rebuild) */
export function placeWords(words, offcut, clips) {
  const all = [...words.map((w, i) => ({ w, i })), ...(offcut || []).map((w) => ({ w, i: -1 }))];
  const on = [], off = [];
  for (const { w, i } of all) {
    if (!w.src) { if (i >= 0) on.push({ w, i, t: w.t0 }); else off.push(w); continue; }
    const p0 = placeSrc(clips, w.src[0], w.src[1], w.src[3], w.t0);
    if (!p0) { off.push(w); continue; }
    // the end through the same clip when it still holds it (a word cut in half ends where its clip ends)
    const c = p0.c, s1 = Math.min(c.out, w.src[2]);
    const t0 = r3(p0.t), t1 = r3(Math.max(p0.t + 0.02, toTl(c, s1)));
    on.push({ w: t0 === w.t0 && t1 === w.t1 ? w : { ...w, t0, t1 }, i, t: t0 });
  }
  on.sort((a, b) => a.t - b.t || (a.i - b.i));
  // never overlap after a re-order: a word ends where the next starts
  const list = on.map((x) => x.w);
  for (let k = 0; k + 1 < list.length; k++) if (list[k].t1 > list[k + 1].t0 && !list[k].tag) list[k] = { ...list[k], t1: Math.max(list[k].t0, list[k + 1].t0) };
  const order = on.map((x) => x.i);
  const same = order.length === words.length && order.every((i, k) => i === k);
  // only some words left (or none came back) and the rest kept their order: the old lines can be kept, minus the gone
  const subset = !same && order.every((i, k) => i >= 0 && (k === 0 || i > order[k - 1])) ? order : null;
  return { words: list, offcut: off, same, subset };
}

/** the follow-up for captions after a clip edit: a new captions object, or null when nothing moved */
export function followCaptions(cap, clips, canvas) {
  if (!cap?.words?.length && !cap?.offcut?.length) return null;
  const r = placeWords(cap.words || [], cap.offcut, clips);
  const moved = !r.same || r.words.some((w, k) => w !== cap.words[k]) || (r.offcut.length !== (cap.offcut?.length || 0));
  if (!moved) return null;
  const out = { ...cap, words: r.words };
  if (r.offcut.length) out.offcut = r.offcut; else delete out.offcut;
  if (r.same) {
    out.lines = cap.lines.map((L) => ({ ...L, wordIdx: [...L.wordIdx] }));
    finishTimes(out.lines, out.words, resolveStyle(cap.style).minDur);
  } else if (r.subset) {
    const at = new Map(r.subset.map((i, k) => [i, k]));
    out.lines = [];
    for (const L of cap.lines) {
      let a = Infinity, b = -1;
      for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) if (at.has(i)) { a = Math.min(a, at.get(i)); b = Math.max(b, at.get(i)); }
      if (b >= 0) out.lines.push({ ...L, wordIdx: [a, b] });
    }
    finishTimes(out.lines, out.words, resolveStyle(cap.style).minDur);
  } else {
    out.lines = r.words.length ? carryLineOverrides(cap.lines, buildLines(r.words, cap.style, canvas)) : [];
  }
  if (cap.alt?.words?.length) { const a = followCaptions(cap.alt, clips, canvas); if (a) out.alt = a; }
  return out;
}

/** re-anchor captions (and alt) after an edit of the captions; null when every anchor already agrees */
export function anchorCaptions(cap, clips) {
  if (!cap?.words?.length) return null;
  const w = anchorWords(cap.words, clips);
  const alt = cap.alt?.words?.length ? anchorWords(cap.alt.words, clips) : null;
  if (!w && !alt) return null;
  const out = { ...cap };
  if (w) out.words = w;
  if (alt) out.alt = { ...cap.alt, words: alt };
  return out;
}

/** a command that swaps project.captions between two snapshots (exact under coalescing) */
export function captionsSwap(label, before, after) {
  return { label, do(p) { p.captions = clone(after); }, undo(p) { p.captions = clone(before); } };
}

const r3 = (x) => Math.round(x * 1000) / 1000;
const r4 = (x) => Math.round(x * 10000) / 10000;
