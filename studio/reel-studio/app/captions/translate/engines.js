// The translation engines the studio can run offline, and how each one is asked for a language pair.
// Every engine is a model in models/manifest.json (group "Translation"), downloaded only after he allows it.
// Licences: OPUS-MT (Helsinki-NLP) CC-BY-4.0, M2M-100 MIT, MADLAD-400 Apache-2.0, Qwen2.5 Apache-2.0 — all allow
// commercial use; NLLB (CC-BY-NC) is deliberately not here.

import { LANG, langName } from './langs.js';

const opus = (pair, size) => ({ id: 'mt-' + pair, kind: 'marian', repo: 'Xenova/opus-mt-' + pair, dtype: 'q8', size, licence: 'CC-BY-4.0',
  label: `OPUS-MT ${pair}`, src: pair.split('-')[0], tgt: pair.split('-').slice(1).join('-') });

export const ENGINES = {
  'mt-en-ar': opus('en-ar'), 'mt-ar-en': opus('ar-en'), 'mt-en-hi': opus('en-hi'), 'mt-hi-en': opus('hi-en'),
  'mt-en-mul': opus('en-mul'), 'mt-mul-en': opus('mul-en'),
  'mt-en-fr': opus('en-fr'), 'mt-fr-en': opus('fr-en'), 'mt-en-de': opus('en-de'), 'mt-de-en': opus('de-en'),
  'mt-en-es': opus('en-es'), 'mt-es-en': opus('es-en'), 'mt-en-it': opus('en-it'), 'mt-it-en': opus('it-en'),
  'mt-en-ru': opus('en-ru'), 'mt-ru-en': opus('ru-en'), 'mt-en-zh': opus('en-zh'), 'mt-zh-en': opus('zh-en'), 'mt-tr-en': opus('tr-en'),
  'mt-m2m100': { id: 'mt-m2m100', kind: 'm2m', repo: 'Xenova/m2m100_418M', dtype: 'q8', licence: 'MIT', label: 'M2M-100 418M (100 languages)' },
  'mt-madlad': { id: 'mt-madlad', kind: 'madlad', repo: 'Kutalia/madlad400-3b-mt-onnx', dtype: 'q8', licence: 'Apache-2.0', label: 'MADLAD-400 3B' },
  'mt-qwen05': { id: 'mt-qwen05', kind: 'llm', repo: 'onnx-community/Qwen2.5-0.5B-Instruct', dtype: 'q8', licence: 'Apache-2.0', label: 'Qwen2.5 0.5B Instruct (local LLM)' },
  'mt-qwen': { id: 'mt-qwen', kind: 'llm', repo: 'onnx-community/Qwen2.5-1.5B-Instruct', dtype: 'q8', licence: 'Apache-2.0', label: 'Qwen2.5 1.5B Instruct (local LLM)' },
};

/** Can this engine do src -> tgt at all? */
export function engineCovers(eng, src, tgt) {
  if (src === tgt) return false;
  if (eng.kind === 'marian') {
    if (eng.tgt === 'mul') return src === 'en' && !!LANG[tgt]?.mul;
    if (eng.src === 'mul') return tgt === 'en' && !!LANG[src];
    return eng.src === src && eng.tgt === tgt;
  }
  return !!LANG[src] && !!LANG[tgt];
}

/** The worker request for one pair: { src, tgt, langTag } (langTag goes in front of each sentence). */
export function requestFor(eng, src, tgt) {
  if (eng.kind === 'marian' && eng.tgt === 'mul') return { langTag: `>>${LANG[tgt].mul}<< ` };
  if (eng.kind === 'm2m') return { src: LANG[src].m2m, tgt: LANG[tgt].m2m };
  if (eng.kind === 'madlad') return { langTag: `<2${LANG[tgt].madlad}> ` };
  return {};
}

// ---------------------------------------------------------------- the local LLM as a translator
// Few-shot pairs (NOT from the test set) so a 1.5B model answers with the translation only, in the right register.
const SHOTS = {
  en: ['New arrivals just landed, shop them today.', 'Our team will call you within 24 hours.'],
  ar: ['وصلت المنتجات الجديدة للتو، تسوّقها اليوم.', 'سيتصل بك فريقنا خلال 24 ساعة.'],
  ur: ['نئی مصنوعات ابھی آئی ہیں، آج ہی خریدیں۔', 'ہماری ٹیم 24 گھنٹوں کے اندر آپ کو کال کرے گی۔'],
  hi: ['नए प्रोडक्ट अभी आए हैं, आज ही खरीदें।', 'हमारी टीम 24 घंटों के अंदर आपको कॉल करेगी।'],
};

/** Chat messages asking the instruct model for one translation. keep = terms to copy exactly. */
export function llmMessages(text, src, tgt, keep = []) {
  const S = langName(src), T = tgt === 'ar' ? 'Modern Standard Arabic' : langName(tgt);
  const rules = [
    `Translate the ${S} video caption into ${T}.`,
    'Reply with the translation only: no quotes, no notes, no transliteration, no explanation.',
    'Keep every number, price, percentage, code, URL and emoji exactly as written.',
    keep.length ? `Copy these names exactly, in Latin letters: ${keep.join(', ')}.` : '',
    'Keep the meaning, tone and length of an advert; do not add or drop information.',
  ].filter(Boolean).join(' ');
  const msgs = [{ role: 'system', content: 'You are a professional advertising translator.' }];
  const ex = SHOTS[src] && SHOTS[tgt] ? [0, 1].map((i) => [SHOTS[src][i], SHOTS[tgt][i]]) : [];
  for (const [a, b] of ex) msgs.push({ role: 'user', content: `${rules}\n\n${a}` }, { role: 'assistant', content: b });
  msgs.push({ role: 'user', content: `${rules}\n\n${text}` });
  return msgs;
}

/** Clean an LLM reply down to the translation (first non-empty line, no wrapping quotes / labels). */
export function cleanLlm(reply) {
  let s = String(reply || '').replace(/<\|[^|]*\|>/g, '').trim();
  s = s.split(/\n+/).map((x) => x.trim()).find(Boolean) || '';
  s = s.replace(/^(translation|ترجمة|ترجمہ|अनुवाद)\s*[:：]\s*/i, '').replace(/^["“”«»']+|["“”«»']+$/g, '').trim();
  return s;
}
