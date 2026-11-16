// Accuracy helpers that do not depend on the model: the company glossary (do-not-translate names and fixed
// translations), the "keep exactly" spans (numbers, prices, percentages, dates, codes, URLs, @handles, #tags, emoji),
// masking them before the model sees a sentence and putting them back after, and the checks that prove nothing was
// lost. Pure functions, no DOM.
//
// Glossary entry: { term, keep: true }                    -> copied exactly in every language ("KBB", "Chancenkarte")
//                 { term, ar: '…', ur: '…', hi: '…' }      -> that language gets exactly this ("Schengen" -> "شنغن");
//                                                            a language not listed keeps the term as written.
// Stored per company in workspace.translate.glossary (edited in the Translate panel); seeded from the captions
// round's vocabulary (vocab.js: the KBB catalogue's brands, Planet Careers' programme names, the company's own words).

import { KBB_BRANDS } from '../vocab.js';

// Proper names from the captions vocabulary that are never translated (generic words like "visa", "serum", "toner"
// in the same lists ARE translated, so they are not here).
const SEED_COMMON = ['KBB', 'K-Beauty', 'K-Beauty Bliss', 'kbeautybliss.com', 'Tabby', 'Tamara', 'WhatsApp', 'Instagram', 'TikTok',
  'Snapchat', 'YouTube', 'Apple Pay', 'Google Pay', 'SPF'];
const SEED_KBB = KBB_BRANDS.map(([en]) => en);
const SEED_PC = ['Planet Careers', 'Chancenkarte', 'IELTS', 'TOEFL', 'PTE', 'Duolingo', 'APS', 'Uni-Assist', 'DAAD', 'Erasmus',
  'Erasmus+', 'Stipendium Hungaricum', 'VFS', 'VFS Global', 'Express Entry', 'CRS', 'EU Blue Card', 'Sperrkonto', 'D7', 'GIC', 'CAS'];
// Fixed translations (programme words that HAVE a standard local spelling).
const SEED_FIXED = [
  { term: 'Schengen', ar: 'شنغن', ur: 'شینگن', hi: 'शेंगेन', fa: 'شینگن' },
];

const norm = (s) => String(s || '').trim();

/** The glossary for a workspace: its own entries first (they win), then the seed. */
export function glossaryFor(ws) {
  const out = [], seen = new Set();
  const add = (e) => {
    const g = typeof e === 'string' ? { term: norm(e), keep: true } : { ...e, term: norm(e.term) };
    if (!g.term || g.term.length < 2 || seen.has(g.term.toLowerCase())) return;
    if (!Object.keys(g).some((k) => k.length === 2)) g.keep = true;
    seen.add(g.term.toLowerCase()); out.push(g);
  };
  for (const e of ws?.translate?.glossary || []) add(e);
  if (ws?.name) add(ws.name);
  if (ws?.fullName) add(ws.fullName);
  // the company's own "Words to spell right" (captions panel) are its names: keep them
  const own = ws?.captions?.vocab;
  for (const t of Array.isArray(own) ? own : String(own || '').split(/[\n,]/)) if (/[A-Z0-9]/.test(t)) add(t);
  const ind = String(ws?.industry || '') + ' ' + String(ws?.id || '');
  SEED_FIXED.forEach(add);
  SEED_COMMON.forEach(add);
  if (/beauty|kbb|cosmetic|skin/i.test(ind)) SEED_KBB.forEach(add);
  if (/visa|immigra|study|career|planet/i.test(ind)) SEED_PC.forEach(add);
  return out;
}

/** What an entry must read in language `lang`. */
export const termIn = (g, lang) => (g[lang] != null && g[lang] !== '' ? g[lang] : g.term);

// ------------------------------------------------------------------ spans kept exactly
const EMOJI = '\\p{Extended_Pictographic}(?:\\uFE0F|\\u200D\\p{Extended_Pictographic})*';
const CUR = 'AED|USD|EUR|GBP|CAD|AUD|PKR|INR|SAR|QAR|KWD|OMR|BHD|Dhs?|Rs\\.?|€|\\$|£|₹|﷼';
// one price/number expression: optional currency, digits with , . separators, optional %, optional currency after
const NUM = `(?:(?:${CUR})\\s?)?\\d[\\d,.]*(?:\\s?(?:%|٪))?(?:\\s?(?:${CUR})(?![A-Za-z]))?`;
const NUM_ONLY = new RegExp(`^(?:${NUM}|\\d{1,2}:\\d{2})$`, 'u');
const KEEP_RE = new RegExp([
  'https?:\\/\\/\\S+', '\\b[\\w.-]+@[\\w-]+\\.[\\w.]+\\b', '\\b[\\w-]+\\.(?:com|ae|net|org|io|co|de|uk|pk|in)\\b',
  '[@#][\\p{L}\\p{N}_]+', '\\b(?=[A-Z0-9-]*\\d)(?=[A-Z0-9-]*[A-Z])[A-Z0-9-]{2,}\\b', '\\b\\d{1,2}:\\d{2}\\b', EMOJI, NUM,
].join('|'), 'gu');

const DIGITS = { '٠': 0, '١': 1, '٢': 2, '٣': 3, '٤': 4, '٥': 5, '٦': 6, '٧': 7, '٨': 8, '٩': 9, '۰': 0, '۱': 1, '۲': 2, '۳': 3, '۴': 4,
  '۵': 5, '۶': 6, '۷': 7, '۸': 8, '۹': 9, '०': 0, '१': 1, '२': 2, '३': 3, '४': 4, '५': 5, '६': 6, '७': 7, '८': 8, '९': 9 };
/** Eastern Arabic, Persian and Devanagari digits -> 0-9; Arabic percent / separators -> ASCII. */
export const westernDigits = (s) => String(s || '').replace(/[٠-٩۰-۹०-९]/g, (d) => DIGITS[d]).replace(/٪/g, '%').replace(/(\d)٫(\d)/g, '$1.$2').replace(/(\d)٬(\d)/g, '$1,$2');

/** Every keep-exactly span in a text: [{s, e, text, kind}] (numbers, prices, codes, URLs, handles, emoji). */
export function keepSpans(text) {
  const out = [];
  for (const m of String(text).matchAll(KEEP_RE)) {
    const t = m[0].replace(/[.,]+$/, '');
    if (!t) continue;
    const kind = /^\p{Extended_Pictographic}/u.test(t) ? 'emoji' : NUM_ONLY.test(t) ? 'number' : 'code';
    out.push({ s: m.index, e: m.index + t.length, text: t, kind });
  }
  return out;
}

/** The numbers in a text, normalised (digits only, separators dropped): "AED 1,500" -> ['1500']. */
export const numbersIn = (text) => (westernDigits(text).match(/\d[\d,.]*/g) || []).map((n) => n.replace(/[.,]+$/, '').replace(/,/g, ''));

const escRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
/** Glossary hits in a source text, longest first, non-overlapping: [{s, e, g}] */
export function glossaryHits(text, glossary) {
  const hits = [];
  const list = [...glossary].sort((a, b) => b.term.length - a.term.length);
  for (const g of list) {
    const re = new RegExp(`(?<![\\p{L}\\p{N}])${escRe(g.term)}(?![\\p{L}\\p{N}])`, 'giu');
    for (const m of String(text).matchAll(re)) {
      const s = m.index, e = s + m[0].length;
      if (hits.some((h) => s < h.e && e > h.s)) continue;
      hits.push({ s, e, g, text: m[0] });
    }
  }
  return hits.sort((a, b) => a.s - b.s);
}

// ------------------------------------------------------------------ masking
// Each protected span becomes a placeholder the models copy through unchanged. Measured with tools/t-mask-probe.cjs
// on every test sentence holding a name, number or code (tools/out/t/mask-probe*.json): curly "{1}" survives
// OPUS-MT best (en-ar 16/27, en-hi 22/27, en-mul 26-27/27) where bare tokens ("ZXA", "XXA") are translated or
// dropped; anything a model still loses is caught by the retry / repair in pipeline.translateBatch.
export const PH = (i) => `{${i + 1}}`;
const PH_RE = /[{｛]\s*(\d{1,2})\s*[}｝]/g;

/**
 * Mask a sentence for translation into `tgt`. Returns { text, slots:[{ph, out, src, kind}] } where `out` is what the
 * placeholder becomes in the translation (the glossary's target spelling, or the span exactly as written).
 * opts.numbers (default true) also masks numbers/prices; opts.terms (default true) masks glossary terms.
 */
export function mask(text, glossary, tgt, opts = {}) {
  const spans = [];
  if (opts.terms !== false) for (const h of glossaryHits(text, glossary)) spans.push({ s: h.s, e: h.e, out: termIn(h.g, tgt), src: h.text, kind: 'term' });
  for (const k of keepSpans(text)) {
    if (k.kind === 'number' && opts.numbers === false) continue;
    if (spans.some((x) => k.s < x.e && k.e > x.s)) continue;
    spans.push({ s: k.s, e: k.e, out: k.text, src: k.text, kind: k.kind });
  }
  spans.sort((a, b) => a.s - b.s);
  let out = '', at = 0;
  const slots = [];
  spans.forEach((sp, i) => {
    const ph = PH(i);
    out += text.slice(at, sp.s) + ph; at = sp.e;
    slots.push({ ph, out: sp.out, src: sp.src, kind: sp.kind });
  });
  out += text.slice(at);
  return { text: out, slots };
}

/** Put the slots back into a translation. Returns { text, missing:[slot] } (a slot the model dropped is missing). */
export function unmask(translated, slots) {
  const used = new Set();
  let text = String(translated || '').replace(PH_RE, (m, a) => {
    const ph = `{${+a}}`;
    const sl = slots.find((x) => x.ph === ph);
    if (!sl || used.has(ph)) return used.has(ph) ? '' : m;
    used.add(ph);
    return sl.out;
  });
  text = text.replace(/\s{2,}/g, ' ').replace(/\s+([.,!?،؛؟।۔])/g, '$1').trim();
  return { text, missing: slots.filter((x) => !used.has(x.ph)) };
}

/** Glossary terms in `src` that the translation does not carry exactly (as the target spelling). */
export function glossaryMisses(src, out, glossary, tgt) {
  return glossaryHits(src, glossary).filter((h) => !String(out).includes(termIn(h.g, tgt))).map((h) => h.text);
}
/** Numbers in `src` that are not in the translation (digits compared, any script). */
export function numberMisses(src, out) {
  const have = numbersIn(out);
  const miss = [];
  for (const n of numbersIn(src)) { const k = have.indexOf(n); if (k < 0) miss.push(n); else have.splice(k, 1); }
  return miss;
}
/** Keep-exactly spans (codes, URLs, handles, emoji) that did not come through exactly. */
export function keepMisses(src, out) {
  return keepSpans(src).filter((k) => k.kind !== 'number' && !String(out).includes(k.text)).map((k) => k.text);
}
