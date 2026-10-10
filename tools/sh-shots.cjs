/* Lane SH: the checkout's back-to-top arrow, at 1280 and 390, measured.
   BASE=http://127.0.0.1:PORT OUT=docs/lane-sh-shots TAG=before|after node tools/sh-shots.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT, TAG = process.env.TAG || 'shot';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const w of [1280, 390]) {
    // A real browser's UA: BlockGate answers HeadlessChrome on checkout with its bot page.
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
    const page = await ctx.newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/product/glow-deep-serum-rice-alpha-arbutin/', { waitUntil: 'networkidle' });
    await page.click('#mainAdd');
    await page.waitForTimeout(1500);
    await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(800);
    const m = await page.evaluate(() => {
      const f = document.querySelector('.kbb-slimfoot'); if (!f) return { footer: false };
      const inn = f.querySelector('.sf-in').getBoundingClientRect(), t = f.querySelector('.sf-top');
      const b = t.getBoundingClientRect();
      const hit = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
      return { cls: f.className, inLeft: Math.round(inn.left), inRight: Math.round(inn.right), topLeft: Math.round(b.left), topRight: Math.round(b.right), topW: Math.round(b.width),
        rightGap: Math.round(inn.right - b.right), clickable: hit === t || t.contains(hit), sw: document.documentElement.scrollWidth, vw: document.documentElement.clientWidth };
    });
    await page.locator('.kbb-slimfoot').screenshot({ path: `${OUT}/${TAG}-checkout-footer-${w}.png` });
    if (m.footer !== false) { await page.click('.kbb-slimfoot .sf-top'); await page.waitForTimeout(1200); m.scrollAfterClick = await page.evaluate(() => Math.round(scrollY)); }
    out.push({ w, ...m, errors });
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
