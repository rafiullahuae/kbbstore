// (Lane PHA) Arabic header pictures on /super-sale/. Against tools/pha-preview.sh:
//   node tools/pha-shoot.cjs shop|admin <port> <tag>
// Writes docs/lane-pha-shots/<tag>-<page>-<width>.png and prints the numbers.
// getBoundingClientRect is the HARNESS measuring; the shop measures nothing.
const { chromium } = require('playwright');
const phase = process.argv[2] || 'shop';
const BASE = `http://127.0.0.1:${process.argv[3] || 10530}`;
const TAG = process.argv[4] || 'after';
const OUT = `${__dirname}/../docs/lane-pha-shots`;
require('fs').mkdirSync(OUT, { recursive: true });

const measure = () => {
  const img = document.querySelector('.kbb-ph-i img');
  const r = img ? img.getBoundingClientRect() : null;
  return {
    lang: document.documentElement.lang, dir: document.documentElement.dir,
    innerWidth: window.innerWidth, scrollWidth: document.documentElement.scrollWidth,
    shown: img ? img.currentSrc.replace(location.origin, '') : null,
    source: (document.querySelector('.kbb-ph-i source') || {}).srcset || null,
    imgBox: r ? [Math.round(r.width), Math.round(r.height)] : null,
  };
};

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 800 : 844 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    if (phase === 'shop') {
      for (const [name, path] of [['en', '/super-sale/'], ['ar', '/ar/super-sale/']]) {
        await page.goto(BASE + path, { waitUntil: 'networkidle' });
        console.log(TAG, name, w, JSON.stringify(await page.evaluate(measure)), 'errors', JSON.stringify(errs));
        await page.screenshot({ path: `${OUT}/${TAG}-${name}-${w}.png` });
      }
    } else if (w === 1280) {
      await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
      await page.fill('input[name=email]', 'owner@preview.test');
      await page.fill('input[name=password]', 'preview-secret-1');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
      await page.evaluate(() => window.go('pageheader'));
      await page.waitForSelector('.phs-list', { timeout: 20000 });
      await page.click('.phs-list button:has-text("Super Sale")');
      await page.waitForSelector('.kbb-phe-pic', { timeout: 20000 });
      await page.waitForTimeout(400);
      const sec = page.locator('.kbb-phe-sec', { has: page.locator('.kbb-phe-pic') }).first();
      await sec.scrollIntoViewIfNeeded();
      console.log('admin', JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('.kbb-phe-pic span')].map((s) => s.textContent))), 'errors', JSON.stringify(errs));
      await sec.screenshot({ path: `${OUT}/${TAG}-admin-pictures.png` });
    }
    await ctx.close();
  }
  await b.close();
})();
