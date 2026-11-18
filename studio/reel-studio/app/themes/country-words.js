// KBB Studio · themes · COUNTRY WORDS: which country or region a caption is talking about, in English, Arabic, Urdu
// and Hindi — for the corner globe badges' "Badge every country I mention" (the owner, 18 Nov: "europe globe type
// circled themes to fit on the videos corner while speaking related to europe or any country").
//
//   REGIONS                      Europe, the Gulf, Asia, North America, Oceania (names in four languages, a centre on
//                                the globe, the ring colours, the member countries)
//   centreOf(code)               [lon, lat] a globe turns to for a country or a region
//   placeName(code, lang)        its name ('en' | 'ar' | 'ur' | 'hi')
//   findMentions(words, opts)    caption words [{t0, t1, w}] -> [{code, t0, t1, word, i}] (longest phrase first)
//   mentionSpans(mentions, opts) -> [{code, t0, t1, words:[...]}]: N seconds from each mention, nearby mentions of the
//                                same place merged, a new place cutting the previous one short (one badge at a time)
//
// The words: every country with a flag (themes/capsules/countries.js) by its English name and the browser's own CLDR
// names in Arabic, Urdu and Hindi (Intl.DisplayNames: offline, no list to keep), the 33 country packs' own names and
// cities (auto/countries), a few demonyms, and the regions' words. Arabic and Urdu are compared after folding hamza
// forms, taa marbuta, alef maqsura, the Urdu letters and the diacritics, and with و / ب / ل / ف / ال prefixes removed.
import { COUNTRIES as FLAGS } from './capsules/countries.js';
import { COUNTRIES as PACKS } from '../auto/countries/countries.js';

export const REGIONS = {
  europe: { en: 'Europe', ar: 'أوروبا', ur: 'یورپ', hi: 'यूरोप', c: [12, 50], ring: ['#003399', '#FFCC00'], stars: true, members: ['at', 'be', 'bg', 'hr', 'cy', 'cz', 'dk', 'ee', 'fi', 'fr', 'de', 'gr', 'hu', 'ie', 'it', 'lv', 'lt', 'lu', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'si', 'es', 'se'],
    words: ['europe', 'european', 'eu', 'schengen', 'أوروبا', 'اوروبا', 'أوربا', 'الأوروبي', 'الاوروبي', 'شنغن', 'یورپ', 'یورپی', 'यूरोप', 'यूरोपीय', 'शेंगेन'] },
  gulf: { en: 'The Gulf', ar: 'الخليج', ur: 'خلیج', hi: 'खाड़ी', c: [51, 25], ring: ['#00732F', '#FFFFFF', '#CE1126'], members: ['ae', 'sa', 'qa', 'kw', 'bh', 'om'],
    words: ['gulf', 'gcc', 'الخليج', 'خليجي', 'خلیج', 'खाड़ी'] },
  asia: { en: 'Asia', ar: 'آسيا', ur: 'ایشیا', hi: 'एशिया', c: [95, 30], ring: ['#DE2910', '#FFDE00'], members: ['cn', 'jp', 'kr', 'in', 'sg', 'my', 'th', 'id', 'ph', 'vn'],
    words: ['asia', 'asian', 'آسيا', 'اسيا', 'ایشیا', 'एशिया'] },
  'north-america': { en: 'North America', ar: 'أمريكا الشمالية', ur: 'شمالی امریکہ', hi: 'उत्तर अमेरिका', c: [-100, 45], ring: ['#B31942', '#FFFFFF', '#0A3161'], members: ['us', 'ca', 'mx'],
    words: ['north america', 'أمريكا الشمالية', 'امريكا الشماليه', 'شمالی امریکہ', 'उत्तर अमेरिका'] },
  oceania: { en: 'Oceania', ar: 'أوقيانوسيا', ur: 'اوشیانا', hi: 'ओशिनिया', c: [150, -25], ring: ['#00247D', '#FFFFFF', '#CC142B'], members: ['au', 'nz', 'fj', 'pg'],
    words: ['oceania', 'أوقيانوسيا', 'اوقيانوسيا', 'اوشیانا', 'ओशिनिया'] },
};
export const REGION_IDS = Object.keys(REGIONS);
export const isRegion = (code) => !!REGIONS[code];

// approximate centres [lon, lat] for the globe to turn to (public geography, rounded); others use their continent's
const CENTRE = {
  al: [20, 41], ad: [1.5, 42.5], at: [14.5, 47.5], by: [28, 53.5], be: [4.5, 50.6], ba: [17.8, 44], bg: [25.3, 42.7], hr: [16, 45.2], cy: [33, 35], cz: [15.3, 49.8], dk: [10, 56], ee: [25.5, 58.7],
  fi: [26, 64], fr: [2.5, 46.5], de: [10.4, 51], gr: [22, 39], hu: [19.5, 47.2], is: [-18, 65], ie: [-8, 53.2], it: [12.5, 42.8], xk: [21, 42.6], lv: [24.9, 56.9], li: [9.55, 47.15], lt: [23.9, 55.3],
  lu: [6.1, 49.8], mt: [14.4, 35.9], md: [28.5, 47], mc: [7.4, 43.7], me: [19.3, 42.7], nl: [5.3, 52.2], mk: [21.7, 41.6], no: [9, 61], pl: [19.4, 52], pt: [-8, 39.6], ro: [25, 45.9], ru: [40, 56],
  sm: [12.45, 43.94], rs: [20.8, 44], sk: [19.5, 48.7], si: [14.8, 46.1], es: [-3.7, 40.2], se: [15, 62], ch: [8.2, 46.8], ua: [31.2, 49], gb: [-2, 54], va: [12.45, 41.9],
  ae: [54, 24], sa: [45, 24], qa: [51.2, 25.3], kw: [47.6, 29.3], bh: [50.55, 26], om: [57, 21], ye: [48, 15.5], iq: [44, 33], ir: [53.7, 32.4], jo: [36.5, 31.2], lb: [35.9, 33.9], sy: [38.5, 35],
  il: [35, 31.4], ps: [35.2, 31.9], tr: [35, 39], eg: [30, 26.7], af: [66, 34], pk: [69.4, 30], in: [79, 22], bd: [90.3, 23.7], lk: [80.7, 7.8], np: [84, 28.3], cn: [104, 35], jp: [138, 36.5],
  kr: [127.8, 36.3], kp: [127.2, 40.1], sg: [103.8, 1.35], my: [102, 4], id: [117, -2.5], ph: [122, 12], th: [101, 15], vn: [106, 16], kh: [105, 12.6], la: [102.5, 19], mm: [96, 21], tw: [121, 23.7],
  hk: [114.2, 22.3], mn: [103.8, 46.9], kz: [67, 48], uz: [64.6, 41.4], az: [47.6, 40.3], ge: [43.4, 42.3], am: [45, 40.1], kg: [74.8, 41.2], tj: [71.3, 38.9], tm: [59.6, 38.9], mv: [73.2, 3.2],
  bt: [90.4, 27.5], bn: [114.7, 4.5], us: [-98, 39], ca: [-100, 57], mx: [-102, 23.6], br: [-52, -10], ar: [-64, -34], cl: [-71, -35.7], co: [-73, 4], pe: [-75, -9.2], ve: [-66, 7], cu: [-79.5, 21.5],
  jm: [-77.3, 18.1], do: [-70.2, 18.7], pa: [-80, 8.5], cr: [-84, 9.9], ec: [-78.5, -1.8], bo: [-64.7, -16.3], py: [-58.4, -23.4], uy: [-56, -32.5], gt: [-90.2, 15.7], hn: [-86.2, 15.2],
  dz: [3, 28], ma: [-7, 31.8], tn: [9.5, 34], ly: [17, 27], sd: [30, 15.5], et: [40.5, 9], ke: [38, 0.2], ug: [32.3, 1.4], tz: [35, -6.4], rw: [29.9, -2], ng: [8.7, 9.1], gh: [-1, 7.9],
  ci: [-5.5, 7.5], sn: [-14.5, 14.5], cm: [12.4, 5.7], za: [24, -29], zw: [29.2, -19], zm: [27.8, -13.1], mz: [35.5, -18.7], ao: [17.9, -11.2], cd: [23.6, -2.9], mg: [46.9, -18.8], mu: [57.6, -20.3],
  so: [46.2, 5.2], au: [134, -25], nz: [172, -41], fj: [178, -17.7], pg: [145, -6.3],
};
const GROUP_CENTRE = { Europe: [12, 50], Asia: [95, 30], 'Middle East': [45, 27], 'North America': [-100, 40], 'South America': [-60, -15], Africa: [20, 5], Oceania: [150, -25], Antarctica: [0, -80] };
const flagRow = new Map(FLAGS.map((r) => [r[0], r]));
const packOf = new Map(PACKS.map((c) => [c.code, c]));
export function centreOf(code) {
  if (REGIONS[code]) return REGIONS[code].c;
  return CENTRE[code] || GROUP_CENTRE[flagRow.get(code)?.[2]] || [20, 30];
}
export const knownPlace = (code) => !!(REGIONS[code] || flagRow.has(code));

const DN = {};
const dn = (lang) => { try { return (DN[lang] ||= new Intl.DisplayNames([lang], { type: 'region' })); } catch { return null; } };
/** a country's or region's name in 'en' | 'ar' | 'ur' | 'hi' */
export function placeName(code, lang = 'en') {
  const R = REGIONS[code]; if (R) return R[lang] || R.en;
  if (lang === 'en') return packOf.get(code)?.name || flagRow.get(code)?.[1] || code.toUpperCase();
  if (lang === 'ar' && packOf.get(code)?.nameAr) return packOf.get(code).nameAr;
  if (/-/.test(code)) return flagRow.get(code)?.[1] || code;
  const n = dn(lang)?.of(code.toUpperCase());
  return n && n.toUpperCase() !== code.toUpperCase() ? n : placeName(code, 'en');
}

// ── normalising ──
const AR_FOLD = [[/[ً-ٰٟـ]/g, ''], [/[أإآٱ]/g, 'ا'], [/ى/g, 'ي'], [/ة/g, 'ه'], [/[یے]/g, 'ي'], [/ک/g, 'ك'], [/[ہۂۃ]/g, 'ه'], [/ۓ/g, 'ي']];
export function norm(s) {
  let t = String(s || '').toLowerCase().normalize('NFKC');
  if (/[؀-ۿ]/.test(t)) for (const [re, r] of AR_FOLD) t = t.replace(re, r);
  else t = t.normalize('NFKD').replace(/[̀-ͯ]/g, '');
  return t.replace(/['’]s\b/g, '').replace(/[^\p{L}\p{N}\sऀ-ॿ]/gu, ' ').replace(/\s+/g, ' ').trim();
}
/** an Arabic / Urdu word without its joined prefixes (و ف ب ل ك, ال, لل): the forms to look up */
function arForms(w) {
  const out = [w]; if (!/[؀-ۿ]/.test(w)) return out;
  let x = w;
  if (/^[وفبلك]/.test(x) && x.length > 3) { x = x.slice(1); out.push(x); }
  if (/^لل/.test(w) && w.length > 4) out.push('ال' + w.slice(2), w.slice(2));
  for (const y of [...out]) if (/^ال/.test(y) && y.length > 4) out.push(y.slice(2));
  return [...new Set(out)];
}

const DEMONYM = { de: ['german', 'germans'], fr: ['french'], it: ['italian'], es: ['spanish'], pt: ['portuguese'], pl: ['polish'], nl: ['dutch'], ca: ['canadian', 'canadians'], au: ['australian', 'aussie'], gb: ['british', 'uk'], ie: ['irish'], nz: ['kiwi'], us: ['usa', 'american'], mt: ['maltese'], ch: ['swiss'], se: ['swedish'], fi: ['finnish'], dk: ['danish'], at: ['austrian'], be: ['belgian'], gr: ['greek'], tr: ['turkish'], jp: ['japanese'], kr: ['korean'], cn: ['chinese'], in: ['indian'], pk: ['pakistani'], ae: ['uae', 'emirates', 'emirati', 'الامارات', 'اماراتي', 'امارات', 'यूएई'], sa: ['saudi', 'ksa', 'السعوديه'], cz: ['czechia', 'czech'], hu: ['hungarian'], lt: ['lithuanian'], lv: ['latvian'], ee: ['estonian'], cy: ['cypriot'], ro: ['romanian'], hr: ['croatian'], sg: ['singaporean'], my: ['malaysian'] };
const SKIP = new Set(['states', 'new', 'united', 'south', 'north', 'republic', 'island', 'islands', 'the', 'of', 'and', 'saint', 'guinea', 'city', 'chad', 'jordan', 'georgia', 'man',
  // the packs' theme-suggestion words that are not places
  'visa', 'golden', 'abu', 'dhabi', 'arab', 'kuala', 'lumpur', 'campus', 'blocked', 'd7', 'mext', 'gks', 'topik', 'nus', 'ntu', 'stipendium', 'hungaricum', 'الذهبيه']);

let LEX = null, MAXN = 1;
/** phrase (normalised) -> place code */
export function lexicon() {
  if (LEX) return LEX;
  LEX = new Map();
  const add = (phrase, code, force) => {
    const p = norm(phrase); if (!p || (!force && SKIP.has(p)) || (p.length < 3 && !force) || LEX.has(p)) return;
    LEX.set(p, code); MAXN = Math.max(MAXN, p.split(' ').length);
  };
  for (const [id, R] of Object.entries(REGIONS)) for (const w of [...R.words, R.en, R.ar, R.ur, R.hi]) add(w, id, true);
  for (const c of PACKS) {
    add(c.name, c.code, true); add(c.nameAr, c.code, true);
    const parts = new Set(c.name.toLowerCase().split(' ').length > 1 ? c.name.toLowerCase().split(' ') : []);
    for (const k of c.keywords?.en || []) if (!parts.has(k)) add(k, c.code);
    for (const k of c.keywords?.ar || []) add(k, c.code);
  }
  for (const [code, words] of Object.entries(DEMONYM)) for (const w of words) add(w, code, w === 'uk');
  for (const [w, code] of [['abu dhabi', 'ae'], ['kuala lumpur', 'my'], ['new york', 'us'], ['hong kong', 'hk'], ['saudi arabia', 'sa']]) add(w, code, true);
  for (const [code, en] of FLAGS) {
    if (/-/.test(code)) continue;
    add(en, code, true);
    for (const lang of ['en', 'ar', 'ur', 'hi']) { const n = dn(lang)?.of(code.toUpperCase()); if (n && n.toUpperCase() !== code.toUpperCase()) add(n, code, true); }
  }
  // the single-word names that are also ordinary English words or first names never match alone (Chad, Jordan ...)
  for (const w of ['chad', 'jordan', 'georgia', 'guinea']) LEX.delete(w);
  return LEX;
}

/**
 * The places named in caption words: [{code, t0, t1, word, i}] in time order. At each word the longest phrase wins
 * ("North America" before "America", "New Zealand" before anything shorter).
 */
export function findMentions(words, { only } = {}) {
  const L = lexicon(), out = [], W = (words || []).filter((w) => w && w.w != null);
  const N = W.map((w) => norm(w.w));
  for (let i = 0; i < W.length; i++) {
    if (!N[i]) continue;
    let hit = null;
    for (let n = Math.min(MAXN, W.length - i); n >= 1 && !hit; n--) {
      const parts = N.slice(i, i + n); if (parts.some((x) => !x)) continue;
      const joined = parts.join(' ');
      const forms = n === 1 ? N[i].split(' ').length > 1 ? [N[i], ...N[i].split(' ')] : arForms(N[i]) : [joined, ...arForms(parts[0]).slice(1).map((f) => [f, ...parts.slice(1)].join(' '))];
      for (const f of forms) { const code = L.get(f); if (code && (!only || only.includes(code))) { hit = { code, n }; break; } }
    }
    if (hit) { out.push({ code: hit.code, t0: +W[i].t0 || 0, t1: +(W[i + hit.n - 1].t1 ?? W[i + hit.n - 1].t0) || 0, word: W.slice(i, i + hit.n).map((w) => w.w).join(' '), i }); i += hit.n - 1; }
  }
  return out;
}

/**
 * Mentions -> badge spans: each from its word's time for `dur` s; a mention of the same place within `gap` s of the
 * running span extends it (merged), a different place starts a new span and ends the one before (never shorter than
 * `min` s, else the earlier one wins and the new one waits). Clamped to [0, end].
 */
export function mentionSpans(mentions, { dur = 3, gap = 2, min = 1.2, end = Infinity, lead = 0.15 } = {}) {
  const spans = [];
  for (const m of mentions) {
    const t0 = Math.max(0, m.t0 - lead), last = spans[spans.length - 1];
    if (last && last.code === m.code && t0 <= last.t1 + gap) { last.t1 = Math.max(last.t1, Math.min(end, m.t1 + dur)); last.words.push(m.word); continue; }
    let start = t0;
    if (last && t0 < last.t1) {
      // the badge on screen keeps at least `min` s; the new place starts after that (or is dropped when it would
      // only get a flash before its own time runs out)
      start = Math.max(t0, last.t0 + min);
      if (start > m.t1 + dur - min * 0.5) continue;
      last.t1 = start;
    }
    spans.push({ code: m.code, t0: start, t1: Math.min(end, Math.max(start + min, m.t1 + dur)), words: [m.word] });
  }
  return spans.filter((s) => s.t1 - s.t0 > 0.3).map((s) => ({ ...s, t0: +s.t0.toFixed(3), t1: +s.t1.toFixed(3) }));
}
