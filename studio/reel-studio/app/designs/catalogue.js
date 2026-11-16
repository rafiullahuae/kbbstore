// KBB Studio · Designs · the offline catalogue the brief reader and the designs use: KBB product cut-outs, the Planet
// Careers country pack and the occupation library (all read from the studio folder, once).
import { products, countries, readJSON } from '../content/lib/catalogue.js';

let cached = null;
export async function catalogueAll() {
  if (cached) return cached;
  const [p, c, o] = await Promise.all([products(), countries(), readJSON('library/occupations/occupations.json').then((j) => j.occupations).catch(() => [])]);
  cached = { products: p, countries: c, occupations: o };
  return cached;
}
/** the catalogue as "id = name" lines (what a model -- local or Claude -- may pick from) */
export function catalogueNames(cat, industry) {
  const out = [];
  if (industry !== 'consultancy') for (const p of cat.products) out.push(`${p.id} = ${p.brand} ${p.name}`);
  if (industry !== 'beauty') {
    for (const c of cat.countries) out.push(`${c.code} = ${c.name}`);
    for (const o of cat.occupations) out.push(`${o.id} = ${o.name.en}`);
  }
  return out;
}
