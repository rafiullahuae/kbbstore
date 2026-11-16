// KBB Studio · recipes engine: auto-suggest (rank recipes from what was said + what the footage looks like) and
// resolve a recipe into concrete settings for one edit.
import { RECIPES } from './recipes.js';
import { COUNTRIES } from '../countries/countries.js';
import { normWord } from '../lib/audio.js';

let DF = null;
function docFreq() { // how many recipes list each keyword (common words count less)
  if (DF) return DF; DF = new Map();
  for (const r of RECIPES) for (const k of new Set([...(r.keywords?.en || []), ...(r.keywords?.ar || [])].map(normWord))) DF.set(k, (DF.get(k) || 0) + 1);
  return DF;
}
const tokens = text => String(text || '').split(/[\s,.!?؟،;:()"'“”]+/).map(normWord).filter(Boolean);

/**
 * input = { text | words, video:{stats:{motion,cutsPerMin}, frames?}, faces?: 0..1, workspace?: {id, industry}, limit=3 }
 * returns [{recipe, score, reasons, country?}]
 */
export function suggestRecipes(input = {}) {
  const toks = input.text != null ? tokens(input.text) : (input.words || []).map(w => normWord(w.w)).filter(Boolean);
  const bag = new Map(); for (const t of toks) bag.set(t, (bag.get(t) || 0) + 1);
  // two-word phrases ("new york", "abu dhabi") as single keys too
  for (let i = 0; i < toks.length - 1; i++) { const k = toks[i] + ' ' + toks[i + 1]; bag.set(k, (bag.get(k) || 0) + 1); }
  const df = docFreq(), N = RECIPES.length;
  const st = input.video?.stats || {}, faces = input.faces ?? null;
  const motion = st.motion == null ? null : st.motion < 0.012 ? 'low' : st.motion > 0.035 ? 'high' : 'mid';
  const cuts = st.cutsPerMin == null ? null : st.cutsPerMin < 6 ? 'few' : st.cutsPerMin > 15 ? 'many' : 'some';
  const ws = input.workspace || {}, wsId = ws.id || ws.brandRef || '', ind = String(ws.industry || '').toLowerCase();
  // country mentioned?
  let country = null, cBest = 0;
  for (const c of COUNTRIES) { let s = 0; for (const k of new Set([...c.keywords.en, ...c.keywords.ar].map(normWord))) s += bag.get(k) || 0; if (s > cBest) { cBest = s; country = c; } }
  const out = RECIPES.map(r => {
    const reasons = []; let s = 0; const hits = [];
    for (const k of new Set([...(r.keywords?.en || []), ...(r.keywords?.ar || [])].map(normWord))) {
      const n = bag.get(k); if (!n) continue;
      const idf = Math.log(1 + N / (df.get(k) || 1)); s += idf * (1 + Math.log(n)); hits.push(k);
    }
    if (hits.length) reasons.push('heard: ' + hits.slice(0, 5).join(', '));
    const sc = r.scene || {};
    if (motion && sc.motion) { if (sc.motion === motion) { s += 1.2; reasons.push(`${motion} motion fits`); } else if (Math.abs(['low', 'mid', 'high'].indexOf(sc.motion) - ['low', 'mid', 'high'].indexOf(motion)) === 2) s -= 1; }
    if (cuts && sc.cuts) { if (sc.cuts === cuts) { s += 0.8; reasons.push(cuts === 'many' ? 'fast cutting' : cuts === 'few' ? 'long takes' : 'steady pacing'); } }
    if (faces != null && sc.faces != null) { if (sc.faces && faces > 0.4) { s += 0.8; reasons.push('talking to camera'); } else if (!sc.faces && faces < 0.15) s += 0.4; }
    if (r.workspaces.includes(wsId)) { s += 1.5; reasons.push(`a ${ws.name || wsId} recipe`); } else if (r.industries.some(i => ind.includes(i))) s += 1;
    else if (wsId && !r.workspaces.includes('*')) s -= 1.5;
    if (r.country) { if (country && r.country === country.code) { s += 2 + cBest; reasons.push(`mentions ${country.name}`); } else s -= 3; }
    return { recipe: r, score: +s.toFixed(3), reasons };
  }).sort((a, b) => b.score - a.score || a.recipe.id.localeCompare(b.recipe.id));
  // never three country spotlights: keep at most one
  const res = []; let spot = 0;
  for (const o of out) { if (o.recipe.country && spot++) continue; res.push(o); if (res.length >= (input.limit || 3)) break; }
  return res.map(o => ({ ...o, country: o.recipe.country ? countryOf(o.recipe.country) : (country && cBest ? countryOf(country.code) : null) }));
}
const countryOf = code => { const c = COUNTRIES.find(x => x.code === code); return c ? { code: c.code, name: c.name } : null; };

/** Concrete settings for one edit: length, captions, music, effects, end card text. */
export function resolveRecipe(recipe, { length, lang = 'en', kit = null, variant = null } = {}) {
  const L = recipe.lengths.includes(length) ? length : recipe.defaultLength;
  const ar = lang === 'ar';
  const cta = (ar ? recipe.endCard?.ctaAr : recipe.endCard?.cta) || kit?.endCard?.cta || ['Learn more'];
  return {
    id: recipe.id, length: L, aspect: recipe.aspect,
    captionStyle: ar ? (recipe.captionStyleAr || 'arabic-first') : recipe.captionStyle,
    music: recipe.music, effects: recipe.effects, pacing: recipe.pacing,
    endCard: recipe.endCard ? { template: recipe.endCard.template, cta: cta[0], ctaOptions: cta, payments: !!recipe.endCard.payments, title: variant?.title || kit?.endCard?.title, sub: kit?.endCard?.sub, contact: kit?.contactLine } : null,
    structure: recipe.structure, overlays: recipe.overlays, variant,
  };
}
