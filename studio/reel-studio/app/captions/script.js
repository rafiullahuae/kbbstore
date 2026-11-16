// The full script: the captions as readable text (paragraphs by pauses and sentences, optional timestamps, speaker
// turns), exports (TXT / SRT / VTT, bilingual side by side) and the diff that carries an edited paragraph back into
// the caption words without losing their timing. Pure functions, no DOM.

import { displayWord, DEFAULT_TAG_MODE } from './nonspeech.js';

const END_RE = /[.!?…。؟!]["')\]]*$/;

/** Words that make it into text for a tag mode ('hide' drops tags, 'icon' shows ♪, 'text' keeps them). */
export function visibleWords(cap, mode = cap.soundTags || DEFAULT_TAG_MODE) {
  const out = [];
  cap.words.forEach((w, i) => { const t = displayWord(w, mode); if (t) out.push({ ...w, w: t, i }); });
  return out;
}

/**
 * Paragraphs: break on a pause >= `pause` s after a sentence end, on any pause >= 2 * `pause`, on a speaker change,
 * and once a paragraph passes `maxWords` at the next sentence end. Each: { a, b (word indices, inclusive, into
 * cap.words), t0, t1, spk, text }.
 */
export function paragraphs(cap, { pause = 0.9, maxWords = 70, mode } = {}) {
  const ws = cap.words, out = [];
  if (!ws.length) return out;
  let a = 0;
  for (let i = 0; i < ws.length; i++) {
    const w = ws[i], n = ws[i + 1];
    let brk = !n;
    if (n) {
      const gap = n.t0 - w.t1, end = END_RE.test(w.w.trim());
      if ((end && gap >= pause) || gap >= pause * 2.2) brk = true;
      if ((w.spk ?? 0) !== (n.spk ?? 0)) brk = true;
      if (end && i - a + 1 >= maxWords) brk = true;
      if (!!w.tag !== !!n.tag && gap >= 0.3) brk = true;
    }
    if (brk) { out.push(para(cap, a, i, mode)); a = i + 1; }
  }
  return out;
}

function para(cap, a, b, mode = cap.soundTags || DEFAULT_TAG_MODE) {
  const ws = cap.words.slice(a, b + 1);
  const text = ws.map((w) => displayWord(w, mode)).filter(Boolean).join(' ');
  return { a, b, t0: ws[0].t0, t1: ws[ws.length - 1].t1, spk: ws[0].spk ?? null, text, tagOnly: ws.every((w) => w.tag) };
}

export const fmtClock = (t, ms = false) => {
  t = Math.max(0, t);
  const h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = Math.floor(t % 60);
  const base = (h ? String(h).padStart(2, '0') + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  return ms ? base + '.' + String(Math.round((t % 1) * 1000) % 1000).padStart(3, '0') : base;
};
const srtTime = (t) => {
  t = Math.max(0, t);
  const h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = Math.floor(t % 60), ms = Math.round((t - Math.floor(t)) * 1000);
  const fix = ms === 1000 ? [s + 1, 0] : [s, ms];
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(fix[0]).padStart(2, '0')},${String(fix[1]).padStart(3, '0')}`;
};
const vttTime = (t) => srtTime(t).replace(',', '.');

export const speakerName = (cap, s) => (cap.speakerNames && cap.speakerNames[s]) || `Speaker ${s + 1}`;
const hasSpeakers = (cap) => cap.words.some((w) => (w.spk ?? 0) > 0);

/** Plain-text script. opts: { timestamps, speakers (auto), mode } */
export function toTXT(cap, opts = {}) {
  const ps = paragraphs(cap, opts).filter((p) => p.text);
  const spk = opts.speakers ?? hasSpeakers(cap);
  let prev = null;
  return ps.map((p) => {
    const head = [];
    if (opts.timestamps) head.push(`[${fmtClock(p.t0)}]`);
    if (spk && p.spk != null && p.spk !== prev) head.push(speakerName(cap, p.spk) + ':');
    prev = p.spk;
    return (head.length ? head.join(' ') + ' ' : '') + p.text;
  }).join('\n\n') + '\n';
}

/** Caption cues as they show on screen (the line structure), skipping lines that show nothing. */
export function cues(cap, mode = cap.soundTags || DEFAULT_TAG_MODE) {
  const out = [];
  for (const L of cap.lines) {
    const ws = cap.words.slice(L.wordIdx[0], L.wordIdx[1] + 1);
    const text = (L.ov?.text != null ? L.ov.text : ws.map((w) => displayWord(w, mode)).filter(Boolean).join(' ')).trim();
    if (!text) continue;
    const prevEnd = out.length ? out[out.length - 1].t1 : 0;
    out.push({ t0: Math.max(L.t0, prevEnd), t1: Math.max(L.t1, Math.max(L.t0, prevEnd) + 0.05), text, spk: ws[0]?.spk ?? null });
  }
  return out;
}

function pairAlt(cap, list, mode) {
  if (!cap.alt?.lines?.length) return list.map((c) => ({ ...c, alt: '' }));
  const alt = cues(cap.alt, mode);
  return list.map((c) => {
    let best = '', bo = 0;
    for (const a of alt) { const o = Math.min(c.t1, a.t1) - Math.max(c.t0, a.t0); if (o > bo) { bo = o; best = a.text; } }
    return { ...c, alt: best };
  });
}

/** SubRip. opts.bilingual: second language as a second line of each cue. */
export function toSRT(cap, opts = {}) {
  const mode = opts.mode || cap.soundTags || DEFAULT_TAG_MODE;
  const list = opts.bilingual ? pairAlt(cap, cues(cap, mode), mode) : cues(cap, mode);
  return list.map((c, i) => `${i + 1}\n${srtTime(c.t0)} --> ${srtTime(c.t1)}\n${c.text}${c.alt ? '\n' + c.alt : ''}\n`).join('\n');
}

/** WebVTT (speaker turns as <v Speaker N> when detected). */
export function toVTT(cap, opts = {}) {
  const mode = opts.mode || cap.soundTags || DEFAULT_TAG_MODE;
  const list = opts.bilingual ? pairAlt(cap, cues(cap, mode), mode) : cues(cap, mode);
  const spk = hasSpeakers(cap);
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return 'WEBVTT\n\n' + list.map((c, i) => `${i + 1}\n${vttTime(c.t0)} --> ${vttTime(c.t1)}\n${spk && c.spk != null ? `<v ${speakerName(cap, c.spk)}>` : ''}${esc(c.text)}${c.alt ? '\n' + esc(c.alt) : ''}\n`).join('\n');
}

/** Bilingual script: paragraphs of the main language with the overlapping second-language text beside them. */
export function toBilingualTXT(cap, opts = {}) {
  if (!cap.alt?.words?.length) return toTXT(cap, opts);
  const ps = paragraphs(cap, opts).filter((p) => p.text);
  const alt = cap.alt.words;
  return ps.map((p) => {
    const a = alt.filter((w) => w.t1 > p.t0 && w.t0 < p.t1).map((w) => w.w).join(' ');
    return (opts.timestamps ? `[${fmtClock(p.t0)}]\n` : '') + p.text + '\n' + (a ? a : '') ;
  }).join('\n\n') + '\n';
}

/** Parse SRT or VTT back into cues: [{t0,t1,text}] (used by the checks and by "import subtitles"). */
export function parseSubtitles(s) {
  const out = [];
  const T = (x) => { const m = /(?:(\d+):)?(\d{1,2}):(\d{2})[,.](\d{3})/.exec(x); return m ? (+(m[1] || 0)) * 3600 + +m[2] * 60 + +m[3] + +m[4] / 1000 : NaN; };
  for (const block of String(s).replace(/\r/g, '').split(/\n{2,}/)) {
    const lines = block.split('\n').filter((l) => l.trim() !== '');
    const k = lines.findIndex((l) => l.includes('-->'));
    if (k < 0) continue;
    const [a, b] = lines[k].split('-->');
    out.push({ t0: T(a), t1: T(b), text: lines.slice(k + 1).join('\n').replace(/<[^>]+>/g, '') });
  }
  return out;
}

// ------------------------------------------------------------------ speakers (best effort)
// Per sentence: median pitch (autocorrelation on voiced 32 ms frames) and a brightness measure. Two clusters by
// 2-means on log-pitch; called "two speakers" only when the clusters are far apart (> ~22 % in pitch) and each holds
// at least two sentences or a fifth of the talk. Otherwise everybody is Speaker 1 (no labels shown).

function pitchOf(x, a, b) {
  const SR = 16000, N = 512, minLag = Math.floor(SR / 400), maxLag = Math.floor(SR / 70);
  const f0s = [];
  const step = Math.max(N, Math.floor((b - a) / 14));
  for (let o = a; o + N + maxLag < b; o += step) {
    let e = 0; for (let i = 0; i < N; i++) e += x[o + i] * x[o + i];
    if (e / N < 1e-5) continue;
    let best = 0, bl = 0;
    for (let l = minLag; l <= maxLag; l++) {
      let s = 0, e2 = 0; for (let i = 0; i < N; i++) { s += x[o + i] * x[o + i + l]; e2 += x[o + i + l] * x[o + i + l]; }
      const r = s / Math.sqrt(e * e2 + 1e-12);
      if (r > best) { best = r; bl = l; }
    }
    if (best > 0.55 && bl) f0s.push(SR / bl);
  }
  f0s.sort((p, q) => p - q);
  return f0s.length >= 2 ? f0s[f0s.length >> 1] : null;
}

/** Sentences of the word list: [{a,b,t0,t1}] */
export function sentences(words) {
  const out = []; let a = 0;
  for (let i = 0; i < words.length; i++) {
    const n = words[i + 1];
    if (!n || END_RE.test(words[i].w.trim()) || n.t0 - words[i].t1 > 0.8) { out.push({ a, b: i, t0: words[a].t0, t1: words[i].t1 }); a = i + 1; }
  }
  return out;
}

/** Returns the words with w.spk = 0|1 when two voices are told apart, else the words unchanged (and n=1). */
export function detectSpeakers(words, pcm16k) {
  if (!pcm16k || words.length < 6) return { words, n: 1 };
  const ss = sentences(words).filter((s) => !words[s.a].tag);
  const SR = 16000;
  for (const s of ss) s.f0 = pitchOf(pcm16k, Math.floor(s.t0 * SR), Math.min(pcm16k.length, Math.ceil(s.t1 * SR)));
  const v = ss.filter((s) => s.f0);
  if (v.length < 3) return { words, n: 1 };
  const lf = v.map((s) => Math.log(s.f0));
  let c0 = Math.min(...lf), c1 = Math.max(...lf);
  for (let it = 0; it < 12; it++) {
    let s0 = 0, n0 = 0, s1 = 0, n1 = 0;
    for (const x of lf) { if (Math.abs(x - c0) <= Math.abs(x - c1)) { s0 += x; n0++; } else { s1 += x; n1++; } }
    if (n0) c0 = s0 / n0; if (n1) c1 = s1 / n1;
  }
  const lab = lf.map((x) => (Math.abs(x - c0) <= Math.abs(x - c1) ? 0 : 1));
  const dur = [0, 0], cnt = [0, 0];
  v.forEach((s, k) => { dur[lab[k]] += s.t1 - s.t0; cnt[lab[k]]++; });
  const total = dur[0] + dur[1];
  const apart = Math.abs(c1 - c0) > 0.2;
  const both = [0, 1].every((k) => cnt[k] >= 2 || dur[k] / total >= 0.2);
  if (!apart || !both) return { words, n: 1, f0: v.map((s) => Math.round(s.f0)) };
  // the first voice heard is Speaker 1
  const first = lab[0];
  const out = words.map((w) => ({ ...w }));
  let cur = 0;
  const lbl = new Map(v.map((s, k) => [s, lab[k] === first ? 0 : 1]));
  for (const s of sentences(words)) {
    const m = [...lbl.keys()].find((x) => x.a === s.a);
    if (m) cur = lbl.get(m);
    for (let i = s.a; i <= s.b; i++) out[i].spk = cur;
  }
  return { words: out, n: 2, f0: v.map((s) => Math.round(s.f0)) };
}

// ------------------------------------------------------------------ edited text -> caption words

const core = (w) => String(w).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');

/**
 * Align an edited paragraph to the words it came from and return the new word objects for that span.
 * Word-level LCS on letters only: unchanged words keep their own timing (and overrides); a changed word takes the
 * timing of the word it replaced (several new words share it, by length); an inserted word takes the pause next to
 * it or a share of its neighbour; deleted words go. Punctuation/case changes count as an edit of that word.
 */
export function alignEdit(oldWords, newText) {
  const nt = String(newText).trim().split(/\s+/).filter(Boolean);
  const A = oldWords.map((w) => core(w.w)), B = nt.map(core);
  const n = A.length, m = B.length;
  const L = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) L[i][j] = A[i] && A[i] === B[j] ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
  // walk: produce ops
  const ops = []; let i = 0, j = 0;
  while (i < n || j < m) {
    if (i < n && j < m && A[i] && A[i] === B[j]) { ops.push({ k: 'eq', i, j }); i++; j++; }
    else if (j < m && (i >= n || L[i][j + 1] >= L[i + 1][j])) { ops.push({ k: 'ins', j }); j++; }
    else { ops.push({ k: 'del', i }); i++; }
  }
  // group runs of del/ins between equals into substitutions
  const out = [];
  let q = 0;
  while (q < ops.length) {
    const o = ops[q];
    if (o.k === 'eq') { const w = oldWords[o.i]; out.push(nt[o.j] === w.w ? w : { ...w, w: nt[o.j], conf: 1 }); q++; continue; }
    const dels = [], ins = [];
    while (q < ops.length && ops[q].k !== 'eq') { (ops[q].k === 'del' ? dels : ins).push(ops[q]); q++; }
    const texts = ins.map((x) => nt[x.j]);
    if (!texts.length) continue;                                     // pure deletion
    let t0, t1, base;
    if (dels.length) { base = oldWords[dels[0].i]; t0 = base.t0; t1 = oldWords[dels[dels.length - 1].i].t1; }
    else {
      const prev = out[out.length - 1], nextOp = ops[q], next = nextOp ? oldWords[nextOp.i] : null;
      base = prev || next || { t0: 0, t1: 0.4 };
      if (prev && next && next.t0 - prev.t1 >= 0.12) { t0 = prev.t1; t1 = next.t0; }
      else if (prev) { const mid = (prev.t0 + prev.t1) / 2; out[out.length - 1] = { ...prev, t1: +mid.toFixed(3) }; t0 = mid; t1 = prev.t1; }
      else if (next) { t0 = Math.max(0, next.t0 - 0.3 * texts.length); t1 = next.t0; }
      else { t0 = 0; t1 = 0.4 * texts.length; }
    }
    const tot = texts.reduce((s, x) => s + Math.max(1, x.length), 0);
    let t = t0;
    texts.forEach((x, k) => {
      const d = (t1 - t0) * Math.max(1, x.length) / tot;
      const w = { t0: +t.toFixed(3), t1: +(t + d).toFixed(3), w: x, conf: 1 };
      if (base.spk != null) w.spk = base.spk;
      if (dels[k] && oldWords[dels[k].i].ov) w.ov = oldWords[dels[k].i].ov;
      out.push(w); t += d;
    });
  }
  return out;
}
