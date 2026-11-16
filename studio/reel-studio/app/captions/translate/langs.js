// Languages the caption translator offers, with their script, direction, caption font and the codes each engine
// uses. Pure data + tiny helpers, no DOM.

export const LANGS = [
  { id: 'en', name: 'English', native: 'English', script: 'Latn', m2m: 'en', mul: 'eng', madlad: 'en' },
  { id: 'ar', name: 'Arabic', native: 'العربية', script: 'Arab', rtl: true, font: 'Noto Naskh Arabic', m2m: 'ar', mul: 'ara', madlad: 'ar' },
  { id: 'ur', name: 'Urdu', native: 'اردو', script: 'Arab', rtl: true, font: 'Noto Nastaliq Urdu', m2m: 'ur', mul: 'urd', madlad: 'ur' },
  { id: 'hi', name: 'Hindi', native: 'हिन्दी', script: 'Deva', font: 'Noto Sans Devanagari', m2m: 'hi', mul: 'hin', madlad: 'hi' },
  { id: 'fr', name: 'French', native: 'Français', script: 'Latn', m2m: 'fr', mul: 'fra', madlad: 'fr' },
  { id: 'de', name: 'German', native: 'Deutsch', script: 'Latn', m2m: 'de', mul: 'deu', madlad: 'de' },
  { id: 'es', name: 'Spanish', native: 'Español', script: 'Latn', m2m: 'es', mul: 'spa', madlad: 'es' },
  { id: 'it', name: 'Italian', native: 'Italiano', script: 'Latn', m2m: 'it', mul: 'ita', madlad: 'it' },
  { id: 'pt', name: 'Portuguese', native: 'Português', script: 'Latn', m2m: 'pt', mul: 'por', madlad: 'pt' },
  { id: 'ru', name: 'Russian', native: 'Русский', script: 'Cyrl', m2m: 'ru', mul: 'rus', madlad: 'ru' },
  { id: 'tr', name: 'Turkish', native: 'Türkçe', script: 'Latn', m2m: 'tr', mul: 'tur', madlad: 'tr' },
  { id: 'zh', name: 'Chinese', native: '中文', script: 'Hans', m2m: 'zh', mul: 'cmn_Hans', madlad: 'zh' },
  { id: 'tl', name: 'Tagalog', native: 'Tagalog', script: 'Latn', m2m: 'tl', mul: 'tgl', madlad: 'fil' },
  { id: 'bn', name: 'Bengali', native: 'বাংলা', script: 'Beng', m2m: 'bn', mul: 'ben', madlad: 'bn' },
  { id: 'ml', name: 'Malayalam', native: 'മലയാളം', script: 'Mlym', m2m: 'ml', mul: 'mal', madlad: 'ml' },
  { id: 'ta', name: 'Tamil', native: 'தமிழ்', script: 'Taml', m2m: 'ta', mul: 'tam', madlad: 'ta' },
  { id: 'fa', name: 'Persian', native: 'فارسی', script: 'Arab', rtl: true, font: 'Noto Naskh Arabic', m2m: 'fa', mul: 'pes', madlad: 'fa' },
];
export const LANG = Object.fromEntries(LANGS.map((l) => [l.id, l]));
export const langName = (id) => LANG[id]?.name || id;
export const isRtlLang = (id) => !!LANG[id]?.rtl;

// Script detection for "source auto": the transcript's language when Whisper reported one, else the dominant script.
const SCRIPTS = [
  ['ur', /[ٹڈڑںےۓھ]/g], ['ar', /[؀-ۿ]/g], ['hi', /[ऀ-ॿ]/g], ['bn', /[ঀ-৿]/g],
  ['ml', /[ഀ-ൿ]/g], ['ta', /[஀-௿]/g], ['ru', /[Ѐ-ӿ]/g], ['zh', /[一-鿿]/g],
];
/** Guess the language of a text from its script (Latin -> 'en'). Urdu wins over Arabic on its own letters. */
export function guessLang(text) {
  const s = String(text || '');
  const latin = (s.match(/[A-Za-z]/g) || []).length;
  let best = 'en', n = latin;
  for (const [id, re] of SCRIPTS) {
    const k = (s.match(re) || []).length * (id === 'ur' ? 12 : 1);   // a few Urdu-only letters decide Arabic vs Urdu
    if (k > n) { best = id; n = k; }
  }
  if (best === 'ar' && /[پچژگک]/.test(s) && /[ی]/.test(s) && !/[ةى]/.test(s)) best = 'fa';
  return best;
}

/** The caption track's language: what Whisper said ('en', 'ar' …) when it is one we know, else guessed from text. */
export function sourceLangOf(cap) {
  const l = (cap?.detectedLang || cap?.lang || '').slice(0, 2);
  if (l && l !== 'au' && LANG[l]) return l;
  return guessLang((cap?.words || []).slice(0, 200).map((w) => w.w).join(' '));
}
