// KBB Studio · Designs · BRIEF -> 6–8 DISTINCT DESIGNS. Families × colour schemes × copy variants × languages, each
// composed for every size the brief asked for, rendered, checked by the rules (rules.js), FIXED when a rule fails
// (readable ink, a plate under text on a busy spot, smaller text, smaller hero) and rejected when it still fails.
// Only designs that pass every hard rule in every size are returned, best score first, no two from one family.
import { compose, familiesFor, FAMILY_NAMES } from './layouts.js';
import { schemesFor } from './lib/palette.js';
import { copyVariants } from './lib/copy.js';
import { artFor } from './art.js';
import { prepare, loadFont, layout } from './render.js';
import { check } from './rules.js';
import { iconsFor, loadIcons, BENEFITS } from './icons.js';
import { contrast, ink as inkOn } from './lib/color.js';

const uid = () => 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
export const fontsFor = (ws) => ({ head: ws.fonts?.heading || 'Playfair Display', body: ws.fonts?.body || 'Poppins', ar: ws.fonts?.arabic || 'Cairo', display: 'Bebas Neue', serif: 'Playfair Display' });
export function brandOf(ws) {
  const ref = ws.brandRef || ws.id;
  const kitLogo = (f) => (f ? `brands/${ref}/${f}` : null);
  return { name: ws.fullName || ws.name || '', logo: ws.logoFull || kitLogo('logo.svg'), logoLight: kitLogo(ws.id === 'kbb' || ws.id === 'planet-careers' ? 'logo-white.svg' : null) || ws.logoFull, mark: ws.logo };
}

/** what the picture is: real product cut-outs, a worker in uniform, a destination scene -- or an AI image when given */
export function heroFor(spec, cat, P, opts = {}) {
  if (opts.image) return { kind: 'image', src: opts.image, name: 'AI picture' };
  if (spec.products?.length || (spec.industry === 'beauty' && cat.products?.length)) {
    const ids = spec.products.length ? spec.products : cat.products.slice(0, 3).map((p) => p.id);
    const items = ids.map((id) => cat.products.find((p) => p.id === id)).filter(Boolean).map((p) => ({ id: p.id, name: p.name, src: p.src || 'brands/kbb/products/' + p.file, w: p.w, h: p.h }));
    if (items.length) return { kind: 'products', items };
  }
  const occ = (cat.occupations || []).find((o) => spec.occupations?.includes(o.id));
  if (occ && opts.workerPhoto) return { kind: 'image', src: opts.workerPhoto, name: occ.name.en + ' (photo)' };
  if (occ) return { kind: 'worker', src: 'library/occupations/' + (occ.figure?.file || 'figures/trades.svg'), name: occ.name.en };
  const c = (cat.countries || []).find((x) => x.code === spec.countries?.[0]);
  if (c) return { kind: 'scene', src: 'brands/planet-careers/countries/' + (c.landmark?.file || c.map), name: c.landmark?.name || c.name, tint: { '#0b2545': P.dark ? '#FFFFFF' : P.ink === '#FFFFFF' ? '#0b2545' : P.ink }, flag: 'brands/planet-careers/countries/' + c.flag };
  return null;
}

/** the big number for the "big number" / "badge" families (only from the brief: never invented) */
export function bigFor(spec, lang) {
  const o = spec.offer;
  if (o?.type === 'percent') return { txt: o.value + '%', small: lang === 'ar' ? 'خصم' : (o.upTo ? 'UP TO · OFF' : 'OFF') };
  if (o?.type === 'amount') return { txt: String(o.value), small: lang === 'ar' ? 'درهم خصم' : 'AED OFF' };
  if (spec.intake) return { txt: spec.intake.slice(0, 3).toUpperCase(), small: lang === 'ar' ? 'دفعة' : 'INTAKE' };
  return null;
}

function assemble(spec, ws, cat, { family, palette, copy, lang, sizes, hero, icons, seed, textK = 1, heroK = 1 }) {
  const C = lang === 'ar' ? copy.ar : copy.en;
  const C2 = spec.languages.length > 1 ? (lang === 'ar' ? copy.en : copy.ar) : null;
  const D = {
    v: 1, id: uid(), name: `${FAMILY_NAMES[family]} · ${palette.name}`, workspace: ws.id, family, lang, copyId: copy.id, paletteId: palette.id,
    sizes: sizes.slice(), palette: { ...palette }, art: artFor(spec, palette, family, seed), bgImage: null, brand: brandOf(ws), fonts: fontsFor(ws),
    els: [], geo: {}, spec: { kind: spec.kind, brief: spec.brief, occasion: spec.occasion?.id || null, products: spec.products, countries: spec.countries, occupations: spec.occupations, languages: spec.languages, tone: spec.tone, industry: spec.industry },
    created: new Date().toISOString(),
  };
  const ctx = { P: palette, C, C2, spec, hero, icons, benefits: BENEFITS[spec.kind] || BENEFITS.offer, lang, big: bigFor(spec, lang), measureD: D, textK, heroK };
  const seen = new Map();
  for (const size of sizes) {
    D.geo[size] = {};
    for (const [el, g] of compose(family, size, ctx)) {
      if (!seen.has(el.id)) { seen.set(el.id, el); D.els.push(el); }
      D.geo[size][el.id] = g;
    }
  }
  for (const size of sizes) for (const el of D.els) if (!D.geo[size][el.id]) D.geo[size][el.id] = { x: 0, y: 0, w: 1, h: 1, hd: true };
  return D;
}

/** fix what can be fixed in place (colour / plate); returns true when something changed */
function fixColours(D, size, res) {
  let changed = false;
  const bad = res.checks.find((c) => c.id === 'contrast' && !c.ok);
  if (!bad) return false;
  for (const id of bad.ids) {
    const el = D.els.find((e) => e.id === id), it = res.items.find((x) => x.el.id === id);
    if (!el || !it) continue;
    if (el.t === 'btn') { el.pc = undefined; el.col = inkOn(D.palette[el.fill || 'acc'] || D.palette.acc); changed = true; continue; }
    if (el.plate) { el.plate.op = 1; el.col = inkOn(D.palette[el.plate.pc || 'card'] || D.palette.card); changed = true; continue; }
    // try the other ink first, then a plate in the card colour
    const tried = el._tried || (el._tried = []);
    const opts = ['#FFFFFF', '#111111', D.palette.ink, D.palette.cardInk].filter((c) => !tried.includes(c));
    if (!el.plate && tried.length >= 2) { el.plate = { pc: 'card', op: 0.94, pad: 0.35 }; el.col = D.palette.cardInk; changed = true; continue; }
    const next = opts.find((c) => c !== (el.col || D.palette[el.pc]));
    if (next) { tried.push(next); el.col = next; changed = true; }
  }
  return changed;
}

/**
 * spec + company -> designs. opts: {count (6–8), sizes, seed, image (an AI picture to use as hero in some designs),
 * onProgress(f, label)}. Returns {designs:[D], rejected:[{family, reasons}], tried}
 */
export async function generate(spec, ws, cat, opts = {}) {
  await loadIcons();
  const sizes = (opts.sizes || spec.sizes || ['story', 'post', 'square']).filter((s) => ['story', 'post', 'square'].includes(s));
  const count = Math.max(6, Math.min(8, opts.count || 8));
  const fonts = fontsFor(ws);
  await Promise.all(Object.values(fonts).map(loadFont));
  const palettes = opts.palettes?.length ? opts.palettes : schemesFor(spec, ws, cat);
  const copies = copyVariants(spec, ws, cat);
  const families = familiesFor(spec);
  const icons = iconsFor(spec, 4);
  const langs = spec.languages.length ? spec.languages : ['en'];
  const designs = [], rejected = [];
  let tried = 0;
  const seed0 = opts.seed || 1;
  for (let i = 0; i < families.length && designs.length < count; i++) {
    const family = families[i];
    const lang = langs.length > 1 && i % 3 === 2 ? langs[1] : langs[0];
    const big = bigFor(spec, lang);
    if ((family === 'bignum' || family === 'badge') && !big) continue;
    let accepted = null, reasons = [];
    // several palettes per family if the first one cannot be made to pass
    for (let pj = 0; pj < Math.min(3, palettes.length) && !accepted; pj++) {
      const palette = palettes[(i + pj * 2) % palettes.length];
      const useImage = opts.image && (family === 'poster' || family === 'card' || family === 'arch');
      // products are always the real cut-outs: with products, an AI picture becomes the BACKGROUND, never the hero
      const prodHero = heroFor(spec, cat, palette, { workerPhoto: opts.workerPhoto });
      const asBg = useImage && prodHero?.kind === 'products';
      const hero = asBg ? prodHero : heroFor(spec, cat, palette, { image: useImage ? opts.image : null, workerPhoto: opts.workerPhoto });
      let textK = 1, heroK = 1;
      for (let attempt = 0; attempt < 6; attempt++) {
        tried++;
        const D = assemble(spec, ws, cat, { family, palette, copy: copies[i % copies.length], lang, sizes, hero, icons, seed: seed0 + i * 31 + pj, textK, heroK });
        if (asBg) D.bgImage = { src: opts.image, op: 1 };
        if (opts.keepEls) for (const [id, v] of Object.entries(opts.keepEls)) { const el = D.els.find((e) => e.id === id); if (el) Object.assign(el, v); }
        await prepare(D);
        let ok = true, worst = 100, colourFixes = 0;
        for (const size of sizes) {
          let res = check(D, size);
          while (!res.pass && colourFixes < 8 && res.checks.filter((c) => !c.ok && ['safe', 'size', 'fit', 'overlap', 'crop', 'hierarchy'].includes(c.id)).length === 0 && fixColours(D, size, res)) { colourFixes++; await prepare(D); res = check(D, size); }
          if (!res.pass) { ok = false; reasons = res.checks.filter((c) => !c.ok && c.id !== 'grid' && c.id !== 'words').map((c) => `${size}: ${c.msg}`); break; }
          worst = Math.min(worst, res.score);
          D.checks = D.checks || {}; D.checks[size] = res.checks.map(({ id, ok: o, msg }) => ({ id, ok: o, msg }));
        }
        if (ok) { D.score = worst; D.fixes = colourFixes + (textK < 1 ? 1 : 0) + (heroK < 1 ? 1 : 0); for (const el of D.els) delete el._tried; accepted = D; break; }
        // geometry fixes: overlaps / fit / size -> smaller text and hero, a step at a time
        if (reasons.some((r) => /overlap|fit|outside|cropped/.test(r))) { textK *= 0.92; heroK *= 0.9; }
        else if (reasons.some((r) => /too small/.test(r))) textK *= 1.06;
        else if (reasons.some((r) => /contrast/.test(r))) continue;
        else break;
      }
    }
    if (accepted) { designs.push(accepted); opts.onProgress?.(designs.length / count, `${designs.length} of ${count} designs`); }
    else rejected.push({ family, reasons });
  }
  designs.sort((a, b) => b.score - a.score);
  return { designs, rejected, tried, palettes, copies };
}

/** re-check one design (the editor after a change): {pass, score, bySize} */
export async function recheck(D) {
  await prepare(D);
  const bySize = {};
  let pass = true, score = 100;
  for (const s of D.sizes) { const r = check(D, s); bySize[s] = r.checks; pass &&= r.pass; score = Math.min(score, r.score); }
  return { pass, score, bySize };
}
export { contrast, layout };
