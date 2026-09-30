/*
 * Lane PDP2 — the REAL product page, photographed and measured.
 *
 * The first round shot five DRAWINGS at /admin-api/catalog/pdp-preview/...; the
 * owner picked Ledger, so this one shoots /product/{slug}/ — the page the shop
 * actually serves, with its reviews section, its related grid and its footer
 * still on the end of it, which is the half the preview never had.
 *
 * WHAT IT PRODUCES, into docs/lane-pdp-shots/ (every file prefixed `real-`, so
 * the five drawings' own shots are never overwritten):
 *
 *   real-<case>-390.png / -1280.png     the whole page, both widths
 *   real-<case>-tabs-390.png            the tab strip close up, at 390
 *   real-<case>-tabs-open3-<w>.png      the same strip with the FOURTH tab
 *                                       opened by a real click, which is the
 *                                       only way to show that tapping one
 *                                       really opens it and that the row has
 *                                       scrolled
 *   real-type-<opt>-390.png             the mobile type options, top of page
 *   sheet-real-type-390.png             those options side by side  ← HE PICKS
 *   sheet-real-390.png / -1280.png      all six cases side by side
 *   ar-real-*.png                       the same, mirrored (PDP_AR=1)
 *   MEASUREMENTS-REAL.json              every number below
 *
 * THE ORDER MATTERS AND tools/pdp-shoot.sh's note says why: turning Arabic on
 * adds a language switcher to the header and an hreflang pair to <head> that the
 * English shop does not have today, so the English pass must run first, against
 * a freshly seeded fixture. storage/pdp-logs/ carries the runner this lane used.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, IN A BROWSER, NEVER BY A SCRIPT
 *   THE SHOP SERVES. CLAUDE.md rule 4 forbids JavaScript that measures layout
 *   in the product; measuring the product from outside is how the claim gets
 *   checked. Nothing this file injects reaches a package.
 *
 * ▲ THE TYPE OPTIONS ARE INJECTED BY THIS HARNESS, NOT SHIPPED. The page ships
 *   one answer — the recommended one — and the other two are a stylesheet this
 *   script adds with addStyleTag() so the owner can compare three pictures
 *   before anything is committed to. Each option's CSS is printed below exactly
 *   as it would be written into kbb-product.css, so his pick is a diff of the
 *   three lines under `.bb-title` and nothing else.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PDP_BASE || 'http://127.0.0.1:9211';
const OUT = process.env.PDP_OUT || path.join(__dirname, '..', 'docs', 'lane-pdp-shots');
const EXE = process.env.PDP_CHROME || '/opt/pw-browsers/chromium';
const TAG = process.env.PDP_TAG || 'real';
const AR = !!process.env.PDP_AR;

/* The fixture, and what each case is here to answer. tools/pdp-seed.php builds
   all of them. */
const CASES = [
  ['toner', 'pdp-heartleaf-toner', 'on sale, 4 shots, 3 bundle bars, 5 reviews, 5 tabs'],
  ['noreviews', 'pdp-sold-out-serum', 'no reviews at all — and sold out'],
  ['many', 'pdp-many-reviews-cream', '96 reviews'],
  ['longname', 'pdp-very-long-name-ampoule', 'a name that wraps however wide the screen is'],
  ['set', 'pdp-glow-ritual-set', 'a SET: no bundle bars, the contents panel instead'],
  ['variable', 'pdp-variable-ampoule', 'real VARIATIONS, the first one sold out'],
  ['unbreakable', 'pdp-unbreakable-name', 'a 56-character name with NO break opportunity in it'],
];

/*
 * ── STICKY AND fullPage DO NOT MIX ──────────────────────────────────────────
 *
 * Chromium's full-page capture resizes the viewport to the whole document, so
 * every `position:sticky` element resolves against a viewport as tall as the
 * page and is painted somewhere no reader ever sees it. On the shipped product
 * page that is the shop's own <header> and the gallery column, which at 1280 is
 * `position:sticky; top:84px` — left alone it paints itself two thousand pixels
 * down, over the reviews. The first round's docs/PDP-PRODUCT-PAGE-DESIGNS.md
 * records the same finding for the drawings.
 */
const FLATTEN = 'header,.head,.catbar,.pdp .gallery,.stickybar{position:static !important}';

/* The measurement. One pass, in the page, returning plain numbers. */
const M = () => {
  const box = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { w: Math.round(r.width), h: Math.round(r.height), y: Math.round(r.top + window.scrollY) };
  };
  const cs = (sel, prop) => {
    const el = document.querySelector(sel);
    return el ? getComputedStyle(el)[prop] : null;
  };
  /* How many LINE-BOXES a block of text really occupies, read from the element's
     own client rectangles rather than guessed from its height — the number the
     owner's complaint is actually about ("wraps to three lines"). */
  const lines = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = document.createRange();
    r.selectNodeContents(el);
    const tops = new Set([...r.getClientRects()].map((b) => Math.round(b.top)));
    return tops.size || null;
  };

  const row = document.querySelector('.dtabbar');

  return {
    dir: document.documentElement.getAttribute('dir') || 'ltr',
    viewport: document.documentElement.clientWidth,
    // The number CLAUDE.md asks for by name.
    scrollWidth: document.documentElement.scrollWidth,
    pageHeight: document.documentElement.scrollHeight,

    galleryFrame: box('.gmain'),
    galleryRadius: cs('.gmain', 'borderRadius'),
    galleryAspect: cs('.gmain', 'aspectRatio'),
    thumbs: document.querySelectorAll('.gthumb').length,
    thumbStrip: box('.gthumbs'),

    brand: box('.bb-brand'),
    title: box('.bb-title'),
    titleFont: cs('.bb-title', 'fontSize'),
    titleWeight: cs('.bb-title', 'fontWeight'),
    titleLineHeight: cs('.bb-title', 'lineHeight'),
    titleLines: lines('.bb-title'),

    price: box('.bb-price'),
    priceFont: cs('.bb-price .now', 'fontSize'),
    priceWeight: cs('.bb-price .now', 'fontWeight'),
    // "right side cut price and actual price": the price must be BESIDE the
    // title, not under it.
    priceBesideTitle: (() => {
      const t = document.querySelector('.bb-title');
      const m = document.querySelector('.bb-price');
      if (!t || !m) return null;
      return m.getBoundingClientRect().top < t.getBoundingClientRect().bottom;
    })(),

    ratingRow: box('.cap-area'),
    ratingBarFill: (() => {
      const i = document.querySelector('.bb-ratebar i');
      return i ? i.style.inlineSize : null;
    })(),
    capsulePill: (() => {
      const el = document.querySelector('.sr-capbar');
      if (!el) return null;
      const s = getComputedStyle(el);
      return { background: s.backgroundColor, border: s.borderTopWidth, radius: s.borderTopLeftRadius };
    })(),

    blurb: box('.bb-desc'),
    blurbLines: lines('.bb-desc'),
    blurbMasked: (cs('.bb-desc', 'maskImage') || cs('.bb-desc', 'webkitMaskImage') || 'none') !== 'none',
    readMore: box('.bb-more'),

    bundleBars: document.querySelectorAll('.variants .variant').length,
    setRows: document.querySelectorAll('.ksl-r').length,
    /* THE STRIP'S OWN THREE FACTS, for the variable product. `$buyable` is what
       decides which row is highlighted, and the defect it exists to answer is a
       product whose FIRST option is sold out rendering with NOTHING highlighted
       and an armed button pointing at the sold-out row. */
    optionRows: [...document.querySelectorAll('.variants .variant')].map((v) => ({
      on: v.classList.contains('on'),
      oos: v.classList.contains('oos'),
      label: (v.querySelector('.vn') || {}).textContent,
      vid: v.dataset.vid || null,
    })),
    armedVariationId: (document.getElementById('kbbVarId') || {}).value || null,

    stockline: box('.stockline'),
    addToCart: box('.addcart#mainAdd'),
    qty: box('.pdp .qty'),
    buyNow: box('.buynow'),

    // The tab strip: the shipped page hides it under 720px and draws an
    // accordion instead, which is not "these tabs in same row".
    tabs: document.querySelectorAll('.dtab').length,
    tabRow: box('.dtabbar'),
    tabRowVisible: cs('.dtabbar', 'display') !== 'none',
    accordionVisible: cs('.macc', 'display') !== 'none',
    tabRowScrolls: row ? row.scrollWidth > row.clientWidth + 1 : null,
    tabRowOverflowPx: row ? row.scrollWidth - row.clientWidth : null,
    tabOpacities: (() => {
      const t = [...document.querySelectorAll('.dtab')];
      return {
        open: t.filter((e) => e.classList.contains('on')).map((e) => getComputedStyle(e).opacity),
        closed: t.filter((e) => !e.classList.contains('on')).map((e) => getComputedStyle(e).opacity),
      };
    })(),
    openPanels: [...document.querySelectorAll('.dtabpanel')].filter((p) => getComputedStyle(p).display !== 'none').length,

    trust: box('.trust'),
    trustBackground: cs('.trust', 'backgroundColor'),
    trustItems: document.querySelectorAll('.trust .ti').length,
    payChips: document.querySelectorAll('.paychips span').length,

    /* ── AND THE THREE THINGS HE SAID MUST NOT DISAPPEAR ──────────────────
       "don't end the page, this design + existing reviews section, and
        related products section and then footer." Each is asked for by a
       marker only that section draws, and by its position down the page, so
       "it is still there" and "it is still AFTER the buy column" are two
       different numbers rather than one claim. */
    fbtY: box('.fbt') ? box('.fbt').y : null,
    reviewsY: box('#sr') ? box('#sr').y : null,
    reviewCards: document.querySelectorAll('#sr .sr-card, #sr .rev').length,
    relatedY: box('#related') ? box('#related').y : null,
    relatedCards: document.querySelectorAll('#related > *').length,
    footerY: box('footer') ? box('footer').y : null,
  };
};

/*
 * ── THE THREE MOBILE TYPE OPTIONS ───────────────────────────────────────────
 *
 * The owner, verbatim: "in mobile you have used big bold font, whichi dont'
 * want." Measured on the Ledger preview at 390: 21px at weight 600 over three
 * line-boxes, 82px tall, beside a 22px/800 price — two heavy objects in one
 * row, and the title is the loudest thing on the phone.
 *
 * Option 1 is what the page SHIPS; the other two are drawn by this harness on
 * top of the shipped page so the three can be compared as pictures. Each block
 * is exactly what would be written into kbb-product.css if he picks it.
 */
const TYPE_OPTIONS = [
  [
    'a',
    'A · Weight only — 21px / 500',
    `@media (max-width:880px){
       .pdp .bb-title{font-size:21px;font-weight:500;line-height:1.34}
     }`,
  ],
  ['b', 'B · Weight and one step down — 19px / 500  (SHIPPED)', ''],
  [
    'c',
    'C · Price leads — 17px / 500, price 23px',
    `@media (max-width:880px){
       .pdp .bb-title{font-size:17px;font-weight:500;line-height:1.42;color:var(--ink-2)}
       .pdp .bb-head .bb-price .now{font-size:23px;font-weight:700}
     }`,
  ],
];

const settle = async (page) => {
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts && document.fonts.ready);
  await page.waitForTimeout(240);
};

const sheet = async (browser, file, title, note, files, colWidth) => {
  /* THE PNGs GO IN AS DATA URIs, NOT AS file:// SRCs — a page built with
     setContent() has an about:blank base and a file:// image is a cross-origin
     subresource the browser declines to load. The first round produced five
     sheets of empty boxes exactly that way. */
  const src = (f) => 'data:image/png;base64,' + fs.readFileSync(f).toString('base64');
  const html = `<!doctype html><meta charset="utf-8"><style>
      body{margin:0;background:#f4f1f2;font:13px/1.45 system-ui,sans-serif;color:#2A2228;padding:22px}
      h1{font-size:17px;margin:0 0 4px}
      p.note{margin:0 0 18px;color:#5E545A;font-size:12px;max-width:1100px}
      .row{display:flex;gap:16px;align-items:flex-start}
      .c{flex:0 0 ${colWidth}px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.09)}
      .c h2{font-size:12.5px;margin:0;padding:9px 11px;background:#2A2228;color:#fff;font-weight:700}
      .c .m{font-size:11.5px;padding:8px 11px;color:#5E545A;border-top:1px solid #eee;white-space:pre-line}
      .c img{display:block;width:100%}
    </style><h1>${title}</h1><p class="note">${note}</p>
    <div class="row">${files
      .map(([label, f, m]) => `<div class="c"><h2>${label}</h2><img src="${src(f)}">${m ? `<div class="m">${m}</div>` : ''}</div>`)
      .join('')}</div>`;

  const ctx = await browser.newContext({ viewport: { width: colWidth * files.length + 60, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.setContent(html, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(OUT, file), fullPage: true });
  await ctx.close();
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const measured = {};

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();

    for (const [key, slug] of CASES) {
      const url = `${BASE}/product/${slug}/` + (AR ? '?kbb_lang=ar' : '');
      await page.goto(AR ? `${BASE}/ar/product/${slug}/` : url, { waitUntil: 'networkidle' });
      await settle(page);
      await page.addStyleTag({ content: FLATTEN });
      await page.waitForTimeout(160);
      measured[`${TAG}-${key}-${width}`] = await page.evaluate(M);
      await page.screenshot({ path: path.join(OUT, `${TAG}-${key}-${width}.png`), fullPage: true });

      /* The tab strip, close up, clipped to the FULL WIDTH OF THE SCREEN rather
         than to the element's own box: the row bleeds past `.wrap` on a phone,
         so an element screenshot slices the first characters off the open tab
         and makes the edge mask look like a rendering fault. */
      /* GUARDED ON THE ROW BEING VISIBLE, and the guard is the BEFORE state
         rather than defensive coding: the shipped page sets `.dtabbar{display:none}`
         under 720px and draws the accordion instead, so at 390 there is a strip in
         the DOM that no shopper can click. Playwright timed out on it, which is the
         same finding stated as a stack trace. */
      /* THE CLIP IS IN PAGE COORDINATES AND boundingBox() IS NOT.
         Playwright measures from the VIEWPORT, so the two agree only while the
         page is scrolled to the top -- and clicking a tab scrolls it into view.
         The second strip shot therefore came back as a picture of the GALLERY,
         which is a shot that looks like a shot and shows the wrong element.
         Asked in the page instead, where `scrollY` can be added. */
      const stripRect = () =>
        page.evaluate(() => {
          const el = document.querySelector('.dtabbar');
          if (!el) return null;
          const r = el.getBoundingClientRect();
          return { y: Math.round(r.top + window.scrollY), h: Math.round(r.height) };
        });

      const strip = await page.$('.dtabbar');
      const stripVisible = strip ? await strip.isVisible() : false;
      if (strip && stripVisible && key === 'toner') {
        const b = await stripRect();
        if (b) {
          await page.screenshot({
            path: path.join(OUT, `${TAG}-${key}-tabs-${width}.png`),
            fullPage: true,
            clip: { x: 0, y: Math.max(0, b.y - 10), width, height: Math.min(b.h + 260, 900) },
          });
        }
        /* AND THE FOURTH TAB OPENED BY A REAL CLICK — the proof of "can
           directly click on any tab, it will open". */
        const fourth = (await page.$$('.dtab'))[3];
        if (fourth) {
          await fourth.click();
          await page.waitForTimeout(280);
          const b2 = await stripRect();
          if (b2) {
            await page.screenshot({
              path: path.join(OUT, `${TAG}-${key}-tabs-open3-${width}.png`),
              fullPage: true,
              clip: { x: 0, y: Math.max(0, b2.y - 10), width, height: Math.min(b2.h + 260, 900) },
            });
          }
          measured[`${TAG}-${key}-${width}-open3`] = await page.evaluate(() => {
            const panels = [...document.querySelectorAll('.dtabpanel')];
            return {
              openTabIndex: [...document.querySelectorAll('.dtab')].findIndex((t) => t.classList.contains('on')),
              visiblePanels: panels.filter((p) => getComputedStyle(p).display !== 'none').length,
              visiblePanelIndex: panels.findIndex((p) => getComputedStyle(p).display !== 'none'),
            };
          });
        }
      }
    }

    await ctx.close();
  }

  /* ── THE MOBILE TYPE OPTIONS, at 390, top of the page only ──────────────── */
  if (!AR) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    const captions = [];

    for (const [key, label, css] of TYPE_OPTIONS) {
      await page.goto(`${BASE}/product/pdp-heartleaf-toner/`, { waitUntil: 'networkidle' });
      await settle(page);
      await page.addStyleTag({ content: FLATTEN });
      if (css) await page.addStyleTag({ content: css });
      await page.waitForTimeout(200);
      const m = await page.evaluate(M);
      measured[`type-${key}-390`] = m;

      /* From the top of the page down past the buy row, so the title is seen
         against the price, the rating and the picture — the comparison he is
         actually making. */
      const stop = await page.evaluate(() => {
        const el = document.querySelector('.stockline');
        return el ? Math.round(el.getBoundingClientRect().top + window.scrollY) : 1400;
      });
      await page.screenshot({
        path: path.join(OUT, `${TAG}-type-${key}-390.png`),
        fullPage: true,
        clip: { x: 0, y: 0, width: 390, height: Math.min(stop + 40, 2200) },
      });

      captions.push([
        label,
        path.join(OUT, `${TAG}-type-${key}-390.png`),
        `title ${m.titleFont} / ${m.titleWeight} / ${m.titleLines} line${m.titleLines === 1 ? '' : 's'} / ${m.title.h}px tall\n`
          + `price ${m.priceFont} / ${m.priceWeight}\n`
          + `brand→blurb block ${m.blurb ? m.blurb.y - m.brand.y : '—'}px`,
      ]);
    }

    await ctx.close();
    await sheet(
      browser,
      'sheet-real-type-390.png',
      'The mobile type — three options at 390px',
      'The same product page, the same moment, one scale. B is what the branch ships; A and C are one CSS block each. '
        + 'The numbers under each are measured in Chromium, not asserted.',
      captions,
      330
    );

    await sheet(
      browser,
      'sheet-real-390.png',
      'The real product page — 390px',
      'Ledger applied to /product/{slug}/, with the reviews section, the related grid and the footer still on the end of it.',
      CASES.map(([k, , why]) => [`${k} — ${why}`, path.join(OUT, `${TAG}-${k}-390.png`)]),
      300
    );
    await sheet(
      browser,
      'sheet-real-1280.png',
      'The real product page — 1280px',
      'The desktop he approved from a preview, on real data.',
      CASES.map(([k, , why]) => [`${k} — ${why}`, path.join(OUT, `${TAG}-${k}-1280.png`)]),
      420
    );
  }

  /* MERGED, NOT OVERWRITTEN — the Arabic pass is a second run of this file and
     would otherwise throw away every English number the first run measured. */
  const file = path.join(OUT, 'MEASUREMENTS-REAL.json');
  const prior = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
  fs.writeFileSync(file, JSON.stringify({ ...prior, ...measured }, null, 2));
  await browser.close();

  for (const [k] of CASES) {
    for (const w of [390, 1280]) {
      const m = measured[`${TAG}-${k}-${w}`];
      if (!m) continue;
      console.log(
        `${(TAG + '-' + k).padEnd(16)} ${String(w).padEnd(5)} scrollW=${m.scrollWidth}/${m.viewport} h=${m.pageHeight} `
          + `title=${m.titleFont}/${m.titleWeight}/${m.titleLines}L/${m.title && m.title.h}px price=${m.priceFont} beside=${m.priceBesideTitle} `
          + `tabs=${m.tabs} row=${m.tabRowVisible} acc=${m.accordionVisible} over=${m.tabRowOverflowPx} `
          + `bundles=${m.bundleBars} set=${m.setRows} revY=${m.reviewsY} relY=${m.relatedY} footY=${m.footerY}`
      );
    }
  }
})();
