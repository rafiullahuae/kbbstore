// KBB Studio · word-perfect sync — CTC forced alignment (pure functions, no DOM, no model).
//
// A wav2vec2 CTC model gives, every 20 ms, a probability for each letter of its alphabet (plus "blank").
// Forced alignment asks: given that we ALREADY KNOW the words (Whisper wrote them), which frames does each letter
// occupy? That is a Viterbi path through the letters in order, so it can never drop, add or swap a word — it only
// finds WHEN each one is said. Word start = first frame of its first letter, end = last frame of its last letter.

/** Letters a vocabulary does not have, mapped to the nearest letter it does (Urdu letters on an Arabic/Persian alphabet). */
const FOLD = {
  'ٹ': 'ت', 'ڈ': 'د', 'ڑ': 'ر', 'ں': 'ن', 'ے': 'ي', 'ۓ': 'ي', 'ہ': 'ه', 'ھ': 'ه', 'ۃ': 'ة', 'ۂ': 'ه', 'ك': 'ک',
  'ـ': '', '‌': '', '‍': '',
};
const DIGIT = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };

/**
 * vocab: { char -> id } (a wav2vec2 vocab.json). Returns a tokenizer: word -> [ids] (letters the model knows, in order).
 * Upper/lower case follows whichever the vocabulary has; letters it lacks are folded or skipped.
 */
export function makeTokenizer(vocab) {
  const has = (c) => Object.prototype.hasOwnProperty.call(vocab, c);
  const upper = has('E') && !has('e');
  const pick = (c) => {
    if (has(c)) return vocab[c];
    const lc = upper ? c.toUpperCase() : c.toLowerCase();
    if (has(lc)) return vocab[lc];
    const f = FOLD[c] ?? DIGIT[c];
    if (f != null) { if (f === '') return null; return pick(f); }
    const base = c.normalize('NFD')[0];   // é -> e
    if (base !== c && has(base)) return vocab[base];
    if (base !== c) { const bl = upper ? base.toUpperCase() : base.toLowerCase(); if (has(bl)) return vocab[bl]; }
    return null;
  };
  return (word) => {
    const out = [];
    for (const c of String(word || '').normalize('NFC')) {
      if (/[\s|]/.test(c)) continue;
      const id = pick(c);
      if (id != null && id !== vocab['[PAD]'] && id !== vocab['<pad>']) out.push(id);
    }
    return out;
  };
}

/** the blank / word-delimiter ids of a wav2vec2 vocabulary */
export function specialIds(vocab) {
  return { blank: vocab['[PAD]'] ?? vocab['<pad>'] ?? 0, delim: vocab['|'] ?? null };
}

/**
 * Viterbi forced alignment.
 * lp(t, k): log-probability of column k at frame t (k is an index into `cols`, the gathered columns);
 * tokens: column indexes in order; skippable: Set of positions in `tokens` that may be skipped (word delimiters);
 * blank: column index of blank. Returns per token [firstFrame, lastFrame] or null when T is too short.
 */
export function forcedAlign(T, lp, tokens, blank, skippable = new Set()) {
  const L = tokens.length, S = 2 * L + 1;
  if (!L || T < 1) return null;
  const ext = new Int32Array(S), tokPos = new Int32Array(S).fill(-1);
  for (let i = 0; i < L; i++) { ext[2 * i] = blank; ext[2 * i + 1] = tokens[i]; tokPos[2 * i + 1] = i; }
  ext[S - 1] = blank;
  const NEG = -1e30;
  let prev = new Float64Array(S).fill(NEG), cur = new Float64Array(S);
  const back = new Uint8Array(T * S);   // how many states back we came from (0..4)
  prev[0] = lp(0, blank); prev[1] = lp(0, ext[1]);
  // a skippable token (word delimiter) at the start may be jumped over
  if (S > 3 && skippable.has(0)) prev[3] = lp(0, ext[3]);
  for (let t = 1; t < T; t++) {
    for (let s = 0; s < S; s++) {
      let best = prev[s], from = 0;
      if (s >= 1 && prev[s - 1] > best) { best = prev[s - 1]; from = 1; }
      const isTok = tokPos[s] >= 0;
      if (isTok && s >= 2 && ext[s] !== ext[s - 2] && prev[s - 2] > best) { best = prev[s - 2]; from = 2; }
      // jump over a skippable delimiter: from the letter (s-4) or blank (s-3) before it
      if (isTok && s >= 4 && tokPos[s - 2] >= 0 && skippable.has(tokPos[s - 2])) {
        if (ext[s] !== ext[s - 4] && prev[s - 4] > best) { best = prev[s - 4]; from = 4; }
        if (prev[s - 3] > best) { best = prev[s - 3]; from = 3; }
      }
      cur[s] = best <= NEG / 2 ? NEG : best + lp(t, ext[s]);
      back[t * S + s] = from;
    }
    const sw = prev; prev = cur; cur = sw;
  }
  // end in the last blank, the last token, or (when it is skippable) before it
  let s = prev[S - 1] >= prev[S - 2] ? S - 1 : S - 2;
  if (prev[s] <= NEG / 2) return null;
  const span = Array.from({ length: L }, () => null);
  for (let t = T - 1; t >= 0; t--) {
    const i = tokPos[s];
    if (i >= 0) { if (!span[i]) span[i] = [t, t]; else span[i][0] = t; }
    s -= back[t * S + s];
  }
  return span;
}

/**
 * Align words (each with .ids = token ids) inside one stretch of emissions.
 * em = { T, cols:[ids], data: Float32Array(T*cols.length) log-probs, frameSec, t0 (s of frame 0) }.
 * Returns [{t0, t1, ok}] per word (ok=false: the word had no letters the model knows).
 */
export function alignStretch(em, words, { blankId, delimId, offset = 0 } = {}) {
  const C = em.cols.length, col = new Map(em.cols.map((id, k) => [id, k]));
  const tokens = [], owner = [], skip = new Set();
  words.forEach((w, wi) => {
    const ids = (w.ids || []).filter((id) => col.has(id));
    if (!ids.length) return;
    if (tokens.length && delimId != null && col.has(delimId)) { skip.add(tokens.length); tokens.push(col.get(delimId)); owner.push(-1); }
    for (const id of ids) { tokens.push(col.get(id)); owner.push(wi); }
  });
  const out = words.map(() => ({ t0: null, t1: null, ok: false }));
  if (!tokens.length) return out;
  // CTC needs a blank between repeated letters: T must cover tokens + repeats
  let need = tokens.length; for (let i = 1; i < tokens.length; i++) if (tokens[i] === tokens[i - 1]) need++;
  if (em.T < need) return out;
  const b = col.get(blankId);
  const spans = forcedAlign(em.T, (t, k) => em.data[t * C + k], tokens, b, skip);
  if (!spans) return out;
  const fs = em.frameSec;
  for (let i = 0; i < tokens.length; i++) {
    const wi = owner[i]; if (wi < 0 || !spans[i]) continue;
    const a = em.t0 + spans[i][0] * fs + offset, z = em.t0 + (spans[i][1] + 1) * fs + offset;
    const o = out[wi];
    if (!o.ok) { o.t0 = a; o.t1 = z; o.ok = true; } else { o.t0 = Math.min(o.t0, a); o.t1 = Math.max(o.t1, z); }
  }
  return out;
}

/**
 * Split words into stretches the aligner runs one at a time: cut at the widest pauses (by the first-pass times) so a
 * stretch is at most maxSec long. Each cut between two stretches is placed by `cutAt(lo, hi)` (the quietest moment
 * of the audio between lo and hi: the first-pass pause widened by `slack` each side), so a first-pass error never
 * moves a word into the wrong stretch as long as there is any quieter moment between the two words.
 */
export function planStretches(words, dur, cutAt, { maxSec = 18, minGap = 0.15, slack = 0.3 } = {}) {
  const parts = [];
  let a = 0;
  while (a < words.length) {
    let b = a, bestCut = -1, bestGap = -1;
    while (b + 1 < words.length && words[b + 1].t1 - words[a].t0 <= maxSec) {
      const g = words[b + 1].t0 - words[b].t1;
      if (g >= bestGap) { bestGap = g; bestCut = b; }
      b++;
    }
    let end = b;
    if (b + 1 < words.length && bestCut >= a && bestGap >= minGap) end = bestCut;
    parts.push([a, end]);
    a = end + 1;
  }
  const cuts = [];
  for (let i = 1; i < parts.length; i++) {
    const L = words[parts[i - 1][1]], R = words[parts[i][0]];
    const lo = Math.max(L.t0 + 0.05, Math.min(L.t1, R.t0) - slack), hi = Math.max(lo + 0.02, Math.min(R.t1 - 0.05, Math.max(L.t1, R.t0) + slack));
    cuts.push(cutAt ? cutAt(lo, hi) : (L.t1 + R.t0) / 2);
  }
  return parts.map(([a, b], i) => ({
    a, b,
    t0: i ? cuts[i - 1] : Math.max(0, words[a].t0 - 1),
    t1: i + 1 < parts.length ? cuts[i] : Math.min(dur, words[b].t1 + 1),
  }));
}
