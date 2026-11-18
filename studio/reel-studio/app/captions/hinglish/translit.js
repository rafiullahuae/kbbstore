// Devanagari -> Roman Hindi ("Hinglish") the way people type it on social media, and back. Offline, deterministic,
// pure functions (no DOM). Casual spelling, not ISO/IAST: कमल kamal (schwa deletion), में mein, हैं hain (nasals),
// है hai / नहीं nahi / क्या kya (common words), ज़ z / फ़ f (nukta), क्ष ksh / ज्ञ gy / अच्छा achha (conjuncts), and
// English words written in Devanagari back in English (जॉब job, कनाडा Canada, अप्लाई apply).
//
//   toRoman(word)           one word (punctuation kept, । -> .)
//   romanizeText(text)      a sentence: words + sentence-initial capitals
//   toDevanagari(word)      Roman -> Devanagari: dictionary first (exact), then rules (best effort)
//   keyOf(word)             the spelling-variant fold used for dictionary lookups
// The data (dict.js) is original work under CC0-1.0.

import { WORDS, VERBS_C, VERBS_V, STEM_V, LOANS } from './dict.js';

const VIRAMA = '्', NUKTA = '़', ANUS = 'ं', CANDRA = 'ँ', VISARGA = 'ः';
const isCons = (c) => (c >= 'क' && c <= 'ह') || (c >= 'क़' && c <= 'य़') || c === 'ळ' || c === 'ऴ';
export const DEVA_RE = /[ऀ-ॿ]/;

// consonant -> roman (nukta forms first)
const NK = { 'क': 'q', 'ख': 'kh', 'ग': 'g', 'ज': 'z', 'ड': 'd', 'ढ': 'dh', 'फ': 'f', 'य': 'y', 'र': 'r', 'न': 'n', 'ल': 'l' };
const CONS = {
  'क': 'k', 'ख': 'kh', 'ग': 'g', 'घ': 'gh', 'ङ': 'n', 'च': 'ch', 'छ': 'chh', 'ज': 'j', 'झ': 'jh', 'ञ': 'n',
  'ट': 't', 'ठ': 'th', 'ड': 'd', 'ढ': 'dh', 'ण': 'n', 'त': 't', 'थ': 'th', 'द': 'd', 'ध': 'dh', 'न': 'n',
  'प': 'p', 'फ': 'ph', 'ब': 'b', 'भ': 'bh', 'म': 'm', 'य': 'y', 'र': 'r', 'ल': 'l', 'व': 'v', 'श': 'sh',
  'ष': 'sh', 'स': 's', 'ह': 'h', 'ळ': 'l',
};
// vowel signs and independent vowels -> [short spelling, long spelling (closed syllable / stressed)]
const SIGN = { 'ा': 'aa', 'ि': 'i', 'ी': 'ii', 'ु': 'u', 'ू': 'uu', 'ृ': 'ri', 'े': 'e', 'ै': 'ai', 'ो': 'o', 'ौ': 'au', 'ॅ': 'e', 'ॉ': 'o', 'ॆ': 'e', 'ॊ': 'o' };
const INDEP = { 'अ': 'a', 'आ': 'aa', 'इ': 'i', 'ई': 'ii', 'उ': 'u', 'ऊ': 'uu', 'ऋ': 'ri', 'ए': 'e', 'ऐ': 'ai', 'ओ': 'o', 'औ': 'au', 'ऑ': 'o', 'ऍ': 'e', 'ऎ': 'e', 'ऒ': 'o' };
const DIGITS = '०१२३४५६७८९';
const LABIAL = new Set(['प', 'फ', 'ब', 'भ', 'म']);

/** The fold used for lookups: nukta-less, chandrabindu = anusvara, half nasal before a consonant = anusvara, no ZWJ. */
export function keyOf(w) {
  let s = String(w || '').normalize('NFC').replace(/[‌‍]/g, '').replace(/ँ/g, ANUS);
  s = s.normalize('NFD').replace(/़/g, '').normalize('NFC');
  s = s.replace(/[नमणङञ]्(?=[क-ह])/g, ANUS);
  return s.replace(/[क़-य़]/g, (c) => c.normalize('NFD')[0]);
}

// ---------------------------------------------------------------- the dictionary (built once)
const FORMS_C = [['ना', 'na'], ['ने', 'ne'], ['नी', 'ni'], ['ता', 'ta'], ['ती', 'ti'], ['ते', 'te'], ['ो', 'o', 1], ['ें', 'ein', 1], ['ेगा', 'ega', 1], ['ेगी', 'egi', 1],
  ['ेंगे', 'enge', 1], ['ेंगी', 'engi', 1], ['ोगे', 'oge', 1], ['के', 'ke'], ['कर', 'kar'], ['िए', 'iye', 1], ['िये', 'iye', 1], ['ा', 'a', 1], ['ी', 'i', 1], ['े', 'e', 1], ['ीं', 'in', 1], ['ूँ', 'un', 1], ['ूं', 'un', 1]];
const FORMS_V = [['ना', 'na'], ['ने', 'ne'], ['नी', 'ni'], ['ता', 'ta'], ['ती', 'ti'], ['ते', 'te'], ['ओ', 'o'], ['एं', 'yein'], ['एँ', 'yein'], ['एगा', 'yega'], ['एगी', 'yegi'],
  ['एंगे', 'yenge'], ['एँगे', 'yenge'], ['ओगे', 'oge'], ['के', 'ke'], ['कर', 'kar'], ['इए', 'iye'], ['इये', 'iye'], ['या', 'ya'], ['ई', 'yi'], ['ए', 'ye'], ['ये', 'ye']];
let DICT = null, REV = null;
function dict() {
  if (DICT) return DICT;
  DICT = new Map();
  const put = (d, r, force) => { const k = keyOf(d); if (force || !DICT.has(k)) DICT.set(k, r.replace(/_/g, ' ')); FULL.set(k, d); };
  for (const [d, r] of VERBS_C) { put(d, r); for (const [sd, sr, v] of FORMS_C) put(d + sd, (v && STEM_V[d] ? STEM_V[d] : r) + sr); }
  for (const [d, r] of VERBS_V) { put(d, r); for (const [sd, sr] of FORMS_V) put(d + sd, r + sr); }
  for (const [d, r] of LOANS) put(d, r, true);
  for (const [d, r] of WORDS) put(d, r, true);   // hand-written words win over generated forms
  return DICT;
}
const FULL = new Map();   // folded key -> the spelling as written (with nukta), for the Urdu side
export const dictSize = () => dict().size;
/** every dictionary entry as [devanagari as written, roman] */
export const dictEntries = () => [...dict()].map(([k, r]) => [FULL.get(k) || k, r]);

// ---------------------------------------------------------------- Devanagari -> Roman (rules)
/** syllable units of one Devanagari word: [{c: consonant roman or '', ch: the letter, v: vowel or null (schwa) or '' (virama), nas, ...}] */
function units(word) {
  const s = word.normalize('NFC').normalize('NFD').replace(/[‌‍]/g, '');
  const out = [];
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (isCons(c)) {
      let r = CONS[c], nk = false;
      if (s[i + 1] === NUKTA) { r = NK[c] || r; nk = true; i++; }
      out.push({ ch: c, c: r, v: null, nk });
    } else if (SIGN[c] && out.length && out[out.length - 1].v === null) out[out.length - 1].v = SIGN[c];
    else if (c === VIRAMA && out.length) out[out.length - 1].v = '';
    else if ((c === ANUS || c === CANDRA) && out.length) out[out.length - 1].nas = true;
    else if (c === VISARGA && out.length) out[out.length - 1].vis = true;
    else if (INDEP[c] !== undefined) out.push({ ch: c, c: '', v: INDEP[c], indep: true });
    else if (c === NUKTA) { /* stray */ }
    else if (SIGN[c]) out.push({ ch: c, c: '', v: SIGN[c], indep: true });   // a sign with no consonant
  }
  return out;
}

function rulesRoman(word) {
  const u = units(word);
  if (!u.length) return '';
  const n = u.length;
  // schwa deletion (right to left): word-final schwa goes; a medial schwa goes in V C _ C V (never making 3 consonants)
  const hasV = (k) => k >= 0 && k < n && u[k].v !== '' && u[k].v !== 'X';
  // ... except after a cluster ending in र / य / व (मित्र mitra, सत्य satya, तत्व tatva)
  if (u[n - 1].v === null && n > 1 && !u[n - 1].nas) u[n - 1].v = u[n - 2].v === '' && 'रयव'.includes(u[n - 1].ch) ? 'a' : 'X';
  else if (u[n - 1].v === null && n === 1) u[n - 1].v = 'a';
  for (let k = n - 2; k >= 1; k--) {
    if (u[k].v !== null || u[k].nas) continue;
    const prev = u[k - 1], next = u[k + 1];
    const prevOpen = hasV(k - 1) && !prev.nas;              // the consonant before has its own vowel and no coda
    const nextCV = next && next.c && hasV(k + 1);           // ... and the one after starts a syllable
    if (prevOpen && nextCV) u[k].v = 'X';
  }
  for (const x of u) if (x.v === null) x.v = 'a';
  // syllables: closed when the next consonant carries no vowel (or a nasal coda)
  const isLong = (v) => v === 'aa' || v === 'ii' || v === 'uu';
  const vowels = u.filter((x) => x.v && x.v !== 'X' && x.v !== '').length;
  let out = '';
  for (let k = 0; k < n; k++) {
    const x = u[k], next = u[k + 1], after = u[k + 2];
    let c = x.c;
    // व: w after a consonant (स्व sw, द्व dw) and before a / aa / ai / o / au; v before i / ii / e / u / r
    if (x.ch === 'व') c = (k > 0 && u[k - 1].v === '') || ['a', 'aa', 'ai', 'o', 'au'].includes(x.v) ? 'w' : 'v';
    // च्छ -> chh (अच्छा achha); a doubled consonant from च्च stays "chch" -> casual "chch"
    if (x.ch === 'च' && x.v === '' && next?.ch === 'छ') c = '';
    // क्ष ksh, ज्ञ gy
    if (x.ch === 'ज' && x.v === '' && next?.ch === 'ञ') { out += 'gy'; next.c = ''; next.done = true; }
    else out += c;
    let v = x.v;
    if (v === 'X' || v === '') continue;
    const coda = next && next.c && (next.v === '' || next.v === 'X');
    const closed = coda || (x.nas && next && next.c);
    if (isLong(v)) {
      const initial = k === 0;
      const lastSyl = !u.slice(k + 1).some((y) => y.v && y.v !== 'X' && y.v !== '');
      if (v === 'aa') v = (initial && x.indep) || (closed && (vowels === 1 || !lastSyl)) || (vowels === 1 && x.nas) ? 'aa' : 'a';
      else if (v === 'ii') v = closed || (vowels === 1 && x.nas) || (initial && x.indep && closed) ? 'ee' : 'i';
      else v = closed || (vowels === 1 && x.nas) ? 'oo' : 'u';
    }
    // a vowel letter right after a vowel: ए -> ye (लिए liye, गए gaye)
    if (x.indep && k > 0 && u[k - 1].v && u[k - 1].v !== '' && u[k - 1].v !== 'X') { if (v === 'e') v = 'ye'; }
    // first-syllable a before a vowelless ह: रहना rehna, पहले pehle, कहना kehna, यह yeh
    if (v === 'a' && k === u.findIndex((y) => y.v && y.v !== 'X' && y.v !== '') && next?.ch === 'ह' && (next.v === '' || next.v === 'X')) v = 'e';
    out += v;
    if (x.nas) {
      if (next && next.c) out += LABIAL.has(next.ch) ? 'm' : 'n';
      else if (!next) out += v === 'e' ? 'in' : 'n';   // में mein, हैं hain, हूँ hoon, यहाँ yahan
      else out += 'n';
    }
    if (x.vis) out += 'h';
  }
  // e + final nasal reads "ein" (में mein, करें karein, बातें baatein)
  return out;
}

/** One Devanagari word -> casual Roman. Keeps leading/trailing punctuation; Latin text passes through. */
export function toRoman(word) {
  const m = /^([^\p{L}\p{N}\p{M}]*)(.*?)([^\p{L}\p{N}\p{M}]*)$/u.exec(String(word || ''));
  const [, pre, core, post] = m;
  const p2 = post.replace(/[।॥]/g, '.');
  if (!DEVA_RE.test(core)) return pre + core + p2;
  // hyphenated / joined forms: transliterate each piece
  if (/[-‐]/.test(core)) return pre + core.split(/[-‐]/).map((x) => toRoman(x)).join('-') + p2;
  const digits = core.replace(/[०-९]/g, (d) => DIGITS.indexOf(d));
  if (!DEVA_RE.test(digits)) return pre + digits + p2;
  const hit = dict().get(keyOf(digits));
  return pre + (hit ?? rulesRoman(digits)) + p2;
}

/**
 * Is this Devanagari word an English loanword (it maps back to English spelling)? fuzzy: also a word the Hindi
 * dictionary does not know whose consonant skeleton (2+ consonants, vowels and marks dropped) is a loanword's —
 * measured: Whisper on a Hindi voice wrote स्किन as सकिन and वीज़ा as विजा. Used to tell Hinglish, never to spell.
 */
export function isLoan(word, { fuzzy = false } = {}) {
  const k = keyOf(String(word || '').replace(/[^\p{L}\p{M}]/gu, ''));
  if (LOAN_KEYS().has(k)) return true;
  if (!fuzzy || dict().has(k)) return false;
  const sk = skeleton(k);
  return sk.length >= 2 && LOAN_SKEL().has(sk);
}
const skeleton = (k) => [...k.normalize('NFD')].filter(isCons).join('');
let loanKeys = null, loanSkel = null;
const LOAN_KEYS = () => (loanKeys ||= new Set(LOANS.map(([d]) => keyOf(d))));
const LOAN_SKEL = () => (loanSkel ||= new Set(LOANS.map(([d]) => skeleton(keyOf(d))).filter((s) => s.length >= 2)));

/** Capitalise the first letter of a sentence (start, or after . ? !). words: [string] in Roman. */
export function sentenceCase(words) {
  let start = true;
  return words.map((w) => {
    let o = w;
    if (start && /\p{Ll}/u.test(w)) o = w.replace(/\p{L}/u, (c) => c.toUpperCase());
    if (/\p{L}|\p{N}/u.test(w)) start = /[.!?…]["')\]]*$/.test(w);
    return o;
  });
}
export const romanizeText = (text) => sentenceCase(String(text || '').split(/\s+/).filter(Boolean).map(toRoman)).join(' ');

// ---------------------------------------------------------------- Roman -> Devanagari
function rev() {
  if (REV) return REV;
  REV = new Map();
  // the dictionary inverted: the FIRST spelling listed wins (the standard one); hand-written words before generated
  for (const [d, r] of [...WORDS, ...LOANS]) { const k = r.replace(/_/g, ' ').toLowerCase(); if (!REV.has(k)) REV.set(k, d); }
  for (const [k, r] of dict()) { const kk = r.toLowerCase(); if (!REV.has(kk)) REV.set(kk, k); }
  return REV;
}
const R_CONS = [['chh', 'छ'], ['kh', 'ख'], ['gh', 'घ'], ['ch', 'च'], ['jh', 'झ'], ['th', 'थ'], ['dh', 'ध'], ['ph', 'फ'], ['bh', 'भ'], ['sh', 'श'],
  ['k', 'क'], ['g', 'ग'], ['j', 'ज'], ['t', 'त'], ['d', 'द'], ['n', 'न'], ['p', 'प'], ['b', 'ब'], ['m', 'म'], ['y', 'य'], ['r', 'र'], ['l', 'ल'],
  ['v', 'व'], ['w', 'व'], ['s', 'स'], ['h', 'ह'], ['z', 'ज़'], ['f', 'फ़'], ['q', 'क़'], ['x', 'क्स'], ['c', 'क']];
const R_VOW = [['aa', 'ा', 'आ'], ['ee', 'ी', 'ई'], ['ii', 'ी', 'ई'], ['oo', 'ू', 'ऊ'], ['ai', 'ै', 'ऐ'], ['au', 'ौ', 'औ'], ['a', '', 'अ'], ['i', 'ि', 'इ'], ['u', 'ु', 'उ'], ['e', 'े', 'ए'], ['o', 'ो', 'ओ']];
function rulesDeva(w) {
  let s = w.toLowerCase(), out = '', prevCons = false;
  while (s) {
    let m = R_VOW.find(([r]) => s.startsWith(r));
    if (m) {
      let sign = m[1];
      if (m[0] === 'a' && !s.slice(1) && prevCons) sign = 'ा';                 // final -a reads long (karna)
      if (m[0] === 'i' && !s.slice(1) && prevCons) sign = 'ी';                 // final -i reads long (nahi)
      out += prevCons ? sign : m[2];
      s = s.slice(m[0].length);
      if (s.startsWith('n') && (!s[1] || !/[aeiou]/.test(s[1]))) { out += 'ं'; s = s.slice(1); }
      prevCons = false; continue;
    }
    m = R_CONS.find(([r]) => s.startsWith(r));
    if (m) { if (prevCons) out += VIRAMA; out += m[1]; s = s.slice(m[0].length); prevCons = true; continue; }
    out += s[0]; s = s.slice(1); prevCons = false;
  }
  return out;
}
/** One Roman word -> Devanagari (dictionary first, then a rough phonetic spelling). Non-letters stay. */
export function toDevanagari(word, { rough = true } = {}) {
  const m = /^([^\p{L}\p{N}]*)(.*?)([^\p{L}\p{N}]*)$/u.exec(String(word || ''));
  const [, pre, core, post] = m;
  if (!core || !/[A-Za-z]/.test(core)) return word;
  const hit = rev().get(core.toLowerCase());
  if (hit == null && !rough) return word;
  return pre + (hit ?? rulesDeva(core)) + post.replace(/\.$/, "।");
}
export const rulesOnly = (w) => rulesRoman(w);
