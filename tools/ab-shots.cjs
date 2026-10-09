/*
 * Lane AB — the evidence for the About us page (/about/).
 *
 *   sh tools/ab-preview.sh 10190
 *   BASE=http://127.0.0.1:10190 TAG=before node tools/ab-shots.cjs
 *   (apply the migration to the preview database, then TAG=after)
 *
 * Full-page shots at 390 and 1280, plus the numbers rule 2 asks for: page
 * height, h1 count, scrollWidth vs clientWidth, the h2 and lead font sizes,
 * console errors, and a real click on the "Shop now" link.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10190';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ab-shots';
const TAG = process.env.TAG || 'after';
const PATH = process.env.PAGE || '/about/';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const numbers = {};

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + PATH, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    // The article, cut from a full-page render so the sticky header sits at the
    // top of the page rather than over the copy.
    const box = await page.evaluate(() => {
      const r = document.querySelector('article.policy').getBoundingClientRect();
      return { x: 0, y: Math.max(0, r.top + scrollY - 24), width: document.documentElement.clientWidth, height: r.height + 48 };
    });
    await page.screenshot({ path: `${OUT}/${TAG}-${width}.png`, fullPage: true, clip: box });
    await page.screenshot({ path: `${OUT}/${TAG}-${width}-full.png`, fullPage: true });

    const n = await page.evaluate(() => {
      const q = (s) => document.querySelector(s);
      const fs = (el) => (el ? getComputedStyle(el).fontSize : null);
      const shop = q('.policy-body .kbb-about-cta a');
      let shopHit = null;
      if (shop) {
        const r = shop.getBoundingClientRect();
        shop.scrollIntoView({ block: 'center', behavior: 'instant' });
        const r2 = shop.getBoundingClientRect();
        const hit = document.elementFromPoint(r2.left + r2.width / 2, r2.top + r2.height / 2);
        shopHit = { href: shop.getAttribute('href'), w: Math.round(r.width), h: Math.round(r.height), hitIsLink: !!(hit && shop.contains(hit)) };
      }
      return {
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        h1: document.querySelectorAll('h1').length,
        scriptsInBody: document.querySelectorAll('.policy-body script').length,
        articleHeight: Math.round(q('article.policy').getBoundingClientRect().height),
        bodyWidth: Math.round(q('.policy-body').getBoundingClientRect().width),
        h2: fs(q('.policy-body h2')),
        lead: fs(q('.policy-body .kbb-about-lead')),
        para: fs(q('.policy-body .kbb-about-lead + p') || q('.policy-body p:not(:first-child)')),
        facts: q('.policy-body .kbb-about-facts') ? getComputedStyle(q('.policy-body .kbb-about-facts')).display : null,
        shop: shopHit,
      };
    });
    n.consoleErrors = errors;
    numbers[`${TAG}-${width}`] = n;

    // A real click on Shop now must navigate.
    if (n.shop && n.shop.hitIsLink) {
      await Promise.all([page.waitForNavigation({ timeout: 15000 }), page.click('.policy-body .kbb-about-cta a')]);
      numbers[`${TAG}-${width}`].shopClickLandedOn = new URL(page.url()).pathname;
    }
    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(`${OUT}/${TAG}-numbers.json`, JSON.stringify(numbers, null, 2) + '\n');
  console.log(JSON.stringify(numbers, null, 2));
})();
