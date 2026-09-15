/*
 * Screenshot a page at a real viewport.
 *
 *   node tools/screenshot.cjs <url> <out.png> <width> <height> [scrollY]
 *
 * Chromium's headless --window-size does NOT give you that viewport: it
 * enforces a minimum window width, so a 390px "phone" screenshot is really a
 * 390px crop of a ~500px render. Every edge looks cut off and the bug is in the
 * camera. This reports clientWidth and scrollWidth alongside the image so the
 * difference is visible, and a genuine horizontal overflow is a number rather
 * than an impression.
 *
 * Needs `npm install` first; playwright is a devDependency and the browser is
 * already on the box at PLAYWRIGHT_BROWSERS_PATH.
 */
const { chromium } = require('playwright');
(async () => {
  const [url, out, w, h, scroll] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle' });
  if (scroll) await page.evaluate(y => window.scrollTo(0, +y), scroll);
  await page.waitForTimeout(400);
  const m = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth }));
  console.log(JSON.stringify(m));
  await page.screenshot({ path: out });
  await browser.close();
})();
