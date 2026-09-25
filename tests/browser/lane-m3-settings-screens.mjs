/*
 * Lane M3 — the three App\Support\*Settings screens, measured in a real browser.
 *
 * Same contract as tests/browser/lane-m2-module-screens.mjs, which this follows
 * rather than replaces: IT REPORTS, IT DOES NOT JUDGE. The Pest suite has no
 * browser in it, and the claim this round has to support — that rewriting three
 * schema constants and moving three casts onto ModuleSchema changed nothing the
 * owner can see — is a claim about rendered layout.
 *
 * WHAT IT ANSWERS, per screen and per width:
 *
 *   scrollWidth / innerWidth   the horizontal-overflow check the project notes
 *                              ask for by name. `overflow: true` is a bug.
 *   controls                   how many settings controls the screen drew, per
 *                              tab and summed. This is the number that must not
 *                              move.
 *   labels                     every control's key, IN ORDER, so "same count"
 *                              cannot hide "different controls" or "same
 *                              controls, reordered" — which is exactly what a
 *                              rewritten schema constant could do, since the
 *                              order these screens draw in is the order of the
 *                              constant.
 *
 * Produces the BEFORE and the AFTER tables without being edited between them:
 * point it at a checkout of either revision and change KBB_M3_TAG.
 *
 *   KBB_M3_BASE=http://127.0.0.1:8983 \
 *   KBB_M3_EMAIL=owner@example.com KBB_M3_PASSWORD=secret-secret \
 *   KBB_M3_OUT=docs/m-module-shots KBB_M3_TAG=m3-after \
 *   KBB_M3_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-m3-settings-screens.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_M3_BASE || 'http://127.0.0.1:8983';
const EMAIL = process.env.KBB_M3_EMAIL;
const PASSWORD = process.env.KBB_M3_PASSWORD;
const OUT = process.env.KBB_M3_OUT || '/tmp';
const TAG = process.env.KBB_M3_TAG || 'run';
const CHROME = process.env.KBB_M3_CHROME;

/*
 * The three screens. `id` is the console's own screen id — the one go()
 * dispatches on.
 *
 * Rating Capsule has NO id of its own any more: review-capsule-screen.blade.php
 * merged into the Badge screen as a tab and its own file now claims no screen
 * id at all, so 'rev-capsule' aliases 'rev-badge'. Listing it separately would
 * photograph the same screen twice and report its controls twice.
 */
const SCREENS = [
  { id: 'rev-settings', name: 'revsettings', sel: '[data-rvs-bool], [data-rvs-int], [data-rvs-enum], [data-rvs-text]', key: ['data-rvs-bool', 'data-rvs-int', 'data-rvs-enum', 'data-rvs-text'] },
  { id: 'rev-badge', name: 'revbadge', sel: '[data-rbt-bool], [data-rbt-enum], [data-rbt-text]', key: ['data-rbt-bool', 'data-rbt-enum', 'data-rbt-text'] },
  { id: 'cache', name: 'cache', sel: '#cch-enabled, #cch-html, #cch-asset', key: ['id'] },
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
       * LOADED AND THEN DISPATCHED FROM THE PAGE, not ?go=<id> — round 2's
       * note, which cost it an hour and is repeated here rather than
       * rediscovered. These partials wrap window.go AFTER the console has
       * booted, the URL is read before those wrappers exist, and `|| renderDash`
       * quietly draws the Dashboard: a screen with no controls, which this
       * script would report as "0 controls, no overflow" for both revisions and
       * call them identical.
       */
      await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(900);
      await page.evaluate((id) => window.go(id), screen.id);
      await page.waitForTimeout(2400);

      /* Every tab, not the one that opens — a control that moved from the last
         tab to nowhere would not change a count taken on arrival. */
      /* The Badge screen is the only one of the three with tabs — Badge themes
         and Rating capsule, the two console ids that both land here — and its
         buttons carry `data-tab`, not `data-rbt-tab`. Named by the class as
         well, so `data-tab` on some other console control cannot be counted as
         a tab of this screen. */
      const tabSel = '.rbt-tab[data-tab]';
      const tabCount = await page.locator(tabSel).count();
      const perTab = [];

      for (let t = 0; t < tabCount; t++) {
        const tab = page.locator(tabSel).nth(t);
        const name = (await tab.innerText()).replace(/\s+/g, ' ').trim().slice(0, 28);
        await tab.click();
        await page.waitForTimeout(700);
        perTab.push({
          tab: name,
          controls: await page.evaluate((s) => document.querySelectorAll(s).length, screen.sel),
          keys: await page.evaluate(
            ({ sel, key }) => Array.from(document.querySelectorAll(sel))
              .map((r) => key.map((k) => r.getAttribute(k)).find(Boolean) || '?'),
            { sel: screen.sel, key: screen.key },
          ),
        });
      }

      const measured = await page.evaluate(({ sel, key }) => {
        const rows = Array.from(document.querySelectorAll(sel));

        return {
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
          controls: rows.length,
          /* The KEY each control edits, in document order. A rewritten schema
             constant that reordered its fields keeps the count and changes
             this. */
            keys: rows.map((r) => key.map((k) => r.getAttribute(k)).find(Boolean) || '?'),
        };
      }, { sel: screen.sel, key: screen.key });

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
