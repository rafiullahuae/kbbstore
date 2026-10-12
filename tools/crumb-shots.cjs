/* Lane CRUMB: the product page breadcrumb at 390 and 1280, measured.
   BASE=http://127.0.0.1:PORT SLUG=... TAG=before|after node tools/crumb-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, SLUG = process.env.SLUG, TAG = process.env.TAG || 'shot';
const OUT = path.resolve(__dirname, '../docs/lane-crumb-shots');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const all = {};
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    const t0 = Date.now();
    const resp = await page.goto(BASE + '/product/' + SLUG + '/', { waitUntil: 'networkidle' });
    const m = await page.evaluate(() => {
      const c = document.querySelector('.pdp-page > .crumb'); const cs = getComputedStyle(c); const r = c.getBoundingClientRect();
      const links = [...c.querySelectorAll('a')].map((a) => { const b = a.getBoundingClientRect(); const x = b.left + Math.min(b.width / 2, 20), y = b.top + b.height / 2; const hit = document.elementFromPoint(x, y); return { text: a.textContent.trim(), h: Math.round(b.height), clickable: !!hit && (hit === a || a.contains(hit)) }; });
      const prev = c.previousElementSibling, next = c.nextElementSibling;
      return { height: Math.round(r.height), top: Math.round(r.top + scrollY), fontSize: cs.fontSize, lineHeight: cs.lineHeight, paddingTop: cs.paddingTop, paddingBottom: cs.paddingBottom, marginBottom: cs.marginBottom, whiteSpace: cs.whiteSpace, overflowX: cs.overflowX, scrollW: c.scrollWidth, clientW: c.clientWidth, links, nextTop: next ? Math.round(next.getBoundingClientRect().top + scrollY) : null, docScrollWidth: document.documentElement.scrollWidth };
    });
    m.status = resp.status(); m.errors = errors;
    await page.screenshot({ path: `${OUT}/${TAG}-product-${w}.png`, clip: { x: 0, y: 0, width: w, height: w === 390 ? 420 : 380 } });
    all[w] = m;
    await ctx.close();
  }
  console.log(JSON.stringify(all, null, 1));
  require('fs').writeFileSync(`${OUT}/${TAG}-measurements.json`, JSON.stringify(all, null, 1));
  await browser.close();
})();
