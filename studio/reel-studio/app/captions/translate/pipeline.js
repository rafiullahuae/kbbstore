// The caption translation pipeline (no DOM):
//   1. sentences: caption words are merged into whole sentences (sentence-final punctuation, a pause, a sound tag),
//      because a model translates "the serum | makes your skin glow" far better as one sentence than as two lines;
//   2. each sentence is masked (glossary names, numbers, prices, codes, URLs, emoji -> placeholders), translated,
//      unmasked, and checked; anything the model dropped is retried without masks and, if still missing, put back
//      at the end of the sentence and the line is flagged — a name or a price is never silently lost;
//   3. the translation is cut back into the ORIGINAL caption lines, in proportion to how much of the sentence each
//      line held (preferring breaks after punctuation, never leaving a postposition / article dangling), so every
//      line keeps its exact start and end time;
//   4. inside each line the translated words share the line's spoken span in proportion to their length, so
//      word-highlight styles still animate word by word; times are monotonic and never leave the line;
//   5. a quality score per sentence: back-translation chrF (when the reverse model is installed), glossary /
//      number / keep-exactly checks, length ratio, repeated phrases, untranslated text.

import { mask, unmask, glossaryMisses, numberMisses, keepMisses, westernDigits, keepSpans } from './glossary.js';
import { requestFor, llmMessages, cleanLlm } from './engines.js';
import { chrf } from './metrics.js';
import { LANG } from './langs.js';

const END_RE = /[.!?…。؟!۔।]["')\]]*$/;

// ------------------------------------------------------------------ 1. sentences
/**
 * Sentences of a caption track: [{ idx:[word indices], text, chunks:[{lineId, idx:[...], t0, t1, chars}] }].
 * Sound tags ([Music], ♪) are never part of a sentence; they stay where they are.
 */
export function sentencesOf(cap, { maxWords = 40, pause = 1.0 } = {}) {
  const words = cap.words || [], lines = cap.lines || [];
  const lineOf = new Array(words.length);
  lines.forEach((L) => { for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) lineOf[i] = L.id; });
  const out = [];
  let cur = [];
  const flush = () => { if (cur.length) out.push(makeSentence(words, cur, lineOf)); cur = []; };
  for (let i = 0; i < words.length; i++) {
    const w = words[i];
    if (w.tag) { flush(); continue; }
    cur.push(i);
    const n = words[i + 1];
    const end = END_RE.test(w.w.trim());
    const gap = n ? n.t0 - w.t1 : 0;
    const lineEnd = !n || lineOf[i + 1] !== lineOf[i];
    if (!n || end || gap > pause || n.tag || (cur.length >= maxWords && lineEnd)) flush();
  }
  flush();
  return out;
}
function makeSentence(words, idx, lineOf) {
  const chunks = [];
  for (const i of idx) {
    const last = chunks[chunks.length - 1];
    if (last && last.lineId === lineOf[i]) last.idx.push(i); else chunks.push({ lineId: lineOf[i], idx: [i] });
  }
  for (const c of chunks) {
    c.t0 = words[c.idx[0]].t0; c.t1 = words[c.idx[c.idx.length - 1]].t1;
    c.chars = c.idx.reduce((s, i) => s + words[i].w.trim().length + 1, 0);
  }
  return { idx, text: idx.map((i) => words[i].w.trim()).join(' '), chunks };
}

// ------------------------------------------------------------------ 2. translate one sentence (masked + checked)
async function runEngine(tr, eng, texts, src, tgt, glossaryKeep) {
  if (eng.kind === 'llm') {
    const r = await tr.chat(texts.map((x) => llmMessages(x, src, tgt, glossaryKeep)), { max: 200 });
    return r.texts.map(cleanLlm);
  }
  const r = await tr.tr(texts, { ...requestFor(eng, src, tgt), max: 256 });
  return r.texts;
}

/** Repeated words / phrases ("the the the", a 3-word loop) — the classic MT hallucination. */
export function hasLoop(s) {
  const t = String(s || '').split(/\s+/).filter(Boolean);
  for (let i = 2; i < t.length; i++) if (t[i] === t[i - 1] && t[i] === t[i - 2]) return true;
  for (let n = 2; n <= 4; n++) {
    const seen = new Map();
    for (let i = 0; i + n <= t.length; i++) { const g = t.slice(i, i + n).join(' '); seen.set(g, (seen.get(g) || 0) + 1); if (seen.get(g) >= 3) return true; }
  }
  return false;
}

const SCRIPT_RE = { Arab: /[؀-ۿ]/g, Deva: /[ऀ-ॿ]/g, Latn: /[A-Za-z]/g, Cyrl: /[Ѐ-ӿ]/g, Hans: /[一-鿿]/g,
  Beng: /[ঀ-৿]/g, Mlym: /[ഀ-ൿ]/g, Taml: /[஀-௿]/g };
/** Share of the letters (outside kept spans) written in the target's script. */
export function targetScriptShare(text, tgt, glossaryOut = []) {
  let s = String(text || '');
  for (const g of glossaryOut) s = s.split(g).join(' ');
  for (const k of keepSpans(s)) s = s.replace(k.text, ' ');
  const letters = (s.match(/\p{L}/gu) || []).length;
  if (!letters) return 1;
  const re = SCRIPT_RE[LANG[tgt]?.script || 'Latn'];
  return (s.match(re) || []).length / letters;
}

/**
 * Translate sentences src -> tgt with the loaded engine. Returns [{ out, attempt, missing:{terms, numbers, keep}, repaired }].
 * PLAIN FIRST: the sentence as written gives the most fluent translation (measured: masking every name and number
 * up front cost M2M-100 several chrF points — "دفع في 4 الفائدة-مرافق" for "Pay in 4 interest-free instalments").
 * Only a sentence whose plain translation lost a glossary name, a number / price or a code is asked again with
 * those spans MASKED as placeholders; whichever reading keeps more of them wins; anything still missing is put back
 * at the end of the sentence and the line is flagged ('repaired'). attempt: 'plain' | 'masked' | 'repaired'.
 */
export async function translateBatch(tr, eng, texts, src, tgt, { glossary = [] } = {}) {
  const keepNames = glossary.filter((g) => g.keep !== false && !g[tgt]).map((g) => g.term);
  const misses = (src0, out) => ({ terms: glossaryMisses(src0, out, glossary, tgt), numbers: numberMisses(src0, out), keep: keepMisses(src0, out) });
  const count = (m) => m.terms.length + m.numbers.length + m.keep.length;
  const plain = await runEngine(tr, eng, texts, src, tgt, keepNames);
  const res = plain.map((r, i) => { const out = westernDigits(r).trim(); return { out, attempt: 'plain', missing: misses(texts[i], out), lost: [] }; });
  const retry = res.map((r, i) => (count(r.missing) || !r.out || hasLoop(r.out) ? i : -1)).filter((i) => i >= 0);
  if (retry.length) {
    const masked = retry.map((i) => mask(texts[i], glossary, tgt));
    const again = await runEngine(tr, eng, masked.map((m) => m.text), src, tgt, keepNames);
    retry.forEach((i, k) => {
      const u = unmask(westernDigits(again[k]), masked[k].slots);
      const m = misses(texts[i], u.text);
      const better = u.text && !hasLoop(u.text) && (count(m) < count(res[i].missing) || !res[i].out || hasLoop(res[i].out));
      if (better) res[i] = { out: u.text, attempt: 'masked', missing: m, lost: u.missing };
    });
  }
  return res.map((r, i) => {
    let out = r.out;
    // last resort: whatever is still missing goes back at the end of the sentence (flagged for review)
    const missing = misses(texts[i], out);
    const add = [];
    for (const sl of r.lost || []) if (!out.includes(sl.out)) add.push(sl.out);
    for (const n of missing.numbers) if (!add.some((x) => westernDigits(x).replace(/,/g, '').includes(n))) add.push(keepSpans(texts[i]).find((k) => k.kind === 'number' && k.text.replace(/,/g, '').includes(n))?.text || n);
    for (const k of missing.keep) if (!add.includes(k)) add.push(k);
    for (const h of missing.terms) { const g = glossary.find((x) => x.term.toLowerCase() === h.toLowerCase()); const o = g ? (g[tgt] || g.term) : h; if (!add.includes(o)) add.push(o); }
    if (add.length) {
      const m = /([.!?؟۔।]+)$/.exec(out);
      out = (m ? out.slice(0, -m[1].length) : out).trim() + ' ' + add.join(' ') + (m ? m[1] : '');
    }
    return { out: out.trim(), attempt: add.length ? 'repaired' : r.attempt, missing, repaired: add };
  });
}

/** Eval helper (tools/t-eval.cjs --mask): the same as translateBatch, shaped like a worker reply. */
export async function translateSentences(tr, eng, texts, src, tgt, { glossary = [] } = {}) {
  const g = glossary.map((x) => (typeof x === 'string' ? { term: x, keep: true } : x));
  const t0 = performance.now();
  const r = await translateBatch(tr, eng, texts, src, tgt, { glossary: g });
  return { texts: r.map((x) => x.out), ms: Math.round(performance.now() - t0), attempts: r.map((x) => x.attempt) };
}

// ------------------------------------------------------------------ 3. + 4. back into lines, retimed
// Words that must not END a line (articles / prepositions / conjunctions) or START one (postpositions).
const NO_END = new Set(('a an the of to in on at for and or with from by my your our this that is are ' +
  'في من على إلى الى عن مع و أو ثم لا هذا هذه ' + 'اور یا کہ ' + 'और या कि ' +
  'le la les un une des de du et à au en pour avec der die das ein eine und mit für el la los las un una y de con para il lo e di per').split(/\s+/));
const NO_START = new Set(('کے کی کا میں سے کو پر نے ہے ہیں تک ' + 'के की का में से को पर ने है हैं तक').split(/\s+/));
const PUNCT_END = /[,،؛;:.!?؟۔।…)]$/;

/** Split translated tokens into k groups whose char share follows `weights`; every group gets ≥1 token if possible. */
export function splitTokens(tokens, weights) {
  const k = weights.length, n = tokens.length;
  if (k <= 1) return [tokens];
  if (n <= k) return weights.map((_, i) => (i < n ? [tokens[i]] : []));
  const W = weights.reduce((a, b) => a + b, 0) || 1;
  const len = tokens.map((t) => t.length + 1), L = len.reduce((a, b) => a + b, 0);
  const cum = [0]; for (const x of len) cum.push(cum[cum.length - 1] + x);
  const cuts = [];
  let prev = 0, wAcc = 0;
  for (let g = 0; g < k - 1; g++) {
    wAcc += weights[g];
    const want = wAcc / W * L;
    let best = -1, bestCost = Infinity;
    for (let j = prev + 1; j <= n - (k - 1 - g); j++) {          // cut after token j-1 (group gets tokens prev..j-1)
      let cost = Math.abs(cum[j] - want) / L * 100;
      const last = tokens[j - 1], next = tokens[j];
      if (PUNCT_END.test(last)) cost -= 6;
      if (NO_END.has(last.toLowerCase())) cost += 9;
      if (next && NO_START.has(next)) cost += 9;
      if (cost < bestCost) { bestCost = cost; best = j; }
    }
    cuts.push(best); prev = best;
  }
  const groups = []; let a = 0;
  for (const c of [...cuts, n]) { groups.push(tokens.slice(a, c)); a = c; }
  return groups;
}

/** Spread tokens over [t0, t1] in proportion to their length (monotonic, inside the span). */
export function spreadTimes(tokens, t0, t1) {
  const total = tokens.reduce((s, t) => s + Math.max(1, [...t].length), 0) || 1;
  const span = Math.max(0, t1 - t0);
  let acc = 0;
  return tokens.map((w) => {
    const a = t0 + span * acc / total; acc += Math.max(1, [...w].length);
    const b = t0 + span * acc / total;
    return { w, t0: +a.toFixed(3), t1: +Math.max(a, b).toFixed(3), conf: 1 };
  });
}

/**
 * Lay translated sentences back onto the source captions' lines. src = { words, lines }; sents[i] = { idx, chunks,
 * out } (from sentencesOf + a translation). Returns { words, lines } with the SAME line ids and line times as the
 * source; sound tags are copied unchanged; a line whose share of a short translation came out empty is dropped.
 */
export function layout(src, sents) {
  // one-word styles (every spoken line is a single word): the translation has its own word count, so it is spread over
  // each sentence's spoken span and every translated word becomes its own cue — no blank gaps where a source word had
  // no counterpart. The first cue of a sentence keeps the source line's id (and its own style, if any).
  const spoken = src.lines.filter((L) => !src.words[L.wordIdx[0]]?.tag);
  if (spoken.length > 2 && spoken.every((L) => L.wordIdx[0] === L.wordIdx[1])) return layoutOneWord(src, sents);
  const perLine = new Map();           // lineId -> [{ first (source word index), words:[...] }]
  const push = (id, first, ws) => { if (!perLine.has(id)) perLine.set(id, []); perLine.get(id).push({ first, words: ws }); };
  for (const s of sents) {
    const tokens = String(s.out || '').split(/\s+/).filter(Boolean);
    const groups = splitTokens(tokens, s.chunks.map((c) => c.chars));
    s.chunks.forEach((c, g) => { if (groups[g].length) push(c.lineId, c.idx[0], spreadTimes(groups[g], c.t0, c.t1)); });
  }
  src.words.forEach((w, i) => { if (w.tag) { const L = src.lines.find((x) => x.wordIdx[0] <= i && i <= x.wordIdx[1]); if (L) push(L.id, i, [{ ...w }]); } });
  const words = [], lines = [];
  for (const L of src.lines) {
    const parts = (perLine.get(L.id) || []).sort((a, b) => a.first - b.first);
    const ws = parts.flatMap((p) => p.words);
    if (!ws.length) continue;
    const a = words.length;
    words.push(...ws);
    const { wordIdx: _w, ...rest } = L;
    lines.push({ ...rest, wordIdx: [a, words.length - 1] });
  }
  return { words, lines };
}

let oneSeq = 0;
function layoutOneWord(src, sents) {
  const items = [];                     // [{ first, words:[{...}], lineId }]
  for (const s of sents) {
    const tokens = String(s.out || '').split(/\s+/).filter(Boolean);
    if (!tokens.length) continue;
    const t0 = s.chunks[0].t0, t1 = s.chunks[s.chunks.length - 1].t1;
    spreadTimes(tokens, t0, t1).forEach((w, k) => items.push({ first: s.idx[0] + k / 1000, words: [w], lineId: k === 0 ? s.chunks[0].lineId : null }));
  }
  src.words.forEach((w, i) => { if (w.tag) { const L = src.lines.find((x) => x.wordIdx[0] <= i && i <= x.wordIdx[1]); items.push({ first: i, words: [{ ...w }], lineId: L?.id || null }); } });
  items.sort((a, b) => a.first - b.first);
  const words = [], lines = [];
  const used = new Set();
  for (const it of items) {
    const a = words.length; words.push(...it.words);
    const O = it.lineId && !used.has(it.lineId) ? src.lines.find((x) => x.id === it.lineId) : null;
    const id = O ? O.id : 'ct' + Date.now().toString(36) + (oneSeq++).toString(36);
    used.add(id);
    const { wordIdx: _w, t0: _a, t1: _b, ...rest } = O || {};
    lines.push({ ...rest, id, t0: it.words[0].t0, t1: it.words[it.words.length - 1].t1, wordIdx: [a, words.length - 1] });
  }
  // hold each cue until the next one starts (as buildLines does), never past the sentence / into the next cue
  for (let i = 0; i < lines.length; i++) { const nx = lines[i + 1]; const hold = Math.min(lines[i].t1 + 0.15, nx ? nx.t0 : lines[i].t1 + 0.15); if (hold > lines[i].t1) lines[i].t1 = +hold.toFixed(3); }
  return { words, lines };
}

// ------------------------------------------------------------------ 5. quality
/** Per-sentence checks (no model): { flags:[…], score (0-100, higher = safer) }. back = back-translation or null. */
export function assess(srcText, out, tgt, glossary, back = null) {
  const flags = [];
  const terms = glossaryMisses(srcText, out, glossary, tgt), nums = numberMisses(srcText, out), keep = keepMisses(srcText, out);
  if (terms.length) flags.push({ k: 'terms', msg: 'Name missing: ' + terms.join(', ') });
  if (nums.length) flags.push({ k: 'numbers', msg: 'Number missing: ' + nums.join(', ') });
  if (keep.length) flags.push({ k: 'keep', msg: 'Not kept exactly: ' + keep.join(' ') });
  const ratio = [...out].length / Math.max(1, [...srcText].length);
  if (!out.trim()) flags.push({ k: 'empty', msg: 'No translation' });
  else if (ratio < 0.35 || ratio > 2.6) flags.push({ k: 'length', msg: ratio < 1 ? 'Much shorter than the original' : 'Much longer than the original' });
  if (hasLoop(out)) flags.push({ k: 'loop', msg: 'Repeats itself' });
  const share = targetScriptShare(out, tgt, glossary.map((g) => g[tgt] || g.term));
  if (share < 0.6) flags.push({ k: 'script', msg: 'Partly untranslated' });
  let bt = null;
  if (back != null) {
    bt = Math.round(chrf(back, srcText));
    if (bt < BT_FLAG) flags.push({ k: 'meaning', msg: `Back-translation differs (${bt}/100)` });
  }
  const score = Math.max(0, Math.min(100, (bt ?? 70) - flags.filter((f) => f.k !== 'meaning').length * 25));
  return { flags, score, bt };
}
/** Back-translation chrF below this flags a line for review (set from the test set: see README, translation). */
export const BT_FLAG = 45;

// ------------------------------------------------------------------ the whole track
/**
 * Translate a caption track. opts: { src, tgt, eng, tr, back: { eng, tr } | null, glossary, onProgress(f, label), signal,
 *   batch (sentences per call, default 4) }.
 * Returns { lang, src, engine, words, lines, sents:[{ text, out, idx, chunks, flags, score, bt, attempt }], ms }.
 */
export async function translateTrack(cap, opts) {
  const { src, tgt, eng, tr, back = null, glossary = [], onProgress = () => {}, signal, batch = 4 } = opts;
  const t0 = performance.now();
  const sents = sentencesOf(cap);
  const total = sents.length * (back ? 2 : 1) || 1;
  let done = 0;
  for (let i = 0; i < sents.length; i += batch) {
    if (signal?.aborted) throw Object.assign(new Error('Cancelled'), { name: 'AbortError' });
    const part = sents.slice(i, i + batch);
    const r = await translateBatch(tr, eng, part.map((s) => s.text), src, tgt, { glossary });
    part.forEach((s, k) => Object.assign(s, { out: r[k].out, attempt: r[k].attempt }));
    done += part.length;
    onProgress(done / total, `Translating ${Math.min(sents.length, i + batch)} / ${sents.length}`);
  }
  if (back) {
    for (let i = 0; i < sents.length; i += batch) {
      if (signal?.aborted) throw Object.assign(new Error('Cancelled'), { name: 'AbortError' });
      const part = sents.slice(i, i + batch);
      const r = await back.tr.tr(part.map((s) => maskForBack(s.out, glossary, tgt)), { ...requestFor(back.eng, tgt, src), max: 256 });
      part.forEach((s, k) => { s.back = r.texts[k]; });
      done += part.length;
      onProgress(done / total, `Checking ${Math.min(sents.length, i + batch)} / ${sents.length}`);
    }
  }
  for (const s of sents) Object.assign(s, assess(s.text, s.out, tgt, glossary, s.back ?? null));
  const lay = layout(cap, sents);
  return { lang: tgt, src, engine: eng.id, back: back?.eng.id || null, ...lay, sents: sents.map(slim), ms: Math.round(performance.now() - t0) };
}
// back-translation sees the target spelling of fixed terms; names stay as they are
const maskForBack = (out) => out;
const slim = (s) => ({ text: s.text, out: s.out, idx: s.idx, chunks: s.chunks, flags: s.flags, score: s.score, bt: s.bt, attempt: s.attempt, back: s.back });

/** Re-lay a stored translation after one sentence was edited / re-translated (no model call). */
export function relayout(source, sents) { return layout(source, sents); }

/** Flags per line id: { lineId: [flag…] } (a line inherits the flags of every sentence it holds part of). */
export function lineFlags(sents) {
  const m = {};
  for (const s of sents) if (s.flags?.length && !s.approved) for (const c of s.chunks) (m[c.lineId] ||= []).push(...s.flags);
  return m;
}
