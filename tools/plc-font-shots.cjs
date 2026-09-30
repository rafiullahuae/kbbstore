/*
 * Lane PLC — the typeface swap, measured and photographed on five pages.
 *
 *   ./tools/plc-preview.sh 8981
 *   node tools/plc-font-shots.cjs 8981 before|after
 *
 * ── IT USES tools/font-probe.cjs AND DOES NOT ASK document.fonts ───────────
 *
 * Lane BG established four separate ways a font measurement lies, and the
 * module holds the three mechanics that stop them: a ruler set in ONE family
 * with a second ruler in a family that cannot exist (so "the system font's
 * widths" cannot be mistaken for the real one), every weight requested before
 * any is measured (so `display:swap` does not report a fallback), and
 * `document.fonts.check()` not used at all, because it answers true for
 * families that do not exist.
 *
 * WHAT IS MEASURED PER PAGE: the rendered width of a fixed ruler string at each
 * of the five served weights, the control width, whether each weight really
 * rendered, and `document.documentElement.scrollWidth` against `clientWidth` —
 * a typeface with different metrics is exactly the kind of change that puts a
 * page into horizontal scroll.
 *
 * AND THE HEIGHTS OF THINGS THAT ARE PINNED TO EACH OTHER. Every product card
 * in a row shares a height; the header has one; buttons and cart rows have
 * theirs. Those are where a metric change shows up as a broken layout rather
 * than as slightly different text, so they are read on both runs and compared.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { probeFamily, declaredFaces, CONTROL_FAMILY, RULER, RULER_PX } = require('./font-probe.cjs');

const PORT = process.argv[2] || '8981';
const LABEL = (process.argv[3] || 'after').replace(/[^a-z]/g, '') || 'after';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'PLC-font-shots');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const FAMILY = process.env.KBB_FAMILY || 'Outfit';
const WEIGHTS = [400, 500, 600, 700, 800];

const VIEWPORTS = [
  { tag: '390', w: 390, h: 844 },
  { tag: '1280', w: 1280, h: 900 },
];

const rows = [];

/** The boxes a metric change breaks rather than merely re-inks. */
async function layout(page) {
  return page.evaluate(() => {
    const box = (sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: Math.round(r.width * 100) / 100, h: Math.round(r.height * 100) / 100 };
    };
    const all = (sel) => [...document.querySelectorAll(sel)]
      .map((el) => Math.round(el.getBoundingClientRect().height * 100) / 100);

    const cards = all('.kbb-card, .pcard, article.card');

    /*
     * THE BOXES A METRIC CHANGE BREAKS, named one at a time rather than
     * sampled. A narrower face can turn a two-line heading into one line, and
     * that is a HEIGHT change on a page the owner did not ask to change — so
     * the headings, the buttons, the cart rows and the checkout fields are
     * read individually and compared, not just the grid.
     */
    const named = {};
    for (const [key, sel] of [
      ['h1', 'h1'],
      ['lead', '.lead, .sub, .pagesub'],
      ['navRow', 'nav, .mm, .kbb-nav'],
      ['cartRow', '.crow, .cart-row, .citem'],
      ['orderBox', '.osum, .order-summary, .cpg-box'],
      ['payBtn', '.cta, .checkout-btn, .cpg-cta'],
      ['field', 'input[type=text], input[type=email]'],
      ['footer', 'footer, .kbb-footer'],
    ]) {
      const el = document.querySelector(sel);
      named[key] = el ? Math.round(el.getBoundingClientRect().height * 100) / 100 : null;
    }

    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      header: box('header, .kbb-header, .mh'),
      cards: cards.length,
      cardHeights: [...new Set(cards)].slice(0, 6),
      cardSpread: cards.length ? Math.round((Math.max(...cards) - Math.min(...cards)) * 100) / 100 : null,
      firstButton: box('button, .btn, .kbb-btn'),
      named,
      /* The whole document's height: the single number that says whether the
         page as a whole got taller or shorter, which is what a shopper sees. */
      docHeight: Math.round(document.documentElement.scrollHeight * 100) / 100,
      bodyFont: getComputedStyle(document.body).fontFamily,
    };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME });

  const pages = [
    ['home', '/'],
    ['shop', '/shop/'],
    ['product', '/product/plc-glass-skin-serum/'],
    ['cart', '/cart/'],
    ['checkout', '/checkout/'],
    ['arabic-home', '/ar/'],
  ];

  for (const vp of VIEWPORTS) {
    for (const [name, url] of pages) {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await ctx.newPage();

      try {
        /*
         * ▲ THE CART AND THE CHECKOUT NEED A BASKET, AND THE FIRST RUN OF THIS
         * SCRIPT DID NOT GIVE THEM ONE.
         *
         * Both pages answered with the empty-bag state, so `field` and the
         * order summary read null and the comparison said "nothing moved" about
         * a form it had never rendered — the checkout is the page a reflow
         * would hurt most and it was the one page not being measured.
         *
         * The bag is filled by pressing the shop's own #mainAdd, not by POSTing
         * an id: the preview seeds its own catalogue, so a hardcoded id is
         * somebody else's product (and id 1 is out of stock).
         */
        if (name === 'cart' || name === 'checkout') {
          await page.goto(BASE + '/product/plc-glass-skin-serum/', { waitUntil: 'domcontentloaded' });
          await page.click('#mainAdd').catch(() => {});
          await page.waitForTimeout(700);
        }

        await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(400);

        const probe = await probeFamily(page, FAMILY, WEIGHTS);
        const cairo = name.startsWith('arabic') ? await probeFamily(page, 'Cairo', [400, 700]) : null;
        const faces = await declaredFaces(page);
        const box = await layout(page);

        rows.push({ run: LABEL, page: name, width: vp.tag, family: FAMILY, probe, cairo, faces: faces.size, ...box });

        console.log(
          `${LABEL.padEnd(6)} ${name.padEnd(12)} ${vp.tag.padStart(4)}  sw=${box.scrollWidth}/${box.clientWidth}` +
          `  cards=${box.cards} spread=${box.cardSpread}` +
          `  w400=${probe.widths[400]} w700=${probe.widths[700]} ctrl=${probe.control[400]}` +
          `  rendered=${WEIGHTS.map((w) => (probe.rendered[w] ? 1 : 0)).join('')}`
        );

        await page.screenshot({ path: path.join(OUT, `plc-${LABEL}-${name}-${vp.tag}.png`), fullPage: false, timeout: 20000 })
          .catch(() => console.log(`  (no shot for ${name} ${vp.tag})`));
      } catch (e) {
        console.log(`  !! ${name} ${vp.tag}: ${e.message.split('\n')[0]}`);
      }

      await ctx.close();
    }
  }

  await browser.close();

  const file = path.join(OUT, `plc-font-${LABEL}.json`);
  fs.writeFileSync(file, JSON.stringify(rows, null, 2));
  console.log('wrote ' + file);
})();
