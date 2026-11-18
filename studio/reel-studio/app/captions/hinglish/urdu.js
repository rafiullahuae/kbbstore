// Hindustani across its two scripts, offline and deterministic (pure functions, no DOM):
//   devaToUrdu(word)   Devanagari -> Urdu script (rules + the Perso-Arabic spellings rules cannot know: ज़रूरत ضرورت)
//   urduToDeva(word)   Urdu -> Devanagari   } every word the Hinglish dictionary knows, read back through devaToUrdu;
//   urduToRoman(word)  Urdu -> Roman Urdu   } an unknown word falls back to letter rules (Urdu leaves short vowels
//                                             unwritten, so those are a best effort and say so: { guessed:true })
// Data: original work, CC0-1.0 (see LICENSE).

import { WORDS, LOANS } from './dict.js';
import { toRoman, keyOf, dictEntries } from './translit.js';

const CONS = {
  'क': 'ک', 'ख': 'کھ', 'ग': 'گ', 'घ': 'گھ', 'ङ': 'ن', 'च': 'چ', 'छ': 'چھ', 'ज': 'ج', 'झ': 'جھ', 'ञ': 'ن', 'ट': 'ٹ', 'ठ': 'ٹھ',
  'ड': 'ڈ', 'ढ': 'ڈھ', 'ण': 'ن', 'त': 'ت', 'थ': 'تھ', 'द': 'د', 'ध': 'دھ', 'न': 'ن', 'प': 'پ', 'फ': 'پھ', 'ब': 'ب', 'भ': 'بھ',
  'म': 'م', 'य': 'ی', 'र': 'ر', 'ल': 'ل', 'व': 'و', 'श': 'ش', 'ष': 'ش', 'स': 'س', 'ह': 'ہ', 'ळ': 'ل',
};
const NUKTA_C = { 'क': 'ق', 'ख': 'خ', 'ग': 'غ', 'ज': 'ز', 'ड': 'ڑ', 'ढ': 'ڑھ', 'फ': 'ف' };
// Perso-Arabic spellings (letters Devanagari merges: स/ص/ث, ज़/ض/ظ/ذ, त/ط, ह/ح, अ/ع) and words ending in ہ
const EXC = `ज़रूरत ضرورت | ज़रूर ضرور | ज़रूरी ضروری | तरह طرح | तरफ़ طرف | तरीका طریقہ | तरीक़ा طریقہ | तरीके طریقے | हाल حال | हक़ حق | सही صحیح
साफ़ صاف | सफ़ाई صفائی | सुबह صبح | शुरू شروع | ख़त्म ختم | इलाज علاج | अल्लाह اللہ | मतलब مطلب | मौका موقع | मौक़ा موقع | फ़ायदा فائدہ | फायदा فائدہ
फ़ायदे فائدے | शुक्रिया شکریہ | बिल्कुल بالکل | बिलकुल بالکل | ज़िंदगी زندگی | दुनिया دنیا | उम्मीद امید | असली اصلی | असल اصل | ख़ास خاص | आम عام
ज़्यादा زیادہ | ज्यादा زیادہ | ताकि تاکہ | कि کہ | इसलिए اس لیے | सवाल سوال | जवाब جواب | ख़ुशी خوشی | मोहब्बत محبت | इज़्ज़त عزت | नतीजा نتیجہ
तालीम تعلیم | साहब صاحب | सिर्फ़ صرف | सिर्फ صرف | हमेशा ہمیشہ | ख़बर خبر | ख़ुद خود | शायद شاید | वक़्त وقت | वक्त وقت | मुश्किल مشکل
कनाडा کینیڈا | ख़ुश خوش | खुश خوش | खुशी خوشی | मुलायम ملائم | आसान آسان | परेशान پریشان | औरत عورت | आदमी آدمی | इंसान انسان | ईद عید | रमज़ान رمضان | दुआ دعا | सलाम سلام | शहर شہر | मेहनत محنت
कोशिश کوشش | मदद مدد | उम्र عمر | ख़ूबसूरत خوبصورت | ज़बान زبان | हफ़्ता ہفتہ | ग़लत غلط | सेहत صحت | इलाक़ा علاقہ | पैसा پیسہ | रास्ता راستہ
यह یہ | वह وہ | ये یہ | वो وہ | है ہے | हैं ہیں | और اور | क्या کیا | क्यों کیوں | मैं میں | में میں | हाँ ہاں | हां ہاں | नहीं نہیں | ठीक ٹھیک
`.trim().split(/\s*[|\n]\s*/).map((p) => p.split(/\s+/));

const SIGN = { 'ा': 'ا', 'ि': '', 'ी': 'ی', 'ु': '', 'ू': 'و', 'ृ': 'ر', 'े': 'ی', 'ै': 'ی', 'ो': 'و', 'ौ': 'و', 'ॅ': 'ی', 'ॉ': 'ا' };
const INIT = { 'अ': 'ا', 'आ': 'آ', 'इ': 'ا', 'ई': 'ای', 'उ': 'ا', 'ऊ': 'او', 'ए': 'ای', 'ऐ': 'ای', 'ओ': 'او', 'औ': 'او', 'ऑ': 'ا', 'ऋ': 'ر' };
const AFTER_V = { 'इ': 'ئ', 'ई': 'ئی', 'ए': 'ئے', 'ऐ': 'ئے', 'उ': 'ؤ', 'ऊ': 'ؤ', 'ओ': 'ؤ', 'औ': 'ؤ', 'अ': 'ا', 'आ': 'آ' };
const isCons = (c) => (c >= 'क' && c <= 'ह') || c === 'ळ';

function rulesUrdu(word) {
  const s = word.normalize('NFC').normalize('NFD').replace(/[‌‍]/g, '');
  let out = '', lastVowel = false;
  for (let i = 0; i < s.length; i++) {
    const c = s[i], end = i === s.length - 1 || (i === s.length - 2 && /[ंँ]/.test(s[s.length - 1]));
    if (isCons(c)) {
      let r = CONS[c];
      if (s[i + 1] === '़') { r = NUKTA_C[c] || r; i++; }
      // aspirates after a consonant cluster stay with do-chashmi (کھ), ہ at a word's end stays ہ
      out += r; lastVowel = false;
    } else if (SIGN[c] !== undefined) {
      // े / ै at the very end are ے (ہے, کے); inside a word ی
      out += (c === 'े' || c === 'ै') && i === s.length - 1 ? 'ے' : SIGN[c]; lastVowel = true;   // ...but ें / ैं are یں (کریں, ہیں)
    } else if (INIT[c] !== undefined) {
      out += lastVowel && out ? (AFTER_V[c] ?? INIT[c]) : INIT[c];
      if ((c === 'ए' || c === 'ऐ') && end && !lastVowel) out = out.replace(/ای$/, 'اے');
      lastVowel = true;
    } else if (c === 'ं' || c === 'ँ') {
      const next = s[i + 1];
      out += next && isCons(next) ? 'ن' : 'ں';
    } else if (c === '्' || c === '़') { /* virama: Urdu writes no vowel anyway */ }
    else if (c === 'ः') out += 'ہ';
    else if (c >= '०' && c <= '९') out += '۰۱۲۳۴۵۶۷۸۹'['०१२३४५६७८९'.indexOf(c)];
    else if (c === '।') out += '۔';
    else out += c;
  }
  return out;
}

let EXC_MAP = null, U2 = null;
const excMap = () => (EXC_MAP ||= new Map(EXC.map(([d, u]) => [keyOf(d), u])));

/** Devanagari word -> Urdu script (punctuation kept, । -> ۔, ? -> ؟, , -> ،). Latin passes through. */
export function devaToUrdu(word) {
  const m = /^([^\p{L}\p{N}\p{M}]*)(.*?)([^\p{L}\p{N}\p{M}]*)$/u.exec(String(word || ''));
  const [, pre, core, post] = m;
  const p2 = post.replace(/[।.]/g, '۔').replace(/\?/g, '؟').replace(/,/g, '،');
  if (!/[ऀ-ॿ]/.test(core)) return pre + core + post;
  return pre + (excMap().get(keyOf(core)) ?? rulesUrdu(core)) + p2;
}

/** Fold Urdu spelling variants: diacritics off, Arabic ي/ك/ه -> Urdu ی/ک/ہ, no tatweel/ZWNJ. */
export const urduKey = (w) => String(w || '').normalize('NFC').replace(/[ً-ْٰٕٔـ‌‍]/g, '')
  .replace(/ي/g, 'ی').replace(/ى/g, 'ی').replace(/ك/g, 'ک').replace(/ه/g, 'ہ').replace(/ۂ/g, 'ہ').replace(/ۓ/g, 'ے').replace(/أ/g, 'ا');

/** Urdu key -> [devanagari, roman]: the exceptions first, then every dictionary word read through the rules. */
function u2() {
  if (U2) return U2;
  U2 = new Map();
  const put = (d, r) => { const k = urduKey(devaToUrdu(d)); if (k && !U2.has(k)) U2.set(k, [d, r]); };
  for (const [d] of EXC) put(d, toRoman(d));
  for (const [d, r] of WORDS) put(d, r.replace(/_/g, ' '));
  for (const [d, r] of LOANS) put(d, r.replace(/_/g, ' '));
  for (const [d, r] of dictEntries()) put(d, r);
  return U2;
}
export const urduDictSize = () => u2().size;

// letter rules for words the dictionary does not know
const U_ROMAN = { 'ب': 'b', 'پ': 'p', 'ت': 't', 'ٹ': 't', 'ث': 's', 'ج': 'j', 'چ': 'ch', 'ح': 'h', 'خ': 'kh', 'د': 'd', 'ڈ': 'd', 'ذ': 'z', 'ر': 'r', 'ڑ': 'r',
  'ز': 'z', 'ژ': 'zh', 'س': 's', 'ش': 'sh', 'ص': 's', 'ض': 'z', 'ط': 't', 'ظ': 'z', 'ع': '', 'غ': 'gh', 'ف': 'f', 'ق': 'q', 'ک': 'k', 'گ': 'g', 'ل': 'l',
  'م': 'm', 'ن': 'n', 'ں': 'n', 'ہ': 'h', 'ھ': 'h', 'ء': '', 'ئ': 'i' };
const U_DEVA = { 'ب': 'ब', 'پ': 'प', 'ت': 'त', 'ٹ': 'ट', 'ث': 'स', 'ج': 'ज', 'چ': 'च', 'ح': 'ह', 'خ': 'ख़', 'د': 'द', 'ڈ': 'ड', 'ذ': 'ज़', 'ر': 'र', 'ڑ': 'ड़',
  'ز': 'ज़', 'ژ': 'झ़', 'س': 'स', 'ش': 'श', 'ص': 'स', 'ض': 'ज़', 'ط': 'त', 'ظ': 'ज़', 'غ': 'ग़', 'ف': 'फ़', 'ق': 'क़', 'ک': 'क', 'گ': 'ग', 'ل': 'ल',
  'م': 'म', 'ن': 'न', 'ہ': 'ह', 'ی': 'य', 'و': 'व' };
const ASP = { 'ب': 'भ', 'پ': 'फ', 'ت': 'थ', 'ٹ': 'ठ', 'ج': 'झ', 'چ': 'छ', 'د': 'ध', 'ڈ': 'ढ', 'ک': 'ख', 'گ': 'घ', 'ڑ': 'ढ़' };
function rulesRomanU(w) {
  const s = [...urduKey(w)];
  let out = '';
  for (let i = 0; i < s.length; i++) {
    const c = s[i], first = i === 0, last = i === s.length - 1, prevC = i > 0 && !/[اویےآ]/.test(s[i - 1]);
    if (c === 'آ') out += 'aa';
    else if (c === 'ا') out += first ? 'a' : 'a';
    else if (c === 'و') out += first ? 'w' : prevC ? (last ? 'o' : 'o') : 'w';
    else if (c === 'ی') out += first ? 'y' : last ? 'i' : prevC ? 'ee' : 'y';
    else if (c === 'ے') out += 'e';
    else if (c === 'ھ') out += 'h';
    else {
      // two consonants in a row with no letter between: a short 'a' was not written
      if (prevC && i > 0 && !/[ںھ]/.test(c) && U_ROMAN[s[i - 1]] !== undefined && !last) out += 'a';
      else if (prevC && last && c !== 'ں' && c !== 'ہ' && U_ROMAN[s[i - 1]] !== undefined && s.length > 2) out += 'a';
      out += U_ROMAN[c] ?? c;
    }
  }
  return out.replace(/ee$/, 'i');
}
function rulesDevaU(w) {
  const s = [...urduKey(w)];
  let out = '';
  for (let i = 0; i < s.length; i++) {
    const c = s[i], first = i === 0, last = i === s.length - 1;
    if (s[i + 1] === 'ھ' && ASP[c]) { out += ASP[c]; i++; continue; }
    if (c === 'آ') out += 'आ';
    else if (c === 'ا') out += first ? 'अ' : 'ा';
    else if (c === 'و' && !first) out += 'ो';
    else if (c === 'ی' && !first) out += last ? 'ी' : 'ी';
    else if (c === 'ے') out += 'े';
    else if (c === 'ں') out += 'ं';
    else if (c === 'ع' || c === 'ء' || c === 'ئ') out += first ? 'अ' : '';
    else out += U_DEVA[c] ?? c;
  }
  return out;
}
const split = (word) => /^([^\p{L}\p{N}\p{M}]*)(.*?)([^\p{L}\p{N}\p{M}]*)$/u.exec(String(word || '')).slice(1);
const URDU_RE = /[؀-ۿ]/;

/** Urdu word -> { text, guessed } in Devanagari. */
export function urduToDeva(word) {
  const [pre, core, post] = split(word);
  if (!URDU_RE.test(core)) return { text: word, guessed: false };
  const hit = u2().get(urduKey(core));
  return { text: pre + (hit ? hit[0] : rulesDevaU(core)) + post.replace(/۔/g, '।').replace(/؟/g, '?').replace(/،/g, ','), guessed: !hit };
}
/** Urdu word -> { text, guessed } in Roman Urdu (the same casual spelling as Roman Hindi). */
export function urduToRoman(word) {
  const [pre, core, post] = split(word);
  if (!URDU_RE.test(core)) return { text: word, guessed: false };
  const hit = u2().get(urduKey(core));
  return { text: pre + (hit ? hit[1] : rulesRomanU(core)) + post.replace(/۔/g, '.').replace(/؟/g, '?').replace(/،/g, ','), guessed: !hit };
}
