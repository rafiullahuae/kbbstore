/*
 * "Buy N items together", pressed for real: the request the button sends, and
 * the basket it leaves. (14 November 2026 -- a set that holds the page's
 * product leads in its place.) Writes <tag>-click-<width>.png (the section,
 * just before the press) and <tag>-cart-<width>.png (the basket page's lines
 * afterwards) into FBT_OUT and prints the request body and the basket lines.
 *
 *   FBT_BASE=http://127.0.0.1:9347 FBT_OUT=docs/plans/frequently-bought-together/shots/v2 FBT_TAG=after \
 *     NODE_PATH=/path/to/node_modules node tools/fbt2-click.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.FBT_BASE || 'http://127.0.0.1:9347';
const OUT = process.env.FBT_OUT || 'docs/plans/frequently-bought-together/shots/v2';
const TAG = process.env.FBT_TAG || 'after';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const out = [];

  for (const width of [390, 1280]) {
    // A real browser's user agent: BlockGate asks headless browsers to leave
    // the cart ("Ask bots to leave"), which is a 403 on the press itself.
    const ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, userAgent: ua });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/product/pdp-heartleaf-toner/', { waitUntil: 'networkidle' });
    const sec = await page.$('.kbb-fbt');
    await sec.scrollIntoViewIfNeeded();
    await page.waitForTimeout(1200);
    const label = (await page.textContent('.kbb-fbt .bt-label')).trim();
    await sec.screenshot({ path: path.join(OUT, `${TAG}-click-${width}.png`) });

    const [req] = await Promise.all([
      page.waitForRequest((r) => r.url().includes('/add-together')),
      page.click('.kbb-fbt [data-bt-buy]'),
    ]);
    const res = await req.response();
    const body = JSON.parse(req.postData() || '{}');

    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const items = await page.$('.items');
    if (items) await items.screenshot({ path: path.join(OUT, `${TAG}-cart-${width}.png`) });
    const lines = await page.$$eval('.items a', (as) => [...new Set(as.map((a) => a.textContent.trim()).filter(Boolean))]);

    out.push({ width, button: label, request: body, status: res ? res.status() : null, cartLines: lines, errors });
    await ctx.close();
  }

  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
