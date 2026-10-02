/*
 * Lane PR — "load more on scroll" on a category, a brand page and /shop/,
 * photographed WHILE the grey placeholders are up and after the batch lands.
 *
 *   PR_BASE=http://127.0.0.1:8961 node tools/pr-scroll.cjs
 *
 * Each batch request (`kbbbatch=1`) is held for 1.5s by the harness — not by
 * the shop — so the placeholders are on screen long enough to photograph. Then
 * the page is scrolled to the end and the tile count read, and the same page is
 * opened with JavaScript OFF to prove the numbered arrows are still there.
 *
 * NOTHING HERE RUNS ON THE SHOP. IntersectionObserver is the only thing the
 * shop's own loader uses; this harness scrolls and counts.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PR_BASE || 'http://127.0.0.1:8961';
const OUT = path.resolve(__dirname, '..', process.env.PR_OUT || 'docs/lane-pr-shots/after');
const PAGES = (process.env.PR_PAGES || '/collections/skincare-sets/,/brands/cosrx/,/shop/').split(',');

const slug = (p) => p.replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '-') || 'home';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const w of [390, 1280]) {
    const phone = w < 700;

    for (const p of PAGES) {
      const ctx = await browser.newContext({ viewport: { width: w, height: phone ? 844 : 900 }, isMobile: phone, hasTouch: phone });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

      await page.route(/kbbbatch=1/, async (route) => {
        await new Promise((r) => setTimeout(r, 1500));
        await route.continue();
      });

      await page.goto(BASE + p, { waitUntil: 'networkidle' });
      const first = await page.locator('.kbb-tile').count();

      // To the foot of the grid: the pager comes within 600px, the batch is
      // asked for, and the placeholders go up while the harness holds it.
      await page.evaluate(() => {
        const grid = document.querySelector(document.querySelector('.kbb-pager')?.dataset.grid || '#grid');
        const last = grid?.lastElementChild;
        if (last) window.scrollTo(0, window.scrollY + last.getBoundingClientRect().top - 200);
      });
      await page.waitForSelector('.kbb-pskel', { timeout: 4000 }).catch(() => null);
      const skel = await page.locator('.kbb-pskel').count();
      const skelBg = skel ? await page.locator('.kbb-pskel i').first().evaluate((e) => getComputedStyle(e).backgroundImage.slice(0, 80) + ' / ' + getComputedStyle(e).backgroundColor) : null;
      await page.screenshot({ path: `${OUT}/scroll-${slug(p)}-${w}-loading.png` });

      // Then keep going until the loader says there is no more.
      for (let i = 0; i < 12; i++) {
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await page.waitForTimeout(1900);
        if (await page.locator('.kbb-pager').evaluate((n) => n.hidden).catch(() => true)) break;
      }
      await page.waitForTimeout(400);
      const all = await page.locator('.kbb-tile').count();
      const url = new URL(page.url());
      const sw = await page.evaluate(() => document.documentElement.scrollWidth);
      await page.screenshot({ path: `${OUT}/scroll-${slug(p)}-${w}-end.png` });
      await ctx.close();

      // And with JavaScript off: the same page is still a set of links.
      const nojs = await browser.newContext({ viewport: { width: w, height: 900 }, javaScriptEnabled: false });
      const np = await nojs.newPage();
      await np.goto(BASE + p, { waitUntil: 'domcontentloaded' });
      const arrows = await np.locator('.kbb-pager a.page-numbers').count();
      const nextHref = await np.locator('.kbb-pager a[rel="next"]').getAttribute('href').catch(() => null);
      await nojs.close();

      console.log(JSON.stringify({ page: p, width: w, firstPaint: first, placeholders: skel, placeholderPaint: skelBg, afterScrolling: all, addressBar: url.pathname + url.search, scrollWidth: sw, noJsArrowLinks: arrows, noJsNext: nextHref, errors }));
    }
  }

  await browser.close();
})();
