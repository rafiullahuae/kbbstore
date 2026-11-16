// KBB Studio · Designs · the test briefs (EN + AR, KBB + Planet Careers) and a node loader for the catalogue.
// Shared by tools/d-check.cjs; `node tools/d-briefs.mjs` prints the rule reading of each brief.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export const BRIEFS = [
  { id: 'kbb-en', ws: 'kbb', text: 'UAE National Day offer: 30% off Anua and BoJ, story and post, luxury feel, for women. CTA: Shop the offer' },
  { id: 'kbb-ar', ws: 'kbb', text: 'عرض اليوم الوطني: خصم ٣٠٪ على منتجات أنوا وبيوتي أوف جوسون، ستوري وبوست، طابع فاخر للسيدات' },
  { id: 'pc-study-en', ws: 'planet-careers', text: 'Study in Germany - September intake now open. Bachelor and master programs for students, book a free consultation. Post and story.' },
  { id: 'pc-study-ar', ws: 'planet-careers', text: 'الدراسة في ألمانيا - التسجيل مفتوح لدفعة سبتمبر للطلاب، احجز استشارة مجانية' },
  { id: 'pc-work-en', ws: 'planet-careers', text: 'Work abroad: welders wanted for Poland. Show welders in uniform, bold, apply on WhatsApp. Square and story.' },
  { id: 'pc-work-ar', ws: 'planet-careers', text: 'مطلوب لحامين للعمل في بولندا، قدّم عبر واتساب' },
];

export function catalogue() {
  const rd = (p) => JSON.parse(fs.readFileSync(path.join(ROOT, p), 'utf8'));
  const products = rd('brands/kbb/products/index.json').products;
  const countries = rd('brands/planet-careers/countries/countries.json').countries;
  const occupations = rd('library/occupations/occupations.json').occupations;
  return { products, countries, occupations };
}
export function workspace(id) {
  const kit = JSON.parse(fs.readFileSync(path.join(ROOT, 'brands', id, 'kit.json'), 'utf8'));
  return { id, name: kit.name, fullName: kit.fullName, industry: kit.industry, industries: kit.industries, colors: kit.colors, endCard: { cta: kit.endCard?.cta?.[0], ctaAr: kit.endCard?.ctaAr?.[0] } };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const { parseBrief } = await import(path.join(ROOT, 'app/designs/lib/brief.js'));
  const cat = catalogue();
  for (const b of BRIEFS) {
    const s = parseBrief(b.text, workspace(b.ws), cat);
    console.log(b.id, JSON.stringify({ kind: s.kind, occasion: s.occasion?.id, offer: s.offer?.en, products: s.products, brands: s.brands, missing: s.missingBrands, countries: s.countries, visa: s.visa, intake: s.intake, occ: s.occupations, aud: s.audience, tone: s.tone, langs: s.languages, sizes: s.sizes, cta: s.cta }, null, 0));
  }
}
