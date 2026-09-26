/*
 * Lane S8 — the eight concern pages in a real browser, at 390px and 1280px.
 *
 * IT REPORTS, IT DOES NOT JUDGE. Same contract as tests/browser/lane-s7-seo-
 * back-office.mjs and lane-m3-settings-screens.mjs, which this follows rather
 * than replaces: the Pest suite has no browser in it, and CLAUDE.md rule 2 wants
 * a picture and the measured numbers behind every patch.
 *
 * WHAT IT ANSWERS, per concern and per width:
 *
 *   code                the HTTP status. A concern page 404s until MIN_PRODUCTS
 *                       products are tagged for it, so a row of 404s here means
 *                       the fixture was not seeded rather than that the page is
 *                       broken.
 *   h1 / h1 font / h1 h the heading, its computed font-size and its rendered
 *                       height. The heading is a SENTENCE aimed at a search
 *                       result, so its height at 390px is the number that decides
 *                       whether it is usable on a phone.
 *   intro words / font  the intro block, which is the prose a ranking system
 *                       reads. Counted rather than asserted: docs/SEO-CONCERN-
 *                       COPY.md is explicit that the word band is practitioner
 *                       convention and must not be in a test.
 *   eyebrow             the product card's own label. It must be the concern's
 *                       SHORT name ("Pores & oil"), not the page's h1 repeated
 *                       twenty-four times down the page.
 *   scrollW/clientW     document.documentElement.scrollWidth and clientWidth.
 *                       Equal at both widths or the page scrolls sideways.
 *
 * HOW TO RUN IT. The preview server is php -S in front of public-web-root, the
 * recipe docs/GB-MEDIA-AND-REDIRECTS.md §12 documents, with the router in this
 * directory:
 *
 *   cp -al public/build public-web-root/build      # public_path() is public-web-root
 *   DB_DATABASE=/tmp/s8.sqlite php artisan migrate --force
 *   # tag three products per concern, then:
 *   KBB_PUBLIC_PATH=$PWD/public-web-root SESSION_DRIVER=file \
 *     DB_DATABASE=/tmp/s8.sqlite APP_URL=http://127.0.0.1:8912 \
 *     php -S 127.0.0.1:8912 -t public-web-root tests/browser/preview-router.php &
 *   node tests/browser/lane-s8-concern-pages.mjs
 *
 * Run it from the repository root: playwright is resolved out of the checkout's
 * own node_modules, so a copy of this file in /tmp cannot import it.
 */
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = process.env.S8_BASE || 'http://127.0.0.1:8912';
const OUT = process.env.S8_OUT || 'docs/s8-seo-shots';
const SLUGS = ['hydration', 'dark-spots', 'acne', 'ageing', 'sensitivity', 'pores', 'dullness', 'sun'];

fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({
  executablePath: process.env.S8_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});

const rows = [];

for (const width of [390, 1280]) {
  const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 900 } });

  for (const slug of SLUGS) {
    const url = `${BASE}/concern/${slug}/`;
    const res = await page.goto(url, { waitUntil: 'networkidle' });
    const m = await page.evaluate(() => {
      const h1 = document.querySelector('h1');
      const intro = document.querySelector('.kbb-cat-intro, .kbb-collection-intro, main p');
      const cs = h1 ? getComputedStyle(h1) : null;
      const cards = document.querySelectorAll('.kbb-card, .kbb-product-card, article');
      const eyebrow = document.querySelector('.kbb-card-cat');
      return {
        status: 0,
        h1: h1 ? h1.textContent.trim() : '(none)',
        h1FontPx: cs ? cs.fontSize : '—',
        h1Height: h1 ? Math.round(h1.getBoundingClientRect().height) : 0,
        introWords: intro ? intro.textContent.trim().split(/\s+/).length : 0,
        introFontPx: intro ? getComputedStyle(intro).fontSize : '—',
        cards: cards.length,
        eyebrow: eyebrow ? eyebrow.textContent.trim() : '(none)',
        docScrollWidth: document.documentElement.scrollWidth,
        docClientWidth: document.documentElement.clientWidth,
      };
    });
    m.status = res.status();
    m.slug = slug;
    m.width = width;
    rows.push(m);

    // One full-page shot per concern at each width.
    await page.screenshot({ path: `${OUT}/concern-${slug}-${width}.png`, fullPage: width === 1280 ? false : true });
  }

  await page.close();
}

await browser.close();

const pad = (s, n) => String(s).padEnd(n);
console.log(pad('slug', 13) + pad('w', 6) + pad('code', 6) + pad('h1 font', 9) + pad('h1 h', 7)
  + pad('intro words', 13) + pad('intro font', 12) + pad('cards', 7) + pad('eyebrow', 24) + 'scrollW/clientW');
for (const r of rows) {
  console.log(pad(r.slug, 13) + pad(r.width, 6) + pad(r.status, 6) + pad(r.h1FontPx, 9) + pad(r.h1Height, 7)
    + pad(r.introWords, 13) + pad(r.introFontPx, 12) + pad(r.cards, 7) + pad(r.eyebrow, 24)
    + `${r.docScrollWidth}/${r.docClientWidth}`);
}
console.log('\nH1 text, per concern:');
for (const r of rows.filter(r => r.width === 1280)) console.log(`  ${pad(r.slug, 13)} ${r.h1}`);
const overflow = rows.filter(r => r.docScrollWidth > r.docClientWidth);
console.log('\nhorizontal overflow: ' + (overflow.length === 0 ? 'none at either width' : JSON.stringify(overflow)));
