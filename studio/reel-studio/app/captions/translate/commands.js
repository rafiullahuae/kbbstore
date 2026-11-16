// Translation commands. Every change is ONE undoable captions command (captions/commands.js captionsCommand), so
// ⌘Z takes a whole translation back. The original transcript is never lost: the first translation stores it in
// captions.tr.source and every later step reads from there.
//
// captions.tr = {
//   source: { lang, words, lines },                    the original captions as they were before any translation
//   langs:  { ar: { lang, engine, back, words, lines, sents:[{ text, out, idx, chunks, flags, score, bt, approved }] } },
//   mode:   'replace' | 'bilingual' | 'track',         how the last translation was applied
//   active: 'ar' | null                                 the language on screen in replace / bilingual mode
// }
// Modes: replace  -> captions.words / lines ARE the translation (captions.lang = target), source kept in tr.source;
//        bilingual -> captions stay the original, captions.alt = the translation, a bilingual style is chosen;
//        track    -> captions.tracks[lang] = the translation, drawn by its own caption item (item.capTrack = lang).

import { captionsCommand } from '../commands.js';
import { resolveStyle } from '../styles.js';
import { relayout, assess } from './pipeline.js';

const BILINGUAL_DEFAULT = 'bilingual';
const strip = ({ words, lines, lang }) => ({ lang, words, lines });

/** The original captions (the source of every translation). */
export const sourceOf = (cap) => cap.tr?.source || { lang: cap.lang, words: cap.words, lines: cap.lines };

/** Before switching away from replace mode, keep edits made in the transcript on the translated words. */
function syncReplaced(cap) {
  const tr = cap.tr;
  if (!tr || tr.mode !== 'replace' || !tr.active || !tr.langs?.[tr.active]) return tr;
  return { ...tr, langs: { ...tr.langs, [tr.active]: { ...tr.langs[tr.active], words: cap.words, lines: cap.lines } } };
}

/** Put a stored translation on screen in `mode` (cap -> cap). */
export function showTranslation(cap, lang, mode) {
  const tr0 = syncReplaced(cap) || {};
  const src = tr0.source || sourceOf(cap);
  const T = tr0.langs?.[lang];
  if (!T) return cap;
  const c = { ...cap, tr: { ...tr0, source: src, mode, active: mode === 'track' ? tr0.active ?? null : lang } };
  if (mode === 'replace') {
    Object.assign(c, { words: T.words, lines: T.lines, lang });
    if (c.alt?.fromTr) delete c.alt;
  } else {
    Object.assign(c, { words: src.words, lines: src.lines, lang: src.lang });
    if (mode === 'bilingual') {
      c.alt = { lang, words: T.words, lines: T.lines, fromTr: true };
      if (!resolveStyle(cap.style).bilingual) c.style = { id: BILINGUAL_DEFAULT };
    } else {
      c.tracks = { ...(cap.tracks || {}), [lang]: { lang, words: T.words, lines: T.lines, style: trackStyle(cap.style, lang) } };
    }
  }
  return c;
}

/** The second track's style: the main style moved to the top of the safe area, single row, no emoji. */
function trackStyle(spec, lang) {
  const st = resolveStyle(spec);
  return { id: st.id, pos: { x: st.pos?.x ?? 0.5, y: 0.08 }, maxRows: 2, emoji: false, size: Math.round(st.size * 0.82), anim: st.anim === 'one-word' ? 'highlight-color' : st.anim };
}

/** Store a fresh translation result (pipeline.translateTrack) and show it in `mode`. */
export const applyTranslation = (result, mode, label) => captionsCommand('translate', label || `Translate captions → ${result.lang.toUpperCase()}`, (cap) => {
  const tr0 = syncReplaced(cap) || {};
  const source = tr0.source || sourceOf(cap);
  const T = { lang: result.lang, src: result.src, engine: result.engine, alt: result.alt || null, back: result.back, words: result.words, lines: result.lines, sents: result.sents, at: Date.now() };
  return showTranslation({ ...cap, tr: { ...tr0, source, langs: { ...(tr0.langs || {}), [result.lang]: T } } }, result.lang, mode);
});

/** Show a translation already made (switch language / mode without translating again). */
export const showLang = (lang, mode) => captionsCommand('translateShow', `Show ${lang.toUpperCase()} captions`, (cap) => showTranslation(cap, lang, mode));

/** Back to the original captions: main = source, the bilingual line gone; translations stay stored (re-show any time). */
export const restoreOriginal = () => captionsCommand('translateRestore', 'Restore original captions', (cap) => {
  if (!cap.tr?.source) return cap;
  const tr = syncReplaced(cap);
  const src = tr.source;
  const c = { ...cap, words: src.words, lines: src.lines, lang: src.lang, tr: { ...tr, active: null, mode: tr.mode === 'track' ? 'track' : null } };
  if (c.alt?.fromTr) delete c.alt;
  return c;
});

/** Remove one translated second track (its caption item is removed by the panel in the same batch). */
export const dropTrack = (lang) => captionsCommand('translateDropTrack', `Remove ${lang.toUpperCase()} track`, (cap) => {
  if (!cap.tracks?.[lang]) return cap;
  const { [lang]: _x, ...rest } = cap.tracks;
  return { ...cap, tracks: rest };
});

/**
 * Edit (or re-translate) one sentence of language `lang`: its text becomes `out`, the language is laid out again on
 * the original lines and timing, re-checked, and whatever shows it on screen (replace / bilingual / track) follows.
 * opts: { approved, back, glossary, flags } — flags/back come from a re-translation.
 */
export const editSentence = (lang, i, out, opts = {}) => captionsCommand('translateEdit', opts.label || 'Edit translation', (cap) => {
  const tr0 = syncReplaced(cap);
  const T = tr0?.langs?.[lang]; if (!T?.sents?.[i]) return cap;
  const sents = T.sents.slice();
  const prev = sents[i];
  const q = assess(prev.text, out, lang, opts.glossary || [], opts.back ?? null, T.src);
  sents[i] = { ...prev, out: String(out).replace(/\s+/g, ' ').trim(), ...q, back: opts.back ?? null, edited: !opts.retranslated, approved: !!opts.approved };
  const lay = relayout(tr0.source, sents);
  const nT = { ...T, sents, ...lay };
  return applyStored({ ...cap, tr: { ...tr0, langs: { ...tr0.langs, [lang]: nT } } }, lang);
});

/** Approve one sentence (i) or all (i = null) of language `lang`: approved lines lose their review highlight. */
export const approve = (lang, i = null, value = true) => captionsCommand('translateApprove', i == null ? 'Approve all lines' : 'Approve line', (cap) => {
  const T = cap.tr?.langs?.[lang]; if (!T) return cap;
  const sents = T.sents.map((s, k) => (i == null || k === i ? { ...s, approved: value } : s));
  return { ...cap, tr: { ...cap.tr, langs: { ...cap.tr.langs, [lang]: { ...T, sents } } } };
});

/** After a stored language changed, refresh wherever it is on screen. */
function applyStored(cap, lang) {
  const tr = cap.tr, T = tr.langs[lang];
  const c = { ...cap };
  if (tr.mode === 'replace' && tr.active === lang) Object.assign(c, { words: T.words, lines: T.lines });
  if (c.alt?.fromTr && c.alt.lang === lang) c.alt = { ...c.alt, words: T.words, lines: T.lines };
  if (c.tracks?.[lang]) c.tracks = { ...c.tracks, [lang]: { ...c.tracks[lang], words: T.words, lines: T.lines } };
  return c;
}

/** The caption set of one language for exports: 'src' (the original) or a language code. */
export function captionsIn(cap, lang) {
  if (!lang || lang === 'src') { const s = sourceOf(cap); return { ...strip(s), soundTags: cap.soundTags, style: cap.style }; }
  const T = cap.tr?.langs?.[lang];
  if (!T) return null;
  // in replace mode the transcript may hold later hand edits: those win
  if (cap.tr.mode === 'replace' && cap.tr.active === lang) return { lang, words: cap.words, lines: cap.lines, soundTags: cap.soundTags, style: cap.style };
  return { lang, words: T.words, lines: T.lines, soundTags: cap.soundTags, style: cap.style };
}
