/*
 * Lane PDP — five product-page designs, photographed and measured.
 *
 * WHAT IT PRODUCES, into docs/lane-pdp-shots/:
 *
 *   <cand>-390.png / <cand>-1280.png      the whole page, both widths
 *   <cand>-tabs-390.png / -1280.png       the tab strip, close up, open
 *   <cand>-tabs-open3-390.png             the same strip with the FOURTH tab
 *                                         open, which is the only way to show
 *                                         that tapping one really opens it and
 *                                         that the row has scrolled
 *   <cand>-set-390.png                    the same design on a SET, where the
 *                                         bundle bars are replaced by the set
 *                                         contents panel
 *   <cand>-soldout-390.png                and on a sold-out product
 *   sheet-390.png / sheet-1280.png        THE TWO CONTACT SHEETS — all five side
 *                                         by side, which is what he looks at
 *   sheet-tabs.png                        the five tab ideas side by side
 *   ar-<cand>-390.png                     the strongest one in Arabic
 *   MEASUREMENTS.json                     every number below
 *
 * ▲ EVERY RECTANGLE IN HERE IS READ BY THE HARNESS, IN A BROWSER, NEVER BY A
 *   SCRIPT THE SHOP SERVES. CLAUDE.md forbids JavaScript that MEASURES LAYOUT
 *   in the product; measuring the product from outside is how the claim gets
 *   checked. The five designs themselves contain no JavaScript at all — the tab
 *   switch is a radio group and the read-more is a checkbox — which is why the
 *   "open the fourth tab" shot below is done by CLICKING A LABEL rather than by
 *   calling anything.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PDP_BASE || 'http://127.0.0.1:8987';
const OUT = process.env.PDP_OUT || path.join(__dirname, '..', 'docs', 'lane-pdp-shots');
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const PREVIEW = '/admin-api/catalog/pdp-preview';
const PRODUCT = 'pdp-heartleaf-toner';
const SET = 'pdp-glow-ritual-set';
const SOLDOUT = 'pdp-sold-out-serum';

/* The Arabic pass is a SECOND run of this same file, after
   tools/pdp-arabic-on.php has flipped the fixture -- see tools/pdp-shoot.sh for
   why the order is not negotiable. It must not re-take the English shots,
   because by then the header carries a language switcher and the <head> carries
   an hreflang pair that the English shop does not have today. */
const AR = !!process.env.PDP_AR;

const CANDS = [
  ['ledger', 'A · Ledger'],
  ['dossier', 'B · Dossier'],
  ['counter', 'C · Counter'],
  ['deck', 'D · Deck'],
  ['marquee', 'E · Marquee'],
];

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

  const gal = document.querySelector('.gmain');
  const img = document.querySelector('.gmain-img');
  let fill = null;
  if (gal && img && img.naturalWidth) {
    const f = gal.getBoundingClientRect();
    const s = Math.min(f.width / img.naturalWidth, f.height / img.naturalHeight);
    fill = Math.round(((img.naturalWidth * s * img.naturalHeight * s) / (f.width * f.height)) * 100);
  }

  const row = document.querySelector('.pv-tabrow') || document.querySelector('.pv-deck');

  return {
    dir: document.documentElement.getAttribute('dir') || 'ltr',
    viewport: document.documentElement.clientWidth,
    // The number CLAUDE.md asks for by name: equal to the viewport means no
    // horizontal page scroll anywhere in the design.
    scrollWidth: document.documentElement.scrollWidth,
    pageHeight: document.documentElement.scrollHeight,

    galleryFrame: box('.gmain'),
    galleryAspect: cs('.gmain', 'aspectRatio'),
    galleryFillPct: fill,
    thumbs: document.querySelectorAll('.gthumb').length,
    thumbStrip: box('.gthumbs'),

    brandY: box('.pv-brand') ? box('.pv-brand').y : null,
    title: box('.pv-title'),
    titleFont: cs('.pv-title', 'fontSize'),
    money: box('.pv-money'),
    moneyFont: cs('.pv-money b', 'fontSize'),
    // "right side cut price": on a phone the price box's inline-end must reach
    // the same edge the title's row does, and it must NOT be under the title.
    priceBesideTitle: (() => {
      const t = document.querySelector('.pv-title');
      const m = document.querySelector('.pv-head .pv-money');
      if (!t || !m) return null;
      const a = t.getBoundingClientRect();
      const b = m.getBoundingClientRect();
      return b.top < a.bottom;
    })(),

    ratingBar: box('.pv-rate'),
    ratingFillPct: (() => {
      const i = document.querySelector('.pv-ratebar i');
      return i ? i.style.inlineSize : null;
    })(),

    blurb: box('.pv-blurb'),
    blurbLineHeight: cs('.pv-blurb', 'lineHeight'),
    blurbMasked: (cs('.pv-blurb', 'maskImage') || cs('.pv-blurb', 'webkitMaskImage') || 'none') !== 'none',
    readMore: box('.pv-more'),

    bundleBars: document.querySelectorAll('.pv-variants .variant').length,
    setRows: document.querySelectorAll('.ksl-r').length,

    addToCart: box('.pv-add'),
    qty: box('.pv-qty'),

    tabs: document.querySelectorAll('.pv-tab, .pv-card').length,
    tabRow: box('.pv-tabrow') || box('.pv-deck'),
    // Does the strip actually overflow? If it does not, "scroll left to right"
    // has not been demonstrated and the fixture needs more tabs.
    tabRowScrolls: row ? row.scrollWidth > row.clientWidth + 1 : null,
    tabRowOverflowPx: row ? row.scrollWidth - row.clientWidth : null,
    openTabOpacity: (() => {
      const open = document.querySelector('.pv-tabin:checked');
      if (!open) return null;
      const i = Number(open.id.replace('pvtab', ''));
      const labels = document.querySelectorAll('.pv-tab, .pv-card');
      const others = [...labels].filter((_, k) => k !== i).map((el) => getComputedStyle(el).opacity);
      return { open: labels[i] ? getComputedStyle(labels[i]).opacity : null, others };
    })(),
    openPanels: [...document.querySelectorAll('.pv-panel')].filter((p) => getComputedStyle(p).display !== 'none').length,

    assurances: document.querySelectorAll('.pv-as').length,
    payChips: document.querySelectorAll('.pv-pay span').length,

    // Nothing in a candidate may carry a script tag of its own.
    inlineScriptsInPv: document.querySelectorAll('.pv script').length,
  };
};

const signIn = async (page) => {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
};

/*
 * ── STICKY AND fullPage DO NOT MIX, AND THE FIRST SHEET SHOWED IT ───────────
 *
 * Chromium's full-page capture resizes the viewport to the whole document, so
 * every `position:sticky` element resolves against a viewport as tall as the
 * page and is painted somewhere it never appears to a reader. Candidate D's buy
 * dock came out ON TOP OF THE PRODUCT TITLE in the first contact sheet, and
 * candidate B's card and C's price rail were displaced the same way at 1280.
 *
 * So each design is photographed TWICE and the two shots answer two different
 * questions:
 *
 *   · the FLOW shot (fullPage, sticky neutralised) — "what is on this page, in
 *     what order, at what size". This is the one that goes on the contact
 *     sheet, and it is the only way five designs can be compared at all.
 *   · the STICKY shot (one viewport, nothing neutralised, scrolled to the buy
 *     block) — "and what follows you down it". D's dock, B's pinned pill row
 *     and card, C's price rail and E's desktop tab rail are only real here.
 *
 * The override is injected by the HARNESS and is not in the page: nothing in
 * resources/views/store/pdp-preview/ knows this script exists.
 */
/* `.head` IS ON THE LIST TOO, and it is not this lane's element: the shop's own
   header is `position:sticky; top:0`, so in a full-page capture it is painted
   partway down the document — over the product title in candidates A, D and E,
   measured. A contact sheet where one design appears to have its header in the
   middle of the page is a contact sheet that is lying about that design. */
/* ▲ ONLY THE TWO ROWS THAT ARE REALLY STICKY, NOT `.pv .pv-tabrow`.
   The broad selector was `position:static !important` on EVERY tab row, and the
   base rule gives every row `position:relative` for a reason: candidate C's
   sliding fill is absolutely positioned inside it. Flattened, the fill's
   containing block became `.pv-tabs` and it painted a 270px white rectangle
   down over the panel — measured in counter-tabs-1280.png, and it looked
   exactly like a rendering fault in the design rather than in the camera. */
const FLATTEN = '.pv .pv-gal,.pv .pv-card-buy,.pv .pv-band,.pv .pv-buygroup,.pv .pv-dock,'
  + '.pv-tabs-pill .pv-tabrow,.pv-e .pv-tabs-band .pv-tabrow,.head{position:static !important}';

const settle = async (page) => {
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts && document.fonts.ready);
  await page.waitForTimeout(240);
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const measured = {};

  for (const width of (AR ? [390] : [390, 1280])) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    await signIn(page);

    for (const [key] of (AR ? [] : CANDS)) {
      await page.goto(`${BASE}${PREVIEW}/${key}/${PRODUCT}`, { waitUntil: 'networkidle' });
      await settle(page);

      /* THE STICKY SHOT FIRST, while nothing has been neutralised: one viewport,
         scrolled to the buy block, which is where each design's "what follows
         you" either happens or does not. */
      /* scrollTo with behavior:'instant', NOT scrollIntoView. kbb.css sets
         `scroll-behavior:smooth` on the root, so scrollIntoView animates and a
         screenshot taken a quarter of a second later catches the page still at
         the top — which is exactly what the first run of this produced: five
         "scrolled to the buy block" shots of the top of the page. */
      const scrolledTo = await page.evaluate(() => {
        const el = document.querySelector('.pv-optlabel') || document.querySelector('.pv-variants');
        if (!el) return null;
        const y = Math.max(0, el.getBoundingClientRect().top + window.scrollY - 70);
        window.scrollTo({ top: y, behavior: 'instant' });
        return Math.round(window.scrollY);
      });
      await page.waitForTimeout(280);
      await page.screenshot({ path: path.join(OUT, `${key}-sticky-${width}.png`) });
      measured[`${key}-${width}-sticky`] = await page.evaluate(() => {
        const b = document.querySelector('.pv-add');
        if (!b) return null;
        const r = b.getBoundingClientRect();
        // In the viewport at this scroll position, or not? That is the whole
        // claim D's dock makes and the only one worth a number.
        return { topInViewport: Math.round(r.top), onScreen: r.top >= 0 && r.bottom <= window.innerHeight };
      });
      if (measured[`${key}-${width}-sticky`]) measured[`${key}-${width}-sticky`].scrolledTo = scrolledTo;

      await page.evaluate(() => window.scrollTo(0, 0));
      await page.addStyleTag({ content: FLATTEN });
      await page.waitForTimeout(160);
      measured[`${key}-${width}`] = await page.evaluate(M);
      await page.screenshot({ path: path.join(OUT, `${key}-${width}.png`), fullPage: true });

      /* The tab strip, close up, with the first tab open. `scrollIntoView` is
         navigation, not measurement — the shot has to contain the strip. */
      /* THE FULL WIDTH OF THE SCREEN, NOT THE ELEMENT'S OWN BOX.
         Candidate E's band is full-bleed by a negative inline margin, so its
         painted rectangle is WIDER than `.pv-tabs` — an element screenshot
         sliced the first characters off "Description" and made the one
         full-bleed idea in the round look like a rendering fault. Clipping to
         x=0 and the viewport width shows every candidate's strip as the phone
         shows it. */
      const shotStrip = async (file) => {
        const el = await page.$('.pv-tabs');
        if (!el) return;
        const b = await el.boundingBox();
        if (!b) return;
        await page.screenshot({
          path: path.join(OUT, file),
          clip: { x: 0, y: Math.max(0, b.y - 8), width, height: Math.min(b.height + 16, 1400) },
        });
      };

      const strip = await page.$('.pv-tabs');
      if (strip) {
        await strip.scrollIntoViewIfNeeded();
        await page.waitForTimeout(160);
        await shotStrip(`${key}-tabs-${width}.png`);

        /* AND THE SAME STRIP WITH THE FOURTH TAB OPENED BY A CLICK. This is the
           proof of "can directly click on any tab, it will open": a real click
           on a real label, with no script in the page to receive it. */
        const label = await page.$('label[for="pvtab3"]');
        if (label) {
          await label.click();
          await page.waitForTimeout(320);
          await shotStrip(`${key}-tabs-open3-${width}.png`);
          measured[`${key}-${width}-open3`] = await page.evaluate(() => {
            const open = document.querySelector('.pv-tabin:checked');
            const panels = [...document.querySelectorAll('.pv-panel')];
            return {
              checked: open ? open.id : null,
              visiblePanels: panels.filter((p) => getComputedStyle(p).display !== 'none').length,
              visiblePanelIndex: panels.findIndex((p) => getComputedStyle(p).display !== 'none'),
            };
          });
        }
      }
    }

    /* The set and the sold-out product, phone only — they are there to show what
       each design does with a contents panel instead of bundle bars and with a
       dead button, not to be compared five ways at two widths. */
    if (width === 390) {
      for (const [key] of (AR ? [] : CANDS)) {
        for (const [slug, tag] of [[SET, 'set'], [SOLDOUT, 'soldout']]) {
          await page.goto(`${BASE}${PREVIEW}/${key}/${slug}`, { waitUntil: 'networkidle' });
          await settle(page);
          await page.addStyleTag({ content: FLATTEN });
          measured[`${key}-${tag}-390`] = await page.evaluate(M);
          await page.screenshot({ path: path.join(OUT, `${key}-${tag}-390.png`), fullPage: true });
        }
      }

      /* ARABIC, on the three whose strips are most direction-sensitive: the one
         with a MASK on its edge (A — masks have no logical form and carry the
         sheet's only [dir] rule), the one whose fill SLIDES (C — inset-inline-
         start), and the one with the proportional hairline (E — the same). If
         the row does not scroll the other way, the bar does not fill from the
         right and the fill does not sit on the right, the logical properties
         are not doing what this lane claims they do.

         `?lang=ar` AND NOT `/ar/...`: App\Support\Locale's own header says the
         admin is deliberately not localised, so /ar in front of an admin-api
         route is a 404 and correctly so. Run tools/pdp-arabic-on.php against
         the preview database first — tools/pdp-shoot.sh does both in order. */
      if (AR) {
        for (const key of ['ledger', 'counter', 'marquee']) {
          await page.goto(`${BASE}${PREVIEW}/${key}/${PRODUCT}?lang=ar`, { waitUntil: 'networkidle' });
          await settle(page);
          await page.addStyleTag({ content: FLATTEN });
          measured[`ar-${key}-390`] = await page.evaluate(M);
          await page.screenshot({ path: path.join(OUT, `ar-${key}-390.png`), fullPage: true });

          const arStrip = await page.$('.pv-tabs');
          if (arStrip) {
            await arStrip.scrollIntoViewIfNeeded();
            await page.waitForTimeout(160);
            const b = await arStrip.boundingBox();
            if (b) {
              await page.screenshot({
                path: path.join(OUT, `ar-${key}-tabs-390.png`),
                clip: { x: 0, y: Math.max(0, b.y - 8), width, height: Math.min(b.height + 16, 1400) },
              });
            }
          }
        }
      }
    }

    await ctx.close();
  }

  /* ── THE CONTACT SHEETS ────────────────────────────────────────────────────
   *
   * Built as an HTML page referencing the PNGs off disk and photographed, so
   * this needs no ImageMagick and the labels are real text rather than
   * something drawn. THIS is the artefact he actually looks at: five whole
   * pages side by side, at one scale, at one width.
   */
  const sheet = async (file, title, files, colWidth) => {
    /* THE PNGs GO IN AS DATA URIs, NOT AS file:// SRCs. A page built with
       setContent() has an about:blank base, so a file:// image is a cross-origin
       subresource the browser declines to load — the first run of this produced
       five 900px-tall sheets of empty boxes, which is a sheet that looks like a
       sheet and shows nothing. Read off disk and inlined, they cannot fail to
       be there by the time fullPage measures the document. */
    const src = (f) => 'data:image/png;base64,' + fs.readFileSync(f).toString('base64');

    const html = `<!doctype html><meta charset="utf-8"><style>
      body{margin:0;background:#f4f1f2;font:13px/1.4 system-ui,sans-serif;color:#2A2228;padding:22px}
      h1{font-size:17px;margin:0 0 4px}
      p{margin:0 0 18px;color:#5E545A;font-size:12px}
      .row{display:flex;gap:16px;align-items:flex-start}
      .c{flex:0 0 ${colWidth}px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.09)}
      .c h2{font-size:12.5px;margin:0;padding:9px 11px;background:#2A2228;color:#fff;font-weight:700}
      .c img{display:block;width:100%}
    </style><h1>${title}</h1><p>Lane PDP · five product-page designs · the same product, the same moment, one scale.</p>
    <div class="row">${files
      .map(([label, f]) => `<div class="c"><h2>${label}</h2><img src="${src(f)}"></div>`)
      .join('')}</div>`;

    const ctx = await browser.newContext({ viewport: { width: colWidth * files.length + 60, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.setContent(html, { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(OUT, file), fullPage: true });
    await ctx.close();
  };

  if (!AR) {
  await sheet('sheet-390.png', 'Mobile — 390px', CANDS.map(([k, n]) => [n, path.join(OUT, `${k}-390.png`)]), 300);
  await sheet('sheet-1280.png', 'Desktop — 1280px', CANDS.map(([k, n]) => [n, path.join(OUT, `${k}-1280.png`)]), 420);
  await sheet('sheet-tabs.png', 'The tabs — five ideas, at 390px', CANDS.map(([k, n]) => [n, path.join(OUT, `${k}-tabs-390.png`)]), 330);
  await sheet('sheet-tabs-1280.png', 'The tabs — five ideas, at 1280px', CANDS.map(([k, n]) => [n, path.join(OUT, `${k}-tabs-1280.png`)]), 460);
  await sheet('sheet-sticky-390.png', 'What follows you — 390px, scrolled to the buy block',
    CANDS.map(([k, n]) => [n, path.join(OUT, `${k}-sticky-390.png`)]), 300);
  await sheet('sheet-sticky-1280.png', 'What follows you — 1280px, scrolled to the buy block',
    CANDS.map(([k, n]) => [n, path.join(OUT, `${k}-sticky-1280.png`)]), 420);
  await sheet('sheet-set-390.png', 'On a SET — 390px (no bundle bars; the contents panel instead)', CANDS.map(([k, n]) => [n, path.join(OUT, `${k}-set-390.png`)]), 300);
  } else {
    await sheet('sheet-ar-390.png', 'Arabic — 390px (the same three, mirrored)',
      ['ledger', 'counter', 'marquee'].map((k) => [k, path.join(OUT, `ar-${k}-390.png`)]), 300);
  }

  /* MERGED, NOT OVERWRITTEN. The Arabic pass is a second run of this file and
     would otherwise throw away every English number the first run measured. */
  const file = path.join(OUT, 'MEASUREMENTS.json');
  const prior = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
  fs.writeFileSync(file, JSON.stringify({ ...prior, ...measured }, null, 2));
  await browser.close();

  /* A short read-out, so a failure is visible in the terminal rather than only
     in a file nobody opens. */
  for (const [k] of CANDS) {
    for (const w of (AR ? [] : [390, 1280])) {
      const m = measured[`${k}-${w}`];
      console.log(
        `${k.padEnd(9)} ${String(w).padEnd(5)} scrollW=${m.scrollWidth}/${m.viewport} ` +
          `addY=${m.addToCart && m.addToCart.y} tabs=${m.tabs} rowScrolls=${m.tabRowScrolls} ` +
          `openPanels=${m.openPanels} bundles=${m.bundleBars} thumbs=${m.thumbs} h=${m.pageHeight} ` +
          `stickyOnScreen=${(measured[`${k}-${w}-sticky`] || {}).onScreen}`
      );
    }
  }

  for (const k of (AR ? ['ledger', 'counter', 'marquee'] : [])) {
    const m = measured[`ar-${k}-390`];
    console.log(
      `ar-${k.padEnd(9)} dir=${m.dir} scrollW=${m.scrollWidth}/${m.viewport} ` +
        `tabs=${m.tabs} rowScrolls=${m.tabRowScrolls} openPanels=${m.openPanels} ` +
        `ratingFill=${m.ratingFillPct} h=${m.pageHeight}`
    );
  }
})();
