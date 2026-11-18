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

import { itemDur, findMedia, clone, srcTime, tlTime } from '../core/project.js';
import { buildLines, finishTimes, carryLineOverrides, cueChars, newLineId } from '../captions/model.js';
import { resolveStyle } from '../captions/styles.js';

const EPS = 0.02;   // s: an anchor this close to a clip edge still belongs to it

/** the clips speech can come from: type 'clip' with a media on the video track(s), in track order */
export function speechClips(p) {
  const out = [];
  for (const tr of p.tracks) {
    if (tr.kind !== 'video') continue;
    for (const it of tr.items) if (it.type === 'clip' && it.media && !it.freeze) { const m = findMedia(p, it.media); if (m && m.kind !== 'image') out.push(it); }
  }
  return out;
}

/** a signature of the speech clips' layout (changes iff some word could move) */
export function layoutSig(p) {
  let s = '';
  for (const c of speechClips(p)) s += `${c.id}:${c.media}:${c.t0.toFixed(4)}:${c.in.toFixed(4)}:${c.out.toFixed(4)}:${c.speed || 1}${c.curve ? ':' + JSON.stringify(c.curve) : ''}${c.reverse ? ':r' : ''};`;
  return s;
}

/** the clip under timeline time t (first match in track order) */
export function clipAt(clips, t) {
  for (const c of clips) if (t >= c.t0 - 1e-6 && t < c.t0 + itemDur(c) - 1e-6) return c;
  return null;
}
// through the clip's speed, ramp and direction (core/retime.js) — a constant-speed clip maps exactly as before
const toSrc = (c, t) => (c.curve || c.reverse ? srcTime(c, t) : c.in + (t - c.t0) * (c.speed || 1));
const toTl = (c, s) => tlTime(c, s);

/** anchor for a timeline span [t0, t1]: [media, s0, s1, clip] or null (nothing under it) */
export function anchorOf(clips, t0, t1) {
  const c = clipAt(clips, t0) || clipAt(clips, Math.min(t1, t0 + 0.05));
  if (!c) return null;
  let s0 = toSrc(c, Math.max(t0, c.t0)), s1 = Math.min(c.out, toSrc(c, t1));
  if (c.reverse && s1 < s0) [s0, s1] = [s1, s0];          // a reversed clip says the word backwards: same source span
  return [c.media, r4(s0), r4(Math.max(s0, s1)), c.id];
}

/**
 * timeline time of source time s of media m: the clip `cid` when it still holds s, else the clip of that media holding s
 * nearest to `hint`. null when no clip shows s.
 */
export function placeSrc(clips, m, s, cid, hint) {
  let best = null, bd = Infinity;
  for (const c of clips) {
    // a start AT (or past) the clip's out point is not heard in it: a word that begins exactly where a cut begins is cut
    if (c.media !== m || s < c.in - EPS || s >= c.out - 1e-3) continue;
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
    if (!w.src) { if (i >= 0) on.push({ w, i, t: w.t0, c: null }); else off.push(w); continue; }
    const p0 = placeSrc(clips, w.src[0], w.src[1], w.src[3], w.t0);
    if (!p0) { off.push(w); continue; }
    // the end through the same clip when it still holds it (a word cut in half ends where its clip ends) — or through the
    // piece that plays straight on from it (a blade cut through a word changes nothing)
    let c = p0.c;
    for (let g = 0; g < 8 && !c.reverse && w.src[2] > c.out + 1e-6; g++) { const nx = clips.find((x) => x !== c && continuous(c, x)); if (!nx) break; c = nx; }
    const s1 = Math.min(c.out, w.src[2]);
    let ta = p0.t, tb = toTl(c, s1);
    if (c.reverse && tb < ta) [ta, tb] = [tb, ta];
    const t0 = r3(ta), t1 = r3(Math.max(ta + 0.02, tb));
    on.push({ w: t0 === w.t0 && t1 === w.t1 ? w : { ...w, t0, t1 }, i, t: t0, c: p0.c });
  }
  on.sort((a, b) => a.t - b.t || (a.i - b.i));
  // offcut words that came back have no index in `words`: number them after it so the re-split sees a jump
  let extra = words.length + 1; for (const x of on) if (x.i < 0) { x.i = extra; extra += 2; }
  const clipOf = on.map((x) => x.c);
  // never overlap after a re-order: a word ends where the next starts
  const list = on.map((x) => x.w);
  for (let k = 0; k + 1 < list.length; k++) if (list[k].t1 > list[k + 1].t0 && !list[k].tag) list[k] = { ...list[k], t1: Math.max(list[k].t0, list[k + 1].t0) };
  const order = on.map((x) => x.i);
  const same = order.length === words.length && order.every((i, k) => i === k);
  // only some words left (or none came back) and the rest kept their order: the old lines can be kept, minus the gone
  const subset = !same && order.every((i, k) => i < words.length && (k === 0 || i > order[k - 1])) ? order : null;
  return { words: list, offcut: off, same, subset, order, clipOf };
}

/** do clips a and b play on from each other (a blade cut, nothing removed, nothing moved)? */
// (speed may differ: the two pieces of a cut ramp play on from each other at different average speeds)
const continuous = (a, b) => a === b || !a || !b || (a.media === b.media && !a.freeze && !b.freeze && !!a.reverse === !!b.reverse && Math.abs(a.reverse ? a.in - b.out : a.out - b.in) < 1e-3 && Math.abs(a.t0 + itemDur(a) - b.t0) < 1e-3);

/**
 * Lines after an edit of the clips: a line whose words are no longer one continuous stretch of the recording (words cut
 * out between them, a clip edge that is a real cut, a re-order) is split there, so no line shows words around a hole;
 * a piece left too short (one word, or under half the style's minimum time) joins its neighbour — the one on its own
 * side of the cut when that fits the line's character budget, else the other piece. Overrides (line.ov: drag position,
 * style) go to every piece; an emoji stays on the first. Untouched lines are returned as they were.
 */
export function resplitLines(lines, words, order, clipOf, style, canvas) {
  const st = resolveStyle(style), budget = cueChars(st, canvas), minDur = st.minDur;
  const out = [];
  let changed = false;
  for (const L of lines) {
    const [a, b] = L.wordIdx, pieces = [];
    let s = a;
    for (let k = a; k < b; k++) {
      const hole = order[k + 1] !== order[k] + 1 || !continuous(clipOf[k], clipOf[k + 1]);
      if (hole) { pieces.push([s, k]); s = k + 1; }
    }
    pieces.push([s, b]);
    if (pieces.length === 1) { if (L.frag) changed = true; out.push(L); continue; }
    changed = true;
    pieces.forEach(([x, y], n) => {
      const N = { ...L, id: n ? newLineId() : L.id, wordIdx: [x, y], frag: L.frag || L.id };
      if (n && N.emoji) delete N.emoji;
      out.push(N);
    });
  }
  if (!changed) return null;
  // merge the pieces left too short
  const chars = (x, y) => words.slice(x, y + 1).reduce((n, w) => n + w.w.trim().length + 1, -1);
  const short = (Lx) => Lx.frag && (Lx.wordIdx[0] === Lx.wordIdx[1] || words[Lx.wordIdx[1]].t1 - words[Lx.wordIdx[0]].t0 < minDur / 2);
  for (let i = 0; i < out.length; i++) {
    const L = out[i]; if (!short(L)) continue;
    const prev = out[i - 1], next = out[i + 1];
    const gap = (A, B) => words[B.wordIdx[0]].t0 - words[A.wordIdx[1]].t1;
    const fitsWith = (A, B) => chars(Math.min(A.wordIdx[0], B.wordIdx[0]), Math.max(A.wordIdx[1], B.wordIdx[1])) <= budget;
    const cands = [];
    if (prev && gap(prev, L) < 0.6 && fitsWith(prev, L)) cands.push({ T: prev, at: i - 1, sib: prev.frag === L.frag });
    if (next && gap(L, next) < 0.6 && fitsWith(L, next)) cands.push({ T: next, at: i + 1, sib: next.frag === L.frag });
    if (!cands.length) continue;
    const pick = cands.find((c) => !c.sib) || cands[0];
    const T = pick.T, M = { ...T, wordIdx: [Math.min(T.wordIdx[0], L.wordIdx[0]), Math.max(T.wordIdx[1], L.wordIdx[1])] };
    if (pick.at < i) { out[i - 1] = M; out.splice(i, 1); } else { out[i + 1] = M; out.splice(i, 1); }
    i = Math.max(-1, i - 2);
  }
  for (const L of out) delete L.frag;
  return finishTimes(out, words, minDur);
}

/** the follow-up for captions after a clip edit: a new captions object, or null when nothing moved */
export function followCaptions(cap, clips, canvas) {
  if (!cap?.words?.length && !cap?.offcut?.length) return null;
  const r = placeWords(cap.words || [], cap.offcut, clips);
  const moved = !r.same || r.words.some((w, k) => w !== cap.words[k]) || (r.offcut.length !== (cap.offcut?.length || 0));
  const alt = cap.alt?.words?.length ? followCaptions(cap.alt, clips, canvas) : null;
  if (!moved) return alt ? { ...cap, alt } : null;
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
      // a line that lost words in this edit may be left too short: resplitLines may join it to a neighbour
      if (b >= 0) out.lines.push(b - a < L.wordIdx[1] - L.wordIdx[0] ? { ...L, wordIdx: [a, b], frag: L.id } : { ...L, wordIdx: [a, b] });
    }
    finishTimes(out.lines, out.words, resolveStyle(cap.style).minDur);
  } else {
    out.lines = r.words.length ? carryLineOverrides(cap.lines, buildLines(r.words, cap.style, canvas)) : [];
  }
  // a line around a cut is split there; pieces left too short join a neighbour
  if (out.lines.length) { const L2 = resplitLines(out.lines, out.words, r.order, r.clipOf, cap.style, canvas); if (L2) out.lines = L2; }
  if (alt) out.alt = alt;
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
