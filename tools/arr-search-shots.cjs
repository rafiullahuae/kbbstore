/*
 * Lane AR round 3 -- the search, in pictures: the header box's panel as typed
 * and the results page, for each query, at 390 and 1280.
 *
 *   node tools/arr-search-shots.cjs <port> [outDir=docs/lane-ar-shots/round3]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const [port, out = 'docs/lane-ar-shots/round3'] = process.argv.slice(2);
const B = `http://127.0.0.1:${port}`;
fs.mkdirSync(out, { recursive: true });
const CASES = [
  ['ar', 'أنوا', 'ar-anua-arabic'], ['ar', 'سيروم', 'ar-serum-arabic'], ['ar', 'Anua', 'ar-anua-latin'],
  ['ar', 'sunscreen', 'ar-sunscreen-latin'], ['ar', 'واقي شمس', 'ar-sunscreen-arabic'], ['en', 'Anua', 'en-anua'], ['ar', '', 'ar-starter'], ['en', '', 'en-starter'],
];
const UA = (w) => (w < 900 ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36');

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, userAgent: UA(w), hasTouch: w < 900, isMobile: w < 900 });
    await ctx.route('**/*', (r) => (r.request().url().startsWith(B) ? r.continue() : r.abort()));
    for (const [lang, q, name] of CASES) {
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));
      const pre = lang === 'ar' ? '/ar' : '';
      await page.goto(B + pre + '/', { waitUntil: 'load' });
      const input = page.locator('header .search-in input[type="search"]').first();
      await input.click();
      if (q) {
        await input.pressSequentially(q, { delay: 40 });
      }
      await page.waitForTimeout(1500);
      const panel = await page.evaluate(() => {
        const p = document.getElementById('kbbSuggest');
        return p ? { on: p.classList.contains('on'), heads: [...p.querySelectorAll('.sgh')].map((h) => h.textContent.trim()), rows: [...p.querySelectorAll('.sn, .sgi b, .chip')].slice(0, 6).map((r) => r.textContent.trim().slice(0, 60)), all: (p.querySelector('.viewall') || {}).textContent || null } : null;
      });
      await page.screenshot({ path: path.join(out, `search-${name}-box-${w}.png`), clip: { x: 0, y: 0, width: w, height: w < 900 ? 760 : 620 } });
      let results = null;
      if (q) {
        await page.goto(B + pre + '/shop/?s=' + encodeURIComponent(q), { waitUntil: 'load' });
        await page.waitForTimeout(500);
        results = await page.evaluate(() => ({
          h1: (document.querySelector('h1') || {}).textContent?.trim(),
          names: [...document.querySelectorAll('.kbb-card-nm')].slice(0, 6).map((n) => n.textContent.trim().slice(0, 50)),
          count: document.querySelectorAll('.kbb-card').length,
          sw: document.documentElement.scrollWidth,
        }));
        await page.screenshot({ path: path.join(out, `search-${name}-page-${w}.png`), clip: { x: 0, y: 0, width: w, height: w < 900 ? 1100 : 900 } });
      }
      report[`${name} ${w}`] = { panel, results, errors };
      console.log(name, w, JSON.stringify(panel).slice(0, 220), '|', results ? `${results.count} cards: ${results.names.slice(0, 3).join(' / ')}` : '');
      await page.close();
    }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(out, 'search-shots.json'), JSON.stringify(report, null, 1));
})();
