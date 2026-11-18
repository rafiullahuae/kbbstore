// Caption script for Hindustani (Hindi / Urdu) speech: Devanagari, Roman (Hinglish / Roman Urdu) or Urdu script.
//
// Lossless by construction: a word shown in another script keeps the text it was transcribed in as `w.orig`
// (the native text) and only `w.w` changes; switching back restores `orig` exactly. Times, confidences, styles,
// speakers and anchors are never touched, so word sync, the karaoke highlight and line overrides survive; lines are
// re-broken because the text length changed (carryLineOverrides keeps each line's own style).
// A word edited in Roman has no `orig` any more: the edit is the truth, and it is converted from its own script.
// English words (decoded as English, `w.lang === 'en'`, or plain Latin) stay as they are in every script.

import { toRoman, toDevanagari, sentenceCase, isLoan, DEVA_RE } from './translit.js';
import { devaToUrdu, urduToDeva, urduToRoman } from './urdu.js';
import { captionsCommand } from '../commands.js';
import { buildLines, carryLineOverrides } from '../model.js';

export const SCRIPTS = [
  { id: 'deva', label: 'हिंदी', title: 'Devanagari (हिंदी)' },
  { id: 'roman', label: 'Roman', title: 'Roman Hindi / Hinglish (Aap kaise ho?)' },
  { id: 'urdu', label: 'اردو', title: 'Urdu script (اردو)' },
];
const URDU_RE = /[؀-ۿ]/;
export const scriptOfText = (s) => (DEVA_RE.test(s) ? 'deva' : URDU_RE.test(s) ? 'urdu' : 'latin');
export const isHindustani = (cap) => !!cap && (cap.lang === 'hi' || cap.lang === 'ur' || cap.lang === 'hinglish');
/** the script the captions show now: cap.script, else what the words are written in */
export function currentScript(cap) {
  if (cap?.script) return cap.script;
  const n = { deva: 0, urdu: 0, latin: 0 };
  for (const w of (cap?.words || []).slice(0, 200)) if (!w.tag) n[scriptOfText(w.w)]++;
  return n.deva >= n.urdu && n.deva >= n.latin * 0.5 ? 'deva' : n.urdu > n.latin * 0.5 ? 'urdu' : 'roman';
}

/** one word's text in `to` ('deva' | 'roman' | 'urdu') from its native text */
export function convertText(text, to) {
  const from = scriptOfText(text);
  // Latin: a Roman Hindi word typed by hand goes back through the dictionary; anything else (English) stays Latin
  if (from === 'latin') {
    if (to === 'roman' || !/[A-Za-z]/.test(text)) return text;
    const d = toDevanagari(text, { rough: false });
    return d === text ? text : to === 'urdu' ? devaToUrdu(d) : d;
  }
  if (to === 'roman') return from === 'deva' ? toRoman(text) : urduToRoman(text).text;
  if (to === 'deva') return from === 'deva' ? text : urduToDeva(text).text;
  if (to === 'urdu') return from === 'urdu' ? text : devaToUrdu(text);
  return text;
}

/** The captions with every speech word in `to`. Pure (cap -> cap); canvas for the re-broken lines. */
export function withScript(cap, to, canvas) {
  if (!cap?.words?.length) return { ...cap, script: to };
  const words = cap.words.map((w, i) => {
    if (w.tag || w.lang === 'en') return w;
    const native = w.orig ?? w.w;
    let text = convertText(native, to);
    // Urdu میں is both मैं "main" (I) and में "mein" (in): "I" starts a sentence, "in" follows a word
    if (/^میں\p{P}*$/u.test(native) && to !== 'urdu') {
      const prev = cap.words[i - 1], start = !prev || /[.!?۔؟]\p{P}*$/u.test(prev.orig ?? prev.w);
      text = native.replace('میں', to === 'roman' ? (start ? 'main' : 'mein') : (start ? 'मैं' : 'में')).replace(/۔/g, to === 'roman' ? '.' : '।').replace(/؟/g, '?').replace(/،/g, ',');
    }
    const o = { ...w, w: text };
    if (text === native) delete o.orig; else o.orig = native;
    return o;
  });
  if (to === 'roman') {
    // sentence capitals on the Roman words only (orig keeps the native text, so this is undone on the way back)
    const cased = sentenceCase(words.map((w) => w.w));
    words.forEach((w, i) => { if (!w.tag && cased[i] !== w.w && w.orig) words[i] = { ...w, w: cased[i] }; });
  }
  // lines that are still the automatic ones are re-broken for the new text length; lines split / merged by hand stay
  const same = (a, b) => a.length === b.length && a.every((L, i) => L.wordIdx[0] === b[i].wordIdx[0] && L.wordIdx[1] === b[i].wordIdx[1]);
  const auto = !cap.lines?.length || same(cap.lines, buildLines(cap.words, cap.style, canvas));
  let lines = auto ? carryLineOverrides(cap.lines || [], buildLines(words, cap.style, canvas)) : cap.lines;
  // a line whose words did not change keeps its id (and with it a multi-line selection)
  if (auto) lines = lines.map((L) => { const O = cap.lines?.find((o) => o.wordIdx[0] === L.wordIdx[0] && o.wordIdx[1] === L.wordIdx[1]); return O ? { ...L, id: O.id } : L; });
  return { ...cap, words, lines, script: to };
}

/** Undoable: show the captions in another script. */
export const setScript = (to, canvas) => captionsCommand('script', 'Caption script', (cap, p) => withScript(cap, to, canvas || p.canvas));

/** The words in their native script (for translation and word sync): orig when kept, else the word converted back. */
export function nativeWords(words, lang) {
  return (words || []).map((w) => {
    if (w.tag || w.lang === 'en') return w;
    if (w.orig != null) return { ...w, w: w.orig };
    if (scriptOfText(w.w) === 'latin' && /[A-Za-z]/.test(w.w)) {
      const d = toDevanagari(w.w, { rough: false });
      if (d !== w.w) return { ...w, w: lang === 'ur' ? devaToUrdu(d) : d };
    }
    return w;
  });
}
/** captions -> the same captions in the native script (no command; used to translate from Roman captions) */
export const nativeCaptions = (cap) => (cap && cap.words?.some((w) => w.orig != null || /[A-Za-z]/.test(w.w)) && isHindustani(cap)
  ? { ...cap, words: nativeWords(cap.words, cap.lang) } : cap);

/** Share of speech words that are English: decoded as English, plain Latin, or an English loanword in Devanagari. */
export function englishShare(words) {
  const sp = (words || []).filter((w) => !w.tag && /[\p{L}]/u.test(w.w));
  if (!sp.length) return 0;
  return sp.filter((w) => w.lang === 'en' || scriptOfText(w.w) === 'latin' || isLoan(w.w, { fuzzy: true })).length / sp.length;
}

/**
 * After a transcription: what was heard (cap.detect, shown in the Captions panel) and, for Hindustani speech, the
 * script to show it in. r = captureWords' result; chosen = the language asked for ('auto' | 'en' | 'hi' | 'ur' |
 * 'hinglish' …); pref = project.meta.captions.script ('auto' or a SCRIPTS id): auto = Roman for Hinglish, else the
 * native script. Returns { detect, script (null unless Hindi / Urdu) }.
 */
export function captionLangInfo(r, chosen = 'auto', pref = 'auto') {
  const d = r.detect, hu = r.lang === 'hi' || r.lang === 'ur';
  const probs = d?.probs ? Object.fromEntries(Object.entries(d.probs).sort((a, b) => b[1] - a[1]).slice(0, 4).map(([k, v]) => [k, +v.toFixed(3)])) : null;
  const detect = { lang: r.lang, hinglish: !!r.hinglish, chosen: chosen || 'auto', conf: d?.conf ?? null, alt: hu ? (r.lang === 'hi' ? 'ur' : 'hi') : null, probs };
  const script = !hu ? null : SCRIPTS.some((s) => s.id === pref) ? pref : r.hinglish ? 'roman' : r.lang === 'ur' ? 'urdu' : 'deva';
  return { detect, script };
}
/**
 * captureWords' extra fields: { hinglish, detect }. Hinglish = the detector heard English beside Hindustani, or
 * ≥ 12 % of the words are English (decoded as English, or loanwords Whisper wrote in Devanagari: जॉब, वीज़ा, अप्लाई).
 */
export function heardLanguage(raw, words) {
  const hinglish = (raw.lang === 'hi' || raw.lang === 'ur') && (!!raw.hinglish || englishShare(words) >= 0.12);
  return { hinglish, detect: raw.detect || null };
}

/**
 * autoCaptions: the fields to put on a fresh caption set — detect (what was heard) and, for Hindi / Urdu, the words
 * in the project's script (meta.captions.script; auto = Roman for Hinglish, else as heard). Returns a patch.
 */
export function withHeardLanguage(cap, r, chosen, project) {
  // listening again ("It's Urdu", or Auto captions a second time) keeps the script on screen when the setting is Auto
  let pref = project?.meta?.captions?.script;
  const prev = project?.captions;
  if ((!pref || pref === 'auto') && prev?.script && isHindustani(prev) && prev.words?.length) pref = prev.script;
  const info = captionLangInfo(r, chosen, pref);
  if (!info.script) return { detect: info.detect };
  if (info.script === currentScript(cap)) return { detect: info.detect, script: info.script };
  const c = withScript(cap, info.script, project?.canvas);
  return { detect: info.detect, script: c.script, words: c.words, lines: c.lines };
}

const NAMES = { hi: 'Hindi', ur: 'Urdu', en: 'English', ar: 'Arabic', fr: 'French', de: 'German', es: 'Spanish', ru: 'Russian', tr: 'Turkish', fa: 'Persian', pa: 'Punjabi', bn: 'Bengali' };
/** "Hindi 96%" / "Hinglish (Hindi + English) 91%" / "Urdu" */
export const langLabel = (d) => (d.hinglish ? `Hinglish (${NAMES[d.lang] || d.lang} + English)` : NAMES[d.lang] || d.lang)
  + (d.conf != null && d.chosen === 'auto' ? ` ${Math.round(d.conf * 100)}%` : '');
