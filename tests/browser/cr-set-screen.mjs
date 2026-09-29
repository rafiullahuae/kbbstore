/**
 * Lane CR — the Set screen, and the Cart page screen, as they really draw.
 *
 * Reports the section strip, how many controls are on screen in each section
 * with the fold closed and open, and document.documentElement.scrollWidth. It
 * only reads what the console already rendered; nothing here is shipped.
 */
import { chromium } from 'playwright';

const BASE = process.env.CR_BASE || 'http://127.0.0.1:8931';
const ADMIN = process.env.CR_ADMIN || '/admin';
const out = { steps: [] };

const browser = await chromium.launch({
  executablePath: process.env.KBB_BROWSER_CHROME, args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 950 } });
    const page = await ctx.newPage();

    await page.goto(`${BASE}${ADMIN}`, { waitUntil: 'networkidle' });
    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', process.env.CR_EMAIL);
      await page.fill('input[type=password]', process.env.CR_PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    for (const screen of ['setap', 'cartpage']) {
      await page.goto(`${BASE}${ADMIN}?go=${screen}`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(2500);

      if (screen === 'setap') {
        const cart = page.locator('.sap-g:has-text("cart page")').first();
        if (await cart.count()) { await cart.click(); await page.waitForTimeout(900); }
      } else {
        const tab = page.locator('.cps-tabs button:has-text("spacing and size")').first();
        if (await tab.count()) { await tab.click(); await page.waitForTimeout(900); }
      }

      const folded = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        sections: Array.from(document.querySelectorAll('.sap-g')).map((b) => b.textContent.trim()),
        controls: document.querySelectorAll('#content .sap-f, #content .cps-f').length,
        cards: document.querySelectorAll('#content .sap-card .sap-title, #content .cps-card').length,
        foldButton: (document.querySelector('[data-sap-detail]') || {}).textContent || null,
      }));

      await page.screenshot({ path: `storage/cr-logs/shots/${screen}-folded-${width}.png` });

      let open = null;
      const more = page.locator('[data-sap-detail="all"]').first();
      if (await more.count()) {
        await more.click();
        await page.waitForTimeout(700);
        open = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          controls: document.querySelectorAll('#content .sap-f').length,
        }));
        await page.screenshot({ path: `storage/cr-logs/shots/${screen}-open-${width}.png` });
      }

      out.steps.push({ width, screen, folded, open });
    }

    await ctx.close();
  }
  out.ok = true;
} catch (e) {
  out.error = String(e && e.message ? e.message : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(out, null, 2));
