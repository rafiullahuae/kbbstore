// Lane AMP: "SKIN&amp;LAB" on a card, the product page and the brand page,
// photographed in Chromium at 390 and 1280, with what the page actually says.
//
//   node tools/amp-shots.cjs <tag> <port>
//     -> docs/lane-amp-shots/<tag>-*.png and <tag>-report.json
//
// The harness reads the DOM (textContent, scrollWidth); the shop reads no geometry.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const TAG = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 10440}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-amp-shots');
fs.mkdirSync(OUT, { recursive: true });

const PAGES = {
  product: '/product/skin-lab-vitamin-c-brightening-serum/',
  brand: '/brands/skinlab/',
  category: '/product-category/serums/',
};

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};
  for (const [key, url] of Object.entries(PAGES)) {
    for (const w of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
      page.on('pageerror', (e) => errors.push('pageerror: ' + String(e).slice(0, 200)));
      const res = await page.goto(BASE + url, { waitUntil: 'networkidle' });
      const html = await page.content();
      const facts = await page.evaluate(() => {
        const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent).join('\n');
        const og = document.querySelector('meta[property="og:title"]');
        const h1 = document.querySelector('h1');
        const cards = [...document.querySelectorAll('a')].map((a) => a.textContent.trim()).filter((t) => /SKIN/.test(t)).slice(0, 6);
        return {
          title: document.title,
          ogTitle: og ? og.getAttribute('content') : null,
          h1: h1 ? h1.textContent.trim() : null,
          crumbs: [...document.querySelectorAll('nav[aria-label*="read" i] , .crumbs, .breadcrumb')].map((n) => n.textContent.replace(/\s+/g, ' ').trim()).slice(0, 2),
          cardTexts: cards,
          ldHasAmpEntity: /&amp;|\\u0026amp;/.test(ld),
          visibleAmpEntity: document.body.innerText.includes('&amp;') || document.body.innerText.includes('&#'),
          scrollWidth: document.documentElement.scrollWidth,
        };
      });
      facts.status = res.status();
      facts.htmlDoubleEscaped = (html.match(/&amp;amp;|&amp;#/g) || []).length;
      facts.errors = errors;
      report[`${key}-${w}`] = facts;
      await page.screenshot({ path: path.join(OUT, `${TAG}-${key}-${w}.png`), fullPage: false });
      await ctx.close();
    }
  }
  fs.writeFileSync(path.join(OUT, `${TAG}-report.json`), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
