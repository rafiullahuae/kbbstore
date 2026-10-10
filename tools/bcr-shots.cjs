/* Lane BCR: a category page's breadcrumb at 390 and 1280: the trail, its
   height, every link clickable, and where the products start.
   BASE=http://127.0.0.1:PORT OUT=docs/lane-bcr-shots TAG=before|after node tools/bcr-shots.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT, TAG = process.env.TAG;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const w of [1280, 390]) {
    for (const path of ['/collections/cleansers/', '/shop/']) {
      const page = await (await browser.newContext({ viewport: { width: w, height: 900 }, userAgent: UA })).newPage();
      const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      const m = await page.evaluate(() => {
        const c = document.querySelector('.crumb'); const r = c.getBoundingClientRect();
        const links = [...c.querySelectorAll('a')].map((a) => { const b = a.getBoundingClientRect(); const at = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2); return a.textContent.trim() + (at === a || a.contains(at) ? ':ok' : ':BLOCKED'); });
        const grid = document.querySelector('.grid, .pgrid, [class*="grid"] .card, .card'); 
        return { trail: c.innerText.replace(/\s+/g, ' ').trim(), crumbTop: Math.round(r.top), crumbH: Math.round(r.height), links,
          gridTop: grid ? Math.round(grid.getBoundingClientRect().top + scrollY) : null, sw: document.documentElement.scrollWidth };
      });
      if (path.includes('collections')) await page.screenshot({ path: `${OUT}/${TAG}-category-${w}.png`, clip: { x: 0, y: 0, width: w, height: 420 } });
      out.push({ w, path, ...m, errors });
    }
  }
  console.log(JSON.stringify(out));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
