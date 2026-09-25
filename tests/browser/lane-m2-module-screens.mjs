/*
 * Lane M2 — the five module settings screens this round touched, measured in a
 * real browser.
 *
 * Same contract as tests/browser/lane-m-module-screens.mjs, which this follows
 * rather than replaces: IT REPORTS, IT DOES NOT JUDGE. The Pest suite has no
 * browser in it, and the claim this lane has to support — that migrating a
 * screen's cast and its render loop onto ModuleSchema moved nothing the owner
 * can see — is a claim about rendered layout.
 *
 * WHAT IT ANSWERS, per screen and per width:
 *
 *   scrollWidth / innerWidth   the horizontal-overflow check the project notes
 *                              ask for by name. `overflow: true` is a bug.
 *   controls                   how many settings rows the screen drew. This is
 *                              the number that must not move.
 *   labels                     the first eight control labels, in order, so
 *                              "same count" cannot hide "different controls" —
 *                              a reordered TABS constant keeps the count.
 *
 * Produces the BEFORE and the AFTER tables without being edited between them:
 * point it at a checkout of either revision and change KBB_M2_TAG.
 *
 *   KBB_M2_BASE=http://127.0.0.1:8981 \
 *   KBB_M2_EMAIL=owner@example.com KBB_M2_PASSWORD=secret-secret \
 *   KBB_M2_OUT=docs/m-module-shots KBB_M2_TAG=m2-after \
 *   KBB_M2_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-m2-module-screens.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_M2_BASE || 'http://127.0.0.1:8981';
const EMAIL = process.env.KBB_M2_EMAIL;
const PASSWORD = process.env.KBB_M2_PASSWORD;
const OUT = process.env.KBB_M2_OUT || '/tmp';
const TAG = process.env.KBB_M2_TAG || 'run';
const CHROME = process.env.KBB_M2_CHROME;

/* The five screens this round touched. `id` is the console's own screen id —
   the one go() dispatches on, or the one a wrapping partial claims. */
const SCREENS = [
  { id: 'cartpage', name: 'cartpage' },
  { id: 'checkoutpage', name: 'checkoutpage' },
  { id: 'security', name: 'security' },
  { id: 'hpcontent', name: 'hpcontent' },
  { id: 'mobilemenu', name: 'mobilemenu' },
];

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

    const block = { width, screens: [] };

    for (const screen of SCREENS) {
      /*
       * ?go=<id> IS NOT ENOUGH FOR THREE OF THESE FIVE, and it fails silently.
       *
       * Cart page, Checkout page and Security are installed by partials that
       * WRAP window.go after the console has booted (`var SCREEN = 'security'`
       * and `previousGo`). The URL is read before those wrappers exist, so
       * ?go=security dispatches through the original go(), finds no entry, and
       * `|| renderDash` quietly draws the Dashboard — a screen with no controls
       * on it, which this script would have reported as "0 controls, no
       * overflow" for both revisions and called them identical.
       *
       * So it loads /admin, lets the partials install themselves, and then
       * calls go() from the page.
       */
      await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(900);
      await page.evaluate((id) => window.go(id), screen.id);
      /* The console renders from a fetch, so a fixed settle is the honest way
         to wait — there is no single selector that means "this screen is done"
         across five differently-built screens. */
      await page.waitForTimeout(2200);

      /*
       * EVERY TAB, NOT THE ONE THAT OPENS. These screens draw one band at a
       * time, so a per-screen count taken on arrival is really a count of the
       * first tab — and a control that moved from the last tab to nowhere
       * would not change it.
       */
      const tabSel = '[data-cps-tab], [data-chp-tab], [data-sx-tab], [data-hpc-tab]';
      const tabCount = await page.locator(tabSel).count();
      const perTab = [];

      for (let t = 0; t < Math.max(tabCount, 0); t++) {
        const tab = page.locator(tabSel).nth(t);
        const name = (await tab.innerText()).replace(/\s+/g, ' ').trim().slice(0, 28);
        await tab.click();
        await page.waitForTimeout(700);
        perTab.push({ tab: name, controls: await page.evaluate(() => document
          .querySelectorAll('[data-cps-key], [data-chp-key], [data-sx-key], [data-hpc-set], .mmrow')
          .length) });
      }

      const measured = await page.evaluate(() => {
        /*
         * Every settings CONTROL, by the attribute each screen's own renderer
         * puts on the input it edits — not by a class name. Four of these five
         * screens build their own field markup and their wrapper classes do not
         * agree; `data-<prefix>-key` is what each one hangs the key off, and is
         * therefore one control exactly once.
         *
         * `.cps-row` is deliberately NOT in this list although it looks like a
         * settings row: on the Cart page screen it is a PRODUCT SEARCH RESULT
         * in the recommended-rail picker, and counting it would make this
         * number move with the catalogue.
         */
        const sel = '[data-cps-key], [data-chp-key], [data-sx-key], [data-hpc-set], .mmrow';
        const rows = Array.from(document.querySelectorAll(sel));

        return {
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
          controls: rows.length,
          labels: rows
            .slice(0, 8)
            .map((r) => (r.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 44)),
        };
      });

      await page.screenshot({ path: `${OUT}/${TAG}-${screen.name}-${width}.png`, fullPage: true });

      block.screens.push({
        screen: screen.name,
        ...measured,
        tabs: perTab,
        controlsAllTabs: perTab.length ? perTab.reduce((a, b) => a + b.controls, 0) : measured.controls,
        overflow: measured.scrollWidth > measured.innerWidth,
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
