// Caption commands. Modules never mutate the project directly: every change is a command handed
// to core/history.apply(cmd). A command here is { type, label, do(project), undo(project) }; it
// swaps project.captions between two immutable snapshots, so undo/redo is exact and cheap
// (captions are a few thousand small objects at most).

import { buildLines, finishTimes, newLineId } from './model.js';
import { resolveStyle } from './styles.js';

const EMPTY = () => ({ style: 'word-box', lang: 'auto', words: [], lines: [] });

/** Build a command from a pure (captions, project) -> captions function. */
export function captionsCommand(type, label, fn, key) {
  let before, after;
  return {
    type: 'captions.' + type, label, key,
    do(project) {
      before = project.captions || EMPTY();
      if (after === undefined) after = fn(before, project);
      project.captions = after;
    },
    undo(project) { project.captions = before; },
  };
}

const minDurOf = (cap) => resolveStyle(cap.style).minDur;
const clone = (cap) => ({ ...cap, words: cap.words.map((w) => ({ ...w })), lines: cap.lines.map((l) => ({ ...l, wordIdx: [...l.wordIdx] })) });
const retimed = (cap) => { finishTimes(cap.lines, cap.words, minDurOf(cap)); return cap; };

/** Remap line ranges after words[a..b] were replaced by `n` words. Lines left empty vanish.
 *  The line that held word a receives the new words; ranges keep tiling 0..len-1. */
function remap(lines, a, b, n) {
  const delta = n - (b - a + 1), out = [];
  for (const L of lines) {
    const [x, y] = L.wordIdx;
    if (y < a) { out.push(L); continue; }
    if (x > b) { out.push({ ...L, wordIdx: [x + delta, y + delta] }); continue; }
    const start = x <= a ? x : a + n;
    const end = y > b ? y + delta : (x <= a ? a + n - 1 : a + n - 1);
    if (end >= start) out.push({ ...L, wordIdx: [start, end] });
  }
  return out;
}

/** Replace words[a..b] with `nw` (array of word objects). */
function spliceWords(cap, a, b, nw) {
  const c = clone(cap);
  c.words.splice(a, b - a + 1, ...nw);
  c.lines = remap(c.lines, a, b, nw.length);
  if (c.words.length && !c.lines.length) c.lines = [{ id: newLineId(), t0: 0, t1: 0, wordIdx: [0, c.words.length - 1] }];
  if (c.lines.length) { c.lines[0].wordIdx[0] = 0; c.lines[c.lines.length - 1].wordIdx[1] = c.words.length - 1; }
  return retimed(c);
}

/** Spread `texts` over [t0,t1] proportionally to their length. */
function spread(texts, t0, t1, conf = 1) {
  const total = texts.reduce((s, t) => s + Math.max(1, t.length), 0);
  let t = t0;
  return texts.map((w) => {
    const d = (t1 - t0) * Math.max(1, w.length) / total;
    const o = { t0: +t.toFixed(3), t1: +(t + d).toFixed(3), w, conf };
    t += d;
    return o;
  });
}

// ---------------------------------------------------------------- commands

/** Replace the whole caption set (e.g. a fresh transcription). Lines are built if missing. */
export const setCaptions = (cap, canvas) => captionsCommand('set', 'Auto captions', (_, p) => {
  const c = { ...EMPTY(), ...cap };
  if (!c.lines || !c.lines.length) c.lines = buildLines(c.words, c.style, canvas || p.canvas);
  return c;
});

/** Edit word i. Empty text deletes it; text with spaces becomes several words sharing its time. */
export const editWord = (i, text) => captionsCommand('editWord', 'Edit word', (cap) => {
  const w = cap.words[i]; if (!w) return cap;
  const parts = String(text).trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return spliceWords(cap, i, i, []);
  if (parts.length === 1) { const c = clone(cap); c.words[i] = { ...w, w: parts[0], conf: 1 }; return c; }
  return spliceWords(cap, i, i, spread(parts, w.t0, w.t1));
});

export const deleteWords = (a, b = a) => captionsCommand('delete', b > a ? 'Delete words' : 'Delete word',
  (cap) => spliceWords(cap, a, b, []));

/** Insert text after word `after` (-1 = at the start). Takes the pause after it, or half the word. */
export const insertWords = (after, text) => captionsCommand('insert', 'Add word', (cap) => {
  const parts = String(text).trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return cap;
  const prev = cap.words[after], next = cap.words[after + 1];
  let t0, t1;
  const c0 = clone(cap);
  if (prev && next && next.t0 - prev.t1 >= 0.15) { t0 = prev.t1; t1 = next.t0; }
  else if (prev) { const mid = (prev.t0 + prev.t1) / 2; c0.words[after] = { ...prev, t1: +mid.toFixed(3) }; t0 = mid; t1 = prev.t1; }
  else if (next) { t0 = Math.max(0, next.t0 - 0.4); t1 = next.t0; }
  else { t0 = 0; t1 = 0.4 * parts.length; }
  const nw = spread(parts, t0, t1);
  if (!cap.words.length) return retimed({ ...c0, words: nw, lines: [{ id: newLineId(), t0, t1, wordIdx: [0, nw.length - 1] }] });
  // Insert at after+1 by replacing the (after) word with itself + new words, keeping it in its line.
  if (after < 0) return spliceWords(c0, 0, 0, [...nw, c0.words[0]]);
  return spliceWords(c0, after, after, [c0.words[after], ...nw]);
});

/** Split the cue holding word `at` so that `at` starts a new cue. */
export const splitLine = (at) => captionsCommand('split', 'Split line', (cap) => {
  const k = cap.lines.findIndex((L) => L.wordIdx[0] < at && at <= L.wordIdx[1]);
  if (k < 0) return cap;
  const c = clone(cap), L = c.lines[k];
  c.lines.splice(k, 1, { ...L, wordIdx: [L.wordIdx[0], at - 1] }, { id: newLineId(), t0: 0, t1: 0, wordIdx: [at, L.wordIdx[1]] });
  return retimed(c);
});

/** Merge cue `lineId` with the cue after it. */
export const mergeWithNext = (lineId) => captionsCommand('merge', 'Merge lines', (cap) => {
  const k = cap.lines.findIndex((L) => L.id === lineId);
  if (k < 0 || k === cap.lines.length - 1) return cap;
  const c = clone(cap), A = c.lines[k], B = c.lines[k + 1];
  c.lines.splice(k, 2, { ...A, wordIdx: [A.wordIdx[0], B.wordIdx[1]], emoji: A.emoji || B.emoji });
  return retimed(c);
});

/** Retime one word, clamped between its neighbours. */
export const retimeWord = (i, t0, t1) => captionsCommand('retimeWord', 'Retime word', (cap) => {
  const c = clone(cap), w = c.words[i]; if (!w) return cap;
  const lo = i ? c.words[i - 1].t0 : 0, hi = i < c.words.length - 1 ? c.words[i + 1].t1 : Infinity;
  const a = Math.max(lo, Math.min(t0, hi - 0.02)), b = Math.max(a + 0.02, Math.min(t1, hi));
  c.words[i] = { ...w, t0: +a.toFixed(3), t1: +b.toFixed(3) };
  if (i && c.words[i - 1].t1 > a) c.words[i - 1] = { ...c.words[i - 1], t1: +a.toFixed(3) };
  if (c.words[i + 1] && c.words[i + 1].t0 < b) c.words[i + 1] = { ...c.words[i + 1], t0: +b.toFixed(3) };
  return retimed(c);
});

/** Nudge word i (or every word of a cue) by dt seconds. */
export const nudgeWord = (i, dt) => captionsCommand('nudge', 'Nudge word', (cap) => {
  const w = cap.words[i]; if (!w) return cap;
  const tmp = retimeWord(i, w.t0 + dt, w.t1 + dt); const p = { captions: cap }; tmp.do(p); return p.captions;
});
export const nudgeLine = (lineId, dt) => captionsCommand('nudgeLine', 'Nudge line', (cap) => {
  const L = cap.lines.find((x) => x.id === lineId); if (!L) return cap;
  const [a, b] = L.wordIdx;
  const lo = a ? cap.words[a - 1].t1 : 0, hi = b < cap.words.length - 1 ? cap.words[b + 1].t0 : Infinity;
  const d = Math.max(lo - cap.words[a].t0, Math.min(dt, hi - cap.words[b].t1));
  const c = clone(cap);
  for (let k = a; k <= b; k++) c.words[k] = { ...c.words[k], t0: +(c.words[k].t0 + d).toFixed(3), t1: +(c.words[k].t1 + d).toFixed(3) };
  return retimed(c);
});

/** Shift every caption by dt (clamped so nothing goes before 0). */
export const shiftAll = (dt) => captionsCommand('shift', 'Shift all captions', (cap) => {
  if (!cap.words.length) return cap;
  const d = Math.max(-cap.words[0].t0, dt);
  const c = clone(cap);
  c.words = c.words.map((w) => ({ ...w, t0: +(w.t0 + d).toFixed(3), t1: +(w.t1 + d).toFixed(3) }));
  return retimed(c);
});

/**
 * Find & replace. `find` may span several words ("skin care" -> "skincare"); matching ignores
 * case unless opts.matchCase, and punctuation around a word is kept. Returns command; command.count
 * is filled when it runs.
 */
export const findReplace = (find, repl, opts = {}) => {
  const cmd = captionsCommand('replace', 'Find & replace', (cap) => {
    const fw = String(find).trim().split(/\s+/).filter(Boolean);
    if (!fw.length) return cap;
    const cmp = (a, b) => opts.matchCase ? a === b : a.toLocaleLowerCase() === b.toLocaleLowerCase();
    const core = (w) => w.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');
    let c = cap, count = 0;
    for (let i = 0; i + fw.length <= c.words.length; i++) {
      let ok = true;
      for (let k = 0; k < fw.length && ok; k++) ok = cmp(core(c.words[i + k].w), core(fw[k]) || fw[k]);
      if (!ok) continue;
      const first = c.words[i], last = c.words[i + fw.length - 1];
      const lead = first.w.match(/^[^\p{L}\p{N}]*/u)[0], trail = last.w.match(/[^\p{L}\p{N}]*$/u)[0];
      const parts = String(repl).trim().split(/\s+/).filter(Boolean);
      if (parts.length) { parts[0] = lead + parts[0]; parts[parts.length - 1] += trail; }
      c = spliceWords(c, i, i + fw.length - 1, spread(parts, first.t0, last.t1));
      count++;
      i += Math.max(0, parts.length - 1);
    }
    cmd.count = count;
    return count ? c : cap;
  });
  cmd.count = 0;
  return cmd;
};

/** Change style. relayout=true rebuilds the cues (picking another style); false keeps manual splits. */
export const setStyle = (spec, relayout = false, canvas, key) => captionsCommand('style', 'Caption style', (cap, p) => {
  const c = { ...cap, style: spec };
  if (relayout) {
    c.lines = buildLines(c.words, spec, canvas || p.canvas);
    if (cap.alt) c.alt = { ...cap.alt, style: spec, lines: buildLines(cap.alt.words, spec, canvas || p.canvas) };
  }
  return c;
}, key);

export const relayout = (canvas) => captionsCommand('relayout', 'Re-flow lines', (cap, p) => ({ ...cap, lines: buildLines(cap.words, cap.style, canvas || p.canvas) }));

export const setLineEmoji = (lineId, emoji) => captionsCommand('emoji', emoji ? 'Add emoji' : 'Remove emoji', (cap) => {
  const c = clone(cap), L = c.lines.find((x) => x.id === lineId);
  if (!L) return cap;
  if (emoji) L.emoji = emoji; else delete L.emoji;
  return c;
});

/** Second-language track for the bilingual style. */
export const setAlt = (alt, canvas) => captionsCommand('alt', 'Second language', (cap, p) => {
  if (!alt) { const { alt: _a, ...rest } = cap; return rest; }
  return { ...cap, alt: { ...alt, lines: alt.lines?.length ? alt.lines : buildLines(alt.words, cap.style, canvas || p.canvas) } };
});
