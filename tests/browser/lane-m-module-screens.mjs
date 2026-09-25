/*
 * Lane M — the module settings screens, measured in a real browser.
 *
 * Following the convention of tests/browser/checkout-inline-validation.mjs and
 * checkout-hidden-rows.mjs: IT REPORTS, IT DOES NOT JUDGE. The Pest suite on
 * this project has no browser in it, and the claims this lane makes about the
 * admin — that a screen still draws what it drew, that a retired module shows
 * no switch, that nothing overflows at 390px — are claims about rendered
 * layout, which the server's output cannot settle.
 *
 * WHAT IT ANSWERS, per screen and per width:
 *
 *   scrollWidth / innerWidth   the horizontal-overflow check the project notes
 *                              ask for by name. `overflow: true` is a bug.
 *   controls                   how many settings rows the screen drew. This is
 *                              the number that must not move when a screen's
 *                              render loop is replaced by ModuleSchema::tabs().
 *   rows                       the text of the two registry rows this lane
 *                              changed, so "Not ported yet" versus "Already
 *                              applied to every page" is readable rather than
 *                              asserted.
 *
 * It takes screenshots as it goes, which is the other half of what a patch owes
 * here, and it produces the BEFORE and the AFTER tables without being edited
 * between them — point it at a checkout of either revision.
 *
 *   KBB_M_BASE=http://127.0.0.1:8977 \
 *   KBB_M_EMAIL=owner@example.com KBB_M_PASSWORD=secret-secret \
 *   KBB_M_OUT=/tmp/shots KBB_M_TAG=after \
 *   KBB_M_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-m-module-screens.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_M_BASE || 'http://127.0.0.1:8977';
const EMAIL = process.env.KBB_M_EMAIL;
const PASSWORD = process.env.KBB_M_PASSWORD;
const OUT = process.env.KBB_M_OUT || '/tmp';
const TAG = process.env.KBB_M_TAG || 'run';
const CHROME = process.env.KBB_M_CHROME;

/* The screens this lane touched, plus Modules where the two retired/ported
   rows live. `tab` is the sub-tab to click, where the screen has them. */
const SCREENS = [
  { id: 'modules', name: 'modules' },
  { id: 'ecommerce', name: 'ecommerce-checkout', tab: 'Checkout' },
  { id: 'cartpanel', name: 'cartpanel' },
  { id: 'dividers', name: 'dividers' },
  { id: 'mobilehdr', name: 'mobilehdr' },
  { id: 'newsletter', name: 'newsletter' },
  { id: 'acctpanel', name: 'acctpanel' },
  { id: 'prodstyles', name: 'prodstyles' },
  { id: 'header', name: 'header' },
  { id: 'labels', name: 'labels' },
];

/* The two registry rows this lane changed, read as text rather than guessed. */
const ROWS = [['Performance & Speed', 'perf'], ['Address autocomplete', 'addr']];

const report = { tag: TAG, base: BASE, widths: [], error: null };

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({
      viewport: { width, height: width === 390 ? 844 : 900 },
    });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });

    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', EMAIL);
      await page.fill('input[type=password]', PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    const block = { width, screens: [], rows: [] };

    for (const screen of SCREENS) {
      await page.goto(`${BASE}/admin?go=${screen.id}`, { waitUntil: 'networkidle' });
      /* The console renders from a fetch, so a fixed settle is the honest way
         to wait — there is no single selector that means "this screen is done"
         across ten differently-built screens. */
      await page.waitForTimeout(1800);

      if (screen.tab) {
        const tab = page.locator(`.ectab:has-text("${screen.tab}")`).first();
        if (await tab.count()) {
          await tab.click();
          await page.waitForTimeout(1000);
        }
      }

      const measured = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
        /* Every settings row the screen drew, under any of the class names the
           differently-built screens use for one. */
        controls: document.querySelectorAll('.mmrow, .ecopt, .mdrow').length,
      }));

      await page.screenshot({ path: `${OUT}/${TAG}-${screen.name}-${width}.png`, fullPage: true });

      block.screens.push({
        screen: screen.name,
        ...measured,
        overflow: measured.scrollWidth > measured.innerWidth,
      });
    }

    await page.goto(`${BASE}/admin?go=modules`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);

    for (const [label, name] of ROWS) {
      const row = page.locator(`.mdrow:has-text("${label}")`).first();

      if (!(await row.count())) {
        block.rows.push({ row: name, text: 'ROW NOT FOUND' });
        continue;
      }

      await row.scrollIntoViewIfNeeded();
      await page.waitForTimeout(400);
      await row.screenshot({ path: `${OUT}/${TAG}-${name}-row-${width}.png` });

      block.rows.push({
        row: name,
        /* Whether a switch is drawn at all — a `todo` row draws an inert one in
           the grey off position, an `inherent` row draws none. */
        hasSwitch: await row.locator('.ectog:visible').count() > 0,
        text: (await row.innerText()).replace(/\s+/g, ' ').trim().slice(0, 180),
      });
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
