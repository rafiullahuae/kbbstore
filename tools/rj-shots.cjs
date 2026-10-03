/*
 * Lane RJ — photograph HTML files at fixed widths, and report what was measured.
 *
 *   node tools/rj-shots.cjs <dir> <widths comma list> [glob-ish prefix filter]
 *
 * For every <dir>/*.html: <dir>/shots/<name>-<width>.png (full page), and one
 * JSON line per shot with document.documentElement.scrollWidth (anything above
 * the viewport width is a sideways scroll on a phone) and the page height.
 *
 * Emails are photographed in a plain Chromium. That is a WebKit/Blink stand-in
 * for Apple Mail, iOS Mail and the webmail clients; it is NOT Outlook for
 * Windows (Word renderer) and nothing on Linux reproduces that.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const dir = path.resolve(process.argv[2]);
const widths = (process.argv[3] || '390,600').split(',').map(Number);
const filter = process.argv[4] || '';
const out = path.join(dir, 'shots');
/* RJ_DARK=1 photographs with prefers-color-scheme: dark, as Apple Mail / iOS Mail
   apply it. Gmail's apps do their own colour inversion and ignore the query. */
const dark = !!process.env.RJ_DARK;

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const files = fs.readdirSync(dir).filter((f) => f.endsWith('.html') && f.startsWith(filter)).sort();
  for (const f of files) {
    for (const w of widths) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: w <= 400 ? 2 : 1 });
      const page = await ctx.newPage();
      if (dark) await page.emulateMedia({ colorScheme: 'dark' });
      await page.goto('file://' + path.join(dir, f), { waitUntil: 'load' });
      await page.waitForTimeout(150);
      const m = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        height: document.documentElement.scrollHeight,
      }));
      const name = f.replace(/\.html$/, '');
      await page.screenshot({ path: path.join(out, `${name}-${w}${dark ? '-dark' : ''}.png`), fullPage: true });
      console.log(JSON.stringify({ shot: `${name}-${w}`, viewport: w, ...m, overflow: m.scrollWidth > w }));
      await ctx.close();
    }
  }
  await browser.close();
})();
