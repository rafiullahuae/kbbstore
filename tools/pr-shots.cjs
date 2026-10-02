/*
 * Lane PR — photograph and MEASURE the product grid card on the shop, a
 * category and a brand page, at 390 (a touch phone) and 1280 (a mouse).
 *
 *   PR_BASE=http://127.0.0.1:8961 PR_OUT=docs/lane-pr-shots/before node tools/pr-shots.cjs
 *
 * What it records per page and width, and why each number:
 *
 *   scrollWidth          no horizontal page scroll (must equal the viewport)
 *   tiles                cards drawn on first paint (24 with arrows, 12 a batch)
 *   badgesNew/badgesSale NEW and -N% pills in the grid (the owner's ask 1)
 *   pager                the pager's data-load, and whether its numbers show
 *   heights              the distinct card heights in the grid
 *   gaps                 photo → name, name → price row, price row → button,
 *                        and the text column's padding, off the first tile
 *   type                 computed size/weight of brand, name, price, sale
 *                        price and button
 *   hover                a tile's box-shadow / transform / photo transform
 *                        before and after the pointer is put on it
 *
 * The phone context is `isMobile + hasTouch`, which is what makes Chromium
 * answer `(hover: none)` — checked and printed, because a "phone" that still
 * reports hover:hover would photograph the desktop behaviour.
 *
 * NOTHING HERE RUNS ON THE SHOP. This is the harness; the shop measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PR_BASE || 'http://127.0.0.1:8961';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PR_OUT || 'docs/lane-pr-shots/after');
const PAGES = (process.env.PR_PAGES || '/shop/,/collections/skincare-sets/,/brands/cosrx/').split(',');
const WIDTHS = (process.env.PR_WIDTHS || '390,1280').split(',').map(Number);
const TAG = process.env.PR_TAG || '';

function slug(p) {
  return p.replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '-') || 'home';
}

async function measure(page) {
  return page.evaluate(() => {
    const r2 = (n) => Math.round(n * 100) / 100;
    const box = (el) => (el ? el.getBoundingClientRect() : null);
    const cs = (el) => (el ? getComputedStyle(el) : null);
    const tiles = [...document.querySelectorAll('.kbb-tile')];
    const vis = (sel) => [...document.querySelectorAll(sel)].filter((e) => e.offsetParent !== null && getComputedStyle(e).display !== 'none').length;
    const t = tiles.find((x) => x.querySelector('.kbb-card-reg')) || tiles[0];
    const plain = tiles.find((x) => !x.querySelector('.kbb-card-reg')) || tiles[0];
    const shot = t?.querySelector('.kbb-card-shot') || t?.querySelector('.kbb-card-thumb');
    const cn = t?.querySelector('.cn');
    const cp = t?.querySelector('.cp');
    const cart = t?.querySelector('.kbb-card-cart');
    const cb = t?.querySelector('.cb');
    const font = (el) => (el ? { size: cs(el).fontSize, weight: cs(el).fontWeight } : null);
    const pager = document.querySelector('.kbb-pager');

    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      hoverNone: matchMedia('(hover: none)').matches,
      tiles: tiles.length,
      badgesNew: vis('.kbb-tile .kbb-badge-new'),
      badgesSale: vis('.kbb-tile .kbb-badge-sale'),
      pager: pager ? { load: pager.dataset.load, batch: pager.dataset.batch || null, auto: pager.classList.contains('is-auto'), numbersShown: vis('.kbb-pager .page-numbers') } : null,
      heights: [...new Set(tiles.map((x) => r2(box(x).height)))],
      tileWidth: tiles[0] ? r2(box(tiles[0]).width) : null,
      gaps: t ? {
        photoToName: r2(box(cn).top - box(shot).bottom),
        nameToPrice: r2(box(cp).top - box(cn).bottom),
        priceToButton: cart ? r2(box(cart).top - box(cp).bottom) : null,
        pad: cs(cb).padding,
        cpPaddingTop: cs(cp).paddingTop,
        cartMarginTop: cart ? cs(cart).marginTop : null,
      } : null,
      type: t ? {
        brand: font(t.querySelector('.kbb-card-brand')),
        name: font(t.querySelector('.kbb-card-nm')),
        price: font(plain.querySelector('.kbb-card-price')),
        salePrice: font(t.querySelector('.kbb-card-reg + .kbb-card-price')),
        was: font(t.querySelector('.kbb-card-reg')),
        button: font(cart),
      } : null,
    };
  });
}

async function hoverProbe(page) {
  const tile = page.locator('.kbb-tile:has(img)').first();

  if (!(await tile.count())) return null;

  const read = () => tile.evaluate((el) => {
    const img = el.querySelector('.kbb-card-thumb img, .kbb-card-shot img');
    const s = getComputedStyle(el);

    return { shadow: s.boxShadow, transform: s.transform, img: img ? getComputedStyle(img).transform : null };
  });

  await page.mouse.move(2, 2);
  await page.waitForTimeout(400);
  const before = await read();
  await tile.hover({ position: { x: 20, y: 20 } });
  await page.waitForTimeout(900);
  const after = await read();
  await page.mouse.move(2, 2);

  return { before, after, changed: JSON.stringify(before) !== JSON.stringify(after) };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const rows = [];

  for (const w of WIDTHS) {
    const phone = w < 700;
    const ctx = await browser.newContext({
      viewport: { width: w, height: phone ? 844 : 900 },
      isMobile: phone,
      hasTouch: phone,
      deviceScaleFactor: 1,
    });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));

    for (const p of PAGES) {
      await page.goto(BASE + p, { waitUntil: 'networkidle' });
      await page.waitForTimeout(300);
      const m = await measure(page);
      const hover = await hoverProbe(page);
      const name = `${slug(p)}-${w}${TAG}`;
      await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false });
      rows.push({ shot: name, ...m, hover });
      console.log(JSON.stringify({ shot: name, ...m, hover }));
    }

    await ctx.close();
  }

  fs.writeFileSync(`${OUT}/measurements${TAG}.json`, JSON.stringify(rows, null, 2));
  await browser.close();
})();
