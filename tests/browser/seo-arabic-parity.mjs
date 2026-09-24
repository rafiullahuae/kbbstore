/**
 * Lane SEO round 3 — the Arabic crawl surface, seen and measured.
 *
 * Most of this round is markup a crawler reads and a person does not, so the
 * text this prints IS the evidence: the canonical, the hreflang cluster, the
 * <html lang> and the JSON-LD language fields, fetched from both languages of
 * every page shape. The shots are the other half of rule 1 — proof that a
 * round which rewrote part of every page's <head> moved nothing anybody can
 * see, at 390 and at 1280.
 *
 *   KBB_SEO_URL=http://127.0.0.1:8979 KBB_SEO_OUT=docs/seo-arabic-shots \
 *   node tests/browser/seo-arabic-parity.mjs
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_SEO_URL || 'http://127.0.0.1:8979';
const OUT = process.env.KBB_SEO_OUT || 'docs/seo-arabic-shots';
const CHROME = process.env.KBB_SEO_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const SHAPES = [
  ['home', '/'],
  ['shop', '/shop/'],
  ['category', '/product-category/skincare/toners/'],
  ['product', '/product/relief-sun-rice-probiotics-spf50/'],
  ['concern', '/concern/acne/'],
  ['journal', '/skincare-guide/'],
];

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

for (const [label, width, height] of [['390', 390, 844], ['1280', 1280, 900]]) {
  const ctx = await b.newContext({ viewport: { width, height } });
  const p = await ctx.newPage();

  for (const [name, path] of SHAPES) {
    for (const [lang, prefix] of [['en', ''], ['ar', '/ar']]) {
      const url = `${BASE}${prefix}${path}`;
      const r = await p.goto(url, { waitUntil: 'networkidle' });

      if (r.status() !== 200) {
        console.log(`[${label}] ${name} ${lang} -> ${r.status()} (skipped)`);
        continue;
      }

      const m = await p.evaluate(() => {
        const alts = [...document.querySelectorAll('link[rel=alternate][hreflang]')]
          .map(l => `${l.hreflang}=${l.href}`);
        const langs = [];
        for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
          let d; try { d = JSON.parse(s.textContent); } catch { continue; }
          for (const n of (Array.isArray(d) ? d : [d])) {
            if (n && n.inLanguage !== undefined) {
              langs.push(`${n['@type']}=${JSON.stringify(n.inLanguage)}`);
            }
          }
        }
        return {
          htmlLang: document.documentElement.lang,
          canonical: document.querySelector('link[rel=canonical]')?.href ?? null,
          alts,
          langs,
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        };
      });

      console.log(`[${label}] ${name} ${lang}`);
      console.log(`    html lang=${m.htmlLang}  canonical=${m.canonical}`);
      console.log(`    alternates: ${m.alts.join('  ')}`);
      console.log(`    inLanguage: ${m.langs.join('  ') || '(none)'}`);
      console.log(`    scrollWidth=${m.scrollWidth} clientWidth=${m.clientWidth}`
        + (m.scrollWidth > m.clientWidth ? '  ** HORIZONTAL OVERFLOW **' : ''));

      if (name === 'product' || name === 'category') {
        await p.screenshot({ path: `${OUT}/${label}-${name}-${lang}.png` });
      }
    }
  }

  await ctx.close();
}

await b.close();
