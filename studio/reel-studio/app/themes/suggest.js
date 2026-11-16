// KBB Studio · themes · auto-suggest: which theme fits this reel? From the recipe, the company's industry, what is said
// (transcript keywords, English + Arabic) and the country it is about (Germany → the Germany theme, with Europe next).
import { THEMES } from './themes.js';
import { COUNTRIES } from '../auto/countries/countries.js';

/** Arabic folded the way the country packs spell their keywords (no diacritics, one alef, ه for ة, ي for ى) */
export const fold = (s) => String(s || '').toLowerCase()
  .replace(/[ً-ٰٟـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
  .replace(/[^\p{L}\p{N}\s'-]/gu, ' ').replace(/\s+/g, ' ').trim();

// words the country packs carry that say nothing about a country on their own ("new" Zealand, "united" Kingdom …)
const STOP = new Set(['new', 'united', 'south', 'states', 'kingdom', 'arab', 'republic', 'visa', 'golden', 'blocked', 'campus', 'day', 'abu', 'the', 'of', 'and']);
function hits(text, tokens, words) {
  let n = 0;
  for (const raw of words || []) {
    const w = fold(raw); if (!w || STOP.has(w)) continue;
    if (w.includes(' ')) { let i = -1; while ((i = text.indexOf(w, i + 1)) >= 0) n++; }
    else n += tokens.get(w) || 0;
  }
  return n;
}
const EUROPE = new Set(COUNTRIES.filter((c) => c.region === 'europe').map((c) => c.code));

/**
 * Ranked theme suggestions: [{ id, name, score, why:[...] }].
 * input = { text (transcript), recipe (recipe object or null), workspace, country (code), limit }
 */
export function suggestThemes({ text = '', recipe = null, workspace = null, country = null, limit = 3 } = {}) {
  const ft = ' ' + fold(text) + ' ', tokens = new Map();
  for (const w of ft.trim().split(' ')) if (w) tokens.set(w, (tokens.get(w) || 0) + 1);
  const ind = `${workspace?.industry || ''} ${(workspace?.industries || []).join(' ')}`.toLowerCase();
  const wsId = workspace?.brandRef || workspace?.id || '';
  const rows = THEMES.map((th) => {
    const why = []; let s = 0;
    const k = hits(ft, tokens, [...(th.keywords?.en || []), ...(th.keywords?.ar || [])]);
    if (k) { s += Math.min(12, k * (th.country ? 3 : 2)); why.push(`${k} matching word${k > 1 ? 's' : ''} in what is said`); }
    if (recipe) {
      if (th.recipes?.includes(recipe.id)) { s += 6; why.push(`made for the “${recipe.name}” template`); }
      if (recipe.country && th.country === recipe.country) { s += 9; why.push(`the template is about ${th.name}`); }
      if (recipe.country && th.id === 'europe' && EUROPE.has(recipe.country)) { s += 5; why.push('the template’s country is in Europe'); }
    }
    if (country && th.country === country) { s += 10; why.push(`the reel is about ${th.name}`); }
    if (country && th.id === 'europe' && EUROPE.has(country)) { s += 6; why.push('that country is in Europe'); }
    if ((th.industries || []).some((i) => i !== '*' && ind.includes(i))) { s += 1.5; why.push(`fits the company’s industry`); }
    if (wsId === 'kbb' && th.group === 'Beauty') { s += th.id === 'beauty-petals' ? 2.5 : 1.5; why.push('a K-Beauty Bliss look'); }
    return { th, s, why };
  });
  // Europe as the umbrella: every European country named lifts it
  const eu = rows.find((r) => r.th.id === 'europe');
  if (eu) {
    let n = 0; for (const r of rows) if (r.th.country && EUROPE.has(r.th.country) && r.why.some((w) => /matching/.test(w))) n += hits(ft, tokens, [...r.th.keywords.en, ...r.th.keywords.ar]);
    if (n) { eu.s += Math.min(10, n * 2); eu.why.push(`${n} mention${n > 1 ? 's' : ''} of European countries or cities`); }
  }
  rows.sort((a, b) => b.s - a.s || THEMES.indexOf(a.th) - THEMES.indexOf(b.th));
  const out = rows.filter((r) => r.s > 0).slice(0, limit);
  // nothing to go on: a sensible default for the company
  if (!out.length) { const d = rows.find((r) => r.th.id === (wsId === 'kbb' ? 'beauty-petals' : /immigra|visa|study|education/.test(ind) ? 'europe' : 'corporate')); if (d) out.push({ ...d, why: ['a good default for this company'] }); }
  return out.map((r) => ({ id: r.th.id, name: r.th.name, score: +r.s.toFixed(2), why: r.why }));
}

/** the country a reel is about, from the recipe or the words (code or null) */
export function countryOf({ text = '', recipe = null } = {}) {
  if (recipe?.country) return recipe.country;
  const ft = ' ' + fold(text) + ' ', tokens = new Map();
  for (const w of ft.trim().split(' ')) if (w) tokens.set(w, (tokens.get(w) || 0) + 1);
  let best = null, bn = 0;
  for (const c of COUNTRIES) { const n = hits(ft, tokens, [...c.keywords.en, ...c.keywords.ar]); if (n > bn) { bn = n; best = c.code; } }
  return best;
}
