// Lane BC: the product page breadcrumb for a product filed under a parent AND
// its child (Skincare › Toners), at 390 and 1280.
//   node tools/bc-crumb-shots.cjs <tag> <port> [slug]  -> docs/lane-bc-shots/crumb-<tag>-<w>.png
// elementFromPoint / getBoundingClientRect are the HARNESS measuring.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const TAG = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 8961}`;
const PRODUCT = `/product/${process.argv[4] || 'anua-heartleaf-77-clear-pad'}/`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-bc-shots');
const lang = process.env.BC_LANG || '';
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};
  for (const w of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width: w, height: w === 390 ? 844 : 900 } });
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
    page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
    await page.goto(BASE + lang + PRODUCT, { waitUntil: 'networkidle' });
    const crumb = await page.$('.crumb');
    const b = await crumb.boundingBox();
    await page.screenshot({ path: path.join(OUT, `crumb-${TAG}${lang ? '-ar' : ''}-${w}.png`), clip: { x: 0, y: Math.max(0, b.y - 24), width: w, height: Math.min(260, b.height + 160) } });
    report[w] = await page.evaluate(() => {
      const c = document.querySelector('.crumb');
      const r = c.getBoundingClientRect();
      const links = [...c.querySelectorAll('a')].map((a) => {
        const q = a.getBoundingClientRect();
        const hit = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2);
        return { text: a.textContent, href: a.getAttribute('href'), clickable: !!hit && hit.closest('a') === a };
      });
      return { text: c.textContent.replace(/\s+/g, ' ').trim(), height: Math.round(r.height), fontSize: getComputedStyle(c).fontSize, scrollWidth: document.documentElement.scrollWidth, links };
    });
    report[w].errors = errors;
    await page.close();
  }
  console.log(JSON.stringify(report));
  fs.writeFileSync(path.join(OUT, `crumb-${TAG}${lang ? '-ar' : ''}-report.json`), JSON.stringify(report, null, 1));
  await browser.close();
})();
