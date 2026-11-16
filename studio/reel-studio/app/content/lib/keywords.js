// KBB Studio · content · keywords (pure, no DOM). What in a transcript is worth a pop, a b-roll shot, a hashtag:
// products (the KBB catalogue), prices / percentages, countries (Planet Careers pack), numbers, emoji-able words.
import { normWord } from '../../auto/lib/audio.js';
import { emojiFor } from '../../auto/lib/effects.js';

export { normWord };

export const STOP = new Set(('a an the and or but so to of in on at for from by with as is are was were be been being it its this that these those ' +
  'i you he she we they me him her us them my your his our their mine yours what which who whom when where why how all any each few more most ' +
  'other some such no nor not only own same than too very can will just should now do does did doing have has had having am im ive youre dont ' +
  'about into over after before again then once here there up down out off if because until while also really get got go going like know think ' +
  'one two three okay ok yeah yes well right thing things way lot lots much many make made let lets want need see look say said tell today time something important remember ' +
  'و في من على إلى الى عن مع أو او ثم لا لم لن هذا هذه ذلك التي الذي يا كل بعد قبل انا انت هو هي نحن هم ما ماذا كيف لماذا هل قد كان يكون').split(/\s+/).map(normWord));

const PRICE = /^(?:aed|dhs?|dirhams?|درهم|usd|\$|€|eur|euro|euros|£|gbp|pounds?)$/i;
const NUM = /^\d+(?:[.,]\d+)?%?$/;
const WORDNUM = { five: 5, ten: 10, fifteen: 15, twenty: 20, thirty: 30, forty: 40, fifty: 50, hundred: 100 };

/** light stemmer so "serums" meets "serum", "visas" meets "visa", "studying" meets "study" */
export const stem = (w) => {
  w = normWord(w);
  if (/[؀-ۿ]/.test(w)) return w.replace(/^(ال|وال|بال|لل)/, '').replace(/(ات|ين|ون|ه|ة)$/, '');
  return w.replace(/'s$/, '').replace(/(ies)$/, 'y').replace(/(ing|ed)$/, '').replace(/(es)$/, '').replace(/s$/, '');
};

/** a matcher for one entity list: entries [{id, words:[...], phrases:[...]}] → finds them in a word array */
export function makeMatcher(entries) {
  const single = new Map(), multi = [];
  for (const e of entries) {
    for (const w of e.words || []) { const k = stem(w); if (k.length > 1 && !STOP.has(k)) { if (!single.has(k)) single.set(k, []); single.get(k).push(e); } }
    for (const p of e.phrases || []) { const parts = p.split(/\s+/).map(stem).filter(Boolean); if (parts.length > 1) multi.push({ parts, e }); else if (parts[0]) { if (!single.has(parts[0])) single.set(parts[0], []); single.get(parts[0]).push(e); } }
  }
  return (words) => {
    const out = [], st = words.map((w) => stem(w.w ?? w));
    for (let i = 0; i < st.length; i++) {
      for (const { parts, e } of multi) if (parts.every((p, j) => st[i + j] === p)) out.push({ i, i1: i + parts.length - 1, e, strong: true });
      for (const e of single.get(st[i]) || []) out.push({ i, i1: i, e, strong: false });
    }
    return out;
  };
}

/** product entries from the KBB index: brand + name phrases are strong, category words are weak */
export const productEntries = (products) => products.map((p) => ({ id: p.id, kind: 'product', label: `${p.brand} ${p.name}`, item: p,
  words: p.keywords, phrases: [p.brand + ' ' + p.name.split(' ')[0], p.name.toLowerCase().replace(/[()+.]/g, '')] }));
/** country entries from brands/planet-careers/countries/countries.json */
export const countryEntries = (countries) => countries.map((c) => ({ id: c.code, kind: 'country', label: c.name, item: c,
  words: [c.name.split(' ').length === 1 ? c.name : null, c.nameAr, ...(DEMONYM[c.code] || [])].filter(Boolean),
  phrases: [c.name.toLowerCase(), ...(ALIASES[c.code] || [])] }));
const DEMONYM = { de: ['german', 'germany'], fr: ['french', 'france'], it: ['italian', 'italy'], es: ['spanish', 'spain'], pt: ['portuguese', 'portugal'], pl: ['polish', 'poland'],
  nl: ['dutch', 'netherlands', 'holland'], ie: ['irish', 'ireland'], gb: ['uk', 'british', 'britain', 'england', 'london'], ca: ['canadian', 'canada', 'toronto'], au: ['australian', 'australia', 'sydney'],
  nz: ['zealand'], us: ['usa', 'america', 'american'], ae: ['uae', 'dubai', 'emirates', 'emirati'], mt: ['maltese', 'malta'], hu: ['hungarian', 'hungary'], cz: ['czech', 'czechia', 'prague'],
  lt: ['lithuanian', 'lithuania'], se: ['swedish', 'sweden'], fi: ['finnish', 'finland'], dk: ['danish', 'denmark'], no: ['norwegian', 'norway'], at: ['austrian', 'austria'], ch: ['swiss', 'switzerland'],
  be: ['belgian', 'belgium'], gr: ['greek', 'greece'], tr: ['turkish', 'turkey'], jp: ['japanese', 'japan'], my: ['malaysian', 'malaysia'], cy: ['cypriot', 'cyprus'], lv: ['latvian', 'latvia'], ee: ['estonian', 'estonia'], sg: ['singapore'] };
const ALIASES = { gb: ['united kingdom', 'great britain'], us: ['united states'], nz: ['new zealand'], ae: ['united arab emirates'] };

/**
 * Keyword hits in timed words: [{i, i1, t0, t1, kind:'product'|'country'|'price'|'number'|'emoji', key, label, item?, emoji?}].
 * Prices join the number with its currency ("99 AED", "AED 99", "30 % off"). minGap spaces hits of the same key.
 */
export function keywordHits(words, { products = [], countries = [], minGap = 4 } = {}) {
  const out = [];
  const pm = makeMatcher(productEntries(products)), cm = makeMatcher(countryEntries(countries));
  const push = (h) => { const last = out.findLast?.((x) => x.key === h.key) || [...out].reverse().find((x) => x.key === h.key); if (!last || h.t0 - last.t0 >= minGap) out.push(h); };
  const covered = new Set(), cover = (a, b) => { for (let k = a; k <= b; k++) covered.add(k); };
  // products: strong phrase hits (brand + name) win; a plain category word ("serum") stays a WEAK hit naming every
  // product of that kind (good enough for b-roll, never a pop); words inside a strong hit are not hit again
  const pms = pm(words), strong = pms.filter((m) => m.strong);
  for (const m of strong) { if (covered.has(m.i)) continue; push({ i: m.i, i1: m.i1, t0: words[m.i].t0, t1: words[m.i1].t1, kind: 'product', key: 'p:' + m.e.id, label: m.e.label, item: m.e.item, strong: true }); cover(m.i, m.i1); }
  for (const m of cm(words)) { if (covered.has(m.i)) continue; push({ i: m.i, i1: m.i1, t0: words[m.i].t0, t1: words[m.i1].t1, kind: 'country', key: 'c:' + m.e.id, label: m.e.label, item: m.e.item, strong: true }); cover(m.i, m.i1); }
  const weak = new Map();
  for (const m of pms) if (!m.strong && !covered.has(m.i)) { if (!weak.has(m.i)) weak.set(m.i, []); weak.get(m.i).push(m.e.item); }
  for (const [i, items] of weak) { push({ i, i1: i, t0: words[i].t0, t1: words[i].t1, kind: 'product', key: 'w:' + stem(words[i].w), label: String(words[i].w).trim(), items, item: items.length === 1 ? items[0] : null, strong: false }); cover(i, i); }
  for (let i = 0; i < words.length; i++) {
    if (covered.has(i)) continue;
    const raw = String(words[i].w).trim(), n = normWord(raw), next = words[i + 1] ? normWord(words[i + 1].w) : '', prev = i ? normWord(words[i - 1].w) : '';
    const num = NUM.test(n) ? parseFloat(n.replace(',', '.')) : WORDNUM[n];
    if (num != null && words[i + 1] && (PRICE.test(next) || /^(percent|off)$/.test(next) || /%$/.test(raw))) {
      const cur = PRICE.test(next) ? next.toUpperCase().replace(/^DIRHAMS?$/, 'AED').replace(/^DHS?$/, 'AED').replace('درهم', 'AED') : '%';
      const label = cur === '%' ? `${num}% OFF` : `${cur} ${num}`;
      push({ i, i1: i + 1, t0: words[i].t0, t1: words[i + 1].t1, kind: 'price', key: 'price:' + label, label }); cover(i, i + 2);
    } else if (num != null && PRICE.test(prev)) {
      const cur = prev.toUpperCase().replace(/^DHS?$/, 'AED').replace(/^DIRHAMS?$/, 'AED').replace('درهم', 'AED');
      push({ i: i - 1, i1: i, t0: words[i - 1].t0, t1: words[i].t1, kind: 'price', key: 'price:' + cur + num, label: `${cur} ${num}` }); cover(i, i);
    } else if (NUM.test(n) && +n >= 2 && !/^(19|20)\d\d$/.test(n)) {
      push({ i, i1: i, t0: words[i].t0, t1: words[i].t1, kind: 'number', key: 'n:' + n, label: raw.replace(/[^\d.,%]/g, '') });
    } else if (!PRICE.test(n)) {
      const e = emojiFor(raw);
      if (e) push({ i, i1: i, t0: words[i].t0, t1: words[i].t1, kind: 'emoji', key: 'e:' + e, label: raw, emoji: e });
    }
  }
  return out.sort((a, b) => a.t0 - b.t0);
}

/** content words of a text, most frequent first (for search keywords and hashtags) */
export function topTerms(text, n = 10, extraStop = []) {
  const stop = new Set([...STOP, ...extraStop.map(normWord)]);
  const counts = new Map();
  for (const raw of String(text).split(/\s+/)) {
    const w = normWord(raw); if (w.length < 3 || stop.has(w) || /^\d+$/.test(w)) continue;
    const k = stem(w); const e = counts.get(k) || { w, n: 0 }; e.n++; if (w.length < e.w.length) e.w = w; counts.set(k, e);
  }
  return [...counts.values()].sort((a, b) => b.n - a.n || b.w.length - a.w.length).slice(0, n).map((e) => e.w);
}

/** term vector of words (stems, no stop words) for topic similarity */
export function termVector(words) {
  const v = new Map();
  for (const raw of words) { const w = normWord(raw.w ?? raw); if (w.length < 3 || STOP.has(w)) continue; const k = stem(w); v.set(k, (v.get(k) || 0) + 1); }
  return v;
}
export function cosine(a, b) {
  let dot = 0, na = 0, nb = 0;
  for (const [k, x] of a) { na += x * x; const y = b.get(k); if (y) dot += x * y; }
  for (const y of b.values()) nb += y * y;
  return na && nb ? dot / Math.sqrt(na * nb) : 0;
}
