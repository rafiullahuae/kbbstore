/*
 * Lane MC — the four enrolled settings screens plus the Ecommerce Product page,
 * measured in a real browser. Reports, does not judge.
 */
import { chromium } from 'playwright';

const BASE = process.env.MC_BASE || 'http://127.0.0.1:8932';
const OUT = process.env.MC_OUT || '/home/user/lane-mc/docs/lane-mc-shots';
const TAG = process.env.MC_TAG || 'after';
const CHROME = process.env.MC_CHROME;

const SCREENS = [
  { id: 'ecommerce', name: 'ecommerce-product', tab: 'Product page' },
  { id: 'rev-settings', name: 'review-settings' },
  { id: 'cache', name: 'cache' },
  { id: 'rev-badge', name: 'rating-badge-themes' },
  { id: 'rev-badge', name: 'rating-badge-capsule', rbt: 'capsule' },
  { id: 'mail', name: 'mail' },
];

const report = { tag: TAG, base: BASE, widths: [], error: null };

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', process.env.MC_EMAIL);
      await page.fill('input[type=password]', process.env.MC_PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    const block = { width, screens: [] };

    for (const s of SCREENS) {
      await page.goto(`${BASE}/admin?go=${s.id}`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(2000);

      if (s.tab) {
        const tab = page.locator(`.ectab:has-text("${s.tab}")`).first();
        if (await tab.count()) { await tab.click(); await page.waitForTimeout(1200); }
      }
      if (s.rbt) {
        const tab = page.locator(`.rbt-tab[data-tab="${s.rbt}"]`).first();
        if (await tab.count()) { await tab.click(); await page.waitForTimeout(900); }
      }

      const measured = await page.evaluate(() => {
        const text = (document.querySelector('#content') || document.body).innerText;
        const count = (needle) => (text.match(new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length;
        return {
          scrollWidth: document.documentElement.scrollWidth,
          contentScrollWidth: (document.querySelector('#content') || document.documentElement).scrollWidth,
          contentClientWidth: (document.querySelector('#content') || document.documentElement).clientWidth,
          innerWidth: window.innerWidth,
          controls: document.querySelectorAll('#content input, #content select, #content textarea').length,
          ratingDisplay: count('Rating display'),
          ratingsHeading: count('Ratings'),
          sectionHeadings: [...document.querySelectorAll('#content .ecsec-t, #content .rvs-legend, #content .cch-title, #content .rbt-sec-t, #content .mlf-sec-t')].map(e => e.textContent.trim()),
        };
      });

      /* The console's #content is what scrolls, not the document, so a
         fullPage shot at 844/900 would crop every screen at the fold. The
         WIDTH is what the owner asked to see (390 and 1280) and it is not
         touched; only the height is opened up for the capture, after the
         numbers above were measured at the real one. */
      await page.setViewportSize({ width, height: 2600 });
      await page.waitForTimeout(700);
      await page.screenshot({ path: `${OUT}/${TAG}-${s.name}-${width}.png`, fullPage: true });
      await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
      await page.waitForTimeout(300);

      block.screens.push({ screen: s.name, ...measured, overflow: measured.scrollWidth > measured.innerWidth });
    }

    report.widths.push(block);
    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.message ? e.message : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(report, null, 1));
