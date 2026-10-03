/*
 * Lane RK — the order confirmation before and after package E1, at 600 and
 * 390: the whole email, and the foot of it from the support block down (the
 * contact details and addresses). The delivery address (audit B4) is in
 * the whole-email shots, email-<before|after>-<width>.png.
 *
 *   sh tools/rk-render.sh && node tools/rk-email-shots.cjs
 */
const path = require('path');
const { chromium } = require('playwright');

const DIR = path.resolve(__dirname, '../docs/rk-emails');

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage({ deviceScaleFactor: 1 });

  for (const which of ['before', 'after']) {
    for (const w of [600, 390]) {
      await page.setViewportSize({ width: w, height: 900 });
      await page.goto('file://' + path.join(DIR, `footer-${which}.html`));
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${DIR}/email-${which}-${w}.png`, fullPage: true });

      // From the support heading to the end of the document.
      const top = await page.evaluate(() => {
        const el = [...document.querySelectorAll('div')].find((d) => d.textContent.trim() === 'We are here if you need us');
        return el ? Math.max(0, el.getBoundingClientRect().top + window.scrollY - 24) : 0;
      });
      const height = await page.evaluate(() => document.documentElement.scrollHeight);
      await page.screenshot({ path: `${DIR}/footer-${which}-${w}.png`, fullPage: true, clip: { x: 0, y: top, width: w, height: height - top } });

      const m = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, scrollHeight: document.documentElement.scrollHeight }));
      console.log(JSON.stringify({ shot: `${which}-${w}`, ...m, footerTop: top }));
    }
  }
  await browser.close();
})();
