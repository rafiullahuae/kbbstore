// Caption model: words -> caption lines (cues). Pure functions, no DOM.
//
// project.captions = { style, lang, words:[{t0,t1,w,conf}], lines:[{id,t0,t1,wordIdx:[a,b]}], alt? }
//   wordIdx is INCLUSIVE on both ends. A "line" is one caption cue (shown at once); the renderer
//   wraps it into at most style.maxRows rows. Optional line.emoji = '✨' (emoji-accent style).
//   alt = { lang, words, lines } is a second-language track (bilingual style), same shape.

import { resolveStyle } from './styles.js';

const RTL_RE = /[֐-ࣿיִ-﷿ﹰ-﻿]/;
export const isRtlText = (s) => RTL_RE.test(s || '');

const END_RE = /[.!?…。؟!]["')\]]*$/;
const PAUSE_RE = /[,;:،؛—–-]["')\]]*$/;
// A break right AFTER one of these reads badly ("the | box").
const GLUE = new Set(('a an the of to in on at for and or but with from by as is are was be my your our their his her its ' +
  'this that these those very so not no i you we they he she it if than then into over about up out ' +
  'و في من على إلى الى عن مع أو او ثم لا لم لن هذا هذه ذلك التي الذي يا كل بعد قبل ' +
  'का के की में है और से को पर').split(/\s+/));

export const FILLERS = new Set(('um umm uh uhh erm er ah hmm mm mmm like you-know basically actually literally ' +
  'يعني اه آه ااه ممم امم ايه طيب يعنى هاه').split(/\s+/));

const norm = (w) => (w || '').toLowerCase().replace(/[^\p{L}\p{N}'-]/gu, '');
export const isFiller = (w) => FILLERS.has(norm(w));

let nextId = 1;
export const newLineId = () => 'c' + Date.now().toString(36) + (nextId++).toString(36);

/** Max characters for one cue (all rows) for a style on a given canvas. */
export function cueChars(style, canvas) {
  const s = resolveStyle(style);
  const w = canvas?.w || 1080, h = canvas?.h || 1920;
  // Rows hold more text on wide canvases (16:9 ~1.8x the 9:16 width at the same font size).
  const widthFactor = Math.max(0.8, Math.min(2.2, (w / h) / (9 / 16) * 0.55 + 0.45));
  return Math.round(s.rowChars * widthFactor) * Math.max(1, s.maxRows);
}

/**
 * Group words into cues.
 * Rules: never exceed the char budget or style.maxDur; hard break on a pause > 0.6 s;
 * prefer to break at sentence end > clause punctuation > pause > anything but after a glue word;
 * one-word styles get one word per cue; every cue lasts at least style.minDur when there is room.
 */
export function buildLines(words, style, canvas, opts = {}) {
  const s = resolveStyle(style);
  const budget = opts.maxChars || cueChars(s, canvas);
  const maxDur = s.maxDur, minDur = s.minDur;
  const lines = [];
  if (!words || !words.length) return lines;
  if (s.anim === 'one-word') {
    words.forEach((w, i) => lines.push({ id: newLineId(), t0: w.t0, t1: w.t1, wordIdx: [i, i] }));
    return finishTimes(lines, words, minDur);
  }
  const breakScore = (i) => { // score of breaking AFTER word i
    const w = words[i].w.trim(), next = words[i + 1];
    let sc = 0;
    if (END_RE.test(w)) sc += 10; else if (PAUSE_RE.test(w)) sc += 6;
    if (next) { const gap = next.t0 - words[i].t1; if (gap > 0.25) sc += Math.min(6, gap * 10); }
    if (GLUE.has(norm(w))) sc -= 8;
    return sc;
  };
  let a = 0;
  while (a < words.length) {
    let b = a, chars = words[a].w.trim().length;
    while (b + 1 < words.length) {
      const nw = words[b + 1];
      const gap = nw.t0 - words[b].t1;
      const nc = chars + 1 + nw.w.trim().length;
      if (gap > 0.6) break;                                 // a real pause ends the cue
      if (nc > budget || nw.t1 - words[a].t0 > maxDur) {    // over budget: back off to the best break
        let best = b, bestSc = breakScore(b);
        for (let k = b - 1; k >= a && k >= b - 4; k--) {
          const sc = breakScore(k) - (b - k) * 1.5;         // do not give away too many words
          if (sc > bestSc + 2) { best = k; bestSc = sc; }
        }
        b = best;
        break;
      }
      // Sentence end with the cue already reasonably full: end here.
      if (END_RE.test(words[b].w.trim()) && chars >= budget * 0.4) break;
      b++; chars = nc;
    }
    lines.push({ id: newLineId(), t0: words[a].t0, t1: words[b].t1, wordIdx: [a, b] });
    a = b + 1;
  }
  return finishTimes(lines, words, minDur);
}

export function finishTimes(lines, words, minDur) {
  for (let i = 0; i < lines.length; i++) {
    const L = lines[i], next = lines[i + 1];
    L.t0 = words[L.wordIdx[0]].t0;
    const end = words[L.wordIdx[1]].t1;
    const limit = next ? words[next.wordIdx[0]].t0 : Infinity;
    // Hold briefly after the last word, and stretch short cues to minDur, never into the next cue.
    L.t1 = Math.min(limit, Math.max(end + 0.15, L.t0 + minDur));
    if (L.t1 < end) L.t1 = end;
  }
  return lines;
}

/** Split one cue's words into display rows (≤ maxRows), balanced and phrase-aware. */
export function wrapRows(texts, rowChars, maxRows) {
  const n = texts.length;
  const total = texts.reduce((s, t) => s + t.length, 0) + Math.max(0, n - 1);
  if (maxRows <= 1 || n < 2 || total <= rowChars) return [[0, n - 1]];
  let best = n - 1, bestCost = Infinity;
  for (let k = 0; k < n - 1; k++) {
    const l1 = texts.slice(0, k + 1).join(' ').length, l2 = total - l1 - 1;
    let cost = Math.abs(l1 - l2) + (Math.max(l1, l2) > rowChars ? 100 : 0);
    if (GLUE.has(norm(texts[k]))) cost += 12;
    if (PAUSE_RE.test(texts[k]) || END_RE.test(texts[k])) cost -= 8;
    if (cost < bestCost) { bestCost = cost; best = k; }
  }
  return [[0, best], [best + 1, n - 1]];
}

export function applyCase(text, c) {
  if (c === 'upper') return text.toLocaleUpperCase();
  if (c === 'lower') return text.toLocaleLowerCase();
  if (c === 'title') return text.replace(/(^|\s)(\p{Ll})/gu, (m, a, b) => a + b.toLocaleUpperCase());
  return text;
}

export const lineText = (cap, L) => cap.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w.trim()).join(' ');

/** Active cue at time t (binary search; lines are sorted). */
export function lineAt(lines, t) {
  let lo = 0, hi = lines.length - 1;
  while (lo <= hi) {
    const m = (lo + hi) >> 1, L = lines[m];
    if (t < L.t0) hi = m - 1; else if (t >= L.t1) lo = m + 1; else return L;
  }
  return null;
}

/** Make a captions object from words (keeps style/lang/alt). */
export function withWords(cap, words, canvas) {
  return { ...cap, words, lines: buildLines(words, cap.style, canvas) };
}

/** Sanity: times monotonic, t1 >= t0, ranges valid. Returns a list of problems (empty = ok). */
export function validate(cap) {
  const errs = [];
  cap.words.forEach((w, i) => {
    if (!(w.t1 >= w.t0)) errs.push(`word ${i} t1<t0`);
    if (i && w.t0 < cap.words[i - 1].t0 - 1e-6) errs.push(`word ${i} not monotonic`);
  });
  let prev = -1;
  for (const L of cap.lines) {
    const [a, b] = L.wordIdx;
    if (a !== prev + 1 || b < a || b >= cap.words.length) errs.push(`line ${L.id} range ${a}-${b}`);
    prev = b;
  }
  if (cap.lines.length && prev !== cap.words.length - 1) errs.push('lines do not cover every word');
  return errs;
}
